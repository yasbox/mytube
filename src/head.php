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

// アセットファイルのバージョン取得（共通関数を使用）
// 関数が存在しない場合のフォールバック
if (!function_exists('getAssetVersion')) {
    function getAssetVersion($filePath) {
        $fullPath = __DIR__ . '/' . $filePath;
        if (file_exists($fullPath)) {
            return filemtime($fullPath);
        }
        return '1.0.0'; // デフォルトバージョン
    }
}

$themeCssVersion = getAssetVersion('assets/css/theme.css');
$headerCssVersion = getAssetVersion('assets/css/header.css');
$cssVersion = getAssetVersion('assets/css/style.css');
$indexCssVersion = getAssetVersion('assets/css/index.css');
$adminCssVersion = getAssetVersion('assets/css/admin.css');

// ロゴ画像とファビコンのバージョン管理を追加
$logoVersion = getAssetVersion('images/logo.png');
$faviconSvgVersion = getAssetVersion('favicon.svg');
$faviconIcoVersion = getAssetVersion('favicon.ico');
$faviconPngVersion = getAssetVersion('favicon.png');
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
  <link rel="icon" type="image/svg+xml" href="favicon.svg?v=<?= $faviconSvgVersion ?>">
  <link rel="icon" type="image/x-icon" href="favicon.ico?v=<?= $faviconIcoVersion ?>">
  <link rel="apple-touch-icon" href="favicon.png?v=<?= $faviconPngVersion ?>">
  
  <?php if ($currentVideo): ?>
  <meta name="current-video" content="<?= htmlspecialchars($currentVideo) ?>">
  <?php endif ?>
  
  <?= $additionalMeta ?>
  
  <!-- Tailwind CSS 設定を先に定義（CDN読み込み前） -->
  <script>
    // Tailwind CSSの設定をカスタマイズ（Preflight無効化）
    tailwind = window.tailwind || {};
    tailwind.config = {
      corePlugins: {
        preflight: false,
      },
      theme: {
        extend: {
          screens: {
            '3xl': '1920px',
            '4xl': '2560px',
            '5xl': '3200px',
          },
          colors: {
            'header-bg': 'var(--header-bg)',
            'header-text': 'var(--header-text)',
            'hamburger-line': 'var(--hamburger-line)',
            'header-border': 'var(--header-border)',
          }
        }
      }
    };
  </script>
  <!-- Tailwind CSS（設定適用後に読み込み） -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- 共通テーマ変数 -->
  <link rel="stylesheet" href="assets/css/theme.css?v=<?= $themeCssVersion ?>">
  <!-- ヘッダー専用スタイル -->
  <link rel="stylesheet" href="assets/css/header.css?v=<?= $headerCssVersion ?>">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssVersion ?>">
  <?php if ($pageCss === 'index'): ?>
  <link rel="stylesheet" href="assets/css/index.css?v=<?= $indexCssVersion ?>">
  <?php elseif ($pageCss === 'admin'): ?>
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= $adminCssVersion ?>">
  <?php endif ?>
  <?= $additionalStyles ?>
  
  <?php 
    $commonJsPath = __DIR__ . '/assets/js/common.js';
    $headerJsPath = __DIR__ . '/assets/js/header.js';
    $commonJsVersion = file_exists($commonJsPath) ? filemtime($commonJsPath) : '1.0.0';
    $headerJsVersion = file_exists($headerJsPath) ? filemtime($headerJsPath) : '1.0.0';
  ?>
  <script src="assets/js/common.js?v=<?= $commonJsVersion ?>" defer></script>
  <script src="assets/js/header.js?v=<?= $headerJsVersion ?>" defer></script>
  <?= $additionalScripts ?>
</head> 