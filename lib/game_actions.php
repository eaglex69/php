<?php
declare(strict_types=1);

function add_owned(int $uid, int $cardId, int $copies): void {
    run('INSERT INTO owned_cards(user_id,card_id,copies) VALUES(?,?,?) ON DUPLICATE KEY UPDATE copies=copies+VALUES(copies)', [$uid, $cardId, $copies]);
}
function tx(callable $fn) {
    $pdo = db(); $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (RuleError $e) { $pdo->rollBack(); fail($e->getMessage()); }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function my_deck_ids(array $u): array {
    $own = array_map('intval', array_column(rows('SELECT card_id FROM owned_cards WHERE user_id=?', [$u['id']]), 'card_id'));
    return resolve_deck((string)$u['deck'], $own, rows('SELECT id,cost FROM cards WHERE enabled=1'));
}

function handle_game(array $u, array $b): void {
    seed_game();
    $uid = (int)$u['id'];
    $a = (string)($b['action'] ?? '');
    if ($u['role'] !== 'admin' && setting('maintenance') === 'true') fail('Сейчас идут технические работы. Попробуйте позже.', 503);

    if ($a === 'gift') {
        if ($u['last_gift_at'] && time() - strtotime($u['last_gift_at'] . ' UTC') < 86400) fail('Подарок уже получен. Возвращайтесь завтра.');
        $gold = (int)setting('gift_gold', '500'); $gems = (int)setting('gift_gems', '15');
        if (!run('UPDATE users SET gold=gold+?, gems=gems+?, last_gift_at=? WHERE id=? AND (last_gift_at IS NULL OR last_gift_at < ?)', [$gold, $gems, now_s(), $uid, gmdate('Y-m-d H:i:s', time() - 86400)])) fail('Подарок уже получен.');
        ok("Получено: $gold золота и $gems кристаллов!");
    }
    if ($a === 'upgrade') {
        $o = row('SELECT * FROM owned_cards WHERE user_id=? AND card_id=?', [$uid, (int)($b['cardId'] ?? 0)]);
        if (!$o) fail('Карта не найдена.');
        $need = (int)$o['level'] * 10; $price = (int)$o['level'] * 500;
        tx(function () use ($uid, $o, $need, $price) {
            if (!run('UPDATE users SET gold=gold-?, xp=xp+50 WHERE id=? AND gold>=?', [$price, $uid, $price])) throw new RuleError('Недостаточно золота.');
            if (!run('UPDATE owned_cards SET level=level+1, copies=copies-? WHERE id=? AND copies>=?', [$need, $o['id'], $need])) throw new RuleError('Недостаточно копий карты.');
        });
        ok('Карта улучшена!');
    }
    if ($a === 'buy') {
        $card = row('SELECT * FROM cards WHERE id=?', [(int)($b['cardId'] ?? 0)]);
        if (!$card || !$card['enabled']) fail('Карта недоступна.');
        if ((int)$card['unlock_trophies'] > (int)$u['trophies']) fail('Эта карта откроется на арене при ' . $card['unlock_trophies'] . ' кубках.');
        $price = card_price($card['rarity']);
        if (!run('UPDATE users SET gold=gold-? WHERE id=? AND gold>=?', [$price, $uid, $price])) fail('Недостаточно золота.');
        add_owned($uid, (int)$card['id'], 1);
        ok("Карта «{$card['name']}» получена!");
    }
    if ($a === 'setDeck') {
        $ids = array_map('intval', is_array($b['deck'] ?? null) ? $b['deck'] : []);
        $uniq = array_values(array_unique($ids));
        if (count($uniq) !== 8 || in_array(0, $uniq, true)) fail('В колоде должно быть ровно 8 разных карт.');
        $own = array_map('intval', array_column(rows('SELECT card_id FROM owned_cards WHERE user_id=?', [$uid]), 'card_id'));
        foreach ($uniq as $id) if (!in_array($id, $own, true)) fail('В колоду можно поставить только открытые карты.');
        run('UPDATE users SET deck=? WHERE id=?', [implode(',', $uniq), $uid]);
        ok('Колода сохранена.');
    }
    if ($a === 'pvpFind') {
        if (setting('pvp_enabled') === 'false') fail('PvP-арена временно отключена.');
        if (count(my_deck_ids($u)) !== 8) fail('Сначала соберите колоду из 8 карт.');
        $rivals = rows("SELECT id,username,trophies,deck,tower_skin,banner_skin,equipped_emotes FROM users WHERE banned=0 AND id<>? AND deck<>'' AND (battle_started_at IS NULL OR battle_started_at < ?) ORDER BY ABS(trophies-?) LIMIT 12", [$uid, gmdate('Y-m-d H:i:s', time() - 600), (int)$u['trophies']]);
        if (!$rivals) fail('Сейчас нет свободных противников. Попробуйте через минуту.');
        $p = $rivals[array_rand(array_slice($rivals, 0, 5))];
        $lv = []; foreach (rows('SELECT card_id,level FROM owned_cards WHERE user_id=?', [$p['id']]) as $o) $lv[(int)$o['card_id']] = (int)$o['level'];
        $deck = array_values(array_filter(array_map('intval', explode(',', $p['deck'])), fn($i) => isset($lv[$i])));
        if (count($deck) < 4) fail('Противник ещё не собрал колоду. Попробуйте другого.');
        $deck = array_slice($deck, 0, 8); $levels = new stdClass(); foreach ($deck as $i) $levels->{(string)$i} = $lv[$i];
        out(['ok' => true, 'opponent' => ['id' => (int)$p['id'], 'username' => $p['username'], 'trophies' => (int)$p['trophies'], 'deck' => $deck, 'levels' => $levels, 'towerSkin' => $p['tower_skin'], 'bannerSkin' => $p['banner_skin'], 'emotes' => jget($p, 'equipped_emotes', [])]]);
    }
    if ($a === 'battleStart') {
        $mode = ($b['mode'] ?? '') === 'tournament' ? 'tournament' : (($b['mode'] ?? '') === 'pvp' ? 'pvp' : 'ladder');
        $opp = null;
        if ($mode === 'pvp') {
            $r = row('SELECT id,banned FROM users WHERE id=?', [(int)($b['opponentId'] ?? 0)]);
            if (!$r || $r['banned']) fail('Противник недоступен.');
            $opp = (int)$r['id'];
        }
        if (!run('UPDATE users SET battle_started_at=?, battle_mode=?, pvp_opponent_id=? WHERE id=? AND battle_started_at IS NULL', [now_s(), $mode, $opp, $uid])) fail('У вас уже идёт бой.');
        if ($mode === 'tournament') { $t = current_tournament(); if ($t) tournament_join((int)$t['id'], $uid); }
        out(['ok' => true, 'mode' => $mode]);
    }
    if ($a === 'battle') {
        if (!$u['battle_started_at']) fail('Бой не найден или уже завершён.');
        $elapsed = time() - strtotime($u['battle_started_at'] . ' UTC');
        $result = ($b['result'] ?? '') === 'win' ? 'win' : (($b['result'] ?? '') === 'draw' ? 'draw' : 'loss');
        $crowns = ni($b, 'crowns', 0, 3); $lost = ni($b, 'crownsLost', 0, 3);
        if ($result !== 'loss' && $elapsed < 25) fail('Бой слишком короткий, чтобы засчитать результат.');
        $tc = $result === 'win' ? 20 + $crowns * 5 : ($result === 'draw' ? 0 : -15);
        $gold = $result === 'win' ? 100 + $crowns * 25 : ($result === 'draw' ? 60 : 40);
        $r = rnd();
        $chestType = $result === 'win' ? ($r < 0.14 ? 'magic' : ($r < 0.28 ? 'gold' : 'silver')) : 'silver';
        $mode = (string)$u['battle_mode']; $oppId = $u['pvp_opponent_id'] ? (int)$u['pvp_opponent_id'] : null;
        $chestId = tx(function () use ($u, $uid, $result, $crowns, $lost, $tc, $gold, $chestType, $mode, $oppId, $b) {
            $win = $result === 'win';
            if (!run("UPDATE users SET battle_started_at=NULL, battle_mode='ladder', pvp_opponent_id=NULL, trophies=GREATEST(0,trophies+?), gold=gold+?, xp=xp+?, wins=wins+?, losses=losses+?, tournament_wins=tournament_wins+? WHERE id=? AND battle_started_at IS NOT NULL",
                [$tc, $gold, $win ? 80 : ($result === 'draw' ? 40 : 20), $win ? 1 : 0, $result === 'loss' ? 1 : 0, ($win && $mode === 'tournament') ? 1 : 0, $uid])) throw new RuleError('Бой уже завершён.');
            run('INSERT INTO battles(user_id,opponent,result,crowns,trophies,gold,mode,opponent_id,crowns_lost) VALUES(?,?,?,?,?,?,?,?,?)', [$uid, s($b, 'opponent', 40) ?: 'Страж арены', $result, $crowns, $tc, $gold, $mode, $oppId, $lost]);
            if ($mode === 'pvp' && $oppId) run('INSERT INTO pvp_matches(attacker_id,defender_id,attacker_crowns,defender_crowns,result,trophies) VALUES(?,?,?,?,?,?)', [$uid, $oppId, $crowns, $lost, $crowns > $lost ? 'win' : ($crowns < $lost ? 'loss' : 'draw'), $tc]);
            if ($mode === 'tournament') { $t = current_tournament(); if ($t) tournament_score((int)$t['id'], $uid, $crowns, $win); }
            if ($win) { run('INSERT INTO chests(user_id,type,source) VALUES(?,?,?)', [$uid, $chestType, $mode === 'tournament' ? 'tournament-victory' : 'victory']); return lastId(); }
            return null;
        });
        $msg = $result === 'win' ? "Победа! +$tc кубков, +$gold золота" . ($chestId ? ' · получен ' . mb_strtolower(CHEST_INFO[$chestType]['title']) : '') : ($result === 'draw' ? "Ничья. +$gold золота" : "Поражение. $tc кубков, +$gold золота");
        out(['ok' => true, 'message' => $msg, 'reward' => ['trophies' => $tc, 'gold' => $gold, 'crowns' => $crowns, 'result' => $result], 'chest' => $chestId ? ['id' => $chestId, 'type' => $chestType, 'title' => CHEST_INFO[$chestType]['title']] : null]);
    }
    if ($a === 'buyChest') {
        $type = (string)($b['type'] ?? '');
        if (!in_array($type, ['silver', 'gold', 'magic', 'legendary'], true)) fail('Этот сундук нельзя купить.');
        $info = CHEST_INFO[$type]; $gems = $info['priceGems'] > 0; $price = $gems ? $info['priceGems'] : $info['priceGold'];
        $id = tx(function () use ($uid, $type, $gems, $price) {
            $n = $gems ? run('UPDATE users SET gems=gems-? WHERE id=? AND gems>=?', [$price, $uid, $price]) : run('UPDATE users SET gold=gold-? WHERE id=? AND gold>=?', [$price, $uid, $price]);
            if (!$n) throw new RuleError('Недостаточно ' . ($gems ? 'кристаллов' : 'золота') . '.');
            run('INSERT INTO chests(user_id,type,source) VALUES(?,?,?)', [$uid, $type, 'shop']);
            return lastId();
        });
        ok('Куплен ' . $info['title'] . '.', ['chestId' => $id]);
    }
    if ($a === 'openChest') {
        $chestId = (int)($b['chestId'] ?? 0);
        $rewards = tx(function () use ($uid, $chestId) {
            $chest = row('SELECT * FROM chests WHERE id=? AND user_id=? AND opened_at IS NULL FOR UPDATE', [$chestId, $uid]);
            if (!$chest) throw new RuleError('Сундук уже открыт или не найден.');
            $me = row('SELECT * FROM users WHERE id=? FOR UPDATE', [$uid]);
            $pool = rows('SELECT * FROM cards WHERE enabled=1 AND unlock_trophies<=?', [(int)$me['trophies']]);
            $cosPool = rows('SELECT type,name,rarity FROM cosmetic_items WHERE enabled=1');
            $type = isset(CHEST_INFO[$chest['type']]) ? $chest['type'] : 'silver';
            $rolled = roll_chest($type, $pool, (int)$me['trophies'], $cosPool);
            if (!run('UPDATE chests SET opened_at=?, rewards=? WHERE id=? AND opened_at IS NULL', [now_s(), json_encode($rolled, JSON_UNESCAPED_UNICODE), $chestId])) throw new RuleError('Сундук уже открыт.');
            $goldAdd = 0; $gemsAdd = 0; $evo = 0;
            $wild = jget($me, 'wildcards', []); $cos = jget($me, 'cosmetics', []); $emotes = jget($me, 'equipped_emotes', []);
            $skin = $me['tower_skin']; $banner = $me['banner_skin'];
            foreach ($rolled as $r) {
                if ($r['kind'] === 'gold') $goldAdd += $r['amount'];
                elseif ($r['kind'] === 'gems') $gemsAdd += $r['amount'];
                elseif ($r['kind'] === 'evolution') $evo += $r['amount'];
                elseif ($r['kind'] === 'wildcard') { $k = (string)$r['image']; $wild[$k] = ($wild[$k] ?? 0) + $r['amount']; }
                elseif ($r['kind'] === 'card') add_owned($uid, (int)$r['cardId'], (int)$r['amount']);
                elseif ($r['kind'] === 'cosmetic') {
                    $ct = (string)$r['image']; $have = false;
                    foreach ($cos as $c) if ($c['type'] === $ct && $c['name'] === $r['name']) $have = true;
                    if ($have) { $gemsAdd += 25; continue; }
                    $cos[] = ['type' => $ct, 'name' => $r['name'], 'art' => $ct === 'towerSkin' ? 'tower' : $ct];
                    if ($ct === 'towerSkin' && $skin === 'classic') $skin = $r['name'];
                    if ($ct === 'banner' && $banner === '') $banner = $r['name'];
                    if ($ct === 'emote' && count($emotes) < 6) $emotes[] = $r['name'];
                }
            }
            run('UPDATE users SET gold=gold+?, gems=gems+?, evolution_fragments=evolution_fragments+?, wildcards=?, cosmetics=?, equipped_emotes=?, tower_skin=?, banner_skin=? WHERE id=?',
                [$goldAdd, $gemsAdd, $evo, json_encode($wild ?: new stdClass()), json_encode($cos, JSON_UNESCAPED_UNICODE), json_encode(array_values($emotes), JSON_UNESCAPED_UNICODE), $skin, $banner, $uid]);
            return $rolled;
        });
        ok('Сундук открыт! Получено наград: ' . count($rewards) . '.', ['rewards' => $rewards]);
    }
    if ($a === 'equipCosmetic') {
        $type = (string)($b['type'] ?? ''); $name = (string)($b['name'] ?? '');
        if (!in_array($type, ['emote', 'banner', 'towerSkin'], true)) fail('Неизвестный тип косметики.');
        $emotes = jget($u, 'equipped_emotes', []);
        if (($b['unequip'] ?? false) === true) {
            if ($type === 'towerSkin') run("UPDATE users SET tower_skin='classic' WHERE id=?", [$uid]);
            elseif ($type === 'banner') run("UPDATE users SET banner_skin='' WHERE id=?", [$uid]);
            else run('UPDATE users SET equipped_emotes=? WHERE id=?', [json_encode(array_values(array_filter($emotes, fn($x) => $x !== $name)), JSON_UNESCAPED_UNICODE), $uid]);
            ok('Косметика снята.');
        }
        $has = false; foreach (jget($u, 'cosmetics', []) as $c) if ($c['type'] === $type && $c['name'] === $name) $has = true;
        if (!$has) fail('Этот предмет ещё не открыт. Он выпадает из сундуков.');
        if ($type === 'towerSkin') run('UPDATE users SET tower_skin=? WHERE id=?', [$name, $uid]);
        elseif ($type === 'banner') run('UPDATE users SET banner_skin=? WHERE id=?', [$name, $uid]);
        else {
            if (in_array($name, $emotes, true)) fail('Эта эмоция уже выбрана.');
            if (count($emotes) >= 6) fail('В бою доступно не больше 6 эмоций. Снимите одну из выбранных.');
            $emotes[] = $name; run('UPDATE users SET equipped_emotes=? WHERE id=?', [json_encode($emotes, JSON_UNESCAPED_UNICODE), $uid]);
        }
        ok("Экипировано: «{$name}».");
    }
    if ($a === 'leagueClaim') {
        $season = gmdate('Y-m');
        $res = tx(function () use ($uid, $season) {
            $me = row('SELECT trophies FROM users WHERE id=? FOR UPDATE', [$uid]);
            $league = league_for(rows('SELECT * FROM leagues ORDER BY sort_order, min_trophies'), (int)$me['trophies']);
            if (!$league) throw new RuleError('Лига не найдена.');
            if (!run('UPDATE users SET league_chest_season=?, league_season=?, gold=gold+?, gems=gems+? WHERE id=? AND league_chest_season<>?', [$season, $season, $league['reward_gold'], $league['reward_gems'], $uid, $season])) throw new RuleError('Награда за эту лигу в текущем сезоне уже получена.');
            run('INSERT INTO chests(user_id,type,source) VALUES(?,?,?)', [$uid, $league['chest_type'], 'league']);
            return $league;
        });
        ok("Награда лиги «{$res['name']}» получена: сундук, +{$res['reward_gold']} золота и +{$res['reward_gems']} кристаллов.");
    }
    if ($a === 'tournamentClaim') {
        $t = current_tournament();
        if (!$t) fail('Турнир сейчас не проводится.');
        if (strtotime($t['ends_at'] . ' UTC') > time()) fail('Турнир ещё не завершён.');
        $mine = row('SELECT id FROM tournament_players WHERE tournament_id=? AND user_id=?', [$t['id'], $uid]);
        if (!$mine) fail('Вы не участвовали в этом турнире.');
        $place = 0; foreach (tournament_standings((int)$t['id']) as $i => $r) if ((int)$r['user_id'] === $uid) $place = $i + 1;
        if (!$place) fail('Результат не найден.');
        if (!run('UPDATE tournament_players SET claimed=1 WHERE id=? AND claimed=0', [$mine['id']])) fail('Награда за этот турнир уже получена.');
        $bonus = $place <= 3 ? 1500 : ($place <= 10 ? 800 : 300);
        run('INSERT INTO chests(user_id,type,source) VALUES(?,?,?)', [$uid, $place <= 3 ? 'legendary' : ($place <= 10 ? 'magic' : 'tournament'), 'tournament-prize']);
        run('UPDATE users SET gold=gold+? WHERE id=?', [$bonus, $uid]);
        ok("Турнир завершён! Ваше место: $place. Награда: сундук и +$bonus золота.", ['place' => $place]);
    }
    if ($a === 'pvpSeen') { run('UPDATE pvp_matches SET seen=1 WHERE defender_id=? AND seen=0', [$uid]); out(['ok' => true]); }

    // ---- Кланы, друзья, фрагменты ----
    if ($a === 'requestCards') {
        if (!$u['clan_id']) fail('Чтобы просить фрагменты, вступите в клан.');
        $cardId = (int)($b['cardId'] ?? 0);
        if (!row('SELECT id FROM owned_cards WHERE user_id=? AND card_id=?', [$uid, $cardId])) fail('Просить можно только открытые карты.');
        $card = row('SELECT * FROM cards WHERE id=?', [$cardId]);
        if (!$card || !$card['enabled']) fail('Карта недоступна.');
        $min = max(1, (int)round((float)setting('request_cooldown_minutes', '30')));
        if (!run('UPDATE users SET last_request_at=? WHERE id=? AND (last_request_at IS NULL OR last_request_at < ?)', [now_s(), $uid, gmdate('Y-m-d H:i:s', time() - $min * 60)])) fail("Просить фрагменты можно раз в $min мин. Подождите немного.");
        $amount = request_amount($card['rarity']);
        run('INSERT INTO card_requests(user_id,clan_id,card_id,amount,expires_at) VALUES(?,?,?,?,?)', [$uid, $u['clan_id'], $cardId, $amount, gmdate('Y-m-d H:i:s', time() + 8 * 3600)]);
        $rid = lastId();
        run("INSERT INTO clan_messages(clan_id,user_id,body,kind,request_id) VALUES(?,?,?,'request',?)", [$u['clan_id'], $uid, 'Просит фрагменты: ' . $card['name'], $rid]);
        ok("Просьба отправлена в чат клана: «{$card['name']}», до $amount шт.");
    }
    if ($a === 'donate') {
        if (!$u['clan_id']) fail('Вы не состоите в клане.');
        $msg = tx(function () use ($u, $uid, $b) {
            $r = row('SELECT * FROM card_requests WHERE id=? FOR UPDATE', [(int)($b['requestId'] ?? 0)]);
            if (!$r || (int)$r['clan_id'] !== (int)$u['clan_id']) throw new RuleError('Просьба не найдена.');
            if ((int)$r['user_id'] === $uid) throw new RuleError('Нельзя отправить фрагмент самому себе.');
            if (strtotime($r['expires_at'] . ' UTC') < time()) throw new RuleError('Срок просьбы истёк.');
            $card = row('SELECT * FROM cards WHERE id=?', [$r['card_id']]);
            if (!$card) throw new RuleError('Карта не найдена.');
            if (!run('UPDATE owned_cards SET copies=copies-1 WHERE user_id=? AND card_id=? AND copies>=1', [$uid, $r['card_id']])) throw new RuleError('У вас нет копий этой карты.');
            if (!run('UPDATE card_requests SET received=received+1 WHERE id=? AND received<amount', [$r['id']])) throw new RuleError('Эта просьба уже выполнена.');
            add_owned((int)$r['user_id'], (int)$r['card_id'], 1);
            $reward = donate_reward($card['rarity']);
            run('UPDATE users SET gold=gold+?, xp=xp+10 WHERE id=?', [$reward, $uid]);
            run('INSERT INTO request_donations(request_id,donor_id,amount) VALUES(?,?,1)', [$r['id'], $uid]);
            return "Вы отправили фрагмент «{$card['name']}». Награда: +$reward золота.";
        });
        ok($msg);
    }
    if ($a === 'createClan') {
        if ($u['clan_id']) fail('Сначала покиньте текущий клан.');
        $name = s($b, 'name', 25); $desc = s($b, 'description', 180);
        if (mb_strlen($name) < 3) fail('Название клана: от 3 до 25 символов.');
        if ((int)$u['gold'] < 1000) fail('Для создания клана нужно 1000 золота.');
        if (row('SELECT id FROM clans WHERE LOWER(name)=LOWER(?)', [$name])) fail('Такой клан уже существует.');
        $badge = in_array($b['badge'] ?? '', ['blue', 'red', 'purple', 'green'], true) ? $b['badge'] : 'blue';
        run('INSERT INTO clans(name,description,badge,owner_id) VALUES(?,?,?,?)', [$name, $desc, $badge, $uid]);
        run('UPDATE users SET clan_id=?, gold=gold-1000 WHERE id=?', [lastId(), $uid]);
        ok('Клан создан!');
    }
    if ($a === 'joinClan') {
        if ($u['clan_id']) fail('Сначала покиньте текущий клан.');
        $c = row('SELECT * FROM clans WHERE id=?', [(int)($b['clanId'] ?? 0)]);
        if (!$c) fail('Клан не найден.');
        run('UPDATE users SET clan_id=? WHERE id=?', [$c['id'], $uid]);
        ok("Вы вступили в клан «{$c['name']}»!");
    }
    if ($a === 'leaveClan') {
        if (!$u['clan_id']) fail('Вы не состоите в клане.');
        $c = row('SELECT * FROM clans WHERE id=?', [$u['clan_id']]);
        run('UPDATE users SET clan_id=NULL WHERE id=?', [$uid]);
        if ($c && (int)$c['owner_id'] === $uid) {
            $next = row('SELECT id FROM users WHERE clan_id=? ORDER BY trophies DESC LIMIT 1', [$c['id']]);
            if ($next) run('UPDATE clans SET owner_id=? WHERE id=?', [$next['id'], $c['id']]); else run('DELETE FROM clans WHERE id=?', [$c['id']]);
        }
        ok('Вы покинули клан.');
    }
    if ($a === 'friendRequest') {
        $t = row('SELECT id FROM users WHERE LOWER(username)=LOWER(?)', [s($b, 'username', 40)]);
        if (!$t) fail('Игрок не найден.');
        if ((int)$t['id'] === $uid) fail('Нельзя добавить себя.');
        if (row('SELECT id FROM friends WHERE (requester_id=? AND recipient_id=?) OR (requester_id=? AND recipient_id=?)', [$uid, $t['id'], $t['id'], $uid])) fail('Заявка уже отправлена или вы уже друзья.');
        run('INSERT INTO friends(requester_id,recipient_id) VALUES(?,?)', [$uid, $t['id']]);
        ok('Заявка в друзья отправлена.');
    }
    if ($a === 'acceptFriend') { if (!run("UPDATE friends SET status='accepted' WHERE id=? AND recipient_id=? AND status='pending'", [(int)($b['id'] ?? 0), $uid])) fail('Заявка не найдена.'); ok('Теперь вы друзья!'); }
    if ($a === 'removeFriend') { run('DELETE FROM friends WHERE id=? AND (requester_id=? OR recipient_id=?)', [(int)($b['id'] ?? 0), $uid, $uid]); ok('Список друзей обновлён.'); }
    fail('Неизвестное действие.');
}
