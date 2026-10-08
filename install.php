<?php
declare(strict_types=1);
// Установщик Crownfall: создаёт config.php, таблицы MySQL и стартовые данные.
$lock = __DIR__ . '/data/installed.lock';
$msg = ''; $ok = false;
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (is_file($lock)) { http_response_code(403); echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;background:#120a2e;color:#fff;padding:40px"><h2>Игра уже установлена</h2><p>Удалите файл <code>data/installed.lock</code>, если хотите запустить установщик заново.</p><p><a style="color:#ffd45c" href="index.php">Перейти в игру</a></p>'; exit; }
$f = ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'crownfall', 'user' => 'root', 'pass' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));
    try {
        $pdo0 = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $f['host'], (int)$f['port']), $f['user'], $f['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $f['name'])) throw new RuntimeException('Имя базы: только латиница, цифры и _.');
        $pdo0->exec('CREATE DATABASE IF NOT EXISTS `' . $f['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $cfg = "<?php\nreturn " . var_export(['db_host' => $f['host'], 'db_port' => (int)$f['port'], 'db_name' => $f['name'], 'db_user' => $f['user'], 'db_pass' => $f['pass']], true) . ";\n";
        if (file_put_contents(__DIR__ . '/config.php', $cfg) === false) throw new RuntimeException('Не удалось записать config.php — проверьте права на папку.');
        require __DIR__ . '/lib/bootstrap.php';
        foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__ . '/schema.sql')))) as $sql) { if (preg_match('/^\s*--/m', $sql) && !preg_match('/CREATE/i', $sql)) continue; db()->exec($sql); }
        require __DIR__ . '/lib/rules.php'; require __DIR__ . '/lib/seed.php';
        seed_game();
        @mkdir(__DIR__ . '/uploads', 0755, true);
        file_put_contents($lock, gmdate('c'));
        $ok = true;
    } catch (Throwable $e) { $msg = $e->getMessage(); @unlink(__DIR__ . '/config.php'); }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка Crownfall</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(ellipse at 50% 0,#5a1fb0,#1a0c3f 60%,#0d0a26);font-family:'Trebuchet MS',Arial,sans-serif;color:#fff}.box{width:min(94vw,460px);background:linear-gradient(#3a1c7e,#1b0c42);border:2px solid #ffd45c;border-radius:20px;padding:28px;box-shadow:0 20px 60px #000a}h1{margin:0 0 6px;font:900 24px 'Arial Black',Arial;color:#ffd45c}p{color:#dccbf7;font-size:13px;line-height:1.5}label{display:block;margin:12px 0 5px;font-size:12px;font-weight:bold;color:#dccbf7}input{width:100%;box-sizing:border-box;padding:11px 13px;border-radius:10px;border:1px solid #6a3fc0;background:#160b36;color:#fff;font-size:14px}button{margin-top:18px;width:100%;padding:14px;border:2px solid #d4ffb0;border-radius:12px;background:linear-gradient(#a6ff5e,#4fcc2e 52%,#2f9c20);color:#0e3d08;font-weight:900;font-size:15px;cursor:pointer}.err{background:#8a1f48;border:1px solid #ff8aa0;padding:10px;border-radius:8px;font-size:12px;margin-top:12px}.okb{background:#1f6a3a;border:1px solid #7dff9a;padding:14px;border-radius:10px;margin-top:14px}a{color:#ffd45c}</style></head><body><div class="box"><h1>Crownfall · установка</h1>
<?php if ($ok): ?><div class="okb"><b>Готово!</b> Таблицы созданы, карты, лиги, арены и косметика загружены.<br><br><a href="index.php">Открыть игру →</a><br><small>Первый зарегистрированный игрок станет администратором.</small></div>
<?php else: ?><p>Введите данные MySQL. Если базы нет — установщик создаст её сам.</p><?php if ($msg): ?><div class="err"><?= h($msg) ?></div><?php endif; ?>
<form method="post"><label>Хост MySQL<input name="host" value="<?= h($f['host']) ?>" required></label><label>Порт<input name="port" value="<?= h($f['port']) ?>" required></label><label>Имя базы<input name="name" value="<?= h($f['name']) ?>" required></label><label>Пользователь<input name="user" value="<?= h($f['user']) ?>" required></label><label>Пароль<input name="pass" type="password" value="<?= h($f['pass']) ?>"></label><button>Установить игру</button></form><?php endif; ?></div></body></html>
