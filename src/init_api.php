<?php
// 共通初期化（API用）
// 重要: 先頭で読み込むこと（出力前）

// セッション開始（安全な設定で）
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

// 環境・設定の初期化（.env/Config/ログ設定）
require_once __DIR__ . '/core/Bootstrap.php';
Bootstrap::init();

// 共通関数・管理関数
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_functions.php';

// セキュリティ（Remember Me 復元のみ使用。ヘッダーはAPIでは不要）
require_once __DIR__ . '/security.php';
if (function_exists('checkRememberMe')) {
	checkRememberMe();
}


