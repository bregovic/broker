<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Nejste přihlášen.']); exit; }

// DB
$pdo = null;
try {
    $paths=[__DIR__.'/../env.local.php',__DIR__.'/env.local.php',__DIR__.'/php/env.local.php','../env.local.php','php/env.local.php','../php/env.local.php'];
    foreach ($paths as $p){ if(file_exists($p)){ require_once $p; break; } }
    if(!defined('DB_HOST')) throw new Exception('DB config nenalezen');
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
} catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Chyba DB: '.$e->getMessage()]); exit; }

$inDate = $_POST['date'] ?? '';
$force = strtolower($_POST['format'] ?? 'xml'); // xml|txt
if(!$inDate){ http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Chybí date (YYYY-MM-DD).']); exit; }
$ts = strtotime($inDate);
if($ts===false){ http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Neplatné datum.']); exit; }
$ymd = date('Y-m-d', $ts);
$dmy = date('d.m.Y', $ts);

function http_get_verbose($url,$looseSSL=false){
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_USERAGENT=>'PortfolioTracker/1.0',
        CURLOPT_ENCODING=>'' // accept gzip/deflate
    ]);
    if($looseSSL){ curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); }
    $body=curl_exec($ch); $err=curl_error($ch);
    $info=curl_getinfo($ch);
    curl_close($ch);
    return [$body,$info,$err];
}
function upsert($pdo,$date,$code,$amount,$rate,$src,&$ins,&$upd){
    $s=$pdo->prepare("SELECT rate_id FROM rates WHERE date=? AND currency=? LIMIT 1");
    $s->execute([$date,$code]); $id=$s->fetchColumn();
    if($id){ $u=$pdo->prepare("UPDATE rates SET rate=?,amount=?,source=?,updated_at=CURRENT_TIMESTAMP WHERE rate_id=?"); $u->execute([$rate,$amount,$src,$id]); $upd++; }
    else { $i=$pdo->prepare("INSERT INTO rates (date,currency,rate,amount,source) VALUES (?,?,?,?,?)"); $i->execute([$date,$code,$rate,$amount,$src]); $ins++; }
}

$ins=0;$upd=0;
$tried=[]; $lastInfo=null; $lastErr='';

// Helper to try multiple URLs in order
function try_urls($urls, $parseFn, &$tried, &$lastInfo, &$lastErr){
    foreach($urls as $u){
        $scheme = parse_url($u, PHP_URL_SCHEME);
        list($body,$info,$err)=http_get_verbose($u, $scheme==='https' ? false : false);
        $tried[]=['url'=>$u,'http_code'=>$info['http_code']??0,'err'=>$err];
        if($body!==false && ($info['http_code']??0) < 400){
            $ok = $parseFn($body, $u);
            if($ok===true) return [true,null];
            // else continue to next url
        }
        $lastInfo=$info; $lastErr=$err;
    }
    return [false, ['info'=>$lastInfo,'err'=>$lastErr,'tried'=>$tried]];
}

// XML first (http and https both, some hostings have TLS issues)
if($force!=='txt'){
    $xmlUrls=[
        'http://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.xml?date='.$dmy,
        'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/denni_kurz.xml?date='.$dmy, // CNB má i pomlčkovou verzi URL
        'https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.xml?date='.$dmy,
    ];
    $okData = function($body,$url) use ($pdo,$ymd,&$ins,&$upd){
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if($xml===false){
            $body2 = @mb_convert_encoding($body,'UTF-8','Windows-1250,ISO-8859-2,UTF-8');
            $xml = simplexml_load_string($body2);
        }
        if($xml===false) return false;
        $rows = $xml->xpath('//radek');
        if(!$rows || count($rows)==0) return false;
        upsert($pdo,$ymd,'CZK',1,1.0,'CNB',$ins,$upd);
        foreach($rows as $row){
            $code = (string)$row['kod'];
            $amount = (int)$row['mnozstvi'];
            $rate = (float)str_replace(',', '.', (string)$row['kurz']);
            if(!$code || $amount<=0 || $rate<=0) continue;
            upsert($pdo,$ymd,$code,$amount,$rate,'CNB',$ins,$upd);
        }
        echo json_encode(['ok'=>true,'message'=>'Import (XML) dokončen','inserted'=>$ins,'updated'=>$upd,'date'=>$ymd,'source'=>'CNB XML','url'=>$url]); return true;
    };
    list($ok,$meta)=try_urls($xmlUrls,$okData,$tried,$lastInfo,$lastErr);
    if($ok) exit;
}

// TXT fallback (try http first as browser URL works over http)
$txtUrls=[
    'http://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.txt?date='.$dmy,
    'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/denni_kurz.txt?date='.$dmy,
    'https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/denni_kurz.txt?date='.$dmy,
];
$okTxt = function($body,$url) use ($pdo,$ymd,&$ins,&$upd){
    $lines=preg_split('/\r\n|\r|\n/',trim($body));
    if(count($lines)<3) return false;
    $rows=array_slice($lines,2);
    upsert($pdo,$ymd,'CZK',1,1.0,'CNB',$ins,$upd);
    foreach($rows as $line){
        if(!trim($line)) continue;
        $parts=explode('|',$line);
        if(count($parts)<5) continue;
        $amount=(int)trim($parts[2]);
        $code=trim($parts[3]);
        $rate=(float)str_replace(',','.',trim($parts[4]));
        if(!$code || $amount<=0 || $rate<=0) continue;
        upsert($pdo,$ymd,$code,$amount,$rate,'CNB',$ins,$upd);
    }
    echo json_encode(['ok'=>true,'message'=>'Import (TXT) dokončen','inserted'=>$ins,'updated'=>$upd,'date'=>$ymd,'source'=>'CNB TXT','url'=>$url]); return true;
};
list($ok,$meta)=try_urls($txtUrls,$okTxt,$tried,$lastInfo,$lastErr);
if($ok) exit;

http_response_code(502);
echo json_encode([
    'ok'=>false,
    'message'=>'Nepodařilo se stáhnout kurzovní lístek z ČNB.',
    'debug'=>[
        'last_http_code'=>$meta['info']['http_code']??0,
        'last_url'=>$meta['info']['url']??null,
        'curl_error'=>$meta['err']??'',
        'tried'=>$tried
    ]
]);
