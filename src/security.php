<?php
// セキュリティ機能

// PHPのログ出力先設定はphp.iniに委譲

// 互換レイヤーは不要化。BootstrapでConfigを初期化済み。

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

// リメンバーミートークンの署名に使う鍵（管理者パスワード + 閲覧者パスワード。どちらかが変わると既存のトークンは無効）
// 管理者パスワードは、環境変数で管理している場合はその値（従来どおり）、
// 画面から変更した場合は平文を保存していないためハッシュを使う
function rememberTokenSecret(): string {
    $envAdmin = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: '';
    $hash = Config::get('security.admin_password_hash');
    if ((!is_string($envAdmin) || $envAdmin === '') && is_string($hash) && $hash !== '') {
        $adminPart = $hash;
    } else {
        $adminPart = (string)Config::get('security.admin_password', '');
    }
    return $adminPart . '|' . (string)Config::get('security.user_password', '');
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
    $signature = hash_hmac('sha256', $jsonData, rememberTokenSecret());
    
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
        $expectedSignature = hash_hmac('sha256', $jsonData, rememberTokenSecret());
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

// ログイン試行回数の制限
// 同じ接続元から一定時間内に一定回数ログインに失敗したら、一定時間ログインを受け付けない。
// - 数えるのは失敗だけ（ログイン1回につき1回）。成功したらその接続元の失敗回数をリセットする
//   （以前の実装は成功も数えていたため、普通に使っていても締め出されていた）
// - 接続元は REMOTE_ADDR で判定する（X-Forwarded-For 等のヘッダーは偽装できるため使わない）
// - 記録はインスタンスごとに data/secure に置き、IP アドレスはハッシュで保存する

function loginAttemptsPath(): string {
    return dirname(Config::getSecureAdminPasswordPath()) . DIRECTORY_SEPARATOR . 'login_attempts.json';
}

function loginAttemptKey(string $ip): string {
    return substr(hash('sha256', $ip), 0, 32);
}

/**
 * ログイン試行の記録を排他しながら読み書きする
 * $update は記録（配列）を参照で受け取って書き換え、戻り値をそのまま返す。
 * 記録できない環境では制限せずに通す（ログインできなくなるよりよいため）
 */
function withLoginAttempts(callable $update, bool $write = true) {
    $fp = @fopen(loginAttemptsPath(), $write ? 'c+' : 'r');
    if (!$fp) {
        $entries = [];
        return $update($entries);
    }
    flock($fp, $write ? LOCK_EX : LOCK_SH);
    $entries = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($entries)) {
        $entries = [];
    }
    $result = $update($entries);
    if ($write) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($entries));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

/**
 * ログインが制限中なら残り秒数、受け付けてよければ 0 を返す
 */
function getLoginLockRemaining(string $ip): int {
    $key = loginAttemptKey($ip);
    return withLoginAttempts(function (array &$entries) use ($key) {
        return max(0, (int)($entries[$key]['locked_until'] ?? 0) - time());
    }, false);
}

/**
 * ログインの結果を記録する（成功なら失敗回数をリセット、失敗が上限に達したら制限をかける）
 */
function recordLoginAttempt(string $ip, bool $success): void {
    $key = loginAttemptKey($ip);
    $maxFailures = max(1, (int)Config::get('security.login_max_failures', 10));
    $window = max(60, (int)Config::get('security.login_lockout_seconds', 900));
    withLoginAttempts(function (array &$entries) use ($key, $success, $maxFailures, $window) {
        $now = time();
        // 期限切れの記録を掃除
        foreach ($entries as $k => $entry) {
            if ((int)($entry['locked_until'] ?? 0) <= $now && (int)($entry['last'] ?? 0) <= $now - $window) {
                unset($entries[$k]);
            }
        }
        if ($success) {
            unset($entries[$key]);
            return;
        }
        $entry = $entries[$key] ?? ['failures' => [], 'locked_until' => 0];
        $failures = array_values(array_filter((array)($entry['failures'] ?? []), function ($t) use ($now, $window) {
            return (int)$t > $now - $window;
        }));
        $failures[] = $now;
        $entry['last'] = $now;
        if (count($failures) >= $maxFailures) {
            $entry['locked_until'] = $now + $window;
            $failures = [];
        }
        $entry['failures'] = $failures;
        $entries[$key] = $entry;
    });
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