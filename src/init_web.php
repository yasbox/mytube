<?php
// 共通初期化（Webページ用）
// 重要: 先頭で読み込むこと（出力前）

// セッション開始（安全な設定で）
require_once __DIR__ . '/functions.php';
secureSession();

// 環境・設定の初期化（.env/Config/ログ設定）
require_once __DIR__ . '/core/Bootstrap.php';
Bootstrap::init();

// セキュリティ関連
require_once __DIR__ . '/security.php';
if (function_exists('setSecurityHeaders')) {
	setSecurityHeaders();
}

// リメンバーミーでセッション復元
if (function_exists('checkRememberMe')) {
	checkRememberMe();
}

// 管理系関数（多くのページで使用するため先に読み込む）
require_once __DIR__ . '/admin_functions.php';


