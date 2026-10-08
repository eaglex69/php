<?php
declare(strict_types=1);

function default_settings(): array {
    return ['gift_gold' => '500', 'gift_gems' => '15', 'maintenance' => 'false', 'elixir_seconds' => '2.5', 'battle_seconds' => '180', 'request_cooldown_minutes' => '30', 'pvp_enabled' => 'true'];
}
function catalog(): array { static $c = null; if ($c === null) $c = json_decode(file_get_contents(ROOT . '/data/catalog.json'), true); return $c; }

function seed_game(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (default_settings() as $k => $v) run('INSERT IGNORE INTO settings(k,v) VALUES(?,?)', [$k, $v]);
    $cat = catalog();
    if (setting('cards_version') !== (string)$cat['version']) {
        $sql = 'INSERT INTO cards(name,image,rarity,cost,damage,health,description,enabled,kind,targets,speed,rng,attack_speed,unit_count,splash,lifetime,ability,unlock_trophies,role,pros,cons) VALUES(?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE image=VALUES(image),rarity=VALUES(rarity),cost=VALUES(cost),damage=VALUES(damage),health=VALUES(health),description=VALUES(description),kind=VALUES(kind),targets=VALUES(targets),speed=VALUES(speed),rng=VALUES(rng),attack_speed=VALUES(attack_speed),unit_count=VALUES(unit_count),splash=VALUES(splash),lifetime=VALUES(lifetime),ability=VALUES(ability),unlock_trophies=VALUES(unlock_trophies),role=VALUES(role),pros=VALUES(pros),cons=VALUES(cons)';
        $st = db()->prepare($sql);
        foreach ($cat['cards'] as $c) $st->execute([$c['name'], $c['image'], $c['rarity'], $c['cost'], $c['damage'], $c['health'], $c['description'], $c['kind'], $c['targets'], $c['speed'], $c['range'], $c['attackSpeed'], $c['count'], $c['splash'], $c['lifetime'], $c['ability'], $c['unlockTrophies'], $c['role'], $c['pros'], $c['cons']]);
        run('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', ['cards_version', (string)$cat['version']]);
    }
    if (!(int)val('SELECT COUNT(*) FROM leagues')) { $i = 0; foreach ($cat['leagues'] as $l) run('INSERT INTO leagues(name,min_trophies,sort_order,chest_type,reward_gold,reward_gems,color) VALUES(?,?,?,?,?,?,?)', [$l['name'], $l['minTrophies'], $i++, $l['chestType'], $l['rewardGold'], $l['rewardGems'], $l['color']]); }
    if (!(int)val('SELECT COUNT(*) FROM arenas')) foreach ($cat['arenas'] as $a) run('INSERT INTO arenas(name,min_trophies,difficulty,description) VALUES(?,?,?,?)', [$a['name'], $a['minTrophies'], $a['difficulty'], $a['description']]);
    if (!(int)val('SELECT COUNT(*) FROM cosmetic_items')) foreach ($cat['cosmetics'] as $c) run('INSERT IGNORE INTO cosmetic_items(type,name,rarity,color1,color2,label) VALUES(?,?,?,?,?,?)', [$c['type'], $c['name'], $c['rarity'], $c['color1'], $c['color2'], $c['label']]);
    run_league_season();
}
