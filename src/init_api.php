<?php
// 共通初期化（API用）
// 重要: 先頭で読み込むこと（出力前）

// セッション開始（ページと同じ安全な設定で。Cookie に Secure・HttpOnly を付ける）
require_once __DIR__ . '/functions.php';
secureSession();

// 環境・設定の初期化（.env/Config/ログ設定）
require_once __DIR__ . '/core/Bootstrap.php';
Bootstrap::init();

// 管理関数
require_once __DIR__ . '/admin_functions.php';

// セキュリティ（Remember Me 復元のみ使用。ヘッダーはAPIでは不要）
require_once __DIR__ . '/security.php';
if (function_exists('checkRememberMe')) {
	checkRememberMe();
}


