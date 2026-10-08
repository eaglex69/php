/* Бой на Canvas: тот же движок и отрисовка, что и в Next.js-версии (js/engine.js). */
(function () {
'use strict';
const C = window.Crown, CF = window.CF, esc = CF.esc, STEP = 1 / 30;
const OPPONENTS = ['Страж арены', 'Тёмный лорд', 'Гроза башен', 'Королева льда', 'Мастер осады', 'Капитан Рык', 'Тень арены', 'Огненный Влад'];
const DEF_ME = [{ name: 'Привет', label: 'Привет!', color: '#4aa8ff' }, { name: 'Молодец', label: 'Молодец!', color: '#7be05b' }, { name: 'Ой', label: 'Ой!', color: '#ffd45c' }];
const DEF_RIVAL = [{ name: 'Хорош', label: 'Хорош!', color: '#ffd45c' }, { name: 'Ого', label: 'Ого!', color: '#8fe8ff' }, { name: 'Ха', label: 'Ха-ха!', color: '#7be05b' }];

CF.runBattle = function (o) {
  const data = CF.S.data, elixirSec = data.battleSettings.elixirSeconds, duration = data.battleSettings.battleSeconds;
  const opponent = o.opponentName || OPPONENTS[Math.floor(Math.random() * OPPONENTS.length)];
  const root = document.createElement('div'); root.className = 'battle-overlay'; root.id = 'battle-root';
  root.innerHTML = `<div class="battle-screen"><div class="battle-top"><div class="battle-vs"><span class="live-dot"></span> <strong>Против: ${esc(opponent)}</strong><small>Эликсир: 1 за ${elixirSec.toFixed(1)} с</small></div><div class="battle-score"><span class="crown-score blue" id="b-c0">0</span><span class="battle-clock" id="b-clock">00:00</span><span class="crown-score red" id="b-c1">0</span></div><button class="close-btn" id="b-exit" aria-label="Выйти из боя">×</button></div>
  <div class="battle-stage" id="b-stage"><canvas id="b-canvas"></canvas><div class="battle-hint" id="b-hint" style="display:none"></div><div class="battle-tip" id="b-tip">Выберите карту внизу, затем нажмите на поле</div></div>
  <div class="battle-controls"><div class="elixir-track"><span>ЭЛИКСИР</span><div class="elixir-bar"><i id="b-elixir"></i>${Array.from({ length: 9 }, (_, i) => `<b style="left:${(i + 1) * 10}%"></b>`).join('')}</div><strong id="b-elnum">5</strong></div><div class="emote-bar" id="b-emotes"></div><div class="battle-hand" id="b-hand"></div></div>
  <div class="battle-result" id="b-result" style="display:none"></div></div>`;
  document.body.appendChild(root); document.body.style.overflow = 'hidden';
  const $r = (s) => root.querySelector(s);

  const dp = C.difficultyParams(o.pvp ? 5 : o.difficulty);
  const rivalDeck = o.pvp ? o.allCards.map((c) => Object.assign({}, c)) : C.pickBotDeck(o.allCards.map((c) => Object.assign({}, c)), Math.max(1, o.level + dp.levelDelta));
  const s = C.createSim({ deck0: o.deck, deck1: rivalDeck, elixirSec, duration, elixirMul1: o.pvp ? 1 : dp.elixirMul, towerHpMul1: o.pvp ? 1 : dp.towerHpMul });
  const bot = C.createBot(1, o.pvp ? 1 : dp.reaction);
  const cos = o.cosmetics || { own: {}, rival: {}, emotes: [], rivalEmotes: [] };
  const rivalList = cos.rivalEmotes && cos.rivalEmotes.length ? cos.rivalEmotes : DEF_RIVAL, myEmotes = cos.emotes && cos.emotes.length ? cos.emotes : DEF_ME;

  // загрузка картинок для Canvas (ключ — путь из карточки, как его ожидает движок)
  const imgs = new Map(), load = (key) => { if (imgs.has(key)) return; const im = new Image(); im.src = key.replace(/^\//, ''); imgs.set(key, im); };
  load('/images/units1_2.jpg'); load('images/units1_2.jpg');
  s.queues[0].concat(s.queues[1]).forEach((c) => load(C.parseArt(c.image).src));
  const bg = new Image(); bg.src = 'images/arena.jpg';

  const stage = $r('#b-stage'), canvas = $r('#b-canvas'), ctx = canvas.getContext('2d');
  let view = { scale: 1, ox: 0, oy: 0, cw: 1, ch: 1, dpr: 1 }, sel = null, mouse = null, done = false, cancelled = false, emoteCd = 0, lastCrowns = [0, 0], handKey = '';
  const resize = () => {
    const r = stage.getBoundingClientRect(), dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr); canvas.style.width = r.width + 'px'; canvas.style.height = r.height + 'px';
    const scale = Math.min(r.width / C.W, r.height / C.H); view = { scale, ox: (r.width - C.W * scale) / 2, oy: (r.height - C.H * scale) / 2, cw: r.width, ch: r.height, dpr };
  };
  resize(); const ro = new ResizeObserver(resize); ro.observe(stage);

  const flash = (m) => { const h = $r('#b-hint'); h.textContent = m; h.style.display = 'block'; clearTimeout(flash.t); flash.t = setTimeout(() => h.style.display = 'none', 1400); };
  const choose = (i) => { sel = i; root.querySelectorAll('.hand-card').forEach((b) => b.classList.toggle('selected', +b.dataset.i === sel)); const tip = $r('#b-tip'); if (tip) tip.style.display = sel === null ? 'block' : 'none'; };
  const fireEmote = (em) => {
    if (s.over || Date.now() < emoteCd) return; emoteCd = Date.now() + 2000; C.sendEmote(s, 0, em.label, em.color); $r('#b-emotes').classList.remove('open'); buildEmotes();
    if (Math.random() < 0.3) { const r = rivalList[Math.floor(Math.random() * rivalList.length)]; if (r) setTimeout(() => { if (!s.over && !cancelled) C.sendEmote(s, 1, r.label, r.color); }, 1400); }
  };
  function buildEmotes() { const open = $r('#b-emotes').classList.contains('open'); $r('#b-emotes').innerHTML = '<button class="emote-toggle" data-e="toggle">Эмоции</button>' + (open ? myEmotes.map((e, i) => `<button class="emote-chip" data-e="${i}" style="border-color:${esc(e.color)}">${esc(e.label)}</button>`).join('') : ''); }
  buildEmotes();
  $r('#b-emotes').addEventListener('click', (e) => { const b = e.target.closest('[data-e]'); if (!b) return; if (b.dataset.e === 'toggle') { $r('#b-emotes').classList.toggle('open'); buildEmotes(); } else fireEmote(myEmotes[+b.dataset.e]); });

  function renderHand() {
    const hand = C.hand(s, 0), next = C.nextCard(s, 0), key = hand.map((c) => c.id).join(',') + '|' + (next ? next.id : '');
    if (key !== handKey) {
      handKey = key;
      $r('#b-hand').innerHTML = `<div class="next-card"><small>Далее</small>${next ? CF.art(next.image, next.name) : ''}</div>` + hand.map((c, i) => `<button class="hand-card ${c.rarity.toLowerCase()}" data-i="${i}" title="${esc(c.name)}"><span class="card-cost">${c.cost}</span>${CF.art(c.image, c.name)}<span class="hand-name">${esc(c.name)}</span></button>`).join('');
    }
    root.querySelectorAll('.hand-card').forEach((b) => { const c = hand[+b.dataset.i]; b.classList.toggle('dim', !!c && s.elixir[0] < c.cost); b.classList.toggle('selected', +b.dataset.i === sel); });
  }
  $r('#b-hand').addEventListener('click', (e) => { const b = e.target.closest('.hand-card'); if (b) choose(sel === +b.dataset.i ? null : +b.dataset.i); });
  const toWorld = (e) => { const r = canvas.getBoundingClientRect(); return { x: (e.clientX - r.left - view.ox) / view.scale, y: (e.clientY - r.top - view.oy) / view.scale }; };
  canvas.addEventListener('pointermove', (e) => { mouse = toWorld(e); });
  canvas.addEventListener('pointerleave', () => { mouse = null; });
  canvas.addEventListener('pointerdown', (e) => {
    const p = toWorld(e); mouse = p; if (s.over || sel === null) return;
    const card = s.queues[0][sel]; if (!card) return;
    if (s.elixir[0] < card.cost) return flash('Не хватает эликсира');
    if (!C.canDeployAt(s, 0, card, p.x, p.y)) return flash('Сюда нельзя выставить карту');
    if (C.playCard(s, 0, sel, p.x, p.y)) { choose(null); renderHand(); }
  });
  const onKey = (e) => { const n = Number(e.key); if (n >= 1 && n <= 4) choose(sel === n - 1 ? null : n - 1); if (e.key === 'Escape') choose(null); };
  window.addEventListener('keydown', onKey);

  const hud = () => {
    const left = Math.max(0, s.duration - s.t);
    $r('#b-clock').textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(Math.floor(left % 60)).padStart(2, '0');
    $r('#b-c0').textContent = s.crowns[0]; $r('#b-c1').textContent = s.crowns[1];
    $r('#b-elixir').style.width = (s.elixir[0] / 10 * 100) + '%'; $r('#b-elnum').textContent = Math.floor(s.elixir[0]); renderHand();
  };
  function finish(result, auto) {
    if (done) return; done = true; hud();
    const title = result === 'win' ? 'ПОБЕДА!' : result === 'draw' ? 'НИЧЬЯ' : 'ПОРАЖЕНИЕ', rs = $r('#b-result');
    rs.style.display = 'grid';
    rs.innerHTML = `<div class="result-box"><span class="eyebrow">БОЙ ЗАВЕРШЁН</span><h2>${title}</h2><div class="result-crowns"><span class="crown-score blue">${s.crowns[0]}</span><span>:</span><span class="crown-score red">${s.crowns[1]}</span></div><div class="result-reward" id="b-reward">Начисляем награду...</div><div id="b-chest"></div><button class="btn btn-green" id="b-back">Вернуться в лобби</button></div>`;
    $r('#b-back').onclick = close;
    CF.finishBattle(result, s.crowns[0], opponent, s.crowns[1]).then((r) => {
      const rw = $r('#b-reward'); if (!rw) return;
      if (r.reward) rw.textContent = (r.reward.trophies > 0 ? '+' : '') + r.reward.trophies + ' кубков  ·  +' + r.reward.gold + ' золота'; else rw.textContent = r.error || 'Не удалось сохранить результат боя.';
      if (r.chest) { $r('#b-chest').innerHTML = `<div class="battle-chest-drop"><img src="images/chest.jpg" alt="Сундук за победу"><div><strong>${esc(r.chest.title)}</strong><small>Сундук добавлен в инвентарь</small></div></div>`; $r('#b-back').textContent = 'Забрать сундук'; }
    });
  }
  function close() { cancelled = true; cancelAnimationFrame(raf); ro.disconnect(); window.removeEventListener('keydown', onKey); root.remove(); document.body.style.overflow = ''; CF.exitBattle(); }
  $r('#b-exit').onclick = async () => {
    if (!s.over && !done) { if (!confirm('Сдаться? Это засчитается как поражение.')) return; s.over = true; s.result = 'loss'; done = true; try { await CF.finishBattle('loss', 0, opponent, 0); } catch (e) {} }
    close();
  };

  let raf = 0, last = performance.now(), acc = 0, lastHud = 0;
  const loop = (now) => {
    if (cancelled) return;
    acc += Math.min(0.1, (now - last) / 1000); last = now;
    while (acc >= STEP) { if (!s.over) { C.botTick(s, bot, STEP); C.step(s, STEP); } acc -= STEP; }
    C.draw(ctx, s, view, { sel: sel !== null ? s.queues[0][sel] || null : null, mouse }, imgs, bg, { own: cos.own, rival: cos.rival });
    if (s.crowns[0] !== lastCrowns[0] || s.crowns[1] !== lastCrowns[1]) {
      const side = s.crowns[1] > lastCrowns[1] ? 1 : 0; lastCrowns = [s.crowns[0], s.crowns[1]];
      if (Math.random() < (side === 1 ? 0.75 : 0.35)) { const em = rivalList[Math.floor(Math.random() * rivalList.length)]; if (em) setTimeout(() => { if (!cancelled) C.sendEmote(s, 1, em.label, em.color); }, 600); }
    }
    if (now - lastHud > 100) { lastHud = now; hud(); }
    if (s.over && !done) finish(s.result || 'draw', true);
    raf = requestAnimationFrame(loop);
  };
  hud(); raf = requestAnimationFrame(loop);
};
})();
