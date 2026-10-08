<?php
/**
 * 共通ヘッドコンポーネント
 * 
 * 使用方法:
 * $pageTitle = 'ページタイトル';
 * $additionalMeta = '<meta name="custom" content="value">';
 * $additionalStyles = '<link rel="stylesheet" href="custom.css">';
 * $additionalScripts = '<script src="custom.js"></script>';
 * include 'head.php';
 */

// PHPのログ出力先設定はphp.iniに委譲

// CSRFメタ用にトークンを用意（各ページで事前にセッション開始済み前提）
require_once __DIR__ . '/security.php';
$__csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

// デフォルト値の設定
$defaultAppName = class_exists('Config') ? (Config::get('app.name', 'MyTube')) : 'MyTube';
$pageTitle = $pageTitle ?? $defaultAppName;
$additionalMeta = $additionalMeta ?? '';
$additionalStyles = $additionalStyles ?? '';
$additionalScripts = $additionalScripts ?? '';
$currentVideo = $currentVideo ?? null;
$pageCss = $pageCss ?? 'style'; // デフォルトはstyle.cssのみ

$themeCssVersion = getAssetVersion('assets/css/theme.css');
$headerCssVersion = getAssetVersion('assets/css/header.css');
$cssVersion = getAssetVersion('assets/css/style.css');
$adminCssVersion = getAssetVersion('assets/css/admin.css');

// ロゴ/ファビコン: ユーザー上書きがあればそれを使い、なければデフォルト
$brandDir = __DIR__ . '/data/branding';
$userLogo48 = 'data/branding/logo-48.png';
$userLogo96 = 'data/branding/logo-96.png';
$userFav16 = 'data/branding/favicon-16x16.png';
$userFav32 = 'data/branding/favicon-32x32.png';
$userApple = 'data/branding/apple-touch-icon.png';
$userA192 = 'data/branding/android-chrome-192x192.png';
$userA512 = 'data/branding/android-chrome-512x512.png';

// バージョン（キャッシュバスター）はファイルmtimeを使用
$logoVersion = is_file(__DIR__ . '/' . $userLogo48) ? filemtime(__DIR__ . '/' . $userLogo48) : getAssetVersion('images/logo.png');
$faviconSvgVersion = getAssetVersion('favicon.svg');
$faviconIcoVersion = getAssetVersion('favicon.ico');
$faviconPngVersion = is_file(__DIR__ . '/' . $userApple) ? filemtime(__DIR__ . '/' . $userApple) : getAssetVersion('favicon.png');
?>
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-name" content="<?= htmlspecialchars($defaultAppName) ?>">
  <?php $siteDescription = class_exists('Config') ? (string)Config::get('app.description', '') : ''; ?>
  <?php if ($siteDescription !== ''): ?>
  <meta name="description" content="<?= htmlspecialchars($siteDescription) ?>">
  <?php endif; ?>
  <?php $defaultTheme = class_exists('Config') ? (Config::get('ui.theme', 'light')) : 'light'; ?>
  <meta name="default-theme" content="<?= htmlspecialchars(in_array(strtolower($defaultTheme), ['light','dark']) ? strtolower($defaultTheme) : 'light') ?>">
  <script>
    (function(){
      try {
        var meta = document.querySelector('meta[name="default-theme"]');
        var preferred = (localStorage.getItem('theme') || (meta && meta.content) || 'light').toLowerCase();
        if (preferred === 'dark') {
          document.documentElement.setAttribute('data-theme', 'dark');
        } else {
          document.documentElement.removeAttribute('data-theme');
        }
      } catch(_) { /* noop */ }
    })();
  </script>
  <?php if ($__csrfToken): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars($__csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif ?>
  
  <!-- ファビコン -->
  <?php if (is_file(__DIR__ . '/' . $userFav32)): ?>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($userFav32) ?>?v=<?= filemtime(__DIR__ . '/' . $userFav32) ?>">
  <?php else: ?>
  <link rel="icon" type="image/svg+xml" href="favicon.svg?v=<?= $faviconSvgVersion ?>">
  <link rel="icon" type="image/x-icon" href="favicon.ico?v=<?= $faviconIcoVersion ?>">
  <?php endif; ?>
  <?php if (is_file(__DIR__ . '/' . $userFav16)): ?>
  <link rel="icon" type="image/png" sizes="16x16" href="<?= htmlspecialchars($userFav16) ?>?v=<?= filemtime(__DIR__ . '/' . $userFav16) ?>">
  <?php endif; ?>
  <?php if (is_file(__DIR__ . '/' . $userApple)): ?>
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($userApple) ?>?v=<?= filemtime(__DIR__ . '/' . $userApple) ?>">
  <?php else: ?>
  <link rel="apple-touch-icon" href="favicon.png?v=<?= $faviconPngVersion ?>">
  <?php endif; ?>
  
  <?php if ($currentVideo): ?>
  <meta name="current-video" content="<?= htmlspecialchars($currentVideo) ?>">
  <?php endif ?>
  
  <?= $additionalMeta ?>
  
  <!-- 共通テーマ変数 -->
  <link rel="stylesheet" href="assets/css/theme.css?v=<?= $themeCssVersion ?>">
  <!-- ヘッダー専用スタイル -->
  <link rel="stylesheet" href="assets/css/header.css?v=<?= $headerCssVersion ?>">
  <!-- 共通の部品（ボタン・入力欄・スイッチ・ダイアログなど） -->
  <link rel="stylesheet" href="assets/css/ui.css?v=<?= getAssetVersion('assets/css/ui.css') ?>">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
  <?php if ($pageCss === 'home'): ?>
  <link rel="stylesheet" href="assets/css/home.css?v=<?= getAssetVersion('assets/css/home.css') ?>">
  <?php elseif ($pageCss === 'admin'): ?>
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= $adminCssVersion ?>">
  <?php endif ?>
  <?= $additionalStyles ?>
  <!-- Tailwind CSS（`npm run build:css` で作ったもの。tailwind.config.js 参照）
       ほかの CSS と同じ強さのルールはこちらが勝つよう、最後に読み込む（以前の CDN 版と同じ順番） -->
  <link rel="stylesheet" href="assets/css/tailwind.css?v=<?= getAssetVersion('assets/css/tailwind.css') ?>">

  <script src="assets/js/common.js?v=<?= getAssetVersion('assets/js/common.js') ?>" defer></script>
  <script src="assets/js/header.js?v=<?= getAssetVersion('assets/js/header.js') ?>" defer></script>
  <?= $additionalScripts ?>
</head> 