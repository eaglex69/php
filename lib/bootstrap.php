<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
// Запасные реализации на случай, если расширение mbstring не установлено на хостинге.
if (!function_exists('mb_internal_encoding')) { function mb_internal_encoding($e = null) { return true; } }
if (!function_exists('mb_strlen')) { function mb_strlen($s, $enc = null) { return preg_match_all('/./us', (string)$s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $len = null, $enc = null) { $a = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) ?: []; return implode('', array_slice($a, $start, $len)); } }
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) {
        static $map = null;
        if ($map === null) { $up = preg_split('//u', 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ', -1, PREG_SPLIT_NO_EMPTY); $lo = preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY); $map = array_combine($up, $lo); }
        return strtr(strtolower((string)$s), $map);
    }
}
mb_internal_encoding('UTF-8');
define('ROOT', dirname(__DIR__));

$GLOBALS['CFG'] = is_file(ROOT . '/config.php') ? (require ROOT . '/config.php') : null;
function cfg(string $k, $d = null) { return $GLOBALS['CFG'][$k] ?? $d; }
function installed(): bool { return is_array($GLOBALS['CFG']); }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!installed()) throw new RuntimeException('NO_CONFIG');
    $c = $GLOBALS['CFG'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], (int)($c['db_port'] ?? 3306), $c['db_name']);
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_FOUND_ROWS => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone='+00:00'",
    ]);
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function run(string $sql, array $p = []): int { return q($sql, $p)->rowCount(); }
function lastId(): int { return (int)db()->lastInsertId(); }
function now_s(): string { return gmdate('Y-m-d H:i:s'); }
function iso(string $s): string { return gmdate('Y-m-d\TH:i:s', strtotime($s . ' UTC')) . '.000Z'; }
function in_ph(array $a): string { return implode(',', array_fill(0, max(1, count($a)), '?')); }

class RuleError extends Exception {}
function out($d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function fail(string $m, int $code = 400): void { out(['error' => $m], $code); }
function ok(string $message, array $extra = []): void { out(array_merge(['ok' => true, 'message' => $message], $extra)); }
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '', true); return is_array($j) ? $j : []; }
function s(array $b, string $k, int $max = 255): string { return mb_substr(trim((string)($b[$k] ?? '')), 0, $max); }
function n(array $b, string $k, float $min, float $max, float $def = 0): float { $v = $b[$k] ?? $def; $v = is_numeric($v) ? (float)$v : $def; return max($min, min($max, $v)); }
function ni(array $b, string $k, int $min, int $max, int $def = 0): int { return (int)round(n($b, $k, $min, $max, $def)); }

const BOOL_KEYS = ['banned', 'enabled', 'auto', 'active', 'claimed', 'seen'];
const JSON_KEYS = ['wildcards', 'cosmetics', 'equippedEmotes', 'rewards'];
const KEY_MAP = ['rng' => 'range', 'unitCount' => 'count', 'sortOrder' => 'order'];
function camel_key(string $k): string { $c = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $k)))); return KEY_MAP[$c] ?? $c; }
function camel(?array $r): ?array {
    if (!$r) return $r;
    $o = [];
    foreach ($r as $k => $v) {
        $ck = camel_key((string)$k);
        if (in_array($ck, BOOL_KEYS, true)) $v = (bool)$v;
        elseif (in_array($ck, JSON_KEYS, true)) $v = is_string($v) ? (json_decode($v, true) ?? ($ck === 'wildcards' ? new stdClass() : [])) : $v;
        elseif (is_string($v) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $v)) $v = iso($v);
        $o[$ck] = $v;
    }
    return $o;
}
function camel_all(array $rs): array { return array_map('camel', $rs); }

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Если системный каталог сессий недоступен — используем data/sessions внутри проекта.
        $sp = session_save_path();
        if ($sp === '' || !is_dir($sp) || !is_writable($sp)) {
            $dir = ROOT . '/data/sessions';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
        }
        session_name('crownfall');
        session_set_cookie_params(['lifetime' => 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
}
function current_user(bool $fresh = false): ?array {
    static $u = false;
    if ($u !== false && !$fresh) return $u;
    start_session();
    $id = (int)($_SESSION['uid'] ?? 0);
    $u = null;
    if ($id) { $r = row('SELECT * FROM users WHERE id=?', [$id]); if ($r && !$r['banned']) $u = $r; }
    return $u;
}
function login_user(int $id): void { start_session(); session_regenerate_id(true); $_SESSION['uid'] = $id; }
function jget(array $u, string $k, $def) { $v = json_decode((string)($u[$k] ?? ''), true); return $v === null ? $def : $v; }
function setting(string $k, ?string $d = null): ?string { $v = val('SELECT v FROM settings WHERE k=?', [$k]); return $v === null || $v === false ? $d : (string)$v; }
function audit(int $adminId, string $action, string $detail): void { run('INSERT INTO audit_logs(admin_id,action,detail) VALUES(?,?,?)', [$adminId, $action, mb_substr($detail, 0, 400)]); }
