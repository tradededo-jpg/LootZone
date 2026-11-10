<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$token = getenv('TELEGRAM_BOT_TOKEN');
$chatId = getenv('TELEGRAM_CHAT_ID');
if (!$token || !$chatId) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_config']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 20000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
    exit;
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$clean = function ($key, $max = 500) use ($input) {
    $value = trim(strip_tags((string)($input[$key] ?? '')));
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
};

$service = $clean('service', 180);
$name = $clean('name', 100);
$method = strtolower($clean('contact_method', 20));
$contact = $clean('contact_value', 150);
if ($contact === '') $contact = $clean('phone', 150); // legacy clients
$email = $clean('email', 180);
$player = $clean('player_id', 100);
$note = $clean('note', 1000);

if ($service === '' || $name === '' || $contact === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'required']);
    exit;
}
if (!in_array($method, ['whatsapp', 'telegram'], true)) $method = 'whatsapp';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}

$methodLabel = $method === 'telegram' ? 'Telegram' : 'WhatsApp';
$lines = [
    "🛒 طلب جديد - LootZone",
    "📦 الخدمة: {$service}",
    "👤 الاسم: {$name}",
    "📲 طريقة التواصل: {$methodLabel}",
    "☎️ بيانات التواصل: {$contact}"
];
// Gmail and Player ID are independent fields and are both preserved when supplied.
if ($email !== '') $lines[] = "📧 البريد الإلكتروني: {$email}";
if ($player !== '') $lines[] = "🆔 Player ID: {$player}";

$currency = strtoupper($clean('currency', 10));
if ($currency === 'USDT') {
    $amountRaw = $input['amount'] ?? null;
    if (!is_numeric($amountRaw)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_usdt_amount']);
        exit;
    }
    $amount = (float)$amountRaw;
    if (!is_finite($amount) || $amount <= 0 || $amount > 100000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_usdt_amount']);
        exit;
    }
    // Calculate the 7% fee on the server; do not trust totals supplied by the app.
    $fee = round($amount * 0.07, 2);
    $total = round($amount + $fee, 2);
    $lines[] = "💵 المبلغ المطلوب: " . number_format($amount, 2, '.', '') . " USDT";
    $lines[] = "➕ رسوم الخدمة (7%): " . number_format($fee, 2, '.', '') . " USDT";
    $lines[] = "💳 الإجمالي المطلوب دفعه: " . number_format($total, 2, '.', '') . " USDT";
}
if ($note !== '') $lines[] = "📝 التفاصيل: {$note}";

$message = implode("\n", $lines);
$url = "https://api.telegram.org/bot{$token}/sendMessage";
$payload = json_encode(['chat_id' => $chatId, 'text' => $message], JSON_UNESCAPED_UNICODE);
if ($payload === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'encoding']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
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

if ($curlError || $status < 200 || $status >= 300 || !is_array($telegram) || empty($telegram['ok'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'telegram_failed']);
    exit;
}

echo json_encode(['ok' => true]);
