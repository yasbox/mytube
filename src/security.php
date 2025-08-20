<?php
// セキュリティ機能

// PHPのログ出力先設定はphp.iniに委譲

// 互換レイヤーは不要化。BootstrapでConfigを初期化済み。

// パスワードハッシュ化関数（将来的な拡張用）
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// パスワード検証関数（将来的な拡張用）
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// この関数は functions.php で定義済み

// リメンバーミー機能のチェック
function checkRememberMe() {
    // 既にログインしている場合は何もしない
    if (isUserAuthenticated()) {
        return;
    }
    
    // リメンバーミークッキーをチェック
    $cookieName = Config::get('security.remember_me_cookie_name', 'MyTube_remember');
    if (isset($_COOKIE[$cookieName])) {
        $rememberToken = $_COOKIE[$cookieName];
        @error_log('checkRememberMe: cookie detected');
        
        // トークンを検証
        if (validateRememberToken($rememberToken)) {
            @error_log('checkRememberMe: token validated');
            // 自動ログイン
            $tokenData = decodeRememberToken($rememberToken);
            if ($tokenData) {
                @error_log('checkRememberMe: decoded role=' . ($tokenData['role'] ?? 'unknown'));
                $_SESSION['user_authenticated'] = true;
                $_SESSION['user_role'] = $tokenData['role'];
                $_SESSION['user_login_time'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                
                // 後方互換性のため
                if ($tokenData['role'] === 'admin') {
                    $_SESSION['admin_authenticated'] = true;
                    $_SESSION['admin_login_time'] = time();
                    $_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                }
                
                // トークンを更新
                setRememberMeCookie($tokenData['role']);
                @error_log('checkRememberMe: session restored');
            }
        } else {
            // 無効なトークンは削除
            clearRememberMeCookie();
            @error_log('checkRememberMe: token invalid, cookie cleared');
        }
    }
}

// リメンバーミートークンの生成
function generateRememberToken($role) {
    $data = [
        'role' => $role,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created' => time()
    ];
    
    $jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // HMACシークレットは管理者パスワード + パスワード（JSON）
    $userPassword = (string)Config::get('security.user_password', '');
    $adminPassword = (string)Config::get('security.admin_password', 'admin123');
    $secret = $adminPassword . '|' . $userPassword;
    $signature = hash_hmac('sha256', $jsonData, $secret);
    
    return base64_encode($jsonData . '.' . $signature);
}

// リメンバーミートークンの検証
function validateRememberToken($token) {
    try {
        $decoded = base64_decode($token);
        // 末尾の区切り（署名直前のドット）で分割する（JSON内のドット対策）
        $dotPos = strrpos($decoded, '.');
        if ($dotPos === false) {
            return false;
        }
        $jsonData = substr($decoded, 0, $dotPos);
        $signature = substr($decoded, $dotPos + 1);
        
        // 署名を検証
        $userPassword = (string)Config::get('security.user_password', '');
        $adminPassword = (string)Config::get('security.admin_password', 'admin123');
        $secret = $adminPassword . '|' . $userPassword;
        $expectedSignature = hash_hmac('sha256', $jsonData, $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }
        
        $data = json_decode($jsonData, true);
        if (!$data) {
            return false;
        }
        
        // 有効期限をチェック
        $rememberLifetime = (int)Config::get('security.remember_me_lifetime', 30 * 24 * 3600);
        if (time() - $data['created'] > $rememberLifetime) {
            return false;
        }
        
        // IPアドレス検証を削除（同じデバイスでIPが変わる可能性があるため）
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// リメンバーミートークンのデコード
function decodeRememberToken($token) {
    try {
        $decoded = base64_decode($token);
        // 末尾の区切り（署名直前のドット）で分割する（JSON内のドット対策）
        $dotPos = strrpos($decoded, '.');
        if ($dotPos === false) {
            return null;
        }
        $jsonData = substr($decoded, 0, $dotPos);
        $data = json_decode($jsonData, true);
        
        return $data;
    } catch (Exception $e) {
        return null;
    }
}

// リメンバーミークッキーの設定
function setRememberMeCookie($role) {
    $token = generateRememberToken($role);
    $secure = isset($_SERVER['HTTPS']);
    $httponly = true;
    $samesite = 'Lax'; // StrictからLaxに変更してより確実に動作させる
    
    // クッキーを削除してから再設定
    $cookieName = Config::get('security.remember_me_cookie_name', 'MyTube_remember');
    setcookie(
        $cookieName,
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite
        ]
    );
    
    // 新しいクッキーを設定
    setcookie(
        $cookieName,
        $token,
        [
            'expires' => time() + (int)Config::get('security.remember_me_lifetime', 30 * 24 * 3600),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite
        ]
    );
    
    // クッキーが即座に利用可能になるように手動で設定
    $_COOKIE[$cookieName] = $token;
}

// この関数は functions.php で定義済み

// CSRFトークン生成
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRFトークン検証
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// 入力値のサニタイズ
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ファイルアップロードのセキュリティチェック
function validateUploadedFile($file) {
    $errors = [];
    
    // ファイルサイズチェックは削除（チャンクアップロードにより不要）
    
    // MIMEタイプチェック
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedFormats = Config::get('features.upload.allowed_formats', ['mp4','webm','ogg','avi','mov','mkv','flv']);
    $allowedTypes = array_map(fn($f) => Config::get("video.mime_types.$f", "video/$f"), $allowedFormats);
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = '許可されていないファイル形式です';
    }
    
    // ファイル名のセキュリティチェック
    $filename = $file['name'];
    if (preg_match('/[<>:"\/\\|?*]/', $filename)) {
        $errors[] = 'ファイル名に使用できない文字が含まれています';
    }
    
    return $errors;
}

// レート制限（ログイン試行回数制限）
function checkLoginRateLimit($ip) {
    // レート制限機能を完全に削除
    return true;
}

// ログイン試行を記録
function recordLoginAttempt($ip, $success) {
    // レート制限機能を完全に削除
    return;
}

// セキュリティヘッダーの設定
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    if (isset($_SERVER['HTTPS'])) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
} 