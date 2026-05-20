<?php
session_start();
$step=intval($_GET['step']??$_SESSION['install_step']??1);
$error='';

function checkRequirements():array{
    $c=[];
    $c[]=['label'=>'PHP '.PHP_VERSION,'ok'=>version_compare(PHP_VERSION,'7.4.0','>='),'note'=>'PHP 7.4+ requis'];
    foreach(['pdo','pdo_mysql','openssl','curl','json'] as $ext){$ok=extension_loaded($ext);$c[]=['label'=>"Extension $ext",'ok'=>$ok,'note'=>"Installer $ext"];}
    $c[]=['label'=>'Dossier écrivable','ok'=>is_writable(__DIR__),'note'=>'chmod 755'];
    $c[]=['label'=>'HTTPS disponible','ok'=>!empty($_SERVER['HTTPS'])||$_SERVER['HTTP_HOST']==='localhost','note'=>'Recommandé'];
    return $c;
}

function generateVAPIDKeys():array{
    $key=openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]);
    $d=openssl_pkey_get_details($key);
    $pub=rtrim(strtr(base64_encode("\x04".str_pad($d['ec']['x'],32,"\x00",STR_PAD_LEFT).str_pad($d['ec']['y'],32,"\x00",STR_PAD_LEFT)),'+/','-_'),'=');
    $priv=rtrim(strtr(base64_encode(str_pad($d['ec']['d'],32,"\x00",STR_PAD_LEFT)),'+/','-_'),'=');
    return['public'=>$pub,'private'=>$priv];
}

function writeConfig(array $db):void{
    $h=$db['host'];$n=$db['name'];$u=$db['user'];$p=$db['pass'];$date=date('Y-m-d H:i:s');
    file_put_contents(__DIR__.'/config.php',"<?php\n// LoKy ACC Config — $date\ndefine('DB_HOST','$h');define('DB_NAME','$n');define('DB_USER','$u');define('DB_PASS','$p');define('DB_CHARSET','utf8mb4');\ndefine('SURPLUS_THRESHOLD',200);define('NOTIFY_COOLDOWN',300);define('DATA_RETENTION',30);\nfunction getDB():PDO{static \$p=null;if(\$p===null){try{\$p=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(PDOException \$e){http_response_code(500);die(json_encode(['error'=>\$e->getMessage()]));}}return \$p;}\nfunction getVAPIDConfig():array{static \$v=null;if(\$v===null){try{\$db=getDB();\$rows=\$db->query(\"SELECT \`cle\`,\`valeur\` FROM parametres WHERE \`cle\` IN('vapid_public_key','vapid_private_key','vapid_subject')\")->fetchAll();\$v=[];foreach(\$rows as \$r)\$v[\$r['cle']]=\$r['valeur'];}catch(Exception \$e){\$v=[];}}return \$v;}\nfunction getVAPIDPublicKey():string{return getVAPIDConfig()['vapid_public_key']??'';}\nfunction getVAPIDPrivateKey():string{return getVAPIDConfig()['vapid_private_key']??'';}\nfunction getVAPIDSubject():string{return getVAPIDConfig()['vapid_subject']??'mailto:admin@loky-acc.fr';}\nfunction jsonResponse(array \$d,int \$c=200):void{http_response_code(\$c);header('Content-Type: application/json');echo json_encode(\$d,JSON_UNESCAPED_UNICODE);exit;}\nfunction sanitize(string \$s):string{return htmlspecialchars(strip_tags(trim(\$s)));}\nfunction base64url_encode(string \$d):string{return rtrim(strtr(base64_encode(\$d),'+/','-_'),'=');}\nfunction base64url_decode(string \$d):string{return base64_decode(strtr(\$d,'-_','+/'));}\nfunction sendPushNotification(array \$sub,array \$payload):bool{\$pub=getVAPIDPublicKey();\$priv=getVAPIDPrivateKey();\$subj=getVAPIDSubject();if(!\$pub||!\$priv)return false;\$ep=\$sub['endpoint'];\$parsed=parse_url(\$ep);\$aud=\$parsed['scheme'].'://'.\$parsed['host'];\$h=base64url_encode(json_encode(['typ'=>'JWT','alg'=>'ES256']));\$pl=base64url_encode(json_encode(['aud'=>\$aud,'exp'=>time()+43200,'sub'=>\$subj]));\$si=\$h.'.'.\$pl;\$raw=base64url_decode(\$priv);\$der=\"\x30\x77\x02\x01\x01\x04\x20\".\$raw.\"\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\";\$pem=\"-----BEGIN EC PRIVATE KEY-----\n\".chunk_split(base64_encode(\$der),64,\"\n\").\"-----END EC PRIVATE KEY-----\n\";\$pk=openssl_pkey_get_private(\$pem);openssl_sign(\$si,\$sig,\$pk,OPENSSL_ALGO_SHA256);\$o=2;if(ord(\$sig[\$o])===0x81)\$o++;\$o++;\$rl=ord(\$sig[\$o++]);\$r=substr(\$sig,\$o,\$rl);\$o+=\$rl;\$o++;\$sl=ord(\$sig[\$o++]);\$s=substr(\$sig,\$o,\$sl);\$r=str_pad(ltrim(\$r,\"\x00\"),32,\"\x00\",STR_PAD_LEFT);\$s=str_pad(ltrim(\$s,\"\x00\"),32,\"\x00\",STR_PAD_LEFT);\$jwt=\$si.'.'.base64url_encode(\$r.\$s);\$ch=curl_init(\$ep);curl_setopt_array(\$ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(\$payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Authorization: vapid t='.\$jwt.', k='.\$pub,'Content-Type: application/octet-stream','TTL: 86400'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);curl_exec(\$ch);\$code=curl_getinfo(\$ch,CURLINFO_HTTP_CODE);curl_close(\$ch);return \$code>=200&&\$code<300;}\n");
}

function installAll(PDO $pdo,string $email,string $pub,string $priv):array{
    $results=[];
    $tables=['acc'=>"CREATE TABLE IF NOT EXISTS `acc` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nom` VARCHAR(50) NOT NULL UNIQUE,`description` VARCHAR(255) DEFAULT NULL,`coordinateur` VARCHAR(100) DEFAULT NULL,`actif` TINYINT(1) DEFAULT 1,`date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,`date_update` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",'utilisateurs'=>"CREATE TABLE IF NOT EXISTS `utilisateurs` (`id` INT AUTO_INCREMENT PRIMARY KEY,`role` ENUM('admin','producteur','consommateur') NOT NULL,`nom` VARCHAR(100) NOT NULL,`numero_compteur` VARCHAR(50) NOT NULL UNIQUE,`acc_id` INT DEFAULT NULL,`actif` TINYINT(1) DEFAULT 0,`push_endpoint` TEXT DEFAULT NULL,`push_p256dh` TEXT DEFAULT NULL,`push_auth` TEXT DEFAULT NULL,`push_actif` TINYINT(1) DEFAULT 0,`date_inscription` DATETIME DEFAULT CURRENT_TIMESTAMP,`derniere_connexion` DATETIME DEFAULT NULL,FOREIGN KEY (`acc_id`) REFERENCES `acc`(`id`) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",'producteurs'=>"CREATE TABLE IF NOT EXISTS `producteurs` (`id` INT AUTO_INCREMENT PRIMARY KEY,`acc_id` INT DEFAULT NULL,`module_id` VARCHAR(50) NOT NULL UNIQUE,`nom` VARCHAR(100) DEFAULT NULL,`ip` VARCHAR(15) DEFAULT NULL,`firmware` VARCHAR(20) DEFAULT NULL,`rssi` INT DEFAULT NULL,`actif` TINYINT(1) DEFAULT 1,`derniere_vue` DATETIME DEFAULT NULL,`date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (`acc_id`) REFERENCES `acc`(`id`) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",'mesures'=>"CREATE TABLE IF NOT EXISTS `mesures` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`producteur_id` INT NOT NULL,`timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,`tension` DECIMAL(6,2) DEFAULT NULL,`courant` DECIMAL(8,3) DEFAULT NULL,`puissance` DECIMAL(8,1) DEFAULT NULL,`surplus` DECIMAL(8,1) DEFAULT 0,`est_surplus` TINYINT(1) DEFAULT 0,`energie_in` DECIMAL(12,3) DEFAULT NULL,`energie_out` DECIMAL(12,3) DEFAULT NULL,`facteur_puiss` DECIMAL(4,2) DEFAULT NULL,`rssi` INT DEFAULT NULL,`uptime` INT DEFAULT NULL,INDEX `idx_prod_time` (`producteur_id`,`timestamp`),INDEX `idx_time` (`timestamp`),FOREIGN KEY (`producteur_id`) REFERENCES `producteurs`(`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",'notifications_log'=>"CREATE TABLE IF NOT EXISTS `notifications_log` (`id` INT AUTO_INCREMENT PRIMARY KEY,`acc_id` INT NOT NULL,`surplus_w` INT DEFAULT 0,`nb_envoyes` INT DEFAULT 0,`timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (`acc_id`) REFERENCES `acc`(`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",'parametres'=>"CREATE TABLE IF NOT EXISTS `parametres` (`cle` VARCHAR(50) PRIMARY KEY,`valeur` TEXT DEFAULT NULL,`date_update` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"];
    foreach($tables as $name=>$sql){try{$pdo->exec($sql);$results[]=['ok'=>true,'msg'=>"Table `$name` créée"];}catch(PDOException $e){$results[]=['ok'=>false,'msg'=>"Table `$name` : ".$e->getMessage()];}}
    try{$stmt=$pdo->prepare("INSERT INTO `parametres`(`cle`,`valeur`) VALUES(?,?) ON DUPLICATE KEY UPDATE `valeur`=VALUES(`valeur`)");foreach([['version','1.0.0'],['surplus_threshold','200'],['notify_cooldown','300'],['data_retention','30'],['site_nom','LoKy ACC'],['install_date',date('Y-m-d H:i:s')],['vapid_public_key',$pub],['vapid_private_key',$priv],['vapid_subject','mailto:'.$email]] as[$k,$v])$stmt->execute([$k,$v]);$results[]=['ok'=>true,'msg'=>'Paramètres + clés VAPID sauvegardés'];}catch(PDOException $e){$results[]=['ok'=>false,'msg'=>'Paramètres : '.$e->getMessage()];}
    try{$pdo->exec("INSERT IGNORE INTO `utilisateurs`(`role`,`nom`,`numero_compteur`,`actif`) VALUES('admin','Administrateur','ADMIN001',1)");$results[]=['ok'=>true,'msg'=>'Compte admin créé (ADMIN001 / Administrateur)'];}catch(PDOException $e){$results[]=['ok'=>false,'msg'=>'Admin : '.$e->getMessage()];}
    try{$pdo->exec("INSERT IGNORE INTO `acc`(`nom`,`description`,`coordinateur`) VALUES('ACC_DEMO','ACC de démonstration','Administrateur')");$results[]=['ok'=>true,'msg'=>'ACC de démonstration créé'];}catch(PDOException $e){$results[]=['ok'=>false,'msg'=>'ACC démo : '.$e->getMessage()];}
    return $results;
}

// Test DB AJAX
if(isset($_GET['action'])&&$_GET['action']==='test_db'&&$_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json');
    try{$pdo=new PDO("mysql:host={$_POST['db_host']};dbname={$_POST['db_name']};charset=utf8mb4",$_POST['db_user'],$_POST['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->query("SELECT 1");echo json_encode(['ok'=>true]);}
    catch(PDOException $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
}

if($step===1&&$_SERVER['REQUEST_METHOD']==='POST'){$_SESSION['install_step']=2;header('Location: install.php?step=2');exit;}

if($step===2&&$_SERVER['REQUEST_METHOD']==='POST'){
    $db=['host'=>trim($_POST['db_host']??'localhost'),'name'=>trim($_POST['db_name']??''),'user'=>trim($_POST['db_user']??''),'pass'=>trim($_POST['db_pass']??'')];
    $email=trim($_POST['site_email']??'admin@loky-acc.fr');
    if(!$db['name']||!$db['user']){$error='Nom de base et utilisateur obligatoires';}
    else{
        try{
            $pdo=new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->query("SELECT 1");
            $vapid=generateVAPIDKeys();
            writeConfig($db);
            $_SESSION['db']=$db;$_SESSION['site_email']=$email;$_SESSION['vapid_public']=$vapid['public'];
            $results=installAll($pdo,$email,$vapid['public'],$vapid['private']);
            $_SESSION['install_results']=$results;$_SESSION['install_step']=4;
            header('Location: install.php?step=4');exit;
        }catch(PDOException $e){$error='Connexion impossible : '.$e->getMessage();}
    }
}

$reqs=$requirements=checkRequirements();
$allOk=array_reduce($reqs,fn($c,$r)=>$c&&$r['ok'],true);
$base_url=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']),'/');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>LoKy ACC — Installation</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#050d1a;--surface:#0a1628;--border:#0e2a4a;--accent:#00d4ff;--green:#00ff9d;--warn:#ffaa00;--danger:#ff3860;--text:#e0f0ff;--muted:#4a7090;--card:#071220;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Outfit',sans-serif;min-height:100vh;padding:24px 16px;}
body::after{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,#1a3050 1px,transparent 1px);background-size:28px 28px;opacity:.12;pointer-events:none;}
.wrap{position:relative;z-index:1;max-width:600px;margin:0 auto;}
.logo{text-align:center;margin-bottom:22px;}
.logo-icon{width:60px;height:60px;background:linear-gradient(135deg,var(--accent),var(--green));border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 10px;box-shadow:0 0 28px rgba(0,212,255,.3);}
.logo h1{font-size:1.7rem;font-weight:800;background:linear-gradient(90deg,var(--accent),var(--green));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.logo p{font-size:.68rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:3px;}
.stepper{display:flex;align-items:center;margin-bottom:20px;padding:14px 16px;background:var(--surface);border:1px solid var(--border);border-radius:12px;}
.si{display:flex;flex-direction:column;align-items:center;flex:1;position:relative;}
.si:not(:last-child)::after{content:'';position:absolute;top:13px;left:calc(50% + 16px);right:calc(-50% + 16px);height:2px;background:var(--border);}
.si.done:not(:last-child)::after,.si.active:not(:last-child)::after{background:var(--green);}
.sn{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;margin-bottom:4px;z-index:1;position:relative;}
.si.done .sn{background:var(--green);color:var(--bg);}
.si.active .sn{background:var(--accent);color:var(--bg);box-shadow:0 0 10px rgba(0,212,255,.4);}
.si.pending .sn{background:var(--border);color:var(--muted);}
.sl{font-size:.55rem;color:var(--muted);font-family:'Space Mono',monospace;text-align:center;text-transform:uppercase;letter-spacing:.5px;}
.si.active .sl{color:var(--accent);}
.si.done .sl{color:var(--green);}
.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:22px;margin-bottom:14px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--green));}
.card h2{color:var(--accent);font-size:.95rem;margin-bottom:4px;}
.card-sub{font-size:.72rem;color:var(--muted);font-family:'Space Mono',monospace;margin-bottom:16px;line-height:1.6;}
.req{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);font-size:.8rem;}
.req:last-child{border-bottom:none;}
.req-note{font-size:.65rem;color:var(--danger);font-family:'Space Mono',monospace;margin-left:auto;}
.fg{margin-bottom:13px;}
.fl{display:block;font-size:.65rem;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;}
.fi{width:100%;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:11px 13px;font-size:.88rem;font-family:'Outfit',sans-serif;outline:none;transition:all .2s;}
.fi:focus{border-color:var(--accent);}
.fi::placeholder{color:var(--muted);}
.fn{font-size:.62rem;color:var(--muted);font-family:'Space Mono',monospace;margin-top:4px;}
.fr{display:grid;grid-template-columns:2fr 1fr;gap:10px;}
.iw{position:relative;}
.tp{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);}
.btn{padding:13px 22px;border-radius:10px;border:none;cursor:pointer;font-family:'Outfit',sans-serif;font-size:.88rem;font-weight:700;transition:all .2s;display:inline-block;text-decoration:none;}
.btn-p{background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);}
.btn-p:hover{box-shadow:0 0 20px rgba(0,212,255,.4);}
.btn-full{width:100%;text-align:center;margin-top:6px;}
.btn-s{background:var(--card);color:var(--accent);border:1px solid rgba(0,212,255,.3);}
.test-btn{background:rgba(0,212,255,.08);color:var(--accent);border:1px solid rgba(0,212,255,.25);padding:9px 14px;border-radius:8px;cursor:pointer;font-family:'Space Mono',monospace;font-size:.65rem;transition:all .2s;width:100%;margin-top:8px;}
.test-btn:hover{background:var(--accent);color:var(--bg);}
.msg-err{background:rgba(255,56,96,.1);border:1px solid rgba(255,56,96,.3);color:var(--danger);padding:11px 14px;border-radius:8px;margin-bottom:14px;font-size:.82rem;}
.warn-box{background:rgba(255,56,96,.06);border:1px solid rgba(255,56,96,.22);border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:.76rem;color:var(--danger);line-height:1.6;}
.vapid-note{background:rgba(0,255,157,.06);border:1px solid rgba(0,255,157,.2);border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:.76rem;color:var(--green);line-height:1.6;}
.ri{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:.8rem;}
.ri:last-child{border-bottom:none;}
.ibox{background:rgba(0,212,255,.04);border:1px solid rgba(0,212,255,.18);border-radius:10px;padding:13px;margin-top:10px;}
.ibox h4{color:var(--accent);font-size:.82rem;margin-bottom:8px;}
.ir{display:flex;gap:10px;padding:5px 0;border-bottom:1px solid var(--border);font-size:.76rem;}
.ir:last-child{border-bottom:none;}
.ik{color:var(--muted);font-family:'Space Mono',monospace;min-width:120px;font-size:.68rem;}
.iv{color:var(--text);font-weight:600;}
.iv.hi{color:var(--green);font-family:'Space Mono',monospace;}
.iv a{color:var(--accent);}
.vbox{background:rgba(0,255,157,.05);border:1px solid rgba(0,255,157,.2);border-radius:10px;padding:13px;margin-top:10px;}
.vbox h4{color:var(--green);font-size:.82rem;margin-bottom:8px;}
.vkey{font-family:'Space Mono',monospace;font-size:.6rem;color:var(--green);word-break:break-all;line-height:1.6;background:var(--card);padding:8px;border-radius:6px;margin-top:4px;}
.fstep{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);font-size:.78rem;}
.fstep:last-child{border-bottom:none;}
.fsn{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--green));color:var(--bg);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.7rem;flex-shrink:0;}
.spinner-overlay{display:none;position:fixed;inset:0;background:rgba(5,13,26,.88);z-index:100;align-items:center;justify-content:center;flex-direction:column;gap:14px;}
.spinner-overlay.show{display:flex;}
.spinner{width:44px;height:44px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.sp-txt{color:var(--accent);font-family:'Space Mono',monospace;font-size:.78rem;}
@media(max-width:480px){.fr{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="spinner-overlay" id="sp"><div class="spinner"></div><div class="sp-txt">Installation en cours...</div></div>
<div class="wrap">
<div class="logo"><div class="logo-icon">⚡</div><h1>LoKy ACC</h1><p>Assistant d'installation</p></div>
<div class="stepper">
<?php $steps=['Vérification','Base de données','Installation','Terminé'];foreach($steps as $i=>$label){$n=$i+1;$cls=$n<$step?'done':($n===$step?'active':'pending');echo"<div class='si $cls'><div class='sn'>".($n<$step?'✓':$n)."</div><div class='sl'>$label</div></div>";}?>
</div>

<?php if($error):?><div class="msg-err">❌ <?=htmlspecialchars($error)?></div><?php endif;?>

<?php if($step===1):?>
<div class="card">
<h2>🔍 Vérification du serveur</h2>
<div class="card-sub">Contrôle des prérequis avant l'installation.</div>
<?php foreach($reqs as $r):?>
<div class="req"><span><?=$r['ok']?'✅':'❌'?></span><span><?=htmlspecialchars($r['label'])?></span><?php if(!$r['ok']):?><span class="req-note"><?=htmlspecialchars($r['note'])?></span><?php endif;?></div>
<?php endforeach;?>
<form method="POST" style="margin-top:14px;">
<?php if(!$allOk):?><div class="warn-box">⚠️ Des prérequis manquent. L'installation peut échouer.</div><?php endif;?>
<button type="submit" class="btn btn-p btn-full">→ Continuer</button>
</form>
</div>

<?php elseif($step===2):?>
<div class="card">
<h2>🗄️ Configuration</h2>
<div class="card-sub">Informations disponibles dans votre panneau d'hébergement (cPanel, Plesk...)</div>

<div class="vapid-note">🔐 Les clés VAPID seront <strong>générées et sauvegardées automatiquement</strong> en base de données — aucune configuration manuelle !</div>

<form method="POST" id="dbf" onsubmit="document.getElementById('sp').classList.add('show')">
<div class="fr">
<div class="fg"><label class="fl">Hôte MySQL</label><input type="text" name="db_host" class="fi" value="<?=htmlspecialchars($_POST['db_host']??'localhost')?>" placeholder="localhost"></div>
<div class="fg"><label class="fl">Port</label><input type="text" name="db_port" class="fi" value="3306"></div>
</div>
<div class="fg"><label class="fl">Nom de la base <span style="color:var(--danger)">*</span></label><input type="text" name="db_name" class="fi" required value="<?=htmlspecialchars($_POST['db_name']??'')?>" placeholder="loky_acc"></div>
<div class="fg"><label class="fl">Utilisateur MySQL <span style="color:var(--danger)">*</span></label><input type="text" name="db_user" class="fi" required value="<?=htmlspecialchars($_POST['db_user']??'')?>" placeholder="loky_acc"></div>
<div class="fg"><label class="fl">Mot de passe MySQL</label><div class="iw"><input type="password" name="db_pass" class="fi" id="dbp" value="<?=htmlspecialchars($_POST['db_pass']??'')?>"><button type="button" class="tp" onclick="document.getElementById('dbp').type=document.getElementById('dbp').type==='password'?'text':'password'">👁️</button></div></div>
<button type="button" class="test-btn" onclick="testDB()">🔌 Tester la connexion</button>
<div id="tr" style="margin-top:7px;font-size:.7rem;font-family:'Space Mono',monospace;"></div>
<hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
<div class="fg"><label class="fl">Email administrateur</label><input type="email" name="site_email" class="fi" value="<?=htmlspecialchars($_POST['site_email']??'')?>" placeholder="admin@mon-site.fr"><div class="fn">Utilisé pour les clés VAPID Push</div></div>
<button type="submit" class="btn btn-p btn-full">⚡ Installer LoKy ACC</button>
</form>
</div>

<?php elseif($step===4):
$results=$_SESSION['install_results']??[];
$hasErr=!empty(array_filter($results,fn($r)=>!$r['ok']));
$vpub=$_SESSION['vapid_public']??'';
?>
<div class="card">
<h2><?=$hasErr?'⚠️ Installation avec erreurs':'🎉 Installation réussie !'?></h2>
<?php foreach($results as $r):?><div class="ri"><span><?=$r['ok']?'✅':'❌'?></span><span><?=htmlspecialchars($r['msg'])?></span></div><?php endforeach;?>

<?php if(!$hasErr):?>
<div class="vbox">
<h4>🔐 Clés VAPID générées et sauvegardées ✅</h4>
<div style="font-size:.7rem;color:var(--muted);margin-bottom:6px;">Stockées en base — aucune config manuelle requise !</div>
<?php if($vpub):?><div class="fn" style="color:var(--muted);">Clé publique :</div><div class="vkey" id="vpk"><?=htmlspecialchars($vpub)?></div><button onclick="copyText('vpk')" style="margin-top:5px;padding:4px 10px;border-radius:5px;border:1px solid rgba(0,255,157,.3);background:rgba(0,255,157,.05);color:var(--green);font-family:'Space Mono',monospace;font-size:.6rem;cursor:pointer;">📋 Copier</button><?php endif;?>
</div>

<div class="ibox">
<h4>🔑 Connexion administrateur</h4>
<div class="ir"><span class="ik">URL</span><span class="iv"><a href="login.php"><?=$base_url?>/login.php</a></span></div>
<div class="ir"><span class="ik">Numéro compteur</span><span class="iv hi">ADMIN001</span></div>
<div class="ir"><span class="ik">Nom complet</span><span class="iv hi">Administrateur</span></div>
</div>

<div class="ibox">
<h4>📡 URL API firmware ESP32</h4>
<div class="ir"><span class="ik">SERVER_URL</span><span class="iv hi"><?=$base_url?>/api.php</span></div>
</div>

<div style="margin-top:14px;">
<div style="font-size:.62rem;color:var(--muted);font-family:'Space Mono',monospace;text-transform:uppercase;letter-spacing:2px;margin-bottom:8px;">Prochaines étapes</div>
<div class="fstep"><div class="fsn">1</div><div>⚠️ <strong style="color:var(--danger)">Supprimer install.php</strong> du serveur</div></div>
<div class="fstep"><div class="fsn">2</div><div>Se connecter en administrateur</div></div>
<div class="fstep"><div class="fsn">3</div><div>Créer les groupes <strong style="color:var(--accent)">ACC</strong></div></div>
<div class="fstep"><div class="fsn">4</div><div>Flasher le firmware ESP32 avec l'URL API</div></div>
<div class="fstep"><div class="fsn">5</div><div>Inviter les participants à <a href="register.php" style="color:var(--accent)">s'inscrire</a></div></div>
</div>
<div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
<a href="login.php" class="btn btn-p">→ Se connecter</a>
<a href="home.php" class="btn btn-s">🏠 Accueil</a>
</div>
<?php else:?>
<div class="warn-box" style="margin-top:12px;">⚠️ Des erreurs sont survenues. Vérifiez la configuration et relancez.</div>
<a href="install.php?step=2" class="btn btn-s" style="display:block;text-align:center;margin-top:10px;">← Retour</a>
<?php endif;?>
</div>
<?php endif;?>
</div>

<script>
async function testDB(){
  const tr=document.getElementById('tr');
  tr.style.color='var(--muted)';tr.textContent='⏳ Test...';
  const fd=new FormData();
  ['db_host','db_name','db_user','db_pass'].forEach(k=>{const el=document.querySelector('[name='+k+']');if(el)fd.append(k,el.value);});
  try{
    const r=await fetch('install.php?action=test_db',{method:'POST',body:fd});
    const d=await r.json();
    tr.style.color=d.ok?'var(--green)':'var(--danger)';
    tr.textContent=d.ok?'✅ Connexion réussie !':'❌ '+d.error;
  }catch(e){tr.style.color='var(--danger)';tr.textContent='❌ '+e.message;}
}
function copyText(id){
  navigator.clipboard.writeText(document.getElementById(id).textContent.trim()).then(()=>{
    const t=document.createElement('div');t.textContent='✅ Copié !';
    t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#00ff9d;color:#050d1a;padding:10px 22px;border-radius:10px;font-weight:700;z-index:9999;font-size:.85rem;';
    document.body.appendChild(t);setTimeout(()=>t.remove(),2000);
  });
}
</script>
</body></html>
