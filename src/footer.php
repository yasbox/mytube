<?php
// 共通フッターコンポーネント（著作権表示のみ）
$year = date('Y');
$appName = class_exists('Config') ? (Config::get('app.name', 'MyTube')) : 'MyTube';
?>
<footer class="site-footer">© <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></footer>
