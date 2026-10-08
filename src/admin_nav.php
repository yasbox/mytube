<?php
/**
 * 管理ページ共通の見出しとタブ
 *
 * 使用方法:
 * $adminTab = 'videos'; // videos / upload / settings / manual
 * $adminTitle = '動画の管理';
 * include 'admin_nav.php';
 */
$adminTabs = [
    'videos' => ['admin.php', '動画'],
    'upload' => ['upload.php', 'アップロード'],
    'settings' => ['settings.php', '設定'],
    'manual' => ['manual.php', '使い方'],
];
$adminTab = $adminTab ?? 'videos';
?>
<div class="studio-head">
  <h1 class="studio-title"><?= htmlspecialchars($adminTitle ?? '') ?></h1>
  <nav class="studio-tabs" aria-label="管理メニュー">
    <?php foreach ($adminTabs as $tabKey => [$tabHref, $tabLabel]): ?>
    <a href="<?= $tabHref ?>" class="studio-tab <?= $adminTab === $tabKey ? 'active' : '' ?>"<?= $adminTab === $tabKey ? ' aria-current="page"' : '' ?>><?= $tabLabel ?></a>
    <?php endforeach ?>
  </nav>
</div>
