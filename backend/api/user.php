<?php
require_once __DIR__.'/common.php';
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Headers: Content-Type, Authorization');header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
$user=lz_current_user(); // returns JSON errors itself (401/403/503)
$method=$_SERVER['REQUEST_METHOD'];

function lz_q_orders(PDO $pdo,$uid){$q=$pdo->prepare('SELECT order_code,service,amount,total_due,currency,status,created_at,updated_at FROM orders WHERE user_uid=:u ORDER BY created_at DESC LIMIT 300');$q->execute([':u'=>$uid]);return $q->fetchAll();}
function lz_q_favorites(PDO $pdo,$uid){$q=$pdo->prepare('SELECT item_id,item_name,item_image,item_price,created_at FROM favorites WHERE user_uid=:u ORDER BY created_at DESC');$q->execute([':u'=>$uid]);return $q->fetchAll();}
function lz_q_notifications(PDO $pdo,$uid){$q=$pdo->prepare('SELECT id,order_code,title,body,status_key,is_read,created_at FROM notifications WHERE user_uid=:u ORDER BY created_at DESC LIMIT 200');$q->execute([':u'=>$uid]);return $q->fetchAll();}

function lz_user_get(PDO $pdo, array $user) {
    $action=$_GET['action']??'bootstrap';
    $uid=$user['uid'];
    if($action==='orders') lz_json(['ok'=>true,'orders'=>lz_q_orders($pdo,$uid)]);
    if($action==='order'){
        $code=trim((string)($_GET['code']??''));
        $q=$pdo->prepare('SELECT id,order_code,service,amount,total_due,currency,status,created_at,updated_at FROM orders WHERE user_uid=:u AND order_code=:c');
        $q->execute([':u'=>$uid,':c'=>$code]);$o=$q->fetch();
        if(!$o) lz_json(['ok'=>false,'error'=>'not_found'],404);
        $h=$pdo->prepare('SELECT old_status,new_status,created_at FROM order_status_history WHERE order_id=:id ORDER BY created_at');
        $h->execute([':id'=>$o['id']]);$o['history']=$h->fetchAll();
        lz_json(['ok'=>true,'orders'=>[$o]]);
    }
    if($action==='favorites') lz_json(['ok'=>true,'favorites'=>lz_q_favorites($pdo,$uid)]);
    if($action==='notifications') lz_json(['ok'=>true,'notifications'=>lz_q_notifications($pdo,$uid)]);
    if($action==='bootstrap') lz_json(['ok'=>true,'orders'=>lz_q_orders($pdo,$uid),'favorites'=>lz_q_favorites($pdo,$uid),'notifications'=>lz_q_notifications($pdo,$uid)]);
}

function lz_user_post(PDO $pdo, array $user) {
    $in=json_decode(file_get_contents('php://input'),true);
    if(!is_array($in)) $in=[];
    $action=(string)($in['action']??'');
    $uid=$user['uid'];
    if($action==='register_device'){
        $token=trim((string)($in['fcm_token']??''));
        if($token===''||strlen($token)>4096) lz_json(['ok'=>false,'error'=>'invalid_token'],422);
        $q=$pdo->prepare("INSERT INTO user_devices(user_uid,fcm_token,platform,active) VALUES(:u,:t,'android',TRUE) ON CONFLICT(fcm_token) DO UPDATE SET user_uid=EXCLUDED.user_uid,active=TRUE,updated_at=NOW()");
        $q->execute([':u'=>$uid,':t'=>$token]);
        lz_json(['ok'=>true]);
    }
    if($action==='unregister_device'){
        $token=trim((string)($in['fcm_token']??''));
        $q=$pdo->prepare('UPDATE user_devices SET active=FALSE,updated_at=NOW() WHERE user_uid=:u AND fcm_token=:t');
        $q->execute([':u'=>$uid,':t'=>$token]);
        lz_json(['ok'=>true]);
    }
    if($action==='favorite_add'){
        $id=trim((string)($in['item_id']??''));
        $name=trim((string)($in['item_name']??''));
        $image=trim((string)($in['item_image']??''));
        $price=trim((string)($in['item_price']??''));
        // Lengths are counted in characters, not bytes: Arabic names are multi-byte in UTF-8.
        if($id===''||$name===''||lz_len($id)>120||lz_len($name)>300) lz_json(['ok'=>false,'error'=>'invalid_item'],422);
        $q=$pdo->prepare('INSERT INTO favorites(user_uid,item_id,item_name,item_image,item_price) VALUES(:u,:i,:n,:im,:p) ON CONFLICT(user_uid,item_id) DO NOTHING');
        $q->execute([':u'=>$uid,':i'=>$id,':n'=>$name,':im'=>$image?:null,':p'=>$price?:null]);
        lz_json(['ok'=>true]);
    }
    if($action==='favorite_remove'){
        $id=trim((string)($in['item_id']??''));
        $q=$pdo->prepare('DELETE FROM favorites WHERE user_uid=:u AND item_id=:i');
        $q->execute([':u'=>$uid,':i'=>$id]);
        lz_json(['ok'=>true]);
    }
    if($action==='notification_read'){
        $id=(int)($in['id']??0);
        $q=$pdo->prepare('UPDATE notifications SET is_read=TRUE WHERE id=:id AND user_uid=:u');
        $q->execute([':id'=>$id,':u'=>$uid]);
        lz_json(['ok'=>true]);
    }
    if($action==='notifications_read_all'){
        $q=$pdo->prepare('UPDATE notifications SET is_read=TRUE WHERE user_uid=:u');
        $q->execute([':u'=>$uid]);
        lz_json(['ok'=>true]);
    }
    lz_json(['ok'=>false,'error'=>'unknown_action'],400);
}

try {
    $pdo=lz_db();
    if($method==='GET') lz_user_get($pdo,$user);
    elseif($method==='POST') lz_user_post($pdo,$user);
    else lz_json(['ok'=>false,'error'=>'method'],405);
} catch(Throwable $e) {
    lz_fail('database_unavailable',503,'user.php: '.$e->getMessage());
}
lz_json(['ok'=>false,'error'=>'unknown_action'],400);
