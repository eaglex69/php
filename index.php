<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
if (!installed()) { header('Location: install.php'); exit; }
// Вход по одноразовой ссылке, созданной администратором
if (isset($_GET['login'])) {
    $t = row('SELECT user_id FROM login_tokens WHERE token=? AND expires_at > ?', [(string)$_GET['login'], now_s()]);
    $u = $t ? row('SELECT id,banned FROM users WHERE id=?', [$t['user_id']]) : null;
    if ($u && !$u['banned']) login_user((int)$u['id']);
    header('Location: index.php'); exit;
}
header('Cache-Control: no-store');
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Crownfall — Королевская арена</title>
<meta name="description" content="Соберите колоду, сражайтесь на арене, создавайте кланы и станьте легендой Crownfall.">
<link rel="stylesheet" href="css/app.css?v=6"></head><body><div id="app"></div><div id="toast-root"></div>
<script src="js/engine.js?v=6"></script><script src="js/app.js?v=6"></script><script src="js/battle.js?v=6"></script><script src="js/admin.js?v=6"></script><script>CF.boot();</script></body></html>
