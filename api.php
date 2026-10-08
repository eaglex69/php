<?php
declare(strict_types=1);
// Ошибки пишем в лог, а не в ответ — иначе предупреждения PHP ломают JSON.
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/rules.php';
require __DIR__ . '/lib/seed.php';
require __DIR__ . '/lib/state.php';
require __DIR__ . '/lib/game_actions.php';
require __DIR__ . '/lib/admin_chat.php';

if (!installed()) fail('Игра ещё не установлена. Откройте install.php', 503);
$r = (string)($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Защита от CSRF: изменяющие запросы должны приходить из нашего JS с особым заголовком.
if ($method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') fail('Некорректный запрос.', 400);

function handle_auth(string $method): void {
    if ($method === 'GET') { $u = current_user(); out(['user' => $u ? ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']] : null]); }
    $b = body(); $a = (string)($b['action'] ?? '');
    if ($a === 'logout') { start_session(); $_SESSION = []; session_destroy(); out(['ok' => true]); }
    $email = mb_strtolower(s($b, 'email', 120)); $pass = (string)($b['password'] ?? '');
    seed_game();
    if ($a === 'register') {
        $name = s($b, 'username', 20);
        if (!preg_match('/^[\p{L}\p{N}_ -]{3,20}$/u', $name)) fail('Имя: от 3 до 20 букв или цифр.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Укажите корректную почту.');
        if (mb_strlen($pass) < 8) fail('Пароль должен содержать минимум 8 символов.');
        if (row('SELECT id FROM users WHERE email=?', [$email])) fail('Этот email уже зарегистрирован.', 409);
        if (row('SELECT id FROM users WHERE LOWER(username)=LOWER(?)', [$name])) fail('Это имя уже занято.', 409);
        $role = (int)val('SELECT COUNT(*) FROM users') === 0 ? 'admin' : 'player';
        run('INSERT INTO users(username,email,password_hash,role,deck,equipped_emotes,wildcards,cosmetics) VALUES(?,?,?,?,?,?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, '', '[]', '{"common":0,"rare":0,"epic":0,"legendary":0,"champion":0}', '[]']);
        $uid = lastId();
        $starter = catalog()['starter'];
        $cards = rows('SELECT id,name FROM cards WHERE name IN (' . in_ph($starter) . ')', $starter);
        usort($cards, fn($x, $y) => array_search($x['name'], $starter, true) <=> array_search($y['name'], $starter, true));
        foreach ($cards as $i => $c) run('INSERT IGNORE INTO owned_cards(user_id,card_id,copies) VALUES(?,?,?)', [$uid, $c['id'], $i < 6 ? 12 : 4]);
        run('UPDATE users SET deck=? WHERE id=?', [implode(',', array_map(fn($c) => $c['id'], array_slice($cards, 0, 8))), $uid]);
        login_user($uid); out(['ok' => true]);
    }
    if ($a === 'login') {
        $u = row('SELECT * FROM users WHERE email=?', [$email]);
        if (!$u || !password_verify($pass, $u['password_hash'])) { usleep(300000); fail('Неверная почта или пароль.', 401); }
        if ($u['banned']) fail('Аккаунт заблокирован.', 403);
        login_user((int)$u['id']); out(['ok' => true]);
    }
    fail('Неизвестное действие.');
}

try {
    switch ($r) {
        case 'auth': handle_auth($method); break;
        case 'game':
            $u = current_user();
            if ($method === 'GET') out(game_state($u));
            if (!$u) fail('Войдите в аккаунт, чтобы продолжить.', 401);
            handle_game($u, body());
            break;
        case 'chat': handle_chat(current_user(), $method); break;
        case 'upload': if ($method !== 'POST') fail('Метод не поддерживается.', 405); handle_upload(current_user()); break;
        case 'admin':
            $u = current_user();
            if (!$u || $u['role'] !== 'admin') fail('Доступ запрещён.', 403);
            if ($method === 'GET') admin_get();
            admin_post($u, body());
            break;
        default: fail('Не найдено.', 404);
    }
} catch (Throwable $e) {
    error_log('[crownfall] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Ошибка сервера. Попробуйте ещё раз.', 500);
}
