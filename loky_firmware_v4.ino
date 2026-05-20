// **********************************************************************************
// LoKy ACC v4.0 — Firmware Final avec OTA automatique
// ESP32 WeMos D1 Mini + JSY-MK-163T
// Mise à jour automatique silencieuse via WiFi
// **********************************************************************************

#include <WiFi.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <MycilaJSY.h>

// =================== VERSION FIRMWARE ===================
#define FIRMWARE_VERSION  "4.0.0"

// =================== URL FIXE (ne change jamais !) ===================
// C'est la seule URL hardcodée — pointe vers le fichier de config
#define CONFIG_URL "https://bidules3d.com/fred/loky/firmware/config.json"

// Valeurs par défaut (écrasées par config.json)
#define DEFAULT_API_URL    "https://bidules3d.com/fred/loky/api.php"
#define DEFAULT_UPDATE_URL "https://bidules3d.com/fred/loky/firmware/"

// =================== PARAMÈTRES ===================
#define SEND_INTERVAL_MS   30000    // Envoi données toutes les 30s
#define OTA_CHECK_MS       3600000  // Vérifier MAJ toutes les heures
#define SURPLUS_THRESHOLD  200
#define LONG_PRESS_MS      5000
#define WIFI_RETRY_MS      30000
#define WIFI_CHECK_MS      15000

// =================== PINS ===================
#define PIN_JSY_RX  16
#define PIN_JSY_TX  17
#define PIN_LED     2
#define PIN_BTN     0

// =================== OBJETS ===================
Mycila::JSY  jsy;
Preferences  preferences;
HTTPClient   http;

// =================== CONFIG SERVEUR ===================
char api_url[200]    = DEFAULT_API_URL;
char update_url[200] = DEFAULT_UPDATE_URL;
char server_version[20] = "0.0.0";

// =================== CONFIG UTILISATEUR ===================
char participant_id[32] = "";
char acc_name[32]       = "";

// =================== MESURES JSY ===================
float  ch1_voltage    = 0;
float  ch1_current    = 0;
float  ch1_power      = 0;
float  ch1_energy_in  = 0;
float  ch1_energy_out = 0;
float  ch1_pf         = 0;
float  total_power    = 0;
bool   jsy_ready      = false;
bool   has_pending    = false;

// =================== TIMING ===================
unsigned long last_send_ms     = 0;
unsigned long last_ota_check   = 0;
unsigned long uptime_s         = 0;
unsigned long prev_tick        = 0;
unsigned long last_wifi_check  = 0;
unsigned long last_wifi_retry  = 0;

// =================== BOUTON ===================
unsigned long btn_press_start = 0;
bool          btn_was_pressed = false;
bool          long_press_done = false;

// =================== LED ===================
unsigned long led_blink_ms  = 0;
int           led_blink_dur = 0;

// =================== WIFI STATE ===================
enum WifiState { WIFI_OK, WIFI_LOST, WIFI_RECONNECTING };
WifiState wifi_state = WIFI_OK;

// ================================================
// LED
// ================================================
void ledOn()  { digitalWrite(PIN_LED, HIGH); }
void ledOff() { digitalWrite(PIN_LED, LOW); }
void ledBlink(int dur) { ledOn(); led_blink_ms = millis(); led_blink_dur = dur; }

void ledPattern(int times, int onMs, int offMs) {
    for (int i = 0; i < times; i++) {
        ledOn(); delay(onMs); ledOff(); delay(offMs);
    }
}

// ================================================
// CONFIG FLASH
// ================================================
void saveConfig() {
    preferences.begin("loky", false);
    preferences.putString("pid", participant_id);
    preferences.putString("acc", acc_name);
    preferences.putString("api", api_url);
    preferences.putString("upd", update_url);
    preferences.end();
    Serial.printf("[CFG] Sauvegardé — ID=%s ACC=%s\n", participant_id, acc_name);
}

void loadConfig() {
    preferences.begin("loky", true);
    preferences.getString("pid", "").toCharArray(participant_id, sizeof(participant_id));
    preferences.getString("acc", "").toCharArray(acc_name,       sizeof(acc_name));
    preferences.getString("api", DEFAULT_API_URL).toCharArray(api_url,    sizeof(api_url));
    preferences.getString("upd", DEFAULT_UPDATE_URL).toCharArray(update_url, sizeof(update_url));
    preferences.end();
    Serial.printf("[CFG] ID=%s ACC=%s API=%s\n", participant_id, acc_name, api_url);
}

// ================================================
// RÉCUPÉRER CONFIG SERVEUR (URL + version dispo)
// ================================================
bool fetchServerConfig() {
    if (WiFi.status() != WL_CONNECTED) return false;

    Serial.println("[CFG] Récupération config serveur...");

    HTTPClient httpClient;
    httpClient.begin(CONFIG_URL);
    httpClient.setTimeout(10000);
    httpClient.addHeader("X-LoKy-Version", FIRMWARE_VERSION);
    httpClient.addHeader("X-LoKy-ID", participant_id);

    int code = httpClient.GET();

    if (code == 200) {
        String body = httpClient.getString();
        Serial.println("[CFG] Config reçue : " + body);

        DynamicJsonDocument doc(512);
        if (deserializeJson(doc, body) == DeserializationError::Ok) {

            // Mettre à jour l'URL API si changée
            if (doc.containsKey("api_url")) {
                String newApi = doc["api_url"].as<String>();
                if (newApi != String(api_url)) {
                    Serial.printf("[CFG] URL API mise à jour : %s\n", newApi.c_str());
                    newApi.toCharArray(api_url, sizeof(api_url));
                    saveConfig();
                }
            }

            // URL de mise à jour
            if (doc.containsKey("update_url")) {
                doc["update_url"].as<String>().toCharArray(update_url, sizeof(update_url));
            }

            // Version disponible sur le serveur
            if (doc.containsKey("firmware_version")) {
                doc["firmware_version"].as<String>().toCharArray(server_version, sizeof(server_version));
            }

            Serial.printf("[CFG] Version serveur : %s | Version locale : %s\n",
                          server_version, FIRMWARE_VERSION);

            httpClient.end();
            return true;
        }
    } else {
        Serial.printf("[CFG] Erreur récupération config : %d\n", code);
    }

    httpClient.end();
    return false;
}

// ================================================
// COMPARAISON DE VERSIONS SEMVER
// ================================================
bool isNewerVersion(const char* serverVer, const char* localVer) {
    int sMaj = 0, sMin = 0, sPat = 0;
    int lMaj = 0, lMin = 0, lPat = 0;

    sscanf(serverVer, "%d.%d.%d", &sMaj, &sMin, &sPat);
    sscanf(localVer,  "%d.%d.%d", &lMaj, &lMin, &lPat);

    if (sMaj != lMaj) return sMaj > lMaj;
    if (sMin != lMin) return sMin > lMin;
    return sPat > lPat;
}

// ================================================
// OTA — MISE À JOUR FIRMWARE
// ================================================
void checkAndUpdate() {
    if (WiFi.status() != WL_CONNECTED) return;
    if (strlen(server_version) == 0 || strcmp(server_version, "0.0.0") == 0) return;

    if (!isNewerVersion(server_version, FIRMWARE_VERSION)) {
        Serial.printf("[OTA] Firmware à jour (v%s)\n", FIRMWARE_VERSION);
        return;
    }

    Serial.printf("[OTA] 🆕 Nouvelle version disponible : v%s (actuelle: v%s)\n",
                  server_version, FIRMWARE_VERSION);

    // LED pattern OTA : 3 clignotements rapides
    ledPattern(3, 100, 100);

    // Construire l'URL du firmware
    String firmwareUrl = String(update_url) + "loky_v" + server_version + ".bin";
    Serial.printf("[OTA] Téléchargement : %s\n", firmwareUrl.c_str());

    // Callbacks OTA
    httpUpdate.onStart([]() {
        Serial.println("[OTA] ⬇️ Début téléchargement...");
        ledOn();
    });

    httpUpdate.onEnd([]() {
        Serial.println("[OTA] ✅ Téléchargement terminé — redémarrage...");
        ledPattern(5, 50, 50);
    });

    httpUpdate.onProgress([](int cur, int total) {
        static int lastPct = -1;
        int pct = (cur * 100) / total;
        if (pct != lastPct && pct % 10 == 0) {
            Serial.printf("[OTA] Progression : %d%%\n", pct);
            lastPct = pct;
        }
    });

    httpUpdate.onError([](int err) {
        Serial.printf("[OTA] ❌ Erreur : %s\n", httpUpdate.getLastErrorString().c_str());
        ledPattern(3, 500, 200);
    });

    // Lancer la mise à jour
    HTTPClient httpOTA;
    httpOTA.begin(firmwareUrl);

    t_httpUpdate_return ret = httpUpdate.update(httpOTA);

    switch (ret) {
        case HTTP_UPDATE_FAILED:
            Serial.printf("[OTA] ❌ Échec : %s\n", httpUpdate.getLastErrorString().c_str());
            break;
        case HTTP_UPDATE_NO_UPDATES:
            Serial.println("[OTA] Pas de mise à jour");
            break;
        case HTTP_UPDATE_OK:
            Serial.println("[OTA] ✅ Mise à jour réussie — redémarrage");
            // ESP32 redémarre automatiquement
            break;
    }

    httpOTA.end();
}

// ================================================
// JSY CALLBACK
// ================================================
void jsyCallback(Mycila::JSY::EventType eventType,
                 const Mycila::JSY::Data& data) {
    if (eventType != Mycila::JSY::EventType::EVT_READ) return;

    ch1_voltage    = data.single().voltage;
    ch1_current    = data.single().current;
    ch1_power      = data.single().activePower;
    ch1_energy_in  = data.single().activeEnergyImported;
    ch1_energy_out = data.single().activeEnergyReturned;
    ch1_pf         = data.single().powerFactor;
    total_power    = ch1_power;
    jsy_ready      = true;
    has_pending    = true;

    if (total_power < -SURPLUS_THRESHOLD) {
        Serial.printf("[JSY] ⚡ SURPLUS [%s] : %.0fW\n", acc_name, -total_power);
        ledBlink(50);
    }
}

// ================================================
// ENVOI DONNÉES SERVEUR
// ================================================
void sendToServer() {
    if (WiFi.status() != WL_CONNECTED) return;
    if (strlen(api_url) < 8) return;

    DynamicJsonDocument doc(1024);
    doc["id"]      = participant_id;
    doc["acc"]     = acc_name;
    doc["uptime"]  = uptime_s;
    doc["rssi"]    = WiFi.RSSI();
    doc["ip"]      = WiFi.localIP().toString();
    doc["fw"]      = FIRMWARE_VERSION;

    JsonObject ch1    = doc.createNestedObject("ch1");
    ch1["voltage"]    = round(ch1_voltage    * 10)   / 10.0;
    ch1["current"]    = round(ch1_current    * 1000) / 1000.0;
    ch1["power"]      = round(ch1_power      * 10)   / 10.0;
    ch1["energy_in"]  = round(ch1_energy_in  * 1000) / 1000.0;
    ch1["energy_out"] = round(ch1_energy_out * 1000) / 1000.0;
    ch1["pf"]         = round(ch1_pf         * 100)  / 100.0;

    doc["total_power"] = round(total_power * 10) / 10.0;
    doc["surplus"]     = total_power < 0 ? round(-total_power * 10) / 10.0 : 0;
    doc["is_surplus"]  = total_power < -SURPLUS_THRESHOLD;

    String json;
    serializeJson(doc, json);

    http.begin(api_url);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-LoKy-ID",      participant_id);
    http.addHeader("X-LoKy-ACC",     acc_name);
    http.addHeader("X-LoKy-Version", FIRMWARE_VERSION);
    http.setTimeout(8000);

    int code = http.POST(json);
    if (code > 0) {
        Serial.printf("[HTTP] ✓ %d — surplus=%.0fW\n", code,
                      total_power < 0 ? -total_power : 0.0f);
        if (code == 200) ledBlink(100);
    } else {
        Serial.printf("[HTTP] ✗ %s\n", http.errorToString(code).c_str());
    }
    http.end();
    last_send_ms = millis();
    has_pending  = false;
}

// ================================================
// GESTION WIFI ROBUSTE
// ================================================
void handleWiFiReconnect() {
    unsigned long now = millis();
    if (now - last_wifi_check < WIFI_CHECK_MS) return;
    last_wifi_check = now;

    if (WiFi.status() == WL_CONNECTED) {
        if (wifi_state != WIFI_OK) {
            Serial.printf("[WIFI] ✓ Reconnecté ! IP=%s\n",
                          WiFi.localIP().toString().c_str());
            wifi_state = WIFI_OK;
            // Récupérer la config après reconnexion
            fetchServerConfig();
        }
        return;
    }

    if (wifi_state == WIFI_OK) {
        Serial.println("[WIFI] ⚠ Connexion perdue !");
        wifi_state = WIFI_LOST;
    }

    if (now - last_wifi_retry < WIFI_RETRY_MS) return;
    last_wifi_retry = now;

    wifi_state = WIFI_RECONNECTING;
    Serial.println("[WIFI] Tentative reconnexion...");
    WiFi.reconnect();

    unsigned long t = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - t < 10000) {
        delay(500); Serial.print(".");
    }
    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("[WIFI] ✓ Reconnecté !");
        wifi_state = WIFI_OK;
        ledBlink(300);
    }
}

// ================================================
// CONNEXION WIFI + PORTAIL
// ================================================
bool connectWiFi() {
    WiFi.setAutoReconnect(true);
    WiFi.persistent(true);
    WiFi.mode(WIFI_STA);

    WiFiManagerParameter param_id(
        "pid", "Votre identifiant (ex: MARTIN_01)", participant_id, 31);
    WiFiManagerParameter param_acc(
        "acc", "Nom de votre ACC (ex: ACC_DUPONT)", acc_name, 31);

    WiFiManager wm;
    wm.addParameter(&param_id);
    wm.addParameter(&param_acc);
    wm.setConnectTimeout(20);
    wm.setConfigPortalTimeout(600);
    wm.setScanDispPerc(true);
    wm.setBreakAfterConfig(true);
    wm.setCleanConnect(true);

    wm.setCustomHeadElement(
        "<style>"
        "body{font-family:Arial,sans-serif;background:#050d1a !important;color:#e0f0ff !important;margin:0;padding:16px;}"
        "body *{color:#e0f0ff;}"
        "h1{color:#00d4ff !important;text-align:center;}"
        "h3{color:#4a7090 !important;text-align:center;font-size:.85rem;}"
        "input[type=text],input[type=password]{background:#0a1628 !important;color:#e0f0ff !important;border:1px solid #00d4ff !important;border-radius:8px !important;padding:12px !important;width:100% !important;box-sizing:border-box !important;margin:6px 0 !important;}"
        "input[type=submit],button{background:linear-gradient(135deg,#00d4ff,#00ff9d) !important;color:#050d1a !important;border:none !important;padding:14px !important;border-radius:8px !important;font-weight:bold !important;width:100% !important;margin-top:10px !important;}"
        ".wifilist{list-style:none !important;padding:0 !important;}"
        ".wifilist li{background:#0a1628 !important;border:1px solid #0e2a4a !important;border-radius:10px !important;margin:8px 0 !important;overflow:hidden !important;}"
        ".wifilist li a{display:flex !important;justify-content:space-between !important;align-items:center !important;padding:14px 16px !important;color:#e0f0ff !important;text-decoration:none !important;}"
        ".wifilist li a:hover{background:#00d4ff !important;color:#050d1a !important;}"
        ".wifilist li a span{background:#0e2a4a !important;color:#00d4ff !important;padding:4px 10px !important;border-radius:20px !important;font-size:.75rem !important;}"
        "label{color:#4a7090 !important;font-size:.85rem !important;display:block !important;margin-top:10px !important;}"
        "hr{border:none !important;border-top:1px solid #0e2a4a !important;margin:16px 0 !important;}"
        "</style>"
    );

    wm.setTitle("⚡ LoKy ACC v" FIRMWARE_VERSION);

    String apName = "LoKy-" + WiFi.macAddress().substring(12);
    apName.replace(":", "");

    bool ok = wm.autoConnect(apName.c_str(), "");

    if (ok) {
        if (strlen(param_id.getValue()) > 0) {
            strncpy(participant_id, param_id.getValue(), sizeof(participant_id)-1);
            strncpy(acc_name,       param_acc.getValue(), sizeof(acc_name)-1);
            saveConfig();
        }
        Serial.printf("[WIFI] ✓ Connecté ! SSID=%s IP=%s\n",
                      WiFi.SSID().c_str(), WiFi.localIP().toString().c_str());
        wifi_state = WIFI_OK;
        return true;
    }

    return false;
}

// ================================================
// SETUP
// ================================================
void setup() {
    Serial.begin(115200);
    delay(500);
    Serial.println("\n================================");
    Serial.printf("  LoKy ACC v%s — Démarrage\n", FIRMWARE_VERSION);
    Serial.println("================================");

    pinMode(PIN_LED, OUTPUT);
    pinMode(PIN_BTN, INPUT_PULLUP);
    ledOn();

    loadConfig();

    // Connexion WiFi
    bool connected = connectWiFi();

    if (connected) {
        // 1. Récupérer config serveur (URL API + version dispo)
        Serial.println("[SETUP] Récupération config serveur...");
        fetchServerConfig();

        // 2. Vérifier et appliquer MAJ OTA au démarrage
        Serial.println("[SETUP] Vérification mise à jour...");
        checkAndUpdate();
        // Si MAJ disponible → checkAndUpdate() redémarre le module
        // Si on arrive ici → pas de MAJ, on continue normalement
    }

    // Init JSY
    Serial.println("[JSY] Initialisation...");
    jsy.setCallback(jsyCallback);
    jsy.begin(Serial2, PIN_JSY_RX, PIN_JSY_TX, 4800);

    unsigned long t = millis();
    while (!jsy_ready && (millis() - t) < 10000) {
        jsy.read(); delay(100);
    }

    if (jsy_ready) {
        Serial.println("[JSY] ✓ Prêt !");
    } else {
        Serial.println("[JSY] ⚠ Pas de réponse");
    }

    ledOff();
    Serial.println("[SETUP] ✓ Prêt !\n");
    Serial.println("Commandes : R=Reset WiFi | I=Infos | S=Envoyer | U=Check MAJ");
}

// ================================================
// LOOP
// ================================================
void loop() {
    unsigned long now = millis();

    // Lecture JSY
    jsy.read();

    // Gestion WiFi robuste
    handleWiFiReconnect();

    // Bouton IO0
    if (digitalRead(PIN_BTN) == LOW) {
        if (!btn_was_pressed) {
            btn_was_pressed = true;
            btn_press_start = now;
            long_press_done = false;
        }
        if (!long_press_done && (now - btn_press_start) >= LONG_PRESS_MS) {
            long_press_done = true;
            Serial.println("[BTN] Reset WiFi !");
            ledOn(); delay(500);
            WiFiManager wm; wm.resetSettings();
            preferences.begin("loky", false); preferences.clear(); preferences.end();
            delay(300); ESP.restart();
        }
    } else {
        if (btn_was_pressed && !long_press_done) {
            Serial.printf("[BTN] IP:%s ID:%s ACC:%s WiFi:%ddBm FW:v%s\n",
                WiFi.localIP().toString().c_str(),
                participant_id, acc_name, WiFi.RSSI(), FIRMWARE_VERSION);
            ledBlink(200);
        }
        btn_was_pressed = false;
        long_press_done = false;
    }

    // Commandes série
    if (Serial.available()) {
        char c = Serial.read();
        switch (c) {
            case 'R': case 'r':
                WiFiManager wm; wm.resetSettings();
                preferences.begin("loky", false); preferences.clear(); preferences.end();
                ESP.restart();
                break;
            case 'I': case 'i':
                Serial.printf("[INFO] FW:v%s IP:%s ID:%s ACC:%s WiFi:%s(%ddBm) JSY:%s\n",
                    FIRMWARE_VERSION, WiFi.localIP().toString().c_str(),
                    participant_id, acc_name, WiFi.SSID().c_str(), WiFi.RSSI(),
                    jsy_ready ? "OK" : "KO");
                Serial.printf("[INFO] API:%s\n", api_url);
                break;
            case 'S': case 's':
                Serial.println("[CMD] Envoi forcé !");
                sendToServer(); break;
            case 'U': case 'u':
                Serial.println("[CMD] Vérification MAJ forcée !");
                fetchServerConfig();
                checkAndUpdate();
                break;
        }
    }

    // Envoi données périodique
    if (has_pending && (now - last_send_ms) >= SEND_INTERVAL_MS) {
        sendToServer();
    }

    // Vérification OTA toutes les heures
    if (WiFi.status() == WL_CONNECTED &&
        (now - last_ota_check) >= OTA_CHECK_MS) {
        last_ota_check = now;
        Serial.println("[OTA] Vérification horaire...");
        fetchServerConfig();
        checkAndUpdate();
    }

    // Ticker 1s
    if (now - prev_tick >= 1000) {
        prev_tick = now;
        uptime_s++;
        if (uptime_s % 300 == 0) has_pending = true;
        if (uptime_s % 60 == 0) {
            Serial.printf("[STATUS] FW:v%s Uptime:%lus WiFi:%s JSY:%s P:%.1fW\n",
                FIRMWARE_VERSION, uptime_s,
                WiFi.status() == WL_CONNECTED ? "✓" : "✗",
                jsy_ready ? "✓" : "✗", total_power);
        }
    }

    // LED heartbeat
    static unsigned long last_hb = 0;
    if (wifi_state == WIFI_OK && now - last_hb >= 3000) {
        last_hb = now; ledOn(); delay(50); ledOff();
    }

    // LED blink off
    if (led_blink_ms && (now - led_blink_ms) >= (unsigned long)led_blink_dur) {
        ledOff(); led_blink_ms = 0;
    }

    delay(10);
}
