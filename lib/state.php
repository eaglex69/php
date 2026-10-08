<?php
declare(strict_types=1);

function public_user(array $u): array { unset($u['password_hash']); return camel($u); }
function lg_out(?array $l): ?array { return $l ? camel($l) : null; }

function game_state(?array $u): array {
    seed_game();
    $cards = camel_all(rows('SELECT * FROM cards WHERE enabled=1 ORDER BY id'));
    $leaguesRaw = rows('SELECT * FROM leagues ORDER BY sort_order, min_trophies');
    $arenasRaw = rows('SELECT * FROM arenas ORDER BY min_trophies');
    $t = current_tournament();
    $resetAt = next_league_reset()->format('Y-m-d\TH:i:s') . '.000Z';
    $state = [
        'user' => null, 'cards' => $cards, 'owned' => [], 'deck' => [], 'chests' => [],
        'leaderboard' => camel_all(rows('SELECT id,username,trophies,wins,clan_id FROM users WHERE banned=0 ORDER BY trophies DESC LIMIT 30')),
        'news' => camel_all(rows('SELECT * FROM announcements WHERE active=1 ORDER BY created_at DESC, id DESC LIMIT 5')),
        'clans' => camel_all(rows('SELECT * FROM clans ORDER BY id DESC LIMIT 40')),
        'clanMembers' => [], 'friendships' => [], 'history' => [],
        'giftSettings' => ['gold' => (int)setting('gift_gold', '500'), 'gems' => (int)setting('gift_gems', '15')],
        'battleSettings' => ['elixirSeconds' => max(0.5, (float)setting('elixir_seconds', '2.5')), 'battleSeconds' => max(30, (int)setting('battle_seconds', '180'))],
        'requestCooldownMinutes' => max(1, (int)setting('request_cooldown_minutes', '30')),
        'leagues' => ['list' => camel_all($leaguesRaw), 'resetAt' => $resetAt],
        'league' => null, 'leagueChestReady' => false, 'nextLeague' => null,
        'arenas' => camel_all($arenasRaw), 'arena' => camel(arena_for($arenasRaw, $u ? (int)$u['trophies'] : 0)),
        'cosmeticCatalog' => camel_all(rows('SELECT id,type,name,rarity,color1,color2,label,image FROM cosmetic_items WHERE enabled=1 ORDER BY id')),
        'tournament' => $t ? ['id' => (int)$t['id'], 'name' => $t['name'], 'description' => $t['description'], 'startsAt' => iso($t['starts_at']), 'endsAt' => iso($t['ends_at']), 'chestType' => $t['chest_type']] : null,
        'tournamentStandings' => [], 'tournamentMe' => null,
        'pvp' => ['enabled' => setting('pvp_enabled', 'true') !== 'false', 'attacks' => []],
    ];
    if (!$u) return $state;
    $uid = (int)$u['id'];
    $owned = camel_all(rows('SELECT card_id,level,copies FROM owned_cards WHERE user_id=?', [$uid]));
    $state['user'] = public_user($u);
    $state['owned'] = $owned;
    $state['deck'] = resolve_deck((string)$u['deck'], array_map(fn($o) => (int)$o['cardId'], $owned), rows('SELECT id,cost FROM cards WHERE enabled=1'));
    $state['chests'] = camel_all(rows('SELECT id,type,source,created_at FROM chests WHERE user_id=? AND opened_at IS NULL ORDER BY created_at, id LIMIT 30', [$uid]));
    if ($u['clan_id']) $state['clanMembers'] = camel_all(rows('SELECT id,username,trophies,role FROM users WHERE clan_id=? ORDER BY trophies DESC', [$u['clan_id']]));
    $state['friendships'] = camel_all(rows('SELECT f.id,f.requester_id,f.recipient_id,f.status,a.username AS requester,b.username AS recipient FROM friends f JOIN users a ON a.id=f.requester_id JOIN users b ON b.id=f.recipient_id WHERE f.requester_id=? OR f.recipient_id=?', [$uid, $uid]));
    $state['history'] = camel_all(rows('SELECT * FROM battles WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT 20', [$uid]));
    $lg = league_for($leaguesRaw, (int)$u['trophies']);
    $state['league'] = lg_out($lg);
    $state['leagueChestReady'] = $lg !== null && $u['league_chest_season'] !== gmdate('Y-m');
    $nl = next_league_of($leaguesRaw, (int)$u['trophies']);
    $state['nextLeague'] = $nl ? ['name' => $nl['name'], 'minTrophies' => (int)$nl['min_trophies']] : null;
    if ($t) {
        $st = camel_all(tournament_standings((int)$t['id']));
        $state['tournamentStandings'] = $st;
        foreach ($st as $r) if ((int)$r['userId'] === $uid) $state['tournamentMe'] = $r;
    }
    $state['pvp']['attacks'] = camel_all(rows('SELECT m.id,m.attacker_id,u.username,m.attacker_crowns,m.defender_crowns,m.result,m.trophies,m.created_at FROM pvp_matches m JOIN users u ON u.id=m.attacker_id WHERE m.defender_id=? AND m.seen=0 ORDER BY m.created_at DESC LIMIT 20', [$uid]));
    return $state;
}
