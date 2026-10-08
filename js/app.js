/* Crownfall · клиент PHP-версии: чистый JavaScript + AJAX (fetch) + Canvas. Без фреймворков. */
(function () {
'use strict';
const C = window.Crown, CF = window.CF = {};
const $ = (s, r) => (r || document).querySelector(s);
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = (n) => Number(n || 0).toLocaleString('ru-RU');
const S = CF.S = { data: null, nav: 'lobby', busy: false, deckSlot: 0, shopFilter: 'Все', rankTab: 'players', chatTab: 'global', more: false, modal: null, authMode: 'register', authError: '', rival: { look: {}, emotes: [] }, pvpOpp: null, reveal: null };
CF.esc = esc; CF.fmt = fmt; CF.$ = $;

async function api(route, body, form) {
  const o = { method: body || form ? 'POST' : 'GET', headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin', cache: 'no-store' };
  if (form) o.body = form; else if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
  let r, j = {};
  try { r = await fetch('api.php?r=' + route, o); j = await r.json(); } catch (e) { return { ok: false, status: 0, j: { error: 'Нет соединения с сервером.' } }; }
  return { ok: r.ok, status: r.status, j };
}
CF.api = api;
let toastT = 0;
function toast(m) { const el = $('#toast-root'); el.innerHTML = `<div class="toast-message">${esc(m)}<button data-act="toast-x">×</button></div>`; clearTimeout(toastT); toastT = setTimeout(() => el.innerHTML = '', 4500); }
CF.toast = toast;
async function refresh() { const r = await api('game'); if (r.ok) { S.data = r.j; render(); } }
CF.refresh = refresh;
async function action(payload, silent) {
  S.busy = true; const r = await api('game', payload); S.busy = false;
  if (!silent) toast(r.j.message || r.j.error || 'Ошибка');
  if (r.status === 401) { await refresh(); return null; }
  if (r.ok) { await refresh(); return r.j; }
  return null;
}
CF.action = action;

// ---------- графические примитивы (чистый CSS, без эмодзи и SVG) ----------
const ico = (n, s = 28, cls = '') => `<span class="ico ico-${n} ${cls}" style="width:${s}px;height:${s}px" aria-hidden="true"><i class="p1"></i><i class="p2"></i><i class="p3"></i><i class="p4"></i></span>`;
const art = (image, alt) => { const a = C.parseArt(image); return `<span role="img" aria-label="${esc(alt)}" class="card-art"><i style="background-image:url('${esc(a.src.replace(/^\//, ''))}');${a.hue ? 'filter:hue-rotate(' + a.hue + 'deg)' : ''}"></i></span>`; };
CF.art = art; CF.ico = ico;
function cosView(it, size) {
  size = size || 'md';
  const st = `--c1:${esc(it.color1)};--c2:${esc(it.color2)}`, img = it.image ? `background-image:url('${esc(it.image)}');background-size:cover;background-position:center` : '';
  if (it.type === 'banner') return `<span class="cos cos-banner ${size}" style="${st}"><i class="pole"></i><i class="cloth" style="${img}">${it.image ? '' : '<b>' + esc(it.label) + '</b>'}</i></span>`;
  if (it.type === 'towerSkin') return `<span class="cos cos-tower ${size}" style="${st}"><i class="t-top" style="${img}"></i><i class="t-body"></i><i class="t-door"></i><i class="t-crown"></i></span>`;
  return `<span class="cos cos-emote ${size}" style="${st}">${it.image ? '<i class="e-img" style="' + img + '"></i>' : '<b>' + esc(it.label || it.name) + '</b>'}</span>`;
}
CF.cosView = cosView;
function tile(c, o) {
  o = o || {}; const lv = o.level || 1;
  return `<button class="card-tile ${o.small ? 'small ' : ''}${c.rarity.toLowerCase()}${o.locked ? ' locked' : ''}${o.selected ? ' selected-slot' : ''}" data-act="${o.act || 'card-info'}" data-id="${c.id}"><span class="card-cost">${c.cost}</span>${art(c.image, c.name)}<span class="card-tile-name">${esc(c.name)}</span><span class="card-level">${o.locked ? 'Закрыта' : 'Ур. ' + lv + ' <b>' + (o.copies || 0) + '/' + lv * 10 + '</b>'}</span></button>`;
}

// ---------- данные ----------
const D = () => S.data, U = () => S.data.user;
const ownedOf = (id) => D().owned.find((o) => o.cardId === id);
const cardById = (id) => D().cards.find((c) => c.id === id);
const deckCards = () => D().deck.map(cardById).filter(Boolean);
const catalogBy = (type, name) => D().cosmeticCatalog.find((c) => c.type === type && c.name === name);
const NAV = [['lobby', 'Арена', 'Главная', 'swords'], ['cards', 'Карты', 'Моя колода', 'cards'], ['shop', 'Магазин', 'Особые предложения', 'chest'], ['chests', 'Сундуки', 'Награды и сокровища', 'chest'], ['cosmetics', 'Косметика', 'Облики, знамёна, эмоции', 'star'], ['leagues', 'Лиги', 'Лига и сезон', 'trophy'], ['pvp', 'PvP-арена', 'Бои с игроками', 'swords'], ['tournament', 'Турнир', 'Бои недели', 'trophy'], ['clan', 'Кланы', 'Ваша команда', 'shield'], ['chat', 'Чат', 'Общение', 'chat'], ['friends', 'Друзья', 'Играйте вместе', 'friends'], ['ranking', 'Рейтинг', 'Лучшие игроки', 'trophy'], ['history', 'История', 'Прошлые бои', 'scroll']];
const PRIMARY = ['lobby', 'cards', 'shop', 'clan', 'chat'];
const CHEST_TITLE = { wood: 'Деревянный сундук', silver: 'Серебряный сундук', gold: 'Золотой сундук', magic: 'Магический сундук', legendary: 'Легендарный сундук', tournament: 'Турнирный сундук' };
const chestImg = '<img src="images/chest.jpg" alt="Сундук">';

// ---------- вход / регистрация ----------
function pageAuth() {
  const reg = S.authMode === 'register';
  return `<div class="splash"><div class="splash-art"><div class="splash-glow"></div><div class="splash-bolt"></div><div class="splash-petals">${Array.from({ length: 14 }, (_, i) => `<b style="left:${(i * 37) % 100}%;animation-delay:${(i * 0.9) % 7}s;animation-duration:${7 + (i % 5)}s"></b>`).join('')}</div></div>
  <div class="splash-content"><div class="logo-block"><img src="images/logo.png" alt="Crownfall" style="width:min(74vw,360px);height:auto;filter:drop-shadow(0 8px 24px rgba(120,80,255,.5))"></div>
  <div class="splash-card"><div class="auth-switch neon"><button class="${reg ? 'active' : ''}" data-act="auth-mode" data-id="register">Регистрация</button><button class="${reg ? '' : 'active'}" data-act="auth-mode" data-id="login">Вход</button></div>
  <form class="auth-form" data-form="auth">${reg ? '<label>Имя игрока<input class="field neon" name="username" placeholder="Как вас будут звать на арене?" required minlength="3" maxlength="20" autocomplete="nickname"></label>' : ''}
  <label>Электронная почта<input class="field neon" type="email" name="email" placeholder="you@example.com" required autocomplete="email"></label>
  <label>Пароль<input class="field neon" type="password" name="password" placeholder="${reg ? 'Не менее 8 символов' : 'Ваш пароль'}" required minlength="${reg ? 8 : 1}" autocomplete="${reg ? 'new-password' : 'current-password'}"></label>
  ${S.authError ? `<div class="form-error">${esc(S.authError)}</div>` : ''}<button class="btn btn-play full" type="submit">${ico('swords', 22)}${reg ? 'НАЧАТЬ ИГРУ' : 'ВОЙТИ В ИГРУ'}</button></form>
  <small class="auth-foot">${reg ? 'Уже есть аккаунт?' : 'Нет аккаунта?'} <button data-act="auth-mode" data-id="${reg ? 'login' : 'register'}">${reg ? 'Войти' : 'Зарегистрироваться'}</button></small></div>
  <div class="splash-features"><span>${ico('cards', 20)} ${D().cards.length} карт</span><span>${ico('shield', 20)} Кланы</span><span>${ico('trophy', 20)} Рейтинг</span><span>${ico('chest', 20)} Подарки</span></div></div></div>`;
}

// ---------- оболочка ----------
function shell() {
  const u = U(), clan = D().clans.find((c) => c.id === u.clanId), lvl = Math.max(1, Math.floor(u.xp / 500) + 1);
  const more = NAV.filter((n) => !PRIMARY.includes(n[0])).concat(u.role === 'admin' ? [['admin', 'Админ', 'Управление', 'gear']] : []);
  return `<div class="app-shell"><div class="ambient ambient-one"></div><div class="ambient ambient-two"></div><div class="app-frame">
  <header class="topbar"><div class="brand" data-act="nav" data-id="lobby"><img src="images/logo.png" alt="Crownfall" style="height:46px;width:auto"></div><div class="topbar-center"><span class="season-dot"></span> СЕЗОН 01 <span class="top-divider"></span> ПУТЬ К ЛЕГЕНДЕ</div>
  <div class="topbar-actions"><div class="currency"><span class="coin-icon"></span>${fmt(u.gold)}<button data-act="nav" data-id="shop">+</button></div><div class="currency"><span class="gem-icon"></span>${fmt(u.gems)}<button data-act="nav" data-id="shop">+</button></div><button class="header-avatar" title="Выйти" data-act="logout">${esc(u.username[0].toUpperCase())}<span>Выйти</span></button></div></header>
  <div class="layout"><aside class="sidebar"><div class="profile-mini"><div class="avatar-frame">${esc(u.username[0].toUpperCase())}</div><div class="profile-mini-copy"><strong>${esc(u.username)}</strong><span>Уровень ${lvl} · ${esc(clan ? clan.name : 'Без клана')}</span></div></div><div class="sidebar-label">ИГРА</div>
  <nav class="side-nav">${NAV.map((n) => `<button class="nav-item ${S.nav === n[0] ? 'active' : ''}" data-act="nav" data-id="${n[0]}"><span class="nav-icon">${ico(n[3], 24)}</span><span class="nav-copy"><strong>${n[1]}</strong><small>${n[2]}</small></span>${S.nav === n[0] ? '<span class="nav-arrow">›</span>' : ''}</button>`).join('')}</nav>
  ${u.role === 'admin' ? `<div class="sidebar-label admin-label">УПРАВЛЕНИЕ</div><button class="nav-item admin-nav ${S.nav === 'admin' ? 'active' : ''}" data-act="nav" data-id="admin"><span class="nav-icon">${ico('gear', 24)}</span><span class="nav-copy"><strong>Админ-панель</strong><small>Управление игрой</small></span></button>` : ''}
  <div class="sidebar-footer"><div class="sidebar-tip">${ico('star', 14, 'tip-spark')}<strong>Совет дня</strong><p>Побеждайте в боях, чтобы зарабатывать кубки и подниматься в рейтинге.</p></div><span>© 2026 CROWNFALL</span></div></aside>
  <main class="main-content" id="page">${page()}</main></div>
  <footer class="mobile-nav">${NAV.filter((n) => PRIMARY.includes(n[0])).map((n) => `<button class="${S.nav === n[0] ? 'active' : ''}" data-act="nav" data-id="${n[0]}"><span>${ico(n[3], 24)}</span>${n[1]}</button>`).join('')}<button class="${S.more || more.some((m) => m[0] === S.nav) ? 'active' : ''}" data-act="more"><span>${ico('more', 24)}</span>Ещё</button></footer>
  ${S.more ? `<div class="more-sheet-backdrop" data-act="more-close"><div class="more-sheet">${more.map((m) => `<button class="${S.nav === m[0] ? 'active' : ''}" data-act="nav" data-id="${m[0]}"><span>${ico(m[3], 28)}</span>${m[1]}</button>`).join('')}</div></div>` : ''}</div>`;
}

// ---------- страницы ----------
function head(eyebrow, title, text, chip) { return `<div class="section-heading"><div><span class="eyebrow">${eyebrow}</span><h1>${title}</h1><p>${text}</p></div>${chip ? `<div class="heading-chip">${chip}</div>` : ''}</div>`; }
function page() {
  const fn = { lobby: pLobby, cards: pCards, shop: pShop, chests: pChests, cosmetics: pCosmetics, leagues: pLeagues, pvp: pPvp, tournament: pTournament, clan: pClan, chat: pChat, friends: pFriends, ranking: pRanking, history: pHistory, admin: () => CF.pageAdmin() }[S.nav] || pLobby;
  return fn();
}
function avgCost(list) { return list.length ? (list.reduce((a, c) => a + c.cost, 0) / list.length).toFixed(1) : '0.0'; }

function pLobby() {
  const d = D(), u = U(), ai = Math.max(0, d.arenas.findIndex((a) => a.id === d.arena.id)), na = d.arenas[ai + 1];
  const canGift = !u.lastGiftAt || Date.now() - new Date(u.lastGiftAt).getTime() >= 864e5, deck = deckCards();
  const news = d.news.length ? d.news.slice(0, 2).map((n) => `<div class="news-item"><strong>${esc(n.title)}</strong><p>${esc(n.body)}</p></div>`).join('') : '<div class="news-item"><strong>Добро пожаловать в Crownfall!</strong><p>Соберите свою первую колоду и отправляйтесь навстречу приключениям на королевской арене.</p></div>';
  return `<div class="lobby-grid"><div class="lobby-primary"><section class="arena-panel"><div class="arena-banner"><div class="arena-banner-copy"><span class="arena-overline">АРЕНА ${ai + 1} · СЛОЖНОСТЬ ${d.arena.difficulty}/10</span><h2>${esc(d.arena.name)}</h2><p>${esc(d.arena.description)}</p></div><div class="arena-banner-stats">${ico('trophy', 22, 'trophy-icon')}<strong>${fmt(u.trophies)}</strong><small>КУБКОВ</small></div></div>
  <div class="arena-art"><div class="arena-vignette"></div><div class="arena-flag enemy">ВРАГ</div><div class="arena-flag friendly">ВАША БАШНЯ</div><div class="arena-center-badge"><span class="crossed">×</span><span>КОРОЛЕВСКАЯ<br>АРЕНА</span></div></div>
  <div class="arena-bottom"><div class="arena-rating">${ico('trophy', 22, 'trophy-icon')}<div><strong>${fmt(u.trophies)} / ${na ? fmt(na.minTrophies) : 'MAX'}</strong><small>До следующей арены</small></div></div><button class="btn btn-green battle-cta" data-act="battle">${ico('swords', 30)} В БОЙ</button></div></section>
  <div class="section-title-row"><div><span class="eyebrow">В ВАШЕМ РАСПОРЯЖЕНИИ</span><h3>Сундуки с наградами</h3></div><button class="text-link" data-act="nav" data-id="chests">Мои сундуки →</button></div>
  <div class="chest-row"><div class="chest-card"><div class="chest-art">${chestImg}</div><div><strong>Бесплатный сундук</strong><span>Ежедневная награда</span></div><button class="chest-open" data-act="gift" ${canGift ? '' : 'disabled'}>${canGift ? 'Открыть' : 'Получено'}</button></div>
  <div class="chest-card"><div class="chest-art">${chestImg}</div><div><strong>Сундуки за победы</strong><span>Сейчас в запасе: ${d.chests.length}</span></div><button class="chest-open" data-act="nav" data-id="chests">Открыть</button></div></div></div>
  <aside class="lobby-right"><div class="right-panel daily-panel"><div class="panel-heading"><span class="panel-mini-icon gold">${ico('chest', 26)}</span><div><span class="eyebrow">КАЖДЫЙ ДЕНЬ</span><h3>Ежедневный подарок</h3></div></div><div class="gift-visual">${chestImg}<div class="gift-rays"></div></div><p>Заходите каждый день и получайте награды для новых побед.</p><div class="gift-rewards"><span><i class="coin-icon"></i> ${fmt(d.giftSettings.gold)}</span><span><i class="gem-icon"></i> ${fmt(d.giftSettings.gems)}</span></div><button class="btn btn-gold full" data-act="gift" ${canGift ? '' : 'disabled'}>${canGift ? 'Забрать награду' : 'Уже получено сегодня'}</button></div>
  <div class="right-panel deck-preview"><div class="right-heading"><h3>Боевая колода</h3><button class="text-link" data-act="nav" data-id="cards">Все карты →</button></div><div class="mini-deck">${deck.map((c) => `<button data-act="card-info" data-id="${c.id}">${art(c.image, c.name)}<span>${c.cost}</span></button>`).join('')}</div><div class="deck-footer">Средняя стоимость: <strong>${avgCost(deck)}</strong><span class="elixir-drop"></span></div></div>
  <div class="right-panel news-preview"><div class="right-heading"><h3>Новости арены</h3><span class="news-live">НОВОЕ</span></div>${news}</div></aside></div>`;
}

function reqClock() {
  const u = U(), cd = D().requestCooldownMinutes * 60000, left = u.lastRequestAt ? Math.max(0, new Date(u.lastRequestAt).getTime() + cd - Date.now()) : 0;
  return { left, text: String(Math.floor(left / 60000)).padStart(2, '0') + ':' + String(Math.floor(left / 1000) % 60).padStart(2, '0') };
}
function pCards() {
  const d = D(), deck = deckCards(), own = new Map(d.owned.map((o) => [o.cardId, o])), mine = d.cards.filter((c) => own.has(c.id)), rc = reqClock();
  return head('БОЕВОЙ НАБОР', 'Моя колода', 'Нажмите слот, затем карту в коллекции — замена сохранится сразу. Карта, уже стоящая в колоде, поменяется местами.', deck.length + ' / 8 карт') +
  `<div class="collection-panel deck-editor-panel"><div class="collection-head"><div><h3>Выберите карты для боя</h3><span>Слот ${S.deckSlot + 1} выбран · средняя стоимость ${avgCost(deck)}</span></div><div class="collection-head-actions"><button class="btn btn-outline" data-act="nav" data-id="chests">Открыть сундуки</button><button class="btn btn-gold req-btn" id="req-btn" data-act="req-open">${rc.left > 0 ? 'Попросить через ' + rc.text : 'Попросить фрагмент'}</button></div></div>
  <div class="cards-grid deck-grid">${Array.from({ length: 8 }, (_, i) => { const c = deck[i]; if (!c) return `<div class="empty-deck-slot ${S.deckSlot === i ? 'selected' : ''}" data-act="deck-slot" data-id="${i}">СЛОТ ${i + 1}<small>Выберите карту</small></div>`; const o = own.get(c.id); return tile(c, { level: o.level, copies: o.copies, selected: S.deckSlot === i, act: 'deck-slot' }).replace(`data-id="${c.id}"`, `data-id="${i}"`); }).join('')}</div></div>
  <div class="collection-panel spaced-panel"><div class="collection-head"><div><h3>Доступные карты</h3><span>Нажмите на любую карту, чтобы заменить слот ${S.deckSlot + 1}. Карты из вашей колоды переставляются местами.</span></div></div><div class="cards-grid collection-grid deck-pick-grid">${mine.map((c) => { const o = own.get(c.id), at = d.deck.indexOf(c.id); return `<div class="deck-pick-cell">${tile(c, { level: o.level, copies: o.copies, selected: at === S.deckSlot, act: 'deck-pick' })}<small>${at >= 0 ? 'В колоде · слот ' + (at + 1) : 'Нажмите, чтобы поставить'}</small><button class="tiny-btn" data-act="card-info" data-id="${c.id}">Подробнее</button></div>`; }).join('')}</div></div>
  <details class="collection-panel locked-collection"><summary>Все карты арены · ${d.cards.length} карт, включая ещё не открытые</summary><div class="cards-grid collection-grid">${d.cards.filter((c) => !own.has(c.id)).map((c) => tile(c, { locked: true })).join('')}</div></details>`;
}

function chestShop() {
  return `<div class="chest-shop-grid">${[['silver', 'Серебряный', '450', 'coin-icon'], ['gold', 'Золотой', '1 250', 'coin-icon'], ['magic', 'Магический', '85', 'gem-icon'], ['legendary', 'Легендарный', '300', 'gem-icon']].map((c) => `<div class="chest-shop-card"><img src="images/chest.jpg" alt=""><strong>${c[1]}</strong><button class="btn btn-gold" data-act="buy-chest" data-id="${c[0]}"><span class="${c[3]}"></span>${c[2]}</button></div>`).join('')}</div>`;
}
function pShop() {
  const d = D(), own = new Map(d.owned.map((o) => [o.cardId, o])), f = S.shopFilter;
  return head('КОРОЛЕВСКАЯ ЛАВКА', 'Магазин', 'Пополните коллекцию и укрепите свою армию.') + `<div class="shop-hero"><div><span class="eyebrow">ВСЕ КАРТЫ ИГРЫ</span><h2>Время усилить колоду</h2><p>Каждая купленная карта открывает нового бойца и даёт копии для улучшения.</p></div><img src="images/chest.jpg" alt="Сундук"></div>
  <div class="filter-row">${['Все', 'Обычная', 'Редкая', 'Эпическая', 'Легендарная', 'Чемпион'].map((x) => `<button class="${f === x ? 'active' : ''}" data-act="shop-filter" data-id="${x}">${x === 'Все' ? 'Все карты' : x}</button>`).join('')}</div>
  <div class="shop-grid">${d.cards.filter((c) => f === 'Все' || c.rarity === f).map((c) => { const o = own.get(c.id); return `<div class="shop-item">${tile(c, { locked: !o, level: o && o.level, copies: o && o.copies })}<strong>${esc(c.name)}</strong><span class="rarity-label">${c.rarity} · ${esc(c.role)}</span><button class="btn btn-gold full" data-act="buy" data-id="${c.id}"><i class="coin-icon"></i>${C.cardPrice(c.rarity)}</button></div>`; }).join('')}</div>
  <div class="section-title-row"><div><span class="eyebrow">СОКРОВИЩНИЦА</span><h3>Сундуки</h3><p class="muted">Покупайте сундуки за золото или кристаллы.</p></div></div>${chestShop()}`;
}

function pChests() {
  const d = D(), u = U(), wl = { common: 'обычные', rare: 'редкие', epic: 'эпические', legendary: 'легендарные', champion: 'чемпионы' };
  return head('НАГРАДЫ ЗА ПОБЕДЫ', 'Сундуки', 'Открывайте сундуки за бои, покупайте их или забирайте турнирные награды.', d.chests.length + ' закрыто') +
  `<div class="chest-wallet"><div><span class="coin-icon"></span><b>${fmt(u.gold)}</b><small>Золото</small></div><div><span class="gem-icon"></span><b>${fmt(u.gems)}</b><small>Кристаллы</small></div><div class="evolution-wallet"><b>${fmt(u.evolutionFragments)}</b><small>Фрагменты эволюции</small></div>${Object.entries(u.wildcards || {}).map(([k, v]) => `<div class="wildcard-wallet"><b>${fmt(v)}</b><small>Дикие карты · ${wl[k] || k}</small></div>`).join('')}</div>` +
  (d.chests.length ? `<div class="chest-inventory">${d.chests.map((ch) => `<div class="inventory-chest"><div class="big-chest ${ch.type}"><img src="images/chest.jpg" alt="Сундук"></div><div class="inventory-chest-copy"><span class="eyebrow">${ch.source === 'victory' ? 'ЗА ПОБЕДУ' : ch.source === 'shop' ? 'ПОКУПКА' : ch.source === 'league' ? 'ЗА ЛИГУ' : ch.source.includes('tournament') ? 'ТУРНИР' : 'НАГРАДА'}</span><h3>${CHEST_TITLE[ch.type] || 'Сундук'}</h3><small>${new Date(ch.createdAt).toLocaleDateString('ru-RU')}</small></div><button class="btn btn-gold" data-act="open-chest" data-id="${ch.id}" data-type="${ch.type}">Открыть</button></div>`).join('')}</div>`
    : `<div class="panel empty-chests"><img src="images/chest.jpg" alt="Пустой сундук"><h3>Пока сундуков нет</h3><p>Побеждайте на арене, покупайте сундуки или участвуйте в турнирах.</p><button class="btn btn-green" data-act="nav" data-id="lobby">В бой</button></div>`) +
  `<div class="section-title-row"><div><span class="eyebrow">МАГАЗИН СУНДУКОВ</span><h3>Сундуки за валюту</h3></div></div>${chestShop()}<div class="collection-panel cosmetic-panel"><h3>Косметика</h3><p class="muted">Облики башен, боевые знамёна и эмоции выпадают из сундуков. У вас предметов: ${(u.cosmetics || []).length} из ${d.cosmeticCatalog.length}.</p><button class="btn btn-gold" data-act="nav" data-id="cosmetics">Открыть коллекцию косметики</button></div>`;
}

function pCosmetics() {
  const d = D(), u = U();
  return head('СТИЛЬ ИГРОКА', 'Косметика', 'Выбирайте облик башни, боевое знамя и эмоции — они видны в бою. Новые предметы выпадают из сундуков.', (u.cosmetics || []).length + ' / ' + d.cosmeticCatalog.length) +
  [['towerSkin', 'Облики башни'], ['banner', 'Боевые знамёна'], ['emote', 'Эмоции (до 6 в бою)']].map(([type, title]) => {
    const items = d.cosmeticCatalog.filter((c) => c.type === type), owned = new Set((u.cosmetics || []).filter((c) => c.type === type).map((c) => c.name));
    const on = (n) => type === 'towerSkin' ? u.towerSkin === n : type === 'banner' ? u.bannerSkin === n : (u.equippedEmotes || []).includes(n);
    return `<div class="collection-panel cosmetic-section"><div class="collection-head"><div><h3>${title}</h3><span>${owned.size} из ${items.length} получено</span></div></div><div class="cosmetic-grid">${items.map((it) => { const have = owned.has(it.name), act = on(it.name); return `<div class="cosmetic-card ${have ? '' : 'locked '}${act ? 'on' : ''}">${cosView(it)}<strong>${esc(it.name)}</strong><small class="rar-${it.rarity}">${it.rarity}</small>${have ? `<button class="btn ${act ? 'btn-outline' : 'btn-gold'}" data-act="equip" data-type="${type}" data-name="${esc(it.name)}" data-off="${act ? 1 : 0}">${act ? 'Снять' : 'Надеть'}</button>` : '<span class="cos-locked">Выпадает из сундуков</span>'}</div>`; }).join('') || '<p class="muted">Предметов этого типа пока нет.</p>'}</div></div>`;
  }).join('');
}

function pLeagues() {
  const d = D(), u = U(), lg = d.league, nl = d.nextLeague;
  return head('СЕЗОННАЯ ЛИГА', 'Лиги', 'Ваша лига зависит от кубков. За первое попадание в лигу вы получаете сундук и награды.', 'СБРОС ' + new Date(d.leagues.resetAt).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })) +
  `<div class="league-hero league-${lg ? lg.color : 'blue'}"><div class="league-cup">${ico('trophy', 44)}</div><div class="league-hero-copy"><span class="eyebrow">ВАША ЛИГА</span><h2>${esc(lg ? lg.name : 'Без лиги')}</h2><p>${nl ? 'До лиги «' + esc(nl.name) + '»: ' + Math.max(0, nl.minTrophies - u.trophies) + ' кубков' : 'Вы в высшей лиге сезона!'}</p><div class="league-bar"><i style="width:${nl ? Math.min(100, u.trophies / (nl.minTrophies || 1) * 100) : 100}%"></i></div><small>${fmt(u.trophies)} кубков</small></div>
  <div class="league-actions">${d.leagueChestReady && lg ? '<button class="btn btn-gold" data-act="league-claim">Забрать награду лиги</button>' : '<span class="league-claimed">Награда за сезон получена</span>'}<button class="btn btn-outline" data-act="nav" data-id="ranking">Рейтинг игроков</button></div></div>
  <div class="panel padded league-list-panel"><h3>Все лиги сезона</h3><div class="league-ladder">${d.leagues.list.map((l) => `<div class="league-step ${lg && l.id === lg.id ? 'current' : ''}"><span class="league-emblem lg-${l.color}">${esc(l.name[0])}</span><div class="league-step-copy"><strong>${esc(l.name)}</strong><small>От ${fmt(l.minTrophies)} кубков · сундук: ${l.chestType} · ${fmt(l.rewardGold)} золота</small></div>${lg && l.id === lg.id ? '<span class="league-badge">ВЫ ЗДЕСЬ</span>' : ''}</div>`).join('')}</div><p class="muted league-note">В первый понедельник каждого месяца сезон обновляется: кубки всех игроков уменьшаются на 30%, а награды за лиги можно получить заново.</p></div>`;
}

function pPvp() {
  const d = D(), at = d.pvp.attacks;
  return head('БОИ С РЕАЛЬНЫМИ ИГРОКАМИ', 'PvP-арена', 'Ваша колода защищает башню, а вы атакуете колоды других игроков. Результаты сохраняются для обеих сторон.', at.length ? at.length + ' новых атак' : 'Атак нет') +
  `<div class="pvp-arena-panel"><div class="pvp-arena-art"><div class="pvp-glow"></div><div class="pvp-badge">${ico('swords', 38)}</div><div class="pvp-arena-copy"><span class="eyebrow">НАЙТИ ПРОТИВНИКА</span><h2>${d.pvp.enabled ? 'Готовы к бою?' : 'PvP отключено'}</h2><p>${d.pvp.enabled ? 'Подберём игрока с похожим количеством кубков. Вы увидите его настоящую колоду.' : 'Администратор временно отключил PvP-арену.'}</p><button class="btn btn-green" data-act="pvp-find" ${d.pvp.enabled ? '' : 'disabled'}>Найти противника</button></div></div></div>
  <div class="two-columns pvp-columns"><div class="panel padded"><h3>Вашу башню атаковали <span class="count-badge">${at.length}</span></h3>${at.map((a) => `<div class="pvp-attack"><span class="rank-avatar">${esc(a.username[0].toUpperCase())}</span><div class="grow"><strong>${esc(a.username)}</strong><small>${new Date(a.createdAt).toLocaleString('ru-RU')} · ${a.attackerCrowns}:${a.defenderCrowns}</small></div><span class="history-result ${a.result === 'win' ? 'loss' : a.result === 'loss' ? 'win' : 'draw'}">${a.result === 'win' ? 'ОН ПОБЕДИЛ' : a.result === 'loss' ? 'ВЫ ОТБИЛИ' : 'НИЧЬЯ'}</span></div>`).join('') || '<div class="empty-state">Пока никто не атаковал вашу башню.</div>'}<button class="tiny-btn" data-act="pvp-seen">Отметить как прочитанные</button></div>
  <div class="panel padded"><h3>Как работает PvP</h3><p class="muted">Противник выбирается среди реальных игроков с похожими кубками. В бою используется его настоящая колода и уровни карт, а ваша колода защищает башню.</p><p class="muted">За победу вы получаете кубки, золото и сундук. Проигравший теряет кубки.</p><button class="btn btn-outline" data-act="nav" data-id="cards">Изменить колоду</button></div></div>`;
}

function pTournament() {
  const d = D(), t = d.tournament, me = d.tournamentMe;
  if (!t) return head('ТУРНИР НЕДЕЛИ', 'Турнир', 'Еженедельный турнир создаётся автоматически.', 'НЕТ ТУРНИРА') + '<div class="panel empty-chests"><img src="images/chest.jpg" alt=""><h3>Турнир недоступен</h3><p>Новый турнир недели создаётся автоматически.</p></div>';
  const ended = new Date(t.endsAt).getTime() <= Date.now();
  return head('ТУРНИР НЕДЕЛИ', esc(t.name), esc(t.description), 'До ' + new Date(t.endsAt).toLocaleDateString('ru-RU')) +
  `<div class="tournament-hero"><div class="tournament-badge">${ico('trophy', 42)}</div><div class="tournament-info"><span class="eyebrow">ПРИЗОВОЙ СУНДУК</span><h2>Турнирная таблица</h2><p>Одерживайте победы в турнирном режиме, чтобы набирать короны. Топ-3 — легендарный сундук, топ-10 — магический, остальным — турнирный.</p><div class="tourney-progress"><span>Ваш результат: <b>${me ? me.score + ' корон · ' + me.wins + ' побед' : 'нет боёв'}</b></span><div><i style="width:${Math.min(100, ((me ? me.score : 0) / 30) * 100)}%"></i></div></div></div>
  <div class="tournament-actions"><button class="btn btn-green" data-act="battle-tournament">Турнирный бой</button>${ended ? '<button class="btn btn-gold" data-act="tournament-claim">Забрать награду</button>' : ''}<button class="btn btn-outline" data-act="nav" data-id="chests">Мои сундуки (${d.chests.length})</button></div></div>
  <div class="panel padded tournament-table"><h3>Участники <span class="count-badge">${d.tournamentStandings.length}</span></h3>${d.tournamentStandings.slice(0, 20).map((r, i) => `<div class="rank-row ${r.userId === U().id ? 'me' : ''}"><span class="rank-number">${String(i + 1).padStart(2, '0')}</span><div class="rank-avatar">${esc(r.username[0].toUpperCase())}</div><div class="rank-person"><strong>${esc(r.username)}${r.userId === U().id ? ' <em>ВЫ</em>' : ''}</strong><small>Побед: ${r.wins} · боёв: ${r.battles}</small></div><span class="rank-score">${ico('trophy', 22, 'trophy-icon')}${r.score}</span></div>`).join('') || '<div class="empty-state">Пока нет участников. Станьте первым!</div>'}</div>`;
}

function pClan() {
  const d = D(), u = U(), my = d.clans.find((c) => c.id === u.clanId);
  const hd = head('СИЛА В ЕДИНСТВЕ', 'Кланы', 'Объединяйтесь с друзьями и покоряйте арену вместе.');
  if (my) return hd + `<div class="clan-hero"><span class="clan-emblem large ${my.badge}">C</span><div><span class="eyebrow">ВАШ КЛАН</span><h2>${esc(my.name)}</h2><p>${esc(my.description || 'Одна команда. Одна цель. Победа.')}</p></div><button class="btn btn-outline" data-act="clan-leave">Покинуть</button></div>
  <div class="two-columns"><div class="panel padded"><h3>Участники <span class="count-badge">${d.clanMembers.length}</span></h3>${d.clanMembers.map((m) => `<div class="simple-row"><span class="rank-avatar">${esc(m.username[0])}</span><strong>${esc(m.username)} ${m.id === my.ownerId ? '<small>Лидер</small>' : ''}</strong><span class="rank-score">${ico('trophy', 22, 'trophy-icon')} ${m.trophies}</span></div>`).join('')}</div>
  <div class="panel padded clan-chat"><h3>Чат клана</h3>${chatBox('clan')}</div></div>`;
  return hd + `<div class="two-columns"><div class="panel padded"><h3>Найти клан</h3><input class="field" id="clan-search" placeholder="Поиск клана"><div class="clan-list" id="clan-list">${clanList('')}</div></div>
  <div class="panel padded"><h3>Создать клан</h3><p class="muted">Соберите команду единомышленников за 1 000 золота.</p><form class="form-stack" data-form="createClan"><label>Название клана<input class="field" name="name" placeholder="Например, Легенды арены" required minlength="3" maxlength="25"></label><label>Описание<textarea class="field" name="description" rows="3" placeholder="Расскажите о вашем клане"></textarea></label><button class="btn btn-gold full" type="submit">Создать за 1 000 золота</button></form></div></div>`;
}
function clanList(q) { const l = D().clans.filter((c) => c.name.toLowerCase().includes(q.toLowerCase())); return l.map((c) => `<div class="simple-row"><span class="clan-emblem ${c.badge}">C</span><div class="grow"><strong>${esc(c.name)}</strong><small>${esc(c.description || 'Клан арены')}</small></div><button class="tiny-btn" data-act="clan-join" data-id="${c.id}">Вступить</button></div>`).join('') || '<p class="muted">Пока нет кланов. Создайте первый!</p>'; }

function chatBox(scope) { return `<div class="chat-wrap"><div class="chat-list" id="chat-list" data-scope="${scope}"><div class="empty-state">Загрузка чата...</div></div><form class="chat-form" data-form="chat" data-scope="${scope}"><input class="field" name="body" maxlength="${scope === 'global' ? 200 : 300}" placeholder="${scope === 'global' ? 'Написать в общий чат...' : 'Написать клану...'}" autocomplete="off"><button class="btn btn-gold">Отправить</button></form></div>`; }
function pChat() { return head('ОБЩЕНИЕ', 'Чат', 'Общайтесь с игроками арены и просите фрагменты у соклановцев.') + `<div class="panel padded chat-page-panel"><div class="segmented"><button class="${S.chatTab === 'global' ? 'active' : ''}" data-act="chat-tab" data-id="global">Общий чат</button><button class="${S.chatTab === 'clan' ? 'active' : ''}" data-act="chat-tab" data-id="clan">Чат клана</button></div>${chatBox(S.chatTab)}</div>`; }
const clock = (s) => new Date(s).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
async function loadChat() {
  const el = $('#chat-list'); if (!el || !S.data || !S.data.user) return;
  const scope = el.dataset.scope, r = await api('chat&scope=' + scope); if (!r.ok || $('#chat-list') !== el) return;
  const near = el.scrollHeight - el.scrollTop - el.clientHeight < 60, j = r.j, me = U().id, d = D();
  if (j.noClan) { el.innerHTML = '<div class="empty-state">Вы не состоите в клане. Вступите в клан, чтобы общаться с соклановцами и просить у них фрагменты карт.</div>'; $('.chat-form', el.parentNode).style.display = 'none'; return; }
  el.innerHTML = j.messages.map((m) => {
    if (m.kind === 'request') {
      const rq = j.requests.find((x) => x.id === m.requestId), card = rq && cardById(rq.cardId);
      if (!rq || !card) return `<div class="cmsg"><p>${esc(m.body)}</p></div>`;
      const done = rq.received >= rq.amount, exp = new Date(rq.expiresAt).getTime() < Date.now(), mine = rq.userId === me, have = (ownedOf(rq.cardId) || {}).copies || 0;
      const label = mine ? 'Ваша просьба' : done ? 'Выполнено' : exp ? 'Истекла' : have < 1 ? 'Нет копий' : 'Дать 1 (у вас ' + have + ')';
      return `<div class="req-card"><div class="req-thumb">${art(card.image, card.name)}</div><div class="req-body"><span><strong>${esc(rq.username)}</strong> просит фрагменты</span><span class="req-title">${esc(card.name)} <small>(${card.rarity})</small></span><div class="req-bar"><i style="width:${Math.min(100, rq.received / rq.amount * 100)}%"></i></div><div class="req-meta"><span>Получено ${rq.received} из ${rq.amount}</span><span>${clock(m.createdAt)}</span></div>${rq.donors.length ? '<div class="req-donors">Помогли: ' + rq.donors.map((x) => esc(x.username) + (x.count > 1 ? ' ×' + x.count : '')).join(', ') + '</div>' : ''}${!mine && !done && !exp ? '<div class="req-donors">Награда: +' + C.donateReward(card.rarity) + ' золота за копию</div>' : ''}</div><button class="tiny-btn" data-act="donate" data-id="${rq.id}" ${mine || done || exp || have < 1 ? 'disabled' : ''}>${label}</button></div>`;
    }
    return `<div class="cmsg ${m.userId === me ? 'mine' : ''}"><div class="cmsg-head"><strong>${esc(m.username)}</strong>${m.role === 'admin' ? '<span class="cbadge">АДМИН</span>' : ''}<small>${clock(m.createdAt)}</small></div><p>${esc(m.body)}</p></div>`;
  }).join('') || '<div class="empty-state">Здесь пока тихо. Напишите первым!</div>';
  if (near) el.scrollTop = el.scrollHeight;
}

function pFriends() {
  const d = D(), u = U(), fs = d.friendships, other = (f) => f.requesterId === u.id ? f.recipient : f.requester;
  return head('ВМЕСТЕ СИЛЬНЕЕ', 'Друзья', 'Находите игроков и собирайте команду.') + `<div class="panel padded friend-add"><h3>Добавить друга</h3><p class="muted">Найдите игрока по его точному имени.</p><form data-form="friendRequest"><input class="field" name="username" placeholder="Имя игрока" required><button class="btn btn-gold">Отправить заявку</button></form></div>
  <div class="two-columns"><div class="panel padded"><h3>Мои друзья</h3>${fs.filter((f) => f.status === 'accepted').map((f) => `<div class="simple-row"><span class="rank-avatar">${esc(other(f)[0])}</span><strong class="grow">${esc(other(f))}</strong><button class="tiny-btn danger" data-act="friend-remove" data-id="${f.id}">Удалить</button></div>`).join('') || '<div class="empty-state">Пока нет друзей. Пригласите кого-нибудь!</div>'}</div>
  <div class="panel padded"><h3>Заявки в друзья</h3>${fs.filter((f) => f.status === 'pending').map((f) => `<div class="simple-row"><span class="rank-avatar">${esc(f.requester[0])}</span><strong class="grow">${f.requesterId === u.id ? 'Вы → ' + esc(f.recipient) : esc(f.requester)}</strong>${f.recipientId === u.id ? `<button class="tiny-btn" data-act="friend-accept" data-id="${f.id}">Принять</button>` : '<span class="muted">Ожидание</span>'}<button class="tiny-btn danger" data-act="friend-remove" data-id="${f.id}">×</button></div>`).join('') || '<div class="empty-state">Новых заявок нет.</div>'}</div></div>`;
}
function pRanking() {
  const d = D(), u = U();
  return head('ЗАЛ СЛАВЫ', 'Таблица лидеров', 'Лучшие бойцы и кланы королевства.', 'СЕЗОН 01') + `<div class="panel ranking-panel"><div class="segmented"><button class="${S.rankTab === 'players' ? 'active' : ''}" data-act="rank-tab" data-id="players">Игроки</button><button class="${S.rankTab === 'clans' ? 'active' : ''}" data-act="rank-tab" data-id="clans">Кланы</button></div><div class="rank-list">${S.rankTab === 'players' ? d.leaderboard.map((p, i) => `<div class="rank-row ${p.id === u.id ? 'me' : ''}"><span class="rank-number pos-${i}">${String(i + 1).padStart(2, '0')}</span><div class="rank-avatar">${esc(p.username[0].toUpperCase())}</div><div class="rank-person"><strong>${esc(p.username)} ${p.id === u.id ? '<em>ВЫ</em>' : ''}</strong><small>${esc((d.clans.find((c) => c.id === p.clanId) || {}).name || 'Без клана')} · ${p.wins} побед</small></div><span class="rank-score">${ico('trophy', 22, 'trophy-icon')}${fmt(p.trophies)}</span></div>`).join('') : d.clans.map((c, i) => `<div class="rank-row"><span class="rank-number">${String(i + 1).padStart(2, '0')}</span><span class="clan-emblem ${c.badge}">C</span><div class="rank-person"><strong>${esc(c.name)}</strong><small>${esc(c.description || 'Клан бойцов арены')}</small></div><span class="rank-score">КЛАН</span></div>`).join('') || '<div class="empty-state">Пока нет кланов. Создайте первый!</div>'}</div></div>`;
}
function pHistory() {
  const h = D().history;
  return head('ПУТЬ ГЕРОЯ', 'История боёв', 'Все ваши победы и сражения в одном месте.') + `<div class="panel padded"><h3>Последние сражения</h3>${h.map((b) => `<div class="history-row"><span class="history-result ${b.result}">${b.result === 'win' ? 'ПОБЕДА' : b.result === 'draw' ? 'НИЧЬЯ' : 'ПОРАЖЕНИЕ'}</span><div class="grow"><strong>Против: ${esc(b.opponent)}</strong><small>${new Date(b.createdAt).toLocaleString('ru-RU')} · ${b.crowns} короны</small></div><div class="history-rewards"><span class="${b.trophies > 0 ? 'positive' : 'negative'}">${b.trophies > 0 ? '+' : ''}${b.trophies} кубков</span><small>+${b.gold} золота</small></div></div>`).join('') || '<div class="empty-state">Здесь появятся ваши бои. Отправляйтесь на арену!<br><button class="btn btn-green" data-act="nav" data-id="lobby">На арену</button></div>'}</div>`;
}

// ---------- модальные окна ----------
function modalHtml() {
  const m = S.modal; if (!m) return '';
  const d = D(), u = U();
  if (m.type === 'card') {
    const c = cardById(m.id), o = ownedOf(c.id), lv = o ? o.level : 1, mul = Math.pow(1.1, lv - 1), spell = c.kind === 'spell', inDeck = d.deck.includes(c.id), canAdd = !inDeck && d.deck.length < 8;
    const rows = [['Тип', C.kindLabel(c.kind)], ['Цели', C.targetLabel(c.targets, c.kind)]];
    if (!spell) rows.push(['Здоровье', fmt(Math.round(c.health * mul)) + (c.count > 1 ? ' × ' + c.count : '')]);
    rows.push([spell ? (c.ability === 'poison' ? 'Урон в секунду' : 'Урон') : 'Урон за удар', fmt(Math.round(c.damage * mul))]);
    if (!spell && c.damage > 0) rows.push(['Урон в секунду', fmt(Math.round(c.damage * mul / c.attackSpeed)) + (c.count > 1 ? ' × ' + c.count : '')], ['Скорость атаки', c.attackSpeed.toFixed(1) + ' с']);
    if (!spell && c.kind !== 'building') rows.push(['Скорость', C.speedLabel(c.speed)]);
    if (!spell && c.range > 1.6) rows.push(['Дальность', c.range.toFixed(1) + ' кл.']);
    if (c.splash > 0) rows.push([spell ? 'Радиус' : 'Урон по площади', c.splash.toFixed(1) + ' кл.']);
    if (c.kind === 'building') rows.push(['Время жизни', c.lifetime + ' с']);
    return `<div class="modal-backdrop" data-act="close-modal-bg"><div class="card-modal wide"><button class="close-btn" data-act="close-modal">×</button><div class="card-modal-head"><span class="eyebrow">${esc(c.rarity.toUpperCase())} · ${esc(c.role.toUpperCase())}</span><h2>${esc(c.name)}${o ? ' · уровень ' + lv : ''}</h2></div>
    <div class="card-modal-body">${tile(c, { locked: !o, level: lv, copies: o ? o.copies : 0, act: 'noop' })}<div class="card-details"><p>${esc(c.description)}</p>${rows.map((r) => `<div class="detail-stat"><span>${r[0]}</span><strong>${r[1]}</strong></div>`).join('')}</div></div>
    <div class="proscons"><div class="pros"><h4>Сильные стороны</h4><p>${esc(c.pros)}</p></div><div class="cons"><h4>Слабые стороны</h4><p>${esc(c.cons)}</p></div></div>
    ${o ? `<div class="deck-swap"><h4>${inDeck ? 'Эта карта уже в вашей колоде' : canAdd ? 'В колоде есть свободное место' : 'Поставить в колоду вместо:'}</h4>${canAdd ? `<button class="btn btn-outline" data-act="deck-add" data-id="${c.id}">Добавить в колоду</button>` : ''}${!inDeck && !canAdd ? `<div class="deck-swap-row">${deckCards().map((x) => `<button title="${esc(x.name)}" data-act="deck-swap" data-id="${c.id}" data-out="${x.id}">${art(x.image, x.name)}<span>${x.cost}</span></button>`).join('')}</div>` : ''}</div>` : ''}
    <div class="card-modal-foot">${o ? `<span>Улучшение: ${lv * 10} копий (у вас ${o.copies}) и ${fmt(lv * 500)} золота</span><button class="btn btn-green" data-act="upgrade" data-id="${c.id}">Улучшить</button>` : `<span>Карта ещё не открыта</span><button class="btn btn-gold" data-act="buy" data-id="${c.id}"><i class="coin-icon"></i>${C.cardPrice(c.rarity)} · Купить</button>`}</div></div></div>`;
  }
  if (m.type === 'request') {
    const own = new Map(d.owned.map((o) => [o.cardId, o]));
    return `<div class="modal-backdrop" data-act="close-modal-bg"><div class="card-modal wide"><button class="close-btn" data-act="close-modal">×</button><div class="card-modal-head"><span class="eyebrow">ПОМОЩЬ КЛАНА</span><h2>Попросить фрагмент</h2></div><p class="req-help">Выберите карту: просьба появится в чате клана, а соклановцы смогут подарить вам копии. Просить можно раз в ${d.requestCooldownMinutes} мин.</p><div class="req-grid">${d.cards.filter((c) => own.has(c.id)).map((c) => `<div class="req-cell">${tile(c, { level: own.get(c.id).level, copies: own.get(c.id).copies, act: 'req-pick' })}<span>до ${C.requestAmount(c.rarity)} шт.</span></div>`).join('')}</div></div></div>`;
  }
  if (m.type === 'reveal') return `<div class="modal-backdrop" data-act="close-modal-bg"><div class="chest-reveal-modal"><button class="close-btn" data-act="close-modal">×</button><div class="chest-burst"><img src="images/chest.jpg" alt="Открытый сундук"></div><span class="eyebrow">СОДЕРЖИМОЕ СУНДУКА</span><h2>${esc(m.title)}</h2><div class="loot-grid">${m.rewards.map((r) => { const it = r.kind === 'cosmetic' ? catalogBy(String(r.image), r.name) : null; return `<div class="loot-item loot-${r.kind}">${r.kind === 'card' && r.image ? art(r.image, r.name) : ''}${it ? cosView(it, 'sm') : ''}<b>${esc(r.name)}</b><span>${r.kind === 'card' ? '×' + r.amount : r.kind === 'gold' ? '+' + r.amount + ' золота' : r.kind === 'gems' ? '+' + r.amount + ' кристаллов' : r.kind === 'wildcard' || r.kind === 'evolution' ? '×' + r.amount : r.kind === 'cosmetic' ? 'Новая косметика' : ''}</span></div>`; }).join('')}</div><button class="btn btn-gold full" data-act="close-modal">Забрать награды</button></div></div>`;
  return '';
}

// ---------- отрисовка ----------
function render() {
  const app = $('#app');
  if (!S.data) return;
  if (!S.data.user) { app.innerHTML = pageAuth(); modalRoot(); return; }
  const scroll = window.scrollY, ae = document.activeElement, keep = ae && ae.form ? null : null;
  app.innerHTML = shell(); modalRoot();
  window.scrollTo(0, scroll);
  if (S.nav === 'admin') CF.adminInit(); else if ($('#chat-list')) loadChat();
}
function modalRoot() {
  let el = $('#modal-root'); if (!el) { el = document.createElement('div'); el.id = 'modal-root'; document.body.appendChild(el); }
  el.innerHTML = S.data && S.data.user ? modalHtml() : '';
}
CF.render = render;
function go(n) { S.nav = n; S.more = false; render(); window.scrollTo({ top: 0 }); if (n !== 'admin' && S.data && S.data.user) refresh(); }

// ---------- бой ----------
function randomRival() {
  const pick = (t) => { const l = D().cosmeticCatalog.filter((c) => c.type === t); return l.length ? l[Math.floor(Math.random() * l.length)] : null; };
  const b = Math.random() < 0.7 ? pick('banner') : null, t = Math.random() < 0.5 ? pick('towerSkin') : null;
  const em = D().cosmeticCatalog.filter((c) => c.type === 'emote').sort(() => Math.random() - 0.5).slice(0, 3);
  return { look: sideLook(t && t.name, b && b.name), emotes: emoteDefs(em.map((x) => x.name)) };
}
function sideLook(tower, banner) {
  const t = tower && tower !== 'classic' ? catalogBy('towerSkin', tower) : null, b = banner ? catalogBy('banner', banner) : null;
  return { tower: t ? [t.color1, t.color2] : undefined, banner: b ? { c1: b.color1, c2: b.color2, label: b.label } : undefined };
}
function emoteDefs(names) { return (names || []).map((n) => catalogBy('emote', n)).filter(Boolean).map((c) => ({ name: c.name, label: c.label || c.name, color: c.color1 })); }
function battleCards(ids, levels) { return ids.map((id) => { const c = cardById(id); return c ? Object.assign({}, c, { level: (levels && levels[id]) || 1 }) : null; }).filter(Boolean); }
async function startBattle(mode) {
  if (D().deck.length !== 8) { toast('Соберите колоду ровно из 8 карт.'); go('cards'); return; }
  if (!(await action({ action: 'battleStart', mode }, true))) { toast('Не удалось начать бой.'); return; }
  const d = D(), own = new Map(d.owned.map((o) => [o.cardId, o.level]));
  const deck = battleCards(d.deck, Object.fromEntries(own)), lvls = deck.map((c) => c.level), avg = Math.max(1, Math.round(lvls.reduce((a, b) => a + b, 0) / lvls.length));
  S.rival = randomRival();
  CF.runBattle({ deck, allCards: d.cards.map((c) => Object.assign({}, c, { level: 1 })), level: avg, difficulty: d.arena.difficulty, pvp: false, opponentName: null, cosmetics: { own: sideLook(U().towerSkin, U().bannerSkin), rival: S.rival.look, emotes: emoteDefs(U().equippedEmotes), rivalEmotes: S.rival.emotes } });
}
async function startPvp() {
  if (D().deck.length !== 8) { toast('Соберите колоду ровно из 8 карт.'); go('cards'); return; }
  const r = await api('game', { action: 'pvpFind' });
  if (!r.ok) { toast(r.j.error || 'Не удалось найти противника.'); return; }
  const o = r.j.opponent;
  if (!(await action({ action: 'battleStart', mode: 'pvp', opponentId: o.id }, true))) { toast('Не удалось начать бой.'); return; }
  const d = D(), own = Object.fromEntries(d.owned.map((x) => [x.cardId, x.level])), deck = battleCards(d.deck, own), avg = Math.max(1, Math.round(deck.reduce((a, c) => a + c.level, 0) / deck.length));
  CF.runBattle({ deck, allCards: battleCards(o.deck, o.levels), level: avg, difficulty: 5, pvp: true, opponentName: o.username, cosmetics: { own: sideLook(U().towerSkin, U().bannerSkin), rival: sideLook(o.towerSkin, o.bannerSkin), emotes: emoteDefs(U().equippedEmotes), rivalEmotes: emoteDefs(o.emotes) } });
}
CF.finishBattle = async function (result, crowns, opponent, lost) {
  const r = await api('game', { action: 'battle', result, crowns, opponent, crownsLost: lost || 0 });
  return r.ok ? { reward: r.j.reward, chest: r.j.chest } : { error: r.j.error || 'Ошибка' };
};
CF.exitBattle = function () { refresh(); };

// ---------- обработчики ----------
async function act(name, el, ev) {
  const id = el.dataset.id, d = S.data;
  switch (name) {
    case 'noop': return;
    case 'toast-x': $('#toast-root').innerHTML = ''; return;
    case 'nav': return go(id);
    case 'more': S.more = !S.more; return render();
    case 'more-close': S.more = false; return render();
    case 'logout': await api('auth', { action: 'logout' }); S.data.user = null; S.nav = 'lobby'; S.authMode = 'login'; await refresh(); return;
    case 'auth-mode': S.authMode = id; S.authError = ''; return render();
    case 'battle': return startBattle('ladder');
    case 'battle-tournament': return startBattle('tournament');
    case 'pvp-find': return startPvp();
    case 'pvp-seen': return void action({ action: 'pvpSeen' }, true);
    case 'gift': return void action({ action: 'gift' });
    case 'card-info': S.modal = { type: 'card', id: +id }; return modalRoot();
    case 'close-modal': S.modal = null; return modalRoot();
    case 'close-modal-bg': if (ev && ev.target === el) { S.modal = null; modalRoot(); } return;
    case 'upgrade': return void action({ action: 'upgrade', cardId: +id });
    case 'buy': return void action({ action: 'buy', cardId: +id });
    case 'deck-slot': S.deckSlot = +id; return render();
    case 'deck-pick': case 'deck-swap': case 'deck-add': {
      const cid = +id, next = d.deck.slice();
      if (name === 'deck-add') next.push(cid);
      else if (name === 'deck-swap') { const k = next.indexOf(+el.dataset.out); if (k >= 0) next[k] = cid; }
      else { const slot = S.deckSlot, at = next.indexOf(cid); if (at === slot) return toast('Эта карта уже стоит в выбранном слоте.'); if (at >= 0) { next[at] = next[slot]; next[slot] = cid; } else next[slot] = cid; }
      const c = cardById(cid), ok = await action({ action: 'setDeck', deck: next }, true);
      if (ok) { if (name === 'deck-pick') S.deckSlot = (S.deckSlot + 1) % 8; S.modal = null; modalRoot(); render(); toast('Колода сохранена: ' + c.name + '.'); } else toast('Не удалось сохранить колоду.');
      return;
    }
    case 'shop-filter': S.shopFilter = id; return render();
    case 'rank-tab': S.rankTab = id; return render();
    case 'chat-tab': S.chatTab = id; return render();
    case 'buy-chest': return void action({ action: 'buyChest', type: id });
    case 'open-chest': {
      const r = await api('game', { action: 'openChest', chestId: +id });
      if (!r.ok) return toast(r.j.error || 'Не удалось открыть сундук.');
      S.modal = { type: 'reveal', title: CHEST_TITLE[el.dataset.type] || 'Сундук', rewards: r.j.rewards || [] }; await refresh(); return;
    }
    case 'equip': return void action({ action: 'equipCosmetic', type: el.dataset.type, name: el.dataset.name, unequip: el.dataset.off === '1' });
    case 'league-claim': return void action({ action: 'leagueClaim' });
    case 'tournament-claim': return void action({ action: 'tournamentClaim' });
    case 'req-open': {
      const u = U(); if (!u.clanId) { toast('Чтобы просить фрагменты, вступите в клан.'); return go('clan'); }
      const rc = reqClock(); if (rc.left > 0) return toast('Следующую просьбу можно отправить через ' + rc.text + '.');
      S.modal = { type: 'request' }; return modalRoot();
    }
    case 'req-pick': { const ok = await action({ action: 'requestCards', cardId: +id }); if (ok) { S.modal = null; modalRoot(); } return; }
    case 'clan-join': return void action({ action: 'joinClan', clanId: +id });
    case 'clan-leave': if (confirm('Покинуть клан?')) action({ action: 'leaveClan' }); return;
    case 'donate': { const ok = await action({ action: 'donate', requestId: +id }); loadChat(); return; }
    case 'friend-accept': return void action({ action: 'acceptFriend', id: +id });
    case 'friend-remove': return void action({ action: 'removeFriend', id: +id });
    default: if (name.indexOf('admin-') === 0) return CF.adminAct(name, el);
  }
}
document.addEventListener('click', (e) => { const t = e.target.closest('[data-act]'); if (!t || t.disabled) return; if (t.tagName === 'A') e.preventDefault(); act(t.dataset.act, t, e).catch((er) => { console.error(er); toast('Что-то пошло не так.'); }); });
document.addEventListener('input', (e) => { if (e.target.id === 'clan-search') $('#clan-list').innerHTML = clanList(e.target.value); });
document.addEventListener('submit', async (e) => {
  const f = e.target.closest('[data-form]'); if (!f) return; e.preventDefault();
  const fd = Object.fromEntries(new FormData(f)), kind = f.dataset.form;
  if (kind.indexOf('admin-') === 0) return CF.adminSubmit(kind, fd, f);
  if (kind === 'auth') {
    const r = await api('auth', Object.assign({ action: S.authMode }, fd));
    if (!r.ok) { S.authError = r.j.error || 'Не удалось войти.'; let er = $('.form-error'); if (!er) { er = document.createElement('div'); er.className = 'form-error'; f.querySelector('button[type=submit]').before(er); } er.textContent = S.authError; return; }
    S.authError = ''; S.nav = 'lobby'; await refresh(); return;
  }
  if (kind === 'chat') {
    const r = await api('chat', { scope: f.dataset.scope, body: fd.body });
    if (!r.ok) return toast(r.j.error || 'Не удалось отправить сообщение.');
    f.reset(); const el = $('#chat-list'); if (el) { el.scrollTop = el.scrollHeight; } loadChat(); return;
  }
  if (kind === 'createClan') { await action(Object.assign({ action: 'createClan' }, fd)); return; }
  if (kind === 'friendRequest') { if (await action({ action: 'friendRequest', username: fd.username })) return; }
});
setInterval(() => { if (!document.hidden) loadChat(); }, 4000);
setInterval(() => { const b = $('#req-btn'); if (b && S.data && S.data.user) { const rc = reqClock(); b.textContent = rc.left > 0 ? 'Попросить через ' + rc.text : 'Попросить фрагмент'; } }, 1000);

CF.boot = async function () { const r = await api('game'); if (r.ok) { S.data = r.j; render(); } else $('#app').innerHTML = '<div style="padding:40px;color:#fff">Не удалось загрузить игру: ' + esc(r.j.error || '') + '</div>'; };
})();
