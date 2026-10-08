<?php

/**
 * 共通ヘッダーコンポーネント
 *
 * 左: ロゴとサイト名 / 中央: 検索 / 右: アップロード（管理者）・テーマ切り替え・ログイン・メニュー
 *
 * 使用方法:
 * $showUploadButton = true;
 * $showAdminButton = true;
 * $showHomeButton = true;
 * include 'header.php';
 */

require_once __DIR__ . '/security.php';
// ヘッダー読込時にも最速で復元（セッションが未認証なら）
if (function_exists('checkRememberMe')) {
  if (!isUserAuthenticated()) {
    checkRememberMe();
  }
}
$__csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

// デフォルト値の設定
$showUploadButton = $showUploadButton ?? false;
$showAdminButton = $showAdminButton ?? false;
$showHomeButton = $showHomeButton ?? true;
$isAdminPage = $isAdminPage ?? false;

// ログイン状態をチェック
$isLoggedIn = isUserAuthenticated();
$isAdmin = isAdmin();

// 管理者のみアップロードボタンと管理ボタンを表示
if ($isLoggedIn && $isAdmin) {
  $showUploadButton = true;
  $showAdminButton = true;
}

// 現在のファイル名を取得
$currentFile = basename($_SERVER['PHP_SELF']);
$isLoginPage = $currentFile === 'login.php';

$brandName = Config::get('app.name', 'MyTube');
$userLogo48Rel = 'data/branding/logo-48.png';
$logoPathForTag = is_file(__DIR__ . '/' . $userLogo48Rel) ? $userLogo48Rel : 'images/logo.png';
$logoVersion = getAssetVersion($logoPathForTag);

// 検索（ログイン画面と、共有リンクで開いた画面では出さない。共有リンクの人は検索するとログイン画面に飛ばされるため）
$showSearch = !$isLoginPage && empty($isSharedAccess);
$searchQuery = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';

// メニューの項目
$menuItems = [];
if ($showHomeButton && $currentFile !== 'index.php') {
  $menuItems[] = ['href' => 'index.php', 'label' => 'ホーム', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'];
}
if ($showUploadButton && $currentFile !== 'upload.php') {
  $menuItems[] = ['href' => 'upload.php', 'label' => '動画をアップロード', 'icon' => 'M12 4v16m8-8H4'];
}
if ($showAdminButton && $currentFile !== 'admin.php') {
  $menuItems[] = ['href' => 'admin.php', 'label' => '管理パネル', 'icon' => 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z'];
}
if ($isLoggedIn && $isAdmin && $currentFile !== 'settings.php') {
  $menuItems[] = ['href' => 'settings.php', 'label' => '設定', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'];
}
if ($isLoggedIn && $isAdmin && $currentFile !== 'manual.php') {
  $menuItems[] = ['href' => 'manual.php', 'label' => '使い方', 'icon' => 'M12 4H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4zM9 12h6m-6 4h6'];
}
?>

<!-- ヘッダー -->
<header class="site-header" id="site-header">
  <div class="site-header__inner">
    <a href="index.php" class="site-logo" aria-label="<?= htmlspecialchars($brandName) ?> ホーム">
      <img src="<?= htmlspecialchars($logoPathForTag) ?>?v=<?= $logoVersion ?>" alt="" class="site-logo__icon">
      <span class="site-logo__name" id="site-title"><?= htmlspecialchars($brandName) ?></span>
    </a>

    <?php if ($showSearch): ?>
    <form class="site-search" action="index.php" method="get" role="search">
      <button type="button" class="icon-btn site-search__back" aria-label="検索を閉じる" onclick="toggleHeaderSearch(false)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m7 7l-7-7 7-7"/></svg>
      </button>
      <div class="site-search__box">
        <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="検索" class="site-search__input" aria-label="動画を検索" autocomplete="off" maxlength="100">
        <button type="submit" class="site-search__submit" aria-label="検索">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
        </button>
      </div>
    </form>
    <?php endif; ?>

    <div class="site-header__actions">
      <?php if ($showSearch): ?>
      <button type="button" class="icon-btn site-search__open" aria-label="検索" onclick="toggleHeaderSearch(true)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
      </button>
      <?php endif; ?>

      <?php if ($showUploadButton && $currentFile !== 'upload.php'): ?>
      <a href="upload.php" class="pill-btn site-header__upload" title="動画をアップロード">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 5v14m7-7H5"/></svg>
        <span>アップロード</span>
      </a>
      <?php endif; ?>

      <button type="button" id="theme-toggle-header" class="icon-btn site-header__theme" onclick="ThemeManager.toggleTheme()" title="テーマ切り替え" aria-label="テーマ切り替え">
        <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32l1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
      </button>

      <?php if (!$isLoggedIn && !$isLoginPage): ?>
      <a href="login.php" class="pill-btn pill-btn--outline site-header__login">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21a8 8 0 0116 0"/></svg>
        <span>ログイン</span>
      </a>
      <?php endif; ?>

      <?php if ($isLoggedIn || $menuItems): ?>
      <button type="button" id="mobile-menu-button" class="icon-btn" onclick="toggleMobileMenu()" aria-label="メニュー" aria-haspopup="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- メニュー（右上のボタンで開く） -->
  <div id="mobile-menu" class="hidden site-menu">
    <div class="site-menu__overlay" onclick="toggleMobileMenu()"></div>
    <div id="mobile-menu-content" class="site-menu__panel" role="menu">
      <?php foreach ($menuItems as $item): ?>
      <a href="<?= htmlspecialchars($item['href']) ?>" class="menu-item" role="menuitem">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $item['icon'] ?>"/></svg>
        <span><?= htmlspecialchars($item['label']) ?></span>
      </a>
      <?php endforeach; ?>
      <button type="button" id="theme-toggle-header-mobile" class="menu-item" role="menuitem" onclick="ThemeManager.toggleTheme()">
        <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32l1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
        <span class="theme-text">ダークモード</span>
      </button>
      <?php if ($isLoggedIn): ?>
      <div class="site-menu__divider"></div>
      <button type="button" onclick="adminLogout()" class="menu-item" role="menuitem">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        <span>ログアウト</span>
      </button>
      <?php endif; ?>
    </div>
  </div>
</header>
