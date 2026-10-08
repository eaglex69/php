<?php
declare(strict_types=1);

function card_price(string $r): int { return $r === 'Чемпион' ? 2500 : ($r === 'Легендарная' ? 1200 : ($r === 'Эпическая' ? 420 : ($r === 'Редкая' ? 160 : 60))); }
function request_amount(string $r): int { return ($r === 'Чемпион' || $r === 'Легендарная') ? 1 : ($r === 'Эпическая' ? 2 : ($r === 'Редкая' ? 4 : 8)); }
function donate_reward(string $r): int { return ($r === 'Чемпион' || $r === 'Легендарная') ? 100 : ($r === 'Эпическая' ? 40 : ($r === 'Редкая' ? 15 : 5)); }

const CHEST_INFO = [
    'wood' => ['title' => 'Деревянный сундук', 'cards' => 2, 'gold' => [35, 80], 'gems' => [0, 2], 'priceGold' => 0, 'priceGems' => 0],
    'silver' => ['title' => 'Серебряный сундук', 'cards' => 4, 'gold' => [100, 220], 'gems' => [0, 5], 'priceGold' => 450, 'priceGems' => 0],
    'gold' => ['title' => 'Золотой сундук', 'cards' => 6, 'gold' => [250, 500], 'gems' => [2, 10], 'priceGold' => 1250, 'priceGems' => 0],
    'magic' => ['title' => 'Магический сундук', 'cards' => 8, 'gold' => [450, 850], 'gems' => [8, 22], 'priceGold' => 0, 'priceGems' => 85],
    'legendary' => ['title' => 'Легендарный сундук', 'cards' => 9, 'gold' => [700, 1200], 'gems' => [25, 60], 'priceGold' => 0, 'priceGems' => 300],
    'tournament' => ['title' => 'Турнирный сундук', 'cards' => 7, 'gold' => [350, 700], 'gems' => [10, 30], 'priceGold' => 0, 'priceGems' => 0],
];
function rnd(): float { return mt_rand() / mt_getrandmax(); }
function rrange(array $r): int { return random_int($r[0], $r[1]); }
function pick_one(array $a) { return $a ? $a[array_rand($a)] : null; }

function pick_rarity(string $type, int $i): string {
    if ($type === 'legendary' && $i === 0) return 'Легендарная';
    $n = rnd();
    if (in_array($type, ['magic', 'legendary', 'tournament'], true)) {
        if ($n < ($type === 'tournament' ? 0.025 : 0.05)) return 'Чемпион';
        if ($n < 0.24) return 'Легендарная';
        if ($n < 0.58) return 'Эпическая';
        if ($n < 0.84) return 'Редкая';
        return 'Обычная';
    }
    if ($type === 'gold') return $n < 0.08 ? 'Эпическая' : ($n < 0.40 ? 'Редкая' : 'Обычная');
    if ($type === 'silver') return $n < 0.10 ? 'Эпическая' : ($n < 0.34 ? 'Редкая' : 'Обычная');
    return $n < 0.05 ? 'Эпическая' : ($n < 0.28 ? 'Редкая' : 'Обычная');
}
function cosmetic_weight(string $r): int { return $r === 'Легендарная' ? 1 : ($r === 'Эпическая' ? 3 : ($r === 'Редкая' ? 8 : 16)); }

// Содержимое сундука. Карты — только те, что открыты на арене игрока или ниже.
function roll_chest(string $type, array $cards, int $trophies, array $cosPool): array {
    $info = CHEST_INFO[$type] ?? CHEST_INFO['silver'];
    $order = ['Обычная', 'Редкая', 'Эпическая', 'Легендарная', 'Чемпион'];
    $wild = ['Обычная' => 'common', 'Редкая' => 'rare', 'Эпическая' => 'epic', 'Легендарная' => 'legendary', 'Чемпион' => 'champion'];
    $eligible = array_values(array_filter($cards, fn($c) => $c['enabled'] && (int)$c['unlock_trophies'] <= $trophies));
    $out = [['kind' => 'gold', 'name' => 'Золото', 'amount' => rrange($info['gold'])]];
    $gems = rrange($info['gems']);
    if ($gems > 0) $out[] = ['kind' => 'gems', 'name' => 'Кристаллы', 'amount' => $gems];
    for ($i = 0; $i < $info['cards']; $i++) {
        $rarity = pick_rarity($type, $i);
        $pool = array_values(array_filter($eligible, fn($c) => $c['rarity'] === $rarity));
        while (!$pool && $rarity !== 'Обычная') { $rarity = $order[max(0, array_search($rarity, $order, true) - 1)]; $pool = array_values(array_filter($eligible, fn($c) => $c['rarity'] === $rarity)); }
        $card = pick_one($pool);
        if ($card) {
            $amount = ($type === 'legendary' && $i === 0) ? 1 : (in_array($rarity, ['Чемпион', 'Легендарная'], true) ? 1 : ($rarity === 'Эпическая' ? 2 : ($rarity === 'Редкая' ? 3 : 5)));
            $out[] = ['kind' => 'card', 'name' => $card['name'], 'amount' => $amount, 'cardId' => (int)$card['id'], 'image' => $card['image']];
        }
    }
    $wc = ['wood' => 1, 'silver' => 2, 'gold' => 4, 'magic' => 7, 'legendary' => 9, 'tournament' => 6][$type] ?? 2;
    for ($i = 0; $i < $wc; $i++) {
        $rs = ['Обычная', 'Редкая'];
        if (!in_array($type, ['wood', 'silver'], true)) $rs[] = 'Эпическая';
        if ($type === 'legendary') $rs[] = 'Легендарная';
        if ($type === 'tournament') $rs[] = 'Чемпион';
        $r = pick_one($rs);
        $out[] = ['kind' => 'wildcard', 'name' => 'Дикая карта · ' . mb_strtolower($r), 'amount' => in_array($type, ['magic', 'legendary'], true) ? 2 : 1, 'image' => $wild[$r]];
    }
    $evo = ['wood' => 0, 'silver' => 0.08, 'gold' => 0.25][$type] ?? 0.7;
    if (rnd() < $evo) $out[] = ['kind' => 'evolution', 'name' => 'Фрагменты эволюции', 'amount' => $type === 'legendary' ? 4 : ($type === 'magic' ? 3 : 1 + random_int(0, 1))];
    if (in_array($type, ['magic', 'legendary', 'tournament'], true) || rnd() < 0.16) {
        if ($cosPool) {
            $rich = $type === 'legendary' ? 3 : (in_array($type, ['magic', 'tournament'], true) ? 2 : 1);
            $w = array_map(fn($x) => cosmetic_weight($x['rarity']) * ($x['rarity'] === 'Обычная' ? 1 : $rich), $cosPool);
            $t = rnd() * array_sum($w); $item = end($cosPool);
            foreach ($cosPool as $i => $x) { $t -= $w[$i]; if ($t <= 0) { $item = $x; break; } }
            $out[] = ['kind' => 'cosmetic', 'name' => $item['name'], 'amount' => 1, 'image' => $item['type']];
        }
    }
    return $out;
}

// ---- Лиги и сезоны ----
function utc(string $s = 'now'): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }
function first_monday(DateTimeImmutable $d): DateTimeImmutable {
    $x = $d->setDate((int)$d->format('Y'), (int)$d->format('n'), 1)->setTime(0, 0, 0);
    $dow = (int)$x->format('w');
    $delta = $dow === 0 ? 1 : ($dow === 1 ? 0 : 8 - $dow);
    return $x->modify("+$delta day");
}
function next_league_reset(): DateTimeImmutable {
    $now = utc(); $cur = first_monday($now);
    return $cur > $now ? $cur : first_monday($now->modify('first day of next month'));
}
function league_for(array $list, int $t): ?array { $cur = null; foreach ($list as $l) if ($t >= (int)$l['min_trophies']) $cur = $l; return $cur; }
function next_league_of(array $list, int $t): ?array { foreach ($list as $l) if ((int)$l['min_trophies'] > $t) return $l; return null; }
function arena_for(array $list, int $t): ?array { $cur = null; foreach ($list as $a) if ($t >= (int)$a['min_trophies']) $cur = $a; return $cur ?: ($list[0] ?? null); }

// Ежемесячный сброс: в первый понедельник месяца кубки снижаются на 30%.
function run_league_season(): void {
    $season = gmdate('Y-m');
    $cur = setting('league_season');
    if ($cur === $season) return;
    if ($cur === null || $cur === '') {
        run("UPDATE users SET league_season=?, league_chest_season=''", [$season]);
    } else {
        if (first_monday(utc()) > utc()) return;
        run("UPDATE users SET trophies=GREATEST(0, FLOOR(trophies*7/10)), league_chest_season=''");
    }
    run('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', ['league_season', $season]);
}

// ---- Еженедельный турнир ----
const MONTHS_RU = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
function week_start(): DateTimeImmutable { $d = utc('today'); $dow = (int)$d->format('N'); return $d->modify('-' . ($dow - 1) . ' day'); }
function ensure_weekly_tournament(): void {
    $start = week_start();
    $exists = val('SELECT id FROM tournaments WHERE auto=1 AND starts_at=? LIMIT 1', [$start->format('Y-m-d H:i:s')]);
    if ($exists) return;
    $name = 'Турнир недели · ' . (int)$start->format('j') . ' ' . MONTHS_RU[(int)$start->format('n') - 1];
    run('INSERT INTO tournaments(name,description,starts_at,ends_at,chest_type,auto,active) VALUES(?,?,?,?,?,1,1)', [
        $name, 'Королевский турнир недели. Одерживайте победы, набирайте короны и забирайте сундук награды.',
        $start->format('Y-m-d H:i:s'), $start->modify('+7 day')->format('Y-m-d H:i:s'), 'tournament',
    ]);
}
function current_tournament(): ?array { ensure_weekly_tournament(); return row('SELECT * FROM tournaments WHERE active=1 ORDER BY starts_at DESC, id DESC LIMIT 1'); }
function tournament_join(int $tid, int $uid): void { run('INSERT IGNORE INTO tournament_players(tournament_id,user_id) VALUES(?,?)', [$tid, $uid]); }
function tournament_score(int $tid, int $uid, int $crowns, bool $win): void {
    run('INSERT INTO tournament_players(tournament_id,user_id,score,wins,battles) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE score=score+VALUES(score), wins=wins+VALUES(wins), battles=battles+1, updated_at=?', [$tid, $uid, $crowns, $win ? 1 : 0, now_s()]);
}
function tournament_standings(int $tid): array {
    return rows('SELECT tp.id,tp.user_id,u.username,tp.score,tp.wins,tp.battles,tp.claimed FROM tournament_players tp JOIN users u ON u.id=tp.user_id WHERE tp.tournament_id=? ORDER BY tp.score DESC, tp.wins DESC LIMIT 50', [$tid]);
}

function resolve_deck(string $deckText, array $ownedIds, array $cards): array {
    $enabled = array_flip(array_map(fn($c) => (int)$c['id'], $cards));
    $own = array_flip($ownedIds);
    $ids = [];
    foreach (explode(',', $deckText) as $x) { $n = (int)$x; if ($n && isset($own[$n]) && isset($enabled[$n]) && !in_array($n, $ids, true)) $ids[] = $n; }
    if (count($ids) < 8) {
        $rest = array_values(array_filter($cards, fn($c) => isset($own[(int)$c['id']]) && !in_array((int)$c['id'], $ids, true)));
        usort($rest, fn($a, $b) => (int)$a['cost'] <=> (int)$b['cost']);
        foreach ($rest as $c) { if (count($ids) >= 8) break; $ids[] = (int)$c['id']; }
    }
    return array_slice($ids, 0, 8);
}
