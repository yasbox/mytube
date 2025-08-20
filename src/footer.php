<?php
// 共通フッターコンポーネント（著作権表示のみ）
$year = date('Y');
$appName = class_exists('Config') ? (Config::get('app.name', 'MyTube')) : 'MyTube';
?>
<footer class="mt-auto border-t py-4" style="border-color: var(--header-border);">
  <div class="max-w-none mx-auto px-4 md:px-8 lg:px-12">
    <p class="text-center text-xs md:text-sm video-meta-info" style="color: var(--text-muted);">© <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
  </footer>


