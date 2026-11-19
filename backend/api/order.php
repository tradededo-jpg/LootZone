<?php
require_once __DIR__.'/common.php';
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Headers: Content-Type, Authorization');header('Access-Control-Allow-Methods: POST, OPTIONS');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') lz_json(['ok'=>false,'error'=>'method'],405);
$raw=file_get_contents('php://input');if(!$raw||strlen($raw)>32768) lz_json(['ok'=>false,'error'=>'invalid_request'],413);
$in=json_decode($raw,true);if(!is_array($in)) lz_json(['ok'=>false,'error'=>'invalid_json'],400);
$user=lz_current_user();
$clean=function($k,$max=4000)use($in){$v=trim(strip_tags((string)($in[$k]??'')));return function_exists('mb_substr')?mb_substr($v,0,$max,'UTF-8'):substr($v,0,$max);};
$service=$clean('service',500);$name=$clean('name',180);$method=strtolower($clean('contact_method',20));$contact=$clean('contact_value',500);if($contact==='')$contact=$clean('phone',500);
$email=$clean('email',254);$idempotency=$clean('idempotency_key',64);if($idempotency==='')$idempotency=bin2hex(random_bytes(16));$player=$clean('player_id',300);$jawaker=$clean('jawaker_id',300);$note=$clean('note',4000);$currency=strtoupper($clean('currency',12));
if($service===''||$name===''||$contact==='')lz_json(['ok'=>false,'error'=>'required'],422);
if(!in_array($method,['whatsapp','telegram'],true))$method='whatsapp';
if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))lz_json(['ok'=>false,'error'=>'invalid_email'],422);
// WhatsApp needs a real phone number (7-15 digits, optional leading +). Telegram usernames are not validated here.
if($method==='whatsapp'){$digits=preg_replace('/[\s\-()]/','',$contact);if(!preg_match('/^\+?\d{7,15}$/',$digits))lz_json(['ok'=>false,'error'=>'invalid_phone'],422);$contact=$digits;}
$amount=null;$feePercent=null;$feeAmount=null;$totalDue=null;$a=$clean('amount',40);$fp=$clean('fee_percent',40);$fa=$clean('fee_amount',40);$td=$clean('total_due',40);
if($currency==='USDT'){if(!is_numeric($a)||!is_numeric($fp)||!is_numeric($fa)||!is_numeric($td))lz_json(['ok'=>false,'error'=>'invalid_usdt_amount'],422);$amount=round((float)$a,2);$feePercent=(float)$fp;$feeAmount=round((float)$fa,2);$totalDue=round((float)$td,2);if($amount<=0||$amount>100000||abs($feePercent-7)>0.001||abs($feeAmount-round($amount*.07,2))>.02||abs($totalDue-($amount+$feeAmount))>.02)lz_json(['ok'=>false,'error'=>'invalid_usdt_amount'],422);}elseif($a!==''&&is_numeric($a))$amount=round((float)$a,2);
try{
 $pdo=lz_db();$code='LZ-'.strtoupper(bin2hex(random_bytes(4)));$hash=hash('sha256',bin2hex(random_bytes(24)));
 $pdo->beginTransaction();
 $q=$pdo->prepare("INSERT INTO orders(order_code,tracking_token_hash,service,customer_name,contact_method,contact_value,email,player_id,jawaker_id,note,currency,amount,fee_percent,fee_amount,total_due,status,telegram_notified,user_uid,idempotency_key) VALUES(:c,:h,:s,:n,:m,:v,:e,:p,:j,:note,:cur,:a,:fp,:fa,:td,'processing',FALSE,:uid,:idem) ON CONFLICT DO NOTHING RETURNING id,order_code,created_at,status");
 $q->execute([':c'=>$code,':h'=>$hash,':s'=>$service,':n'=>$name,':m'=>$method,':v'=>$contact,':e'=>$email?:$user['email'],':p'=>$player?:null,':j'=>$jawaker?:null,':note'=>$note?:null,':cur'=>$currency?:null,':a'=>$amount,':fp'=>$feePercent,':fa'=>$feeAmount,':td'=>$totalDue,':uid'=>$user['uid'],':idem'=>$idempotency]);$order=$q->fetch();
 if(!$order){$dupe=$pdo->prepare('SELECT id,order_code,created_at,status FROM orders WHERE user_uid=:u AND idempotency_key=:k');$dupe->execute([':u'=>$user['uid'],':k'=>$idempotency]);$order=$dupe->fetch();if(!$order)throw new RuntimeException('Order insert conflict');$pdo->commit();lz_json(['ok'=>true,'order_code'=>$order['order_code'],'status'=>$order['status'],'created_at'=>$order['created_at'],'duplicate'=>true]);}
 $h=$pdo->prepare("INSERT INTO order_status_history(order_id,old_status,new_status,changed_by) VALUES(:id,NULL,'processing','system')");$h->execute([':id'=>$order['id']]);
 $token=trim((string)($in['fcm_token']??''));if($token!==''&&strlen($token)<=4096){$d=$pdo->prepare("INSERT INTO user_devices(user_uid,fcm_token,platform,active) VALUES(:u,:t,'android',TRUE) ON CONFLICT(fcm_token) DO UPDATE SET user_uid=EXCLUDED.user_uid,active=TRUE,updated_at=NOW()");$d->execute([':u'=>$user['uid'],':t'=>$token]);$legacy=$pdo->prepare('INSERT INTO order_push_tokens(order_id,fcm_token) VALUES(:o,:t) ON CONFLICT DO NOTHING');$legacy->execute([':o'=>$order['id'],':t'=>$token]);}
 $pdo->commit();
 try{lz_notify_order($pdo,$order['id'],$user['uid'],$code,'processing');}catch(Throwable $notifyError){error_log('LootZone initial notification: '.$notifyError->getMessage());}
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('LootZone order create: '.$e->getMessage());lz_json(['ok'=>false,'error'=>'database_error'],503);}
// Telegram is a best-effort admin alert; it never controls order persistence.
$sent=false;$bot=getenv('TELEGRAM_BOT_TOKEN');$chat=getenv('TELEGRAM_CHAT_ID');if($bot&&$chat&&function_exists('curl_init')){$txt="🛒 طلب جديد - LootZone\n🔖 ".$code."\n📦 ".$service."\n👤 ".$name."\n☎️ ".$contact;$ch=curl_init('https://api.telegram.org/bot'.$bot.'/sendMessage');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$chat,'text'=>$txt],JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>12]);$r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$j=is_string($r)?json_decode($r,true):null;$sent=$c>=200&&$c<300&&!empty($j['ok']);}
if($sent){try{$q=$pdo->prepare('UPDATE orders SET telegram_notified=TRUE WHERE id=:id');$q->execute([':id'=>$order['id']]);}catch(Throwable $e){error_log('LootZone telegram flag: '.$e->getMessage());}}
lz_json(['ok'=>true,'order_code'=>$code,'status'=>'processing','created_at'=>$order['created_at'],'telegram_notified'=>$sent]);
