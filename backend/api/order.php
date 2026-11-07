<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

function reply($ok, $error = null, $status = 200) {
    http_response_code($status);
    echo json_encode([
        "ok" => $ok,
        "error" => $error
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    reply(true);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    reply(false, "method_not_allowed", 405);
}

$token = getenv("TELEGRAM_BOT_TOKEN");
$chatId = "6970092473";

if (!$token) {
    reply(false, "server_config", 500);
}

$raw = file_get_contents("php://input");

if (strlen($raw) > 10000) {
    reply(false, "payload_too_large", 413);
}

$data = json_decode($raw, true);

if (!is_array($data)) {
    reply(false, "invalid_json", 400);
}

$clean = function($value, $max = 500) {
    return mb_substr(
        trim(strip_tags((string)($value ?? ""))),
        0,
        $max
    );
};

$service = $clean($data["service"] ?? "", 100);
$name = $clean($data["name"] ?? "", 100);
$phone = $clean($data["phone"] ?? "", 40);
$player = $clean($data["player_id"] ?? "", 100);
$note = $clean($data["note"] ?? "", 500);

if (!$service || !$name || !$phone) {
    reply(false, "missing_fields", 422);
}

$message =
    "🛒 طلب جديد - LootZone\n\n" .
    "🎮 الخدمة: $service\n" .
    "👤 الاسم: $name\n" .
    "📞 الهاتف: $phone\n" .
    "🆔 Player ID: $player\n" .
    "📝 التفاصيل: $note";

$url = "https://api.telegram.org/bot" .
       $token . "/sendMessage";

$options = [
    "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json\r\n",
        "content" => json_encode([
            "chat_id" => $chatId,
            "text" => $message
        ]),
        "timeout" => 15,
        "ignore_errors" => true
    ]
];

$result = @file_get_contents(
    $url,
    false,
    stream_context_create($options)
);

if ($result === false) {
    reply(false, "telegram_network_error", 502);
}

$telegram = json_decode($result, true);

if (!is_array($telegram) || empty($telegram["ok"])) {
    reply(false, "telegram_failed", 502);
}

reply(true);
