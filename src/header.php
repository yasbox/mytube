<?php

/**
 * 共通ヘッダーコンポーネント
 * 
 * 使用方法:
 * $pageTitle = 'ページタイトル';
 * $showUploadButton = true;
 * $showAdminButton = true;
 * $showHomeButton = true;
 * include 'header.php';
 */

// PHPのログ出力先設定はphp.iniに委譲
require_once __DIR__ . '/security.php';
// ヘッダー読込時にも最速で復元（セッションが未認証なら）
if (function_exists('checkRememberMe')) {
  if (!isUserAuthenticated()) {
    checkRememberMe();
  }
}
$__csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

// デフォルト値の設定
$pageTitle = $pageTitle ?? '';
$showUploadButton = $showUploadButton ?? false;
$showAdminButton = $showAdminButton ?? false;
$showHomeButton = $showHomeButton ?? true;
$isAdminPage = $isAdminPage ?? false;

// ログイン状態をチェック
$isLoggedIn = isUserAuthenticated();
$isAdmin = isAdmin();

// 管理者のみアップロードボタンと管理ボタンを表示
if ($isLoggedIn && $isAdmin) {
  $showUploadButton = $showUploadButton || true;
  $showAdminButton = $showAdminButton || true;
}

// 現在のファイル名を取得
$currentFile = basename($_SERVER['PHP_SELF']);

$userLogo48Rel = 'data/branding/logo-48.png';
$logoPathForTag = is_file(__DIR__ . '/' . $userLogo48Rel) ? $userLogo48Rel : 'images/logo.png';
$logoVersion = file_exists(__DIR__ . '/' . $logoPathForTag) ? filemtime(__DIR__ . '/' . $logoPathForTag) : '1.0.0';

// メニュー項目の数を計算
$menuItems = 0;
if ($showUploadButton && $currentFile !== 'upload.php') $menuItems++;
if ($showAdminButton && $currentFile !== 'admin.php') $menuItems++;
if ($showHomeButton && $currentFile !== 'index.php') $menuItems++;
if ($isAdminPage && $currentFile !== 'admin.php') $menuItems++;
if (!$isLoggedIn && $currentFile !== 'login.php') $menuItems++; // ログインボタンを追加
if ($isLoggedIn && $currentFile !== 'login.php') $menuItems++; // ログアウトボタンを追加

// メニュー項目がない場合はハンバーガーボタンを非表示
$hideHamburger = $menuItems === 0;
?>

<!-- ヘッダー -->
<header class="glass-effect-header sticky top-0 z-50">
  <div class="max-w-none mx-auto px-0 md:px-4 py-0">
    <div class="flex items-center justify-between">
      <a href="index.php" class="flex items-center space-x-2 md:space-x-4 lg:space-x-6 hover:opacity-80 transition-opacity duration-300">
        <div class="flex items-center space-x-2 md:space-x-3">
          <?php $brandName = Config::get('app.name', 'MyTube'); ?>
          <img src="<?= htmlspecialchars($logoPathForTag) ?>?v=<?= $logoVersion ?>" alt="<?= htmlspecialchars($brandName) ?>" class="w-8 h-8 md:w-9 md:h-9 object-contain">
          <!-- サイトタイトル -->
          <h1 id="site-title" class="mb-0 text-xl md:text-2xl font-bold text-primary"><?= htmlspecialchars($brandName) ?></h1>
        </div>
      </a>

      <!-- ハンバーガーメニューボタン -->
      <button
        id="mobile-menu-button"
        class="w-11 h-11 flex items-center justify-center transition-all duration-200 p-0 min-h-11 min-w-11 text-base font-normal <?= $hideHamburger ? 'hidden' : '' ?>"
        onclick="toggleMobileMenu()">
        <div class="flex flex-col space-y-[10px]" id="hamburger-icon">
          <span class="w-[30px] h-[2px] rounded-full transition-all duration-200 transform origin-center"></span>
          <span class="w-[30px] h-[2px] rounded-full transition-all duration-200"></span>
          <span class="w-[30px] h-[2px] rounded-full transition-all duration-200 transform origin-center"></span>
        </div>
      </button>
    </div>

    <!-- ドロップダウンメニュー -->
    <div id="mobile-menu" class="hidden fixed inset-0 z-[9999]">
      <!-- 背景オーバーレイ -->
      <div class="mobile-menu-overlay" onclick="toggleMobileMenu()"></div>

      <!-- メニューコンテンツ -->
      <div id="mobile-menu-content" class="fixed top-0 right-0 w-60 sm:w-64 md:w-72 max-h-screen bg-white shadow-2xl flex flex-col mobile-menu-content">
        <div class="p-4 space-y-3 overflow-y-auto overflow-x-hidden flex-1">
          <?php if ($showHomeButton && $currentFile !== 'index.php'): ?>
            <a href="index.php" class="menu-link block w-full">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <span class="menu-text font-semibold text-sm">ホーム</span>
              </div>
            </a>
          <?php endif; ?>

          <?php if ($showUploadButton && $currentFile !== 'upload.php'): ?>
            <a href="upload.php" class="menu-link block w-full">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <span class="menu-text font-semibold text-sm">動画アップロード</span>
              </div>
            </a>
          <?php endif; ?>

          <?php if ($showAdminButton && $currentFile !== 'admin.php'): ?>
            <a href="admin.php" class="menu-link block w-full">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                <span class="menu-text font-semibold text-sm">管理パネル</span>
              </div>
            </a>
          <?php endif; ?>
          
          <?php if ($isAdminPage && $isLoggedIn && $currentFile !== 'settings.php'): ?>
            <a href="settings.php" class="menu-link block w-full">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span class="menu-text font-semibold text-sm">設定</span>
              </div>
            </a>
          <?php endif; ?>

          <?php if ($isLoggedIn && $isAdmin && $currentFile !== 'manual.php'): ?>
            <a href="manual.php" class="menu-link block w-full">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4H8a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2V8l-4-4z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6" />
                </svg>
                <span class="menu-text font-semibold text-sm">使い方</span>
              </div>
            </a>
          <?php endif; ?>

          <?php if (!$isLoggedIn && $currentFile !== 'login.php'): ?>
            <a href="login.php" class="menu-link block w-full">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                </svg>
                <span class="menu-text font-semibold text-sm">ログイン</span>
              </div>
            </a>
          <?php elseif ($isLoggedIn): ?>
            <button
              onclick="adminLogout()"
              class="menu-link block w-full text-left">
              <div class="flex items-center space-x-3">
                <svg class="w-5 h-5 menu-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                <span class="menu-text font-semibold text-sm">ログアウト</span>
              </div>
            </button>
          <?php endif; ?>

          <!-- テーマ切り替えボタン -->
          <button
            id="theme-toggle-header-mobile"
            class="menu-link block w-full text-left"
            onclick="ThemeManager.toggleTheme()"
            title="テーマ切り替え">
            <div class="flex items-center space-x-3">
              <span class="theme-icon menu-icon flex-shrink-0">🌙</span>
              <span class="menu-text font-semibold text-sm">テーマ切り替え</span>
            </div>
          </button>
        </div>
      </div>
    </div>
  </div>
</header>