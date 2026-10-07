<?php
// 管理機能用の関数

// PHPのログ出力先設定はphp.iniに委譲

require_once 'functions.php';
require_once 'security.php';
require_once 'video_converter.php';

// セキュリティセッションを開始
secureSession();

// これらの関数は functions.php で定義済み

// 管理者認証チェック
function isAdminAuthenticated() {
    $authenticated = isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;
    
    // セッションタイムアウトチェック
    if ($authenticated && isset($_SESSION['admin_login_time'])) {
        $sessionAge = time() - $_SESSION['admin_login_time'];
        $adminSessionLifetime = (int)Config::get('security.admin_session_lifetime', 30 * 24 * 3600);
        if ($sessionAge > $adminSessionLifetime) {
            // セッションが期限切れ
            adminLogout();
            $authenticated = false;
        }
    }
    
    return $authenticated;
}

// この関数は functions.php で定義済み

// 管理者権限チェック
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// 一般ユーザー権限チェック
function isRegularUser() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'user';
}

// 管理者ログイン
function adminLogin($password, $rememberMe = false) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $input = normalizeUserPasswordForCompare($password) ?? '';
    $adminPassword = (string)Config::get('security.admin_password', 'admin123');
    $admin = normalizeUserPasswordForCompare($adminPassword) ?? '';
    $success = ($input !== '' && $admin !== '' && hash_equals($admin, $input));
    
    if ($success) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        
        $_SESSION['user_authenticated'] = true;
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_login_time'] = time();
        $_SESSION['user_ip'] = $ip;
        
        // 後方互換性のため
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_login_time'] = time();
        $_SESSION['admin_ip'] = $ip;
        
        
        
        // リメンバーミー機能
        if ($rememberMe) {
            setRememberMeCookie('admin');
        }
        // 復旧端末を自動登録
        if (isRecoveryDeviceEnabled()) {
            registerRecoveryDeviceForCurrentClient();
        }
        
        return true;
    }
    
    
    return false;
}

// 一般ユーザーログイン
function userLogin($password, $rememberMe = false) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    
    $configuredUserPassword = Config::get('security.user_password', null);
    if (!is_string($configuredUserPassword) || $configuredUserPassword === '') {
        // フォールバック: 直接 JSON を参照
        if (method_exists('Config', 'getSettingsJsonPath')) {
            $path = Config::getSettingsJsonPath();
            if (is_file($path) && is_readable($path)) {
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    $dec = json_decode($raw, true);
                    if (is_array($dec) && isset($dec['security']['user_password'])) {
                        $configuredUserPassword = (string)$dec['security']['user_password'];
                    }
                }
            }
        }
    }
    
    $inputPassword = normalizeUserPasswordForCompare($password);
    $storedPassword = normalizeUserPasswordForCompare($configuredUserPassword);
    $success = ($storedPassword !== null && $inputPassword !== null && hash_equals($storedPassword, $inputPassword));
    
    if ($success) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        
        $_SESSION['user_authenticated'] = true;
        $_SESSION['user_role'] = 'user';
        $_SESSION['user_login_time'] = time();
        $_SESSION['user_ip'] = $ip;
        
        
        
        // リメンバーミー機能
        if ($rememberMe) {
            setRememberMeCookie('user');
        }
        
        return true;
    }
    
    
    return false;
}

/**
 * 管理者パスワードが環境変数（.env の ADMIN_PASSWORD）で管理されているか
 * この場合は UI からの変更・復旧端末による再設定はできない
 */
function isAdminPasswordManagedByEnv(): bool {
    $envAdmin = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: null;
    return is_string($envAdmin) && $envAdmin !== '';
}

/**
 * 復旧端末による管理者パスワード再設定が使えるか
 * （RECOVERY_DEVICE_DISABLED=true、または管理者パスワードを環境変数で管理している場合は使えない）
 */
function isRecoveryDeviceEnabled(): bool {
    $disabled = filter_var(($_ENV['RECOVERY_DEVICE_DISABLED'] ?? $_SERVER['RECOVERY_DEVICE_DISABLED'] ?? getenv('RECOVERY_DEVICE_DISABLED') ?: 'false'), FILTER_VALIDATE_BOOLEAN);
    return !$disabled && !isAdminPasswordManagedByEnv();
}

/**
 * 復旧端末を自動登録: クッキー未設定なら新規発行、設定済みなら最終使用日時を更新
 */
function registerRecoveryDeviceForCurrentClient(): void {
    $cookieName = 'MyTube_recovery_device';
    $secure = isset($_SERVER['HTTPS']);
    $httpOnly = true;
    $sameSite = 'Lax';

    $token = $_COOKIE[$cookieName] ?? '';
    if (!is_string($token) || $token === '') {
        // 新規発行（32バイト）
        $token = bin2hex(random_bytes(32));
        setcookie($cookieName, $token, [
            'expires' => time() + (365 * 24 * 3600),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ]);
        // 即時参照可能に
        $_COOKIE[$cookieName] = $token;
    }

    $hash = hash('sha256', $token);
    $path = Config::getRecoveryDevicesPath();
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $list = ['tokens' => []];
    if (is_readable($path)) {
        $raw = @file_get_contents($path);
        $dec = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($dec) && isset($dec['tokens']) && is_array($dec['tokens'])) {
            $list = $dec;
        }
    }
    // 既存を検索
    $found = false;
    $now = time();
    foreach ($list['tokens'] as &$entry) {
        if (($entry['hash'] ?? '') === $hash) {
            $entry['last_used'] = $now;
            $found = true;
            break;
        }
    }
    unset($entry);
    if (!$found) {
        // 上限10件で古いものから削除
        if (count($list['tokens']) >= 10) {
            usort($list['tokens'], function($a,$b){ return ($a['last_used'] ?? 0) <=> ($b['last_used'] ?? 0); });
            $list['tokens'] = array_slice($list['tokens'], -9);
        }
        $list['tokens'][] = [
            'hash' => $hash,
            'created' => $now,
            'last_used' => $now
        ];
    }
    $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json !== false) {
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, $path);
    }
}

// 比較用にユーザーパスワードを正規化
function normalizeUserPasswordForCompare($value): ?string {
    if (!is_string($value)) {
        return null;
    }
    $normalized = trim($value);
    if ($normalized === '') {
        return null;
    }
    return $normalized;
}

// 統合ログイン（管理者または一般ユーザー）
function login($password, $rememberMe = false) {
    
    
    // まず管理者としてログインを試行
    if (adminLogin($password, $rememberMe)) {
        
        return ['success' => true, 'role' => 'admin'];
    }
    
    // 管理者ログインが失敗した場合、一般ユーザーとしてログインを試行
    if (userLogin($password, $rememberMe)) {
        return ['success' => true, 'role' => 'user'];
    }
    
    
    return ['success' => false, 'role' => null];
}

// 管理者ログアウト
function adminLogout() {
    unset($_SESSION['admin_authenticated']);
    unset($_SESSION['admin_login_time']);
    unset($_SESSION['admin_ip']);
    unset($_SESSION['user_authenticated']);
    unset($_SESSION['user_role']);
    unset($_SESSION['user_login_time']);
    unset($_SESSION['user_ip']);
    unset($_SESSION['csrf_token']);
    
    // リメンバーミークッキーを削除
    clearRememberMeCookie();
    
    session_destroy();
}

// 一般ユーザーログアウト
// この関数は functions.php で定義済み

// 統合ログアウト
function logout() {
    userLogout();
}

// リダイレクト先のURLが安全かどうかをチェック
// この関数は functions.php で定義済み

// この関数は functions.php で定義済み

// 管理者権限が必要なページのチェック関数
function requireAdminAuthentication() {
    if (!isUserAuthenticated()) {
        // 現在のURLをセッションに保存（ログイン後に戻るため）
        $currentUrl = $_SERVER['REQUEST_URI'];
        
        // APIリクエストの場合はリダイレクト先を保存しない
        if (isset($_GET['action']) || isset($_POST['action']) || 
            strpos($currentUrl, 'action=') !== false || 
            strpos($currentUrl, 'api') !== false) {
            // 出力バッファをクリアしてリダイレクト
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Location: login.php');
            exit;
        }
        
        // 実際のページアクセスの場合のみリダイレクト先を保存
        // 管理者ページの場合
        if (strpos($currentUrl, 'admin.php') !== false || 
            strpos($currentUrl, 'upload.php') !== false) {
            
            // URLが安全な場合のみ保存
            if (isSafeRedirectUrl($currentUrl)) {
                $_SESSION['redirect_after_login'] = $currentUrl;
            }
        }
        
        // 出力バッファをクリアしてリダイレクト
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // ログインページにリダイレクト
        header('Location: login.php');
        exit;
    }
    
    if (!isAdmin()) {
        // 管理者権限がない場合はトップページにリダイレクト
        header('Location: index.php');
        exit;
    }
}

// ログインページかどうかをチェックする関数
function isLoginPage() {
    $currentPage = basename($_SERVER['PHP_SELF']);
    return $currentPage === 'login.php';
} 

// 動画変換開始関数
function startVideoConversion($videoFile) {
    $videos = Functions::getVideoFiles();
    
    if (!in_array($videoFile, $videos)) {
        return ['success' => false, 'message' => '動画ファイルが見つかりません'];
    }
    
    $basename = pathinfo($videoFile, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));
    
    // 変換可能な形式かチェック
    if (!isConvertibleFormat($ext)) {
        return ['success' => false, 'message' => 'このファイル形式は変換できません'];
    }
    
    // 既に変換中かチェック
    $conversionStatus = getConversionStatus($videoFile);
    if ($conversionStatus['status'] === 'converting') {
        return ['success' => false, 'message' => '既に変換中です'];
    }
    
    // 変換を開始
    $inputPath = "videos/{$videoFile}";
    
    // 新しいファイル名を生成（元のファイル名に_convert_タイムスタンプを追加）
    $timestamp = time();
    $newBasename = $basename . '_convert_' . $timestamp;
    $outputPath = "videos/{$newBasename}.mp4";

    $result = convertVideoToMp4($inputPath, $outputPath, $newBasename);
    
    if ($result['success']) {
        // 変換状態を保存
        saveConversionStatus($videoFile, [
            'status' => 'converting',
            'pid' => $result['pid'],
            'progress_file' => $result['progress_file'],
            'start_time' => $result['start_time'],
            'total_duration' => $result['total_duration'],
            'progress' => 0,
            'message' => '変換を開始しました',
            'temp_output_path' => $outputPath,
            'new_basename' => $newBasename,
            'original_basename' => $basename,
            'is_reencode' => ($ext === 'mp4')
        ]);
        
        return ['success' => true, 'message' => '変換を開始しました'];
    } else {
        return $result;
    }
}

// 変換進捗取得関数
function getConversionProgress($videoFile) {
    $videos = Functions::getVideoFiles();
    
    if (!in_array($videoFile, $videos)) {
        return ['success' => false, 'message' => '動画ファイルが見つかりません'];
    }
    
    $conversionStatus = getConversionStatus($videoFile);
    
    if ($conversionStatus['status'] === 'not_found') {
        return ['success' => false, 'message' => '変換が開始されていません'];
    }
    
    if ($conversionStatus['status'] === 'converting') {
        // FFmpegの進捗を取得
        $ffmpegProgress = getFfmpegProgress(
            $conversionStatus['progress_file'],
            $conversionStatus['pid'],
            $conversionStatus['total_duration']
        );
        
        // 進捗を更新
        $conversionStatus['progress'] = $ffmpegProgress['progress'];
        $conversionStatus['message'] = "変換中... {$ffmpegProgress['progress']}%";
        
        // 変換完了チェック
        if ($ffmpegProgress['status'] === 'completed') {
            $newBasename = $conversionStatus['new_basename'] ?? pathinfo($videoFile, PATHINFO_FILENAME);
            $originalBasename = $conversionStatus['original_basename'] ?? pathinfo($videoFile, PATHINFO_FILENAME);
            $mp4Path = $conversionStatus['temp_output_path'] ?? "videos/{$newBasename}.mp4";
            
            if (file_exists($mp4Path)) {
                // 元のメタデータを取得（共有パスワードを含むためログには出さない）
                $originalMetadata = Functions::getVideoMetadata($originalBasename);

                // 新しいメタデータを作成（元のメタデータを複製）
                $newMetadata = $originalMetadata;
                $newMetadata['filename'] = $newBasename . '.mp4';
                $newMetadata['upload_date'] = date('Y-m-d H:i:s');
                $newMetadata['views'] = 0; // 新しい動画なので再生数は0から開始
                $newMetadata['likes'] = 0; // 新しい動画なのでいいね数は0から開始
                
                // 新しいメタデータを保存
                Functions::saveVideoMetadata($newBasename, $newMetadata);
                error_log("New metadata created for converted video: {$newBasename}");
                
                // サムネイルを処理
                $newThumbnailPath = "thumbnails/{$newBasename}.jpg";
                
                // 新しい動画からサムネイルを生成
                if (!is_dir('thumbnails')) {
                    mkdir('thumbnails', 0755, true);
                }
                
                if (generateThumbnail($mp4Path, $newThumbnailPath)) {
                    error_log("New thumbnail generated for converted video: {$newThumbnailPath}");
                } else {
                    error_log("Failed to generate thumbnail for converted video: {$newThumbnailPath}");
                }
                
                // 進捗ファイルを削除
                if (file_exists($conversionStatus['progress_file'])) {
                    unlink($conversionStatus['progress_file']);
                }
                
                // 変換状態ファイルを削除（完了後は不要）
                clearConversionStatus($videoFile);
                
                return [
                    'success' => true,
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => '変換完了'
                ];
            } else {
                $conversionStatus['status'] = 'failed';
                $conversionStatus['message'] = '変換に失敗しました';
                
                // 変換失敗時も状態ファイルを削除
                clearConversionStatus($videoFile);
            }
        }
        
        // 変換中の場合のみ状態を保存
        if ($conversionStatus['status'] === 'converting') {
            saveConversionStatus($videoFile, $conversionStatus);
        }
    }
    
    return [
        'success' => true,
        'status' => $conversionStatus['status'],
        'progress' => $conversionStatus['progress'],
        'message' => $conversionStatus['message']
    ];
}

// サムネイル生成関数（既に定義済みなら再定義しない）
if (!function_exists('generateThumbnail')) {
    function generateThumbnail($videoPath, $thumbnailPath) {
        // thumbnailsディレクトリが存在しない場合は作成
        $thumbnailDir = dirname($thumbnailPath);
        if (!is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }

        // 長辺1000pxへ縮小（比率維持／アップスケールなし）
        $vf = "scale='if(gte(iw,ih),min(iw,1000),-2)':'if(gte(iw,ih),-2,min(ih,1000))'";
        $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
        $ffmpegCmd = $ffmpeg
            . ' -y -ss 00:00:01'
            . ' -i ' . escapeshellarg($videoPath)
            . ' -vframes 1 -vf ' . escapeshellarg($vf)
            . ' ' . escapeshellarg($thumbnailPath) . ' 2>&1';
        shell_exec($ffmpegCmd);

        return file_exists($thumbnailPath) && filesize($thumbnailPath) > 0;
    }
}

// 動画の公開/非公開切り替え関数
function toggleVideoVisibility($videoFile) {
    $videos = Functions::getVideoFiles();
    
    if (!in_array($videoFile, $videos)) {
        return ['success' => false, 'message' => '動画ファイルが見つかりません'];
    }
    
    $basename = pathinfo($videoFile, PATHINFO_FILENAME);
    $currentMetadata = Functions::getVideoMetadata($basename);
    
    // 現在の公開状態を反転
    $newVisibility = !($currentMetadata['is_public'] ?? true);
    
    // 新しいメタデータを作成
    $newMetadata = [
        'title' => $currentMetadata['title'] ?? '',
        'comment' => $currentMetadata['comment'] ?? '',
        'views' => $currentMetadata['views'] ?? 0,
        'likes' => $currentMetadata['likes'] ?? 0,
        'upload_date' => $currentMetadata['upload_date'] ?? date('Y-m-d H:i:s'),
        'duration' => $currentMetadata['duration'] ?? null,
        'is_public' => $newVisibility
    ];
    
    $success = Functions::saveVideoMetadata($basename, $newMetadata);
    
    if ($success) {
        if (!$newVisibility) {
            // 非公開にしたら、発行済みの専用 URL からも取得できないようにする
            removeMediaUrlsFor($videoFile);
        }
        $status = $newVisibility ? '公開' : '非公開';
        return ['success' => true, 'message' => "動画を{$status}にしました", 'is_public' => $newVisibility];
    } else {
        return ['success' => false, 'message' => '公開状態の更新に失敗しました'];
    }
}

// 変換停止関数
function stopVideoConversion($videoFile) {
    $videos = Functions::getVideoFiles();
    
    if (!in_array($videoFile, $videos)) {
        return ['success' => false, 'message' => '動画ファイルが見つかりません'];
    }
    
    $conversionStatus = getConversionStatus($videoFile);
    
    if ($conversionStatus['status'] !== 'converting') {
        return ['success' => false, 'message' => '変換中ではありません'];
    }
    
    // プロセスを停止
    if (isset($conversionStatus['pid'])) {
        stopFfmpegProcess($conversionStatus['pid']);
    }
    
    // 進捗ファイルを削除
    if (isset($conversionStatus['progress_file']) && file_exists($conversionStatus['progress_file'])) {
        unlink($conversionStatus['progress_file']);
    }

    // 途中のmp4ファイルを削除
    $tempOutputPath = $conversionStatus['temp_output_path'] ?? null;
    if ($tempOutputPath && file_exists($tempOutputPath)) {
        unlink($tempOutputPath);
        error_log("Temporary output file deleted: {$tempOutputPath}");
    }
    
    // 状態をクリア（_conversion_status.jsonファイルを削除）
    clearConversionStatus($videoFile);
    
    return ['success' => true, 'message' => '変換を停止しました'];
}

// 変換状態取得関数
function getConversionStatus($videoFile) {
    $statusFile = "videos/" . pathinfo($videoFile, PATHINFO_FILENAME) . "_conversion_status.json";
    
    if (file_exists($statusFile)) {
        $data = json_decode(file_get_contents($statusFile), true);
        return $data ?: ['status' => 'not_found'];
    }
    
    return ['status' => 'not_found'];
}

// 変換状態保存関数
function saveConversionStatus($videoFile, $status) {
    $statusFile = "videos/" . pathinfo($videoFile, PATHINFO_FILENAME) . "_conversion_status.json";
    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
}

// 変換状態クリア関数
function clearConversionStatus($videoFile) {
    $statusFile = "videos/" . pathinfo($videoFile, PATHINFO_FILENAME) . "_conversion_status.json";
    if (file_exists($statusFile)) {
        unlink($statusFile);
    }
} 