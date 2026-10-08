<?php
declare(strict_types=1);

// ======================= ЧАТ =======================
function handle_chat(?array $u, string $method): void {
    if (!$u) fail('Войдите в аккаунт.', 401);
    if ($method === 'GET') {
        $scope = ($_GET['scope'] ?? '') === 'clan' ? 'clan' : 'global';
        if ($scope === 'global') {
            $r = array_reverse(rows('SELECT m.id,m.body,m.created_at,m.user_id,u.username,u.role FROM global_messages m JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 80'));
            out(['messages' => array_map(fn($x) => camel($x) + ['kind' => 'text', 'requestId' => null], $r), 'requests' => []]);
        }
        if (!$u['clan_id']) out(['messages' => [], 'requests' => [], 'noClan' => true]);
        $r = array_reverse(rows('SELECT m.id,m.body,m.created_at,m.user_id,u.username,u.role,m.kind,m.request_id FROM clan_messages m JOIN users u ON u.id=m.user_id WHERE m.clan_id=? ORDER BY m.id DESC LIMIT 60', [$u['clan_id']]));
        $ids = array_values(array_filter(array_map(fn($x) => $x['request_id'], $r)));
        $reqs = [];
        if ($ids) {
            $ph = in_ph($ids);
            $rq = rows("SELECT cr.id,cr.user_id,u.username,cr.card_id,cr.amount,cr.received,cr.expires_at FROM card_requests cr JOIN users u ON u.id=cr.user_id WHERE cr.id IN ($ph)", $ids);
            $dn = rows("SELECT rd.request_id,u.username,SUM(rd.amount) AS cnt FROM request_donations rd JOIN users u ON u.id=rd.donor_id WHERE rd.request_id IN ($ph) GROUP BY rd.request_id,u.username", $ids);
            foreach ($rq as $x) {
                $c = camel($x);
                $c['donors'] = array_values(array_map(fn($d) => ['username' => $d['username'], 'count' => (int)$d['cnt']], array_filter($dn, fn($d) => (int)$d['request_id'] === (int)$x['id'])));
                $reqs[] = $c;
            }
        }
        out(['messages' => camel_all($r), 'requests' => $reqs]);
    }
    $b = body();
    $scope = ($b['scope'] ?? '') === 'clan' ? 'clan' : 'global';
    $text = s($b, 'body', $scope === 'global' ? 200 : 300);
    if ($text === '') fail('Введите сообщение.');
    $tbl = $scope === 'global' ? 'global_messages' : 'clan_messages';
    $last = val("SELECT created_at FROM $tbl WHERE user_id=? ORDER BY id DESC LIMIT 1", [$u['id']]);
    if ($last && time() - strtotime($last . ' UTC') < 1) fail('Не так быстро. Подождите секунду.', 429);
    if ($scope === 'global') run('INSERT INTO global_messages(user_id,body) VALUES(?,?)', [$u['id'], $text]);
    else { if (!$u['clan_id']) fail('Вы не состоите в клане.'); run('INSERT INTO clan_messages(clan_id,user_id,body) VALUES(?,?,?)', [$u['clan_id'], $u['id'], $text]); }
    out(['ok' => true]);
}

// ======================= ЗАГРУЗКА КАРТИНОК =======================
function handle_upload(?array $u): void {
    if (!$u || $u['role'] !== 'admin') fail('Доступ запрещён.', 403);
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) fail('Файл не получен (проверьте upload_max_filesize в php.ini).');
    if ($f['size'] > 4 * 1024 * 1024) fail('Файл слишком большой (максимум 4 МБ).');
    $d = file_get_contents($f['tmp_name']);
    $ext = null;
    if (strncmp($d, "\x89PNG", 4) === 0) $ext = 'png';
    elseif (strncmp($d, "\xff\xd8\xff", 3) === 0) $ext = 'jpg';
    elseif (substr($d, 0, 4) === 'RIFF' && substr($d, 8, 4) === 'WEBP') $ext = 'webp';
    elseif (substr($d, 0, 3) === 'GIF') $ext = 'gif';
    if (!$ext) fail('Допустимы только PNG, JPG, WEBP или GIF.');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!is_dir(ROOT . '/uploads')) @mkdir(ROOT . '/uploads', 0755, true);
    if (file_put_contents(ROOT . '/uploads/' . $name, $d) === false) fail('Не удалось сохранить файл. Проверьте права на папку uploads.', 500);
    out(['ok' => true, 'url' => 'uploads/' . $name]);
}

// ======================= АДМИН-ПАНЕЛЬ =======================
function img_ok(string $p): bool { return (bool)preg_match('#^(images/[\w.\-]+|uploads/[\w.\-]+)(@\d+)?$#', $p); }
const TYPES_OK = ['emote', 'banner', 'towerSkin'];
const COS_RARITY = ['Обычная', 'Редкая', 'Эпическая', 'Легендарная'];
const CHESTS_OK = ['wood', 'silver', 'gold', 'magic', 'legendary', 'tournament'];

function admin_get(): void {
    $players = camel_all(rows('SELECT id,username,email,role,banned,gold,gems,trophies,wins,clan_id,created_at FROM users ORDER BY id DESC LIMIT 200'));
    $stats = ['players' => (int)val('SELECT COUNT(*) FROM users'), 'battles' => (int)val('SELECT COUNT(*) FROM battles'), 'clans' => (int)val('SELECT COUNT(*) FROM clans'), 'totalGold' => (int)val('SELECT COALESCE(SUM(gold),0) FROM users')];
    $tp = rows('SELECT tournament_id, COUNT(*) AS c FROM tournament_players GROUP BY tournament_id');
    $tours = array_map(function ($t) use ($tp) { $c = camel($t); $c['players'] = 0; foreach ($tp as $x) if ((int)$x['tournament_id'] === (int)$t['id']) $c['players'] = (int)$x['c']; return $c; }, rows('SELECT * FROM tournaments ORDER BY starts_at DESC LIMIT 30'));
    out([
        'players' => $players, 'cards' => camel_all(rows('SELECT * FROM cards ORDER BY id')), 'clans' => camel_all(rows('SELECT * FROM clans ORDER BY id DESC')),
        'news' => camel_all(rows('SELECT * FROM announcements ORDER BY id DESC')),
        'settings' => array_map(fn($r) => ['key' => $r['k'], 'value' => $r['v']], rows('SELECT k,v FROM settings')),
        'logs' => camel_all(rows('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 80')), 'stats' => $stats,
        'chat' => camel_all(rows('SELECT m.id,m.body,m.created_at,u.username FROM global_messages m JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 100')),
        'leagues' => camel_all(rows('SELECT * FROM leagues ORDER BY sort_order, min_trophies')), 'tournaments' => $tours,
        'arenas' => camel_all(rows('SELECT * FROM arenas ORDER BY min_trophies')), 'cosmetics' => camel_all(rows('SELECT * FROM cosmetic_items ORDER BY type, id')),
    ]);
}

function admin_post(array $admin, array $b): void {
    $a = (string)($b['action'] ?? ''); $aid = (int)$admin['id']; $id = (int)($b['id'] ?? 0); $detail = '';
    switch ($a) {
        case 'user':
            $t = row('SELECT * FROM users WHERE id=?', [$id]); if (!$t) fail('Игрок не найден.');
            $f = (string)($b['field'] ?? '');
            if (!in_array($f, ['gold', 'gems', 'trophies', 'role', 'banned'], true)) fail('Недопустимое поле.');
            if ($f === 'role') { if ($id === $aid && ($b['value'] ?? '') !== 'admin') fail('Нельзя снять права с себя.'); run('UPDATE users SET role=? WHERE id=?', [($b['value'] ?? '') === 'admin' ? 'admin' : 'player', $id]); }
            elseif ($f === 'banned') { if ($id === $aid) fail('Нельзя заблокировать себя.'); run('UPDATE users SET banned=? WHERE id=?', [!empty($b['value']) ? 1 : 0, $id]); }
            else run("UPDATE users SET $f=? WHERE id=?", [ni($b, 'value', 0, 9999999), $id]);
            $detail = "{$t['username']}: $f → " . json_encode($b['value'] ?? null); break;
        case 'card': {
            $t = row('SELECT * FROM cards WHERE id=?', [$id]); if (!$t) fail('Карта не найдена.');
            $f = (string)($b['field'] ?? '');
            $ints = ['cost' => ['cost', 0, 10], 'damage' => ['damage', 0, 99999], 'health' => ['health', 1, 99999], 'count' => ['unit_count', 1, 12], 'lifetime' => ['lifetime', 0, 300], 'unlockTrophies' => ['unlock_trophies', 0, 99999]];
            $reals = ['speed' => ['speed', 0, 6], 'range' => ['rng', 0, 15], 'attackSpeed' => ['attack_speed', 0.2, 10], 'splash' => ['splash', 0, 8]];
            $texts = ['description' => 'description', 'name' => 'name', 'rarity' => 'rarity', 'role' => 'role', 'pros' => 'pros', 'cons' => 'cons', 'image' => 'image'];
            if ($f === 'enabled') { run('UPDATE cards SET enabled=? WHERE id=?', [!empty($b['value']) ? 1 : 0, $id]); $v = !empty($b['value']); }
            elseif (isset($ints[$f])) { [$col, $mn, $mx] = $ints[$f]; $v = ni($b, 'value', $mn, $mx, $mn); run("UPDATE cards SET $col=? WHERE id=?", [$v, $id]); }
            elseif (isset($reals[$f])) { [$col, $mn, $mx] = $reals[$f]; $v = n($b, 'value', $mn, $mx, $mn); run("UPDATE cards SET $col=? WHERE id=?", [$v, $id]); }
            elseif (isset($texts[$f])) {
                $v = s($b, 'value', 240);
                if ($f === 'image' && !img_ok($v)) fail('Укажите путь images/… или загрузите файл с устройства.');
                if ($f === 'name' && mb_strlen($v) < 2) fail('Название слишком короткое.');
                if ($f === 'rarity' && !in_array($v, ['Обычная', 'Редкая', 'Эпическая', 'Легендарная', 'Чемпион'], true)) fail('Неизвестная редкость.');
                try { run("UPDATE cards SET {$texts[$f]}=? WHERE id=?", [$v, $id]); } catch (PDOException $e) { fail('Карта с таким названием уже существует.'); }
            } else fail('Недопустимое поле.');
            $detail = "Карта {$t['name']}: $f → " . json_encode($v, JSON_UNESCAPED_UNICODE); break;
        }
        case 'cardCreate': {
            $name = s($b, 'name', 60); $image = s($b, 'image', 200);
            if (mb_strlen($name) < 2) fail('Название карты от 2 до 60 символов.');
            if (!img_ok($image)) fail('Загрузите картинку с устройства или укажите путь images/….');
            $rarity = s($b, 'rarity', 20); if (!in_array($rarity, ['Обычная', 'Редкая', 'Эпическая', 'Легендарная', 'Чемпион'], true)) fail('Неизвестная редкость.');
            $kind = s($b, 'kind', 12); if (!in_array($kind, ['ground', 'air', 'building', 'spell'], true)) fail('Неизвестный тип карты.');
            if (row('SELECT id FROM cards WHERE LOWER(name)=LOWER(?)', [$name])) fail('Карта с таким названием уже существует.');
            $targets = in_array($b['targets'] ?? '', ['ground', 'all', 'buildings'], true) ? $b['targets'] : 'ground';
            run('INSERT INTO cards(name,image,rarity,cost,damage,health,description,enabled,kind,targets,speed,rng,attack_speed,unit_count,splash,lifetime,ability,unlock_trophies,role,pros,cons) VALUES(?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$name, $image, $rarity, ni($b, 'cost', 0, 10), ni($b, 'damage', 0, 99999), ni($b, 'health', 1, 99999, 1), s($b, 'description', 400), $kind, $targets, n($b, 'speed', 0, 6), n($b, 'range', 0, 15), n($b, 'attackSpeed', 0.2, 10, 1.2), ni($b, 'count', 1, 12, 1), n($b, 'splash', 0, 8), ni($b, 'lifetime', 0, 300), s($b, 'ability', 40), ni($b, 'unlockTrophies', 0, 99999), s($b, 'role', 60), s($b, 'pros', 240), s($b, 'cons', 240)]);
            audit($aid, $a, "Создана карта «{$name}»"); ok("Карта «{$name}» создана.", ['id' => lastId()]);
        }
        case 'cardDelete': $c = row('SELECT name FROM cards WHERE id=?', [$id]); if (!$c) fail('Карта не найдена.'); run('DELETE FROM cards WHERE id=?', [$id]); $detail = "Удалена карта «{$c['name']}»"; break;
        case 'newsCreate': $t = s($b, 'title', 80); $x = s($b, 'body', 500); if ($t === '' || $x === '') fail('Заполните заголовок и текст.'); run('INSERT INTO announcements(title,body) VALUES(?,?)', [$t, $x]); $detail = "Опубликована новость: $t"; break;
        case 'newsDelete': run('DELETE FROM announcements WHERE id=?', [$id]); $detail = "Удалена новость #$id"; break;
        case 'newsToggle': run('UPDATE announcements SET active=? WHERE id=?', [!empty($b['value']) ? 1 : 0, $id]); $detail = "Новость #$id переключена"; break;
        case 'clanDelete': run('UPDATE users SET clan_id=NULL WHERE clan_id=?', [$id]); run('DELETE FROM clans WHERE id=?', [$id]); $detail = "Удалён клан #$id"; break;
        case 'setting': {
            $key = (string)($b['key'] ?? ''); $lim = ['gift_gold' => [0, 100000], 'gift_gems' => [0, 100000], 'elixir_seconds' => [0.5, 10], 'battle_seconds' => [30, 600], 'request_cooldown_minutes' => [1, 720]];
            if (!isset($lim[$key]) && !in_array($key, ['maintenance', 'pvp_enabled'], true)) fail('Недопустимая настройка.');
            $v = isset($lim[$key]) ? (string)n($b, 'value', $lim[$key][0], $lim[$key][1], $lim[$key][0]) : (!empty($b['value']) ? 'true' : 'false');
            run('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$key, $v]); $detail = "Настройка $key → $v"; break;
        }
        case 'chatDelete': run('DELETE FROM global_messages WHERE id=?', [$id]); $detail = "Удалено сообщение #$id"; break;
        case 'chatClear': run('DELETE FROM global_messages'); $detail = 'Общий чат очищен'; break;
        case 'league': {
            $f = (string)($b['field'] ?? ''); $map = ['name' => 'name', 'chestType' => 'chest_type', 'rewardGold' => 'reward_gold', 'rewardGems' => 'reward_gems', 'color' => 'color', 'minTrophies' => 'min_trophies'];
            if (!isset($map[$f])) fail('Недопустимое поле.');
            if ($f === 'name') { $v = s($b, 'value', 40); if ($v === '') fail('Укажите название.'); }
            elseif ($f === 'chestType') { $v = s($b, 'value', 16); if (!in_array($v, CHESTS_OK, true)) fail('Неизвестный тип сундука.'); }
            elseif ($f === 'color') $v = s($b, 'value', 16) ?: 'blue';
            else $v = ni($b, 'value', 0, 999999);
            try { run("UPDATE leagues SET {$map[$f]}=? WHERE id=?", [$v, $id]); } catch (PDOException $e) { fail('Лига с таким названием уже существует.'); }
            $detail = "Лига #$id: $f → $v"; break;
        }
        case 'leagueCreate': {
            $name = s($b, 'name', 40); if (mb_strlen($name) < 2) fail('Укажите название лиги.');
            $ct = s($b, 'chestType', 16) ?: 'silver'; if (!in_array($ct, CHESTS_OK, true)) fail('Неизвестный тип сундука.');
            $max = (int)val('SELECT COALESCE(MAX(sort_order),0) FROM leagues');
            try { run('INSERT INTO leagues(name,min_trophies,sort_order,chest_type,reward_gold,reward_gems,color) VALUES(?,?,?,?,?,?,?)', [$name, ni($b, 'minTrophies', 0, 99999), $max + 1, $ct, ni($b, 'rewardGold', 0, 999999), ni($b, 'rewardGems', 0, 999999), s($b, 'color', 16) ?: 'blue']); } catch (PDOException $e) { fail('Лига с таким названием уже существует.'); }
            $detail = "Создана лига «{$name}»"; break;
        }
        case 'leagueDelete': run('DELETE FROM leagues WHERE id=?', [$id]); $detail = "Удалена лига #$id"; break;
        case 'leagueReset': run("UPDATE users SET trophies=GREATEST(0,FLOOR(trophies*7/10)), league_chest_season=''"); run("INSERT INTO settings(k,v) VALUES('league_season',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [gmdate('Y-m')]); $detail = 'Сброшены лиги: кубки −30%'; break;
        case 'tournamentCreate': {
            $name = s($b, 'name', 80); if ($name === '') fail('Укажите название турнира.');
            $ct = s($b, 'chestType', 16) ?: 'tournament'; if (!in_array($ct, CHESTS_OK, true)) fail('Неизвестный тип сундука.');
            $days = ni($b, 'days', 1, 30, 7);
            run('INSERT INTO tournaments(name,description,starts_at,ends_at,chest_type,auto,active) VALUES(?,?,?,?,?,0,1)', [$name, s($b, 'description', 400), now_s(), gmdate('Y-m-d H:i:s', time() + $days * 86400), $ct]);
            audit($aid, $a, "Создан турнир «{$name}»"); ok('Турнир создан.', ['id' => lastId()]);
        }
        case 'tournamentToggle': run('UPDATE tournaments SET active=? WHERE id=?', [!empty($b['value']) ? 1 : 0, $id]); $detail = "Турнир #$id переключён"; break;
        case 'tournamentDelete': run('DELETE FROM tournaments WHERE id=?', [$id]); $detail = "Удалён турнир #$id"; break;
        case 'arena': {
            $f = (string)($b['field'] ?? ''); if (!in_array($f, ['name', 'minTrophies', 'difficulty', 'description'], true)) fail('Недопустимое поле.');
            if ($f === 'name') { $v = s($b, 'value', 50); if ($v === '') fail('Укажите название.'); try { run('UPDATE arenas SET name=? WHERE id=?', [$v, $id]); } catch (PDOException $e) { fail('Арена с таким названием уже есть.'); } }
            elseif ($f === 'description') { $v = s($b, 'value', 240); run('UPDATE arenas SET description=? WHERE id=?', [$v, $id]); }
            elseif ($f === 'difficulty') { $v = ni($b, 'value', 1, 10, 5); run('UPDATE arenas SET difficulty=? WHERE id=?', [$v, $id]); }
            else { $v = ni($b, 'value', 0, 99999); run('UPDATE arenas SET min_trophies=? WHERE id=?', [$v, $id]); }
            $detail = "Арена #$id: $f → $v"; break;
        }
        case 'arenaCreate': {
            $name = s($b, 'name', 50); if (mb_strlen($name) < 2) fail('Укажите название арены.');
            if (row('SELECT id FROM arenas WHERE LOWER(name)=LOWER(?)', [$name])) fail('Арена с таким названием уже есть.');
            run('INSERT INTO arenas(name,min_trophies,difficulty,description) VALUES(?,?,?,?)', [$name, ni($b, 'minTrophies', 0, 99999), ni($b, 'difficulty', 1, 10, 5), s($b, 'description', 240)]); $detail = "Создана арена «{$name}»"; break;
        }
        case 'arenaDelete': if ((int)val('SELECT COUNT(*) FROM arenas') <= 1) fail('Нельзя удалить последнюю арену.'); run('DELETE FROM arenas WHERE id=?', [$id]); $detail = "Удалена арена #$id"; break;
        case 'cosmeticCreate': {
            $type = s($b, 'type', 12); if (!in_array($type, TYPES_OK, true)) fail('Неизвестный тип косметики.');
            $name = s($b, 'name', 50); if (mb_strlen($name) < 2) fail('Укажите название (от 2 символов).');
            $rar = s($b, 'rarity', 20) ?: 'Обычная'; if (!in_array($rar, COS_RARITY, true)) fail('Неизвестная редкость.');
            $hex = fn($x, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$x) ? (string)$x : $d;
            $image = s($b, 'image', 200); if ($image !== '' && !img_ok($image)) fail('Некорректный путь к изображению.');
            if (row('SELECT id FROM cosmetic_items WHERE type=? AND LOWER(name)=LOWER(?)', [$type, $name])) fail('Такой предмет уже существует.');
            run('INSERT INTO cosmetic_items(type,name,rarity,color1,color2,label,image) VALUES(?,?,?,?,?,?,?)', [$type, $name, $rar, $hex($b['color1'] ?? '', '#4aa8ff'), $hex($b['color2'] ?? '', '#1f4fa8'), s($b, 'label', 24), $image]);
            $detail = "Создан косметический предмет «{$name}»"; break;
        }
        case 'cosmetic': {
            $f = (string)($b['field'] ?? ''); if (!in_array($f, ['name', 'rarity', 'color1', 'color2', 'label', 'image', 'enabled'], true)) fail('Недопустимое поле.');
            if ($f === 'enabled') { $v = !empty($b['value']) ? 1 : 0; }
            else {
                $v = $f === 'label' ? s($b, 'value', 24) : s($b, 'value', 200);
                if ($f === 'rarity' && !in_array($v, COS_RARITY, true)) fail('Неизвестная редкость.');
                if (in_array($f, ['color1', 'color2'], true) && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) fail('Цвет в формате #RRGGBB.');
                if ($f === 'image' && $v !== '' && !img_ok($v)) fail('Некорректный путь к изображению.');
                if ($f === 'name' && mb_strlen($v) < 2) fail('Название слишком короткое.');
            }
            try { run("UPDATE cosmetic_items SET $f=? WHERE id=?", [$v, $id]); } catch (PDOException $e) { fail('Такой предмет уже существует.'); }
            $detail = "Косметика #$id: $f → $v"; break;
        }
        case 'cosmeticDelete': run('DELETE FROM cosmetic_items WHERE id=?', [$id]); $detail = "Удалён косметический предмет #$id"; break;
        case 'link': {
            $t = row('SELECT id,banned FROM users WHERE id=?', [$id]); if (!$t) fail('Игрок не найден.'); if ($t['banned']) fail('Игрок заблокирован.', 403);
            $tok = bin2hex(random_bytes(20));
            run('INSERT INTO login_tokens(token,user_id,expires_at) VALUES(?,?,?)', [$tok, $id, gmdate('Y-m-d H:i:s', time() + 86400)]);
            out(['ok' => true, 'message' => 'Ссылка входа создана (действует 24 часа).', 'url' => 'index.php?login=' . $tok]);
        }
        default: fail('Неизвестное действие.');
    }
    audit($aid, $a, $detail);
    ok('Изменения сохранены.');
}
