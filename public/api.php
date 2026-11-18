<?php
session_name('lootzone_admin');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/db.php';
function out($data, $code=200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function csrf_ok() { $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''; return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $h); }
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' && ($_GET['action'] ?? '') === 'bootstrap') {
    if (empty($_SESSION['admin'])) out(['authenticated'=>false]);
    try {
        $pdo = lootzone_db();
        $stats = $pdo->query("SELECT COUNT(*) total, COUNT(*) FILTER (WHERE status='processing') processing, COUNT(*) FILTER (WHERE status='in_progress') in_progress, COUNT(*) FILTER (WHERE status='completed') completed, COUNT(*) FILTER (WHERE status='cancelled') cancelled FROM orders")->fetch();
        $q = $pdo->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 250');
        out(['authenticated'=>true,'csrf'=>$_SESSION['csrf'],'stats'=>$stats,'orders'=>$q->fetchAll()]);
    } catch (Throwable $e) { error_log('LootZone admin read error: '.$e->getMessage()); out(['error'=>'database_unavailable'],503); }
}
if ($method !== 'POST') out(['error'=>'method'],405);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
if ($action === 'login') {
    $expectedUser = getenv('ADMIN_USERNAME') ?: 'admin';
    $expectedPass = getenv('ADMIN_PASSWORD') ?: '';
    if ($expectedPass === '') out(['error'=>'admin_not_configured'],503);
    if (!empty($_SESSION['lock_until']) && time() < $_SESSION['lock_until']) out(['error'=>'too_many_attempts'],429);
    $user = (string)($body['username'] ?? ''); $pass = (string)($body['password'] ?? '');
    if (!hash_equals($expectedUser,$user) || !hash_equals($expectedPass,$pass)) {
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 6) { $_SESSION['lock_until'] = time()+300; $_SESSION['fails']=0; }
        out(['error'=>'invalid_credentials'],401);
    }
    session_regenerate_id(true); $_SESSION['admin']=true; $_SESSION['fails']=0; $_SESSION['csrf']=bin2hex(random_bytes(32));
    out(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
}
if (empty($_SESSION['admin'])) out(['error'=>'unauthorized'],401);
if (!csrf_ok()) out(['error'=>'csrf'],403);
if ($action === 'logout') { $_SESSION=[]; session_destroy(); out(['ok'=>true]); }
if ($action === 'update_status') {
    $code = trim((string)($body['order_code'] ?? '')); $status = (string)($body['status'] ?? '');
    $allowed = ['processing','in_progress','completed','cancelled'];
    if ($code === '' || !in_array($status,$allowed,true)) out(['error'=>'invalid_input'],422);
    try {
        $s = lootzone_db()->prepare('UPDATE orders SET status=:status, updated_at=NOW() WHERE order_code=:code RETURNING order_code');
        $s->execute([':status'=>$status,':code'=>$code]);
        if (!$s->fetch()) out(['error'=>'not_found'],404);
        out(['ok'=>true]);
    } catch (Throwable $e) { error_log('LootZone status update error: '.$e->getMessage()); out(['error'=>'database_error'],503); }
}
out(['error'=>'unknown_action'],400);
