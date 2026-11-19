<?php
/**
 * LootZone order API
 * Stores orders in the same Neon PostgreSQL database used by LootZone Admin.
 *
 * Render environment variables:
 *   DATABASE_URL       Required: Neon PostgreSQL connection URL
 *   TELEGRAM_BOT_TOKEN Optional: Telegram bot token
 *   TELEGRAM_CHAT_ID   Optional: destination chat ID
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function lz_respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lz_respond(['ok' => false, 'error' => 'method'], 405);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 32768) {
    lz_respond(['ok' => false, 'error' => 'invalid_request'], 413);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    lz_respond(['ok' => false, 'error' => 'invalid_json'], 400);
}

$clean = function ($key, $max = 4000) use ($input) {
    $value = trim(strip_tags((string)($input[$key] ?? '')));
    if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
    return substr($value, 0, $max);
};

$service = $clean('service', 500);
$name = $clean('name', 180);
$method = strtolower($clean('contact_method', 20));
$contact = $clean('contact_value', 500);
if ($contact === '') $contact = $clean('phone', 500);
$email = $clean('email', 254);
$player = $clean('player_id', 300);
$jawaker = $clean('jawaker_id', 300);
$note = $clean('note', 4000);
$currency = strtoupper($clean('currency', 12));
$amountRaw = $clean('amount', 40);
$feePercentRaw = $clean('fee_percent', 40);
$feeAmountRaw = $clean('fee_amount', 40);
$totalRaw = $clean('total_due', 40);

if ($service === '' || $name === '' || $contact === '') {
    lz_respond(['ok' => false, 'error' => 'required'], 422);
}
if (!in_array($method, ['whatsapp', 'telegram'], true)) $method = 'whatsapp';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    lz_respond(['ok' => false, 'error' => 'invalid_email'], 422);
}

$amount = null;
$feePercent = null;
$feeAmount = null;
$totalDue = null;
if ($currency === 'USDT') {
    if (!is_numeric($amountRaw) || !is_numeric($feePercentRaw) ||
        !is_numeric($feeAmountRaw) || !is_numeric($totalRaw)) {
        lz_respond(['ok' => false, 'error' => 'invalid_usdt_amount'], 422);
    }
    $amount = round((float)$amountRaw, 2);
    $feePercent = (float)$feePercentRaw;
    $feeAmount = round((float)$feeAmountRaw, 2);
    $totalDue = round((float)$totalRaw, 2);
    if ($amount <= 0 || $amount > 100000 || abs($feePercent - 7.0) > 0.001 ||
        abs($feeAmount - round($amount * 0.07, 2)) > 0.02 ||
        abs($totalDue - ($amount + $feeAmount)) > 0.02) {
        lz_respond(['ok' => false, 'error' => 'invalid_usdt_amount'], 422);
    }
} elseif ($amountRaw !== '' && is_numeric($amountRaw)) {
    $amount = round((float)$amountRaw, 2);
}

$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    error_log('LootZone order API: DATABASE_URL is not configured');
    lz_respond(['ok' => false, 'error' => 'database_not_configured'], 503);
}

try {
    $parts = parse_url($dbUrl);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }
    $dbName = ltrim($parts['path'], '/');
    $dbUser = isset($parts['user']) ? rawurldecode($parts['user']) : '';
    $dbPass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
    $dbPort = isset($parts['port']) ? (int)$parts['port'] : 5432;
    $dsn = 'pgsql:host=' . $parts['host'] . ';port=' . $dbPort .
           ';dbname=' . $dbName . ';sslmode=require';
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    $orderCode = 'LZ-' . strtoupper(bin2hex(random_bytes(4)));
    $trackingHash = hash('sha256', bin2hex(random_bytes(24)));

    // Column names exactly match schema.sql used by the Admin dashboard.
    $sql = "INSERT INTO orders
        (order_code, tracking_token_hash, service, customer_name, contact_method,
         contact_value, email, player_id, jawaker_id, note, currency, amount,
         fee_percent, fee_amount, total_due, status, telegram_notified)
        VALUES
        (:code, :tracking, :service, :name, :method, :contact, :email, :player,
         :jawaker, :note, :currency, :amount, :fee_percent, :fee_amount,
         :total_due, 'processing', FALSE)
        RETURNING id, order_code, created_at";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':code' => $orderCode,
        ':tracking' => $trackingHash,
        ':service' => $service,
        ':name' => $name,
        ':method' => $method,
        ':contact' => $contact,
        ':email' => $email !== '' ? $email : null,
        ':player' => $player !== '' ? $player : null,
        ':jawaker' => $jawaker !== '' ? $jawaker : null,
        ':note' => $note !== '' ? $note : null,
        ':currency' => $currency !== '' ? $currency : null,
        ':amount' => $amount,
        ':fee_percent' => $feePercent,
        ':fee_amount' => $feeAmount,
        ':total_due' => $totalDue
    ]);
    $saved = $stmt->fetch();
    // Associate this order with the device that placed it; tokens are never shared across customers.
    $fcmToken = trim((string)($input['fcm_token'] ?? ''));
    if ($fcmToken !== '' && strlen($fcmToken) <= 4096) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_push_tokens (id BIGSERIAL PRIMARY KEY, order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE, fcm_token TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), UNIQUE(order_id, fcm_token))");
        $pushStmt = $pdo->prepare('INSERT INTO order_push_tokens (order_id, fcm_token) VALUES (:id, :token) ON CONFLICT DO NOTHING');
        $pushStmt->execute([':id' => $saved['id'], ':token' => $fcmToken]);
    }
} catch (Throwable $e) {
    error_log('LootZone order database error: ' . $e->getMessage());
    lz_respond(['ok' => false, 'error' => 'database_error'], 503);
}

/* The order is already safely stored. Telegram failure does not discard it. */
$telegramSent = false;
$token = getenv('TELEGRAM_BOT_TOKEN');
$chatId = getenv('TELEGRAM_CHAT_ID');
if ($token && $chatId && function_exists('curl_init')) {
    $methodLabel = $method === 'telegram' ? 'Telegram' : 'WhatsApp';
    $lines = [
        '🛒 طلب جديد - LootZone',
        '🔖 رقم الطلب: ' . $orderCode,
        '📦 الخدمة: ' . $service,
        '👤 الاسم: ' . $name,
        '📲 طريقة التواصل: ' . $methodLabel,
        '☎️ بيانات التواصل: ' . $contact
    ];
    if ($email !== '') $lines[] = '📧 البريد الإلكتروني: ' . $email;
    if ($player !== '') $lines[] = '🆔 Player ID: ' . $player;
    if ($jawaker !== '') $lines[] = '🆔 Jawaker ID: ' . $jawaker;
    if ($currency === 'USDT') {
        $lines[] = '💵 المبلغ المطلوب استلامه: ' . number_format($amount, 2, '.', '') . ' USDT';
        $lines[] = '➕ رسوم الخدمة (7%): ' . number_format($feeAmount, 2, '.', '') . ' USDT';
        $lines[] = '💳 الإجمالي المطلوب دفعه: ' . number_format($totalDue, 2, '.', '') . ' USDT';
    }
    if ($note !== '') $lines[] = '📝 التفاصيل: ' . $note;

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            ['chat_id' => $chatId, 'text' => implode("\n", $lines)],
            JSON_UNESCAPED_UNICODE
        ),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    curl_close($ch);
    $telegram = is_string($response) ? json_decode($response, true) : null;
    $telegramSent = !$curlError && $status >= 200 && $status < 300 &&
                    is_array($telegram) && !empty($telegram['ok']);
}

if ($telegramSent) {
    try {
        $flag = $pdo->prepare(
            'UPDATE orders SET telegram_notified=TRUE, updated_at=NOW() WHERE order_code=:code'
        );
        $flag->execute([':code' => $orderCode]);
    } catch (Throwable $e) {
        error_log('LootZone Telegram flag update error: ' . $e->getMessage());
    }
}

lz_respond([
    'ok' => true,
    'order_code' => $orderCode,
    'telegram_notified' => $telegramSent
]);
