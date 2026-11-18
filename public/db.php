<?php
function lootzone_db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $url = getenv('DATABASE_URL');
    if (!$url) throw new RuntimeException('DATABASE_URL is not configured');
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) throw new RuntimeException('Invalid DATABASE_URL');
    $db = ltrim($parts['path'], '/');
    $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
    $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
    $port = isset($parts['port']) ? (int)$parts['port'] : 5432;
    $dsn = 'pgsql:host=' . $parts['host'] . ';port=' . $port . ';dbname=' . $db . ';sslmode=require';
    $pdo = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ));
    return $pdo;
}
