<?php
/* Shared LootZone API helpers. Firebase ID tokens are verified server-side via Identity Toolkit. */
function lz_json($data, $code=200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function lz_fail($error, $status, $log=null) {
    if ($log !== null) error_log('LootZone: ' . $log);
    lz_json(['ok'=>false,'error'=>$error], $status);
}
function lz_len($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function lz_db() {
    $url=getenv('DATABASE_URL'); if(!$url) throw new RuntimeException('DATABASE_URL missing');
    $p=parse_url($url); if(!$p||empty($p['host'])||empty($p['path'])) throw new RuntimeException('Invalid DATABASE_URL');
    $dsn='pgsql:host='.$p['host'].';port='.($p['port']??5432).';dbname='.ltrim($p['path'],'/').';sslmode=require';
    return new PDO($dsn,rawurldecode($p['user']??''),rawurldecode($p['pass']??''),[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function lz_bearer() {
    $h=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(preg_match('/^Bearer\s+(.+)$/i',$h,$m)) return trim($m[1]);
    $b=json_decode(file_get_contents('php://input'),true); return is_array($b)?trim((string)($b['id_token']??'')):'';
}
/* Returns user array, or null when the token is invalid/expired.
   Throws RuntimeException when the check itself cannot run (missing config, network, Google outage). */
function lz_verify_firebase_token($token) {
    if(!$token) return null;
    $key=getenv('FIREBASE_WEB_API_KEY');
    if(!$key) throw new RuntimeException('FIREBASE_WEB_API_KEY is not configured');
    if(!function_exists('curl_init')) throw new RuntimeException('cURL extension is missing');
    $ch=curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='.rawurlencode($key));
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['idToken'=>$token]),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15]);
    $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($raw===false) throw new RuntimeException('Identity Toolkit unreachable: '.$err);
    if($code>=500||$code===0) throw new RuntimeException('Identity Toolkit HTTP '.$code);
    // A rejected API key is a server configuration problem, not a logged-out user.
    if(($code===400||$code===403)&&stripos($raw,'API key')!==false) throw new RuntimeException('FIREBASE_WEB_API_KEY rejected by Google');
    $d=json_decode($raw,true);
    if($code<200||$code>=300||empty($d['users'][0]['localId'])) return null;
    $u=$d['users'][0];
    return ['uid'=>$u['localId'],'email'=>$u['email']??null,'name'=>$u['displayName']??null,'verified'=>!empty($u['emailVerified'])];
}
function lz_current_user() {
    try {
        $u=lz_verify_firebase_token(lz_bearer());
    } catch(Throwable $e) {
        lz_fail('auth_service_unavailable',503,'token check: '.$e->getMessage());
    }
    if(!$u) lz_json(['ok'=>false,'error'=>'unauthorized'],401);
    if(empty($u['verified'])) lz_json(['ok'=>false,'error'=>'email_not_verified'],403);
    try {
        $pdo=lz_db();
        $q=$pdo->prepare('INSERT INTO app_users(firebase_uid,email,display_name) VALUES(:u,:e,:n) ON CONFLICT(firebase_uid) DO UPDATE SET email=EXCLUDED.email,display_name=EXCLUDED.display_name,updated_at=NOW()');
        $q->execute([':u'=>$u['uid'],':e'=>$u['email'],':n'=>$u['name']]);
    } catch(Throwable $e) {
        lz_fail('database_unavailable',503,'app_users upsert: '.$e->getMessage());
    }
    return $u;
}
function lz_fcm_access_token() {
    $raw=getenv('FIREBASE_SERVICE_ACCOUNT_JSON'); $sa=$raw?json_decode($raw,true):null;
    if(!is_array($sa)||empty($sa['client_email'])||empty($sa['private_key'])||empty($sa['project_id'])) return null;
    $now=time(); $b64=function($v){return rtrim(strtr(base64_encode($v),'+/','-_'),'=');};
    $unsigned=$b64(json_encode(['alg'=>'RS256','typ'=>'JWT'])).'.'.$b64(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
    $sig=''; if(!openssl_sign($unsigned,$sig,$sa['private_key'],OPENSSL_ALGO_SHA256)) return null;
    $ch=curl_init('https://oauth2.googleapis.com/token'); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$unsigned.'.'.$b64($sig)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15]);
    $r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=is_string($r)?json_decode($r,true):null;
    return $c>=200&&$c<300?($d['access_token']??null):null;
}
function lz_push_token($token,$title,$body,$data=[]) {
    if(!$token) return false; $raw=getenv('FIREBASE_SERVICE_ACCOUNT_JSON');$sa=$raw?json_decode($raw,true):null;$access=lz_fcm_access_token();
    if(!$access||empty($sa['project_id'])) return false;
    $payload=['message'=>['token'=>$token,'notification'=>['title'=>$title,'body'=>$body],'data'=>array_map('strval',$data+['title'=>$title,'body'=>$body]),'android'=>['priority'=>'high','notification'=>['channel_id'=>'lootzone_orders']]]];
    $ch=curl_init('https://fcm.googleapis.com/v1/projects/'.rawurlencode($sa['project_id']).'/messages:send');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$access,'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15]);
    $r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return $c>=200&&$c<300;
}
function lz_notify_order(PDO $pdo,$orderId,$uid,$code,$status) {
    $map=[
      'processing'=>['تم استلام طلبك','تم استلام طلبك بنجاح، وهو الآن في انتظار المراجعة.'],
      'accepted'=>['تم قبول طلبك','تم قبول طلبك بنجاح.'],
      'in_progress'=>['طلبك قيد التنفيذ','طلبك الآن قيد التنفيذ.'],
      'completed'=>['اكتمل طلبك','تهانينا! تم إكمال طلبك بنجاح.'],
      'cancelled'=>['تم إلغاء طلبك','نأسف، تم إلغاء طلبك.']
    ];
    if(!isset($map[$status])) return;
    [$title,$body]=$map[$status];
    if($uid !== null && $uid !== '') {
        $q=$pdo->prepare("INSERT INTO notifications(user_uid,order_id,order_code,title,body,status_key) VALUES(:u,:o,:c,:t,:b,:s) ON CONFLICT DO NOTHING RETURNING id");
        $q->execute([':u'=>$uid,':o'=>$orderId,':c'=>$code,':t'=>$title,':b'=>$body,':s'=>$status]);$n=$q->fetchColumn();
        if(!$n) return; // dedupe: one notification per order/status transition
        $d=$pdo->prepare('SELECT fcm_token FROM user_devices WHERE user_uid=:u AND active=TRUE UNION SELECT fcm_token FROM order_push_tokens WHERE order_id=:o');
        $d->execute([':u'=>$uid,':o'=>$orderId]);
    } else {
        // Preserve push delivery for legacy V23 orders that predate account linking.
        $d=$pdo->prepare('SELECT fcm_token FROM order_push_tokens WHERE order_id=:o');$d->execute([':o'=>$orderId]);
    }
    foreach($d->fetchAll(PDO::FETCH_COLUMN) as $token) lz_push_token($token,$title,$body,['order_code'=>$code,'status'=>$status]);
}
