// **********************************************************************************
// LoKy ACC v4.0.0 — Firmware Final avec OTA GitHub
// ESP32 WeMos D1 Mini + JSY-MK-163T
// Config et firmware hébergés sur GitHub Pages
// **********************************************************************************

#include <WiFi.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <MycilaJSY.h>

// =================== VERSION ===================
#define FIRMWARE_VERSION "4.0.0"

// =================== URL FIXE GITHUB (ne change JAMAIS) ===================
#define CONFIG_URL "https://frederic76430.github.io/loky-config/config.json"

// =================== VALEURS PAR DÉFAUT ===================
#define DEFAULT_API_URL "https://bidules3d.com/fred/loky/api.php"

// =================== PARAMÈTRES ===================
#define SEND_INTERVAL_MS  30000
#define OTA_CHECK_MS      3600000
#define SURPLUS_THRESHOLD 200
#define LONG_PRESS_MS     5000
#define WIFI_RETRY_MS     30000
#define WIFI_CHECK_MS     15000

// =================== PINS ===================
#define PIN_JSY_RX  16
#define PIN_JSY_TX  17
#define PIN_LED     2
#define PIN_BTN     0

Mycila::JSY  jsy;
Preferences  preferences;
HTTPClient   http;

char api_url[200]       = DEFAULT_API_URL;
char firmware_url[300]  = "";
char server_version[20] = "0.0.0";
char participant_id[32] = "";
char acc_name[32]       = "";

float  ch1_voltage=0,ch1_current=0,ch1_power=0;
float  ch1_energy_in=0,ch1_energy_out=0,ch1_pf=0,total_power=0;
bool   jsy_ready=false,has_pending=false;

unsigned long last_send_ms=0,last_ota_check=0,uptime_s=0;
unsigned long prev_tick=0,last_wifi_check=0,last_wifi_retry=0;
unsigned long btn_press_start=0,led_blink_ms=0;
bool btn_was_pressed=false,long_press_done=false;
int  led_blink_dur=0;

enum WifiState{WIFI_OK,WIFI_LOST,WIFI_RECONNECTING};
WifiState wifi_state=WIFI_OK;

void ledOn(){digitalWrite(PIN_LED,HIGH);}
void ledOff(){digitalWrite(PIN_LED,LOW);}
void ledBlink(int d){ledOn();led_blink_ms=millis();led_blink_dur=d;}
void ledPattern(int n,int on,int off){for(int i=0;i<n;i++){ledOn();delay(on);ledOff();delay(off);}}

void saveConfig(){
    preferences.begin("loky",false);
    preferences.putString("pid",participant_id);
    preferences.putString("acc",acc_name);
    preferences.putString("api",api_url);
    preferences.putString("fwu",firmware_url);
    preferences.putString("fwv",server_version);
    preferences.end();
}

void loadConfig(){
    preferences.begin("loky",true);
    preferences.getString("pid","").toCharArray(participant_id,sizeof(participant_id));
    preferences.getString("acc","").toCharArray(acc_name,sizeof(acc_name));
    preferences.getString("api",DEFAULT_API_URL).toCharArray(api_url,sizeof(api_url));
    preferences.getString("fwu","").toCharArray(firmware_url,sizeof(firmware_url));
    preferences.getString("fwv","0.0.0").toCharArray(server_version,sizeof(server_version));
    preferences.end();
    Serial.printf("[CFG] v%s ID=%s ACC=%s\n",FIRMWARE_VERSION,participant_id,acc_name);
    Serial.printf("[CFG] API=%s\n",api_url);
}

bool fetchGitHubConfig(){
    if(WiFi.status()!=WL_CONNECTED) return false;
    Serial.println("[GH] Récupération config...");
    HTTPClient hc;
    hc.begin(CONFIG_URL);
    hc.setTimeout(15000);
    hc.addHeader("User-Agent","LoKy-ACC/" FIRMWARE_VERSION);
    hc.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    int code=hc.GET();
    Serial.printf("[GH] HTTP %d\n",code);
    if(code==200){
        String body=hc.getString();
        DynamicJsonDocument doc(1024);
        if(deserializeJson(doc,body)==DeserializationError::Ok){
            bool changed=false;
            if(doc.containsKey("api_url")){
                String na=doc["api_url"].as<String>();
                if(na.length()>8&&na!=String(api_url)){
                    Serial.printf("[GH] Nouvelle API: %s\n",na.c_str());
                    na.toCharArray(api_url,sizeof(api_url));
                    changed=true;
                }
            }
            if(doc.containsKey("firmware_version"))
                doc["firmware_version"].as<String>().toCharArray(server_version,sizeof(server_version));
            if(doc.containsKey("firmware_url"))
                doc["firmware_url"].as<String>().toCharArray(firmware_url,sizeof(firmware_url));
            if(changed) saveConfig();
            hc.end();
            Serial.printf("[GH] Serveur:v%s Local:v%s\n",server_version,FIRMWARE_VERSION);
            return true;
        }
    }
    hc.end();
    return false;
}

bool isNewerVersion(const char* s,const char* l){
    int sM=0,sm=0,sp=0,lM=0,lm=0,lp=0;
    sscanf(s,"%d.%d.%d",&sM,&sm,&sp);
    sscanf(l,"%d.%d.%d",&lM,&lm,&lp);
    if(sM!=lM)return sM>lM;
    if(sm!=lm)return sm>lm;
    return sp>lp;
}

void checkAndUpdate(){
    if(WiFi.status()!=WL_CONNECTED) return;
    if(!isNewerVersion(server_version,FIRMWARE_VERSION)){
        Serial.printf("[OTA] A jour (v%s)\n",FIRMWARE_VERSION);
        return;
    }
    if(strlen(firmware_url)<10){Serial.println("[OTA] URL manquante");return;}
    Serial.printf("[OTA] Nouvelle v%s ! URL: %s\n",server_version,firmware_url);
    ledPattern(3,100,100);
    httpUpdate.onStart([](){Serial.println("[OTA] Debut...");ledOn();});
    httpUpdate.onEnd([](){Serial.println("[OTA] Termine!");ledPattern(5,50,50);});
    httpUpdate.onProgress([](int c,int t){
        static int last=-1;int p=(c*100)/t;
        if(p!=last&&p%10==0){Serial.printf("[OTA] %d%%\n",p);last=p;}
    });
    httpUpdate.onError([](int e){
        Serial.printf("[OTA] Erreur: %s\n",httpUpdate.getLastErrorString().c_str());
        ledPattern(3,500,200);
    });
    HTTPClient ho;
    ho.begin(firmware_url);
    ho.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    httpUpdate.update(ho);
    ho.end();
}

void jsyCallback(Mycila::JSY::EventType et,const Mycila::JSY::Data& d){
    if(et!=Mycila::JSY::EventType::EVT_READ) return;
    ch1_voltage=d.single().voltage;ch1_current=d.single().current;
    ch1_power=d.single().activePower;ch1_energy_in=d.single().activeEnergyImported;
    ch1_energy_out=d.single().activeEnergyReturned;ch1_pf=d.single().powerFactor;
    total_power=ch1_power;jsy_ready=has_pending=true;
    if(total_power<-SURPLUS_THRESHOLD)
        Serial.printf("[JSY] SURPLUS [%s]: %.0fW\n",acc_name,-total_power);
}

void sendToServer(){
    if(WiFi.status()!=WL_CONNECTED||strlen(api_url)<8) return;
    DynamicJsonDocument doc(1024);
    doc["id"]=participant_id;doc["acc"]=acc_name;
    doc["uptime"]=uptime_s;doc["rssi"]=WiFi.RSSI();
    doc["ip"]=WiFi.localIP().toString();doc["fw"]=FIRMWARE_VERSION;
    JsonObject c1=doc.createNestedObject("ch1");
    c1["voltage"]=round(ch1_voltage*10)/10.0;c1["current"]=round(ch1_current*1000)/1000.0;
    c1["power"]=round(ch1_power*10)/10.0;c1["energy_in"]=round(ch1_energy_in*1000)/1000.0;
    c1["energy_out"]=round(ch1_energy_out*1000)/1000.0;c1["pf"]=round(ch1_pf*100)/100.0;
    doc["total_power"]=round(total_power*10)/10.0;
    doc["surplus"]=total_power<0?round(-total_power*10)/10.0:0;
    doc["is_surplus"]=total_power<-SURPLUS_THRESHOLD;
    String json; serializeJson(doc,json);
    http.begin(api_url);
    http.addHeader("Content-Type","application/json");
    http.addHeader("X-LoKy-ID",participant_id);
    http.addHeader("X-LoKy-ACC",acc_name);
    http.addHeader("X-LoKy-Version",FIRMWARE_VERSION);
    http.setTimeout(8000);
    int code=http.POST(json);
    if(code>0){Serial.printf("[HTTP] %d\n",code);if(code==200)ledBlink(100);}
    else Serial.printf("[HTTP] Err: %s\n",http.errorToString(code).c_str());
    http.end();last_send_ms=millis();has_pending=false;
}

void handleWiFiReconnect(){
    unsigned long now=millis();
    if(now-last_wifi_check<WIFI_CHECK_MS) return;
    last_wifi_check=now;
    if(WiFi.status()==WL_CONNECTED){
        if(wifi_state!=WIFI_OK){
            Serial.printf("[WIFI] Reconnecte! IP=%s\n",WiFi.localIP().toString().c_str());
            wifi_state=WIFI_OK;fetchGitHubConfig();
        }
        return;
    }
    if(wifi_state==WIFI_OK){Serial.println("[WIFI] Perdu!");wifi_state=WIFI_LOST;}
    if(now-last_wifi_retry<WIFI_RETRY_MS) return;
    last_wifi_retry=now;wifi_state=WIFI_RECONNECTING;
    WiFi.reconnect();
    unsigned long t=millis();
    while(WiFi.status()!=WL_CONNECTED&&millis()-t<10000){delay(500);Serial.print(".");}
    Serial.println();
    if(WiFi.status()==WL_CONNECTED){wifi_state=WIFI_OK;ledBlink(300);}
}

bool connectWiFi(){
    WiFi.setAutoReconnect(true);WiFi.persistent(true);WiFi.mode(WIFI_STA);
    WiFiManagerParameter p1("pid","Identifiant (ex: MARTIN_01)",participant_id,31);
    WiFiManagerParameter p2("acc","Nom ACC (ex: ACC_DUPONT)",acc_name,31);
    WiFiManager wm;
    wm.addParameter(&p1);wm.addParameter(&p2);
    wm.setConnectTimeout(20);wm.setConfigPortalTimeout(600);
    wm.setScanDispPerc(true);wm.setBreakAfterConfig(true);wm.setCleanConnect(true);
    wm.setCustomHeadElement("<style>body{font-family:Arial,sans-serif;background:#050d1a !important;color:#e0f0ff !important;margin:0;padding:16px;}body *{color:#e0f0ff;}h1{color:#00d4ff !important;text-align:center;}input[type=text],input[type=password]{background:#0a1628 !important;color:#e0f0ff !important;border:1px solid #00d4ff !important;border-radius:8px !important;padding:12px !important;width:100% !important;box-sizing:border-box !important;margin:6px 0 !important;}input[type=submit],button{background:linear-gradient(135deg,#00d4ff,#00ff9d) !important;color:#050d1a !important;border:none !important;padding:14px !important;border-radius:8px !important;font-weight:bold !important;width:100% !important;margin-top:10px !important;}.wifilist{list-style:none !important;padding:0 !important;}.wifilist li{background:#0a1628 !important;border:1px solid #0e2a4a !important;border-radius:10px !important;margin:8px 0 !important;overflow:hidden !important;}.wifilist li a{display:flex !important;justify-content:space-between !important;align-items:center !important;padding:14px 16px !important;color:#e0f0ff !important;text-decoration:none !important;}.wifilist li a:hover{background:#00d4ff !important;color:#050d1a !important;}.wifilist li a span{background:#0e2a4a !important;color:#00d4ff !important;padding:4px 10px !important;border-radius:20px !important;font-size:.75rem !important;}label{color:#4a7090 !important;font-size:.85rem !important;display:block !important;margin-top:10px !important;}</style>");
    wm.setTitle("LoKy ACC v" FIRMWARE_VERSION);
    String ap="LoKy-"+WiFi.macAddress().substring(12);ap.replace(":","");
    bool ok=wm.autoConnect(ap.c_str(),"");
    if(ok){
        if(strlen(p1.getValue())>0){
            strncpy(participant_id,p1.getValue(),sizeof(participant_id)-1);
            strncpy(acc_name,p2.getValue(),sizeof(acc_name)-1);
            saveConfig();
        }
        Serial.printf("[WIFI] Connecte! %s IP=%s\n",WiFi.SSID().c_str(),WiFi.localIP().toString().c_str());
        wifi_state=WIFI_OK;return true;
    }
    return false;
}

void setup(){
    Serial.begin(115200);delay(500);
    Serial.printf("\n=== LoKy ACC v%s ===\n",FIRMWARE_VERSION);
    Serial.printf("Config URL: %s\n",CONFIG_URL);
    pinMode(PIN_LED,OUTPUT);pinMode(PIN_BTN,INPUT_PULLUP);ledOn();
    loadConfig();
    if(connectWiFi()){
        fetchGitHubConfig();
        checkAndUpdate();
    }
    jsy.setCallback(jsyCallback);
    jsy.begin(Serial2,PIN_JSY_RX,PIN_JSY_TX,4800);
    unsigned long t=millis();
    while(!jsy_ready&&(millis()-t)<10000){jsy.read();delay(100);}
    Serial.println(jsy_ready?"[JSY] OK !":"[JSY] Pas de reponse");
    ledOff();
    Serial.println("[SETUP] Pret!\nCommandes: R=Reset I=Infos S=Envoyer U=MAJ");
}

void loop(){
    unsigned long now=millis();
    jsy.read();
    handleWiFiReconnect();
    // Bouton
    if(digitalRead(PIN_BTN)==LOW){
        if(!btn_was_pressed){btn_was_pressed=true;btn_press_start=now;long_press_done=false;}
        if(!long_press_done&&(now-btn_press_start)>=LONG_PRESS_MS){
            long_press_done=true;ledOn();delay(500);
            WiFiManager wm;wm.resetSettings();
            preferences.begin("loky",false);preferences.clear();preferences.end();
            delay(300);ESP.restart();
        }
    } else {
        if(btn_was_pressed&&!long_press_done){
            Serial.printf("[BTN] IP:%s ID:%s ACC:%s v%s\n",
                WiFi.localIP().toString().c_str(),participant_id,acc_name,FIRMWARE_VERSION);
            ledBlink(200);
        }
        btn_was_pressed=false;long_press_done=false;
    }
    // Série
    if(Serial.available()){
        char c=Serial.read();
        if(c=='R'||c=='r'){WiFiManager wm;wm.resetSettings();preferences.begin("loky",false);preferences.clear();preferences.end();ESP.restart();}
        else if(c=='I'||c=='i')Serial.printf("[INFO] v%s IP:%s ID:%s ACC:%s\n[INFO] API:%s\n[INFO] FW_URL:%s\n",FIRMWARE_VERSION,WiFi.localIP().toString().c_str(),participant_id,acc_name,api_url,firmware_url);
        else if(c=='S'||c=='s'){sendToServer();}
        else if(c=='U'||c=='u'){fetchGitHubConfig();checkAndUpdate();}
    }
    if(has_pending&&(now-last_send_ms)>=SEND_INTERVAL_MS) sendToServer();
    if(WiFi.status()==WL_CONNECTED&&(now-last_ota_check)>=OTA_CHECK_MS){
        last_ota_check=now;fetchGitHubConfig();checkAndUpdate();
    }
    if(now-prev_tick>=1000){
        prev_tick=now;uptime_s++;
        if(uptime_s%300==0)has_pending=true;
        if(uptime_s%60==0)Serial.printf("[STATUS] v%s Up:%lus WiFi:%s JSY:%s P:%.1fW\n",
            FIRMWARE_VERSION,uptime_s,WiFi.status()==WL_CONNECTED?"OK":"KO",jsy_ready?"OK":"KO",total_power);
    }
    static unsigned long last_hb=0;
    if(wifi_state==WIFI_OK&&now-last_hb>=3000){last_hb=now;ledOn();delay(50);ledOff();}
    if(led_blink_ms&&(now-led_blink_ms)>=(unsigned long)led_blink_dur){ledOff();led_blink_ms=0;}
    delay(10);
}
