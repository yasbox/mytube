<?php
/**
 * 共通関数（後方互換性のため）
 * 新しいシステムを使用し、既存の関数も提供
 */

// PHPのログ出力先設定はphp.iniに委譲

// 互換レイヤーは不要化。BootstrapでConfigを初期化済み。

// 新しい設定システムを読み込み
require_once 'core/utils/Functions.php';

// 既存の関数を新しいクラスメソッドとして提供
function getVideoMetadata($basename) {
    return Functions::getVideoMetadata($basename);
}

function saveVideoMetadata($basename, $data) {
    return Functions::saveVideoMetadata($basename, $data);
}

function getVideoFiles() {
    return Functions::getVideoFiles();
}

function getSortedVideos($sort = 'new', $query = '') {
    return Functions::getSortedVideos($sort, (string)$query);
}

/**
 * 並べ替え済みの動画リストを、指定の動画の次から始まり最後まで行ったら先頭に戻る順にする（指定の動画は除く）
 * 動画ページの「次の動画」に使う。指定の動画がリストにないとき（非公開の動画など）はそのまま
 */
function videosAfter(array $videos, string $currentVideo): array {
    $files = array_map(fn($v) => (string)($v['filename'] ?? ''), $videos);
    $position = array_search($currentVideo, $files, true);
    if ($position === false) {
        return $videos;
    }
    return array_merge(array_slice($videos, $position + 1), array_slice($videos, 0, $position));
}

/**
 * 検索語を整える（前後の空白を除き、長すぎるものは切る）。不正な値は空文字
 */
function normalizeSearchQuery($query): string {
    if (!is_string($query)) {
        return '';
    }
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 0, 100);
}

/**
 * 「3日前」のような相対的な日時（YouTube と同じ表し方。JS の formatRelativeTime と同じ規則）
 * @param string|null $dateString 'Y-m-d H:i:s' など
 */
function formatRelativeTime($dateString): string {
    $time = $dateString ? strtotime((string)$dateString) : false;
    if ($time === false) {
        return '';
    }
    $diff = max(0, time() - $time);
    if ($diff < 60) return 'たった今';
    if ($diff < 3600) return floor($diff / 60) . '分前';
    if ($diff < 86400) return floor($diff / 3600) . '時間前';
    $days = floor($diff / 86400);
    if ($days < 7) return $days . '日前';
    if ($days < 30) return floor($days / 7) . '週間前';
    if ($days < 365) return floor($days / 30) . 'か月前';
    return floor($days / 365) . '年前';
}

function getVideoStats() {
    return Functions::getVideoStats();
}

function deleteVideo($videoFile) {
    $result = Functions::deleteVideo($videoFile);
    if (!empty($result['success'])) {
        removeMediaUrlsFor($videoFile);
    }
    return $result;
}

/**
 * 動画・サムネイルの期限付き専用 URL（media/、media.php が作成）を削除する
 * 専用 URL は元ファイルへのハードリンクのため、動画の削除・非公開化の直後から取得できないよう消す
 */
function removeMediaUrlsFor($videoFile) {
    $videoFile = basename((string)$videoFile);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $videoFile)) {
        return;
    }
    $names = [$videoFile, pathinfo($videoFile, PATHINFO_FILENAME) . '.jpg'];
    foreach ($names as $name) {
        foreach (glob(__DIR__ . '/media/*/*/' . $name) ?: [] as $link) {
            @unlink($link);
            @rmdir(dirname($link));
        }
    }
}

function updateVideoMetadata($videoFile, $title, $comment) {
    return Functions::updateVideoMetadata($videoFile, $title, $comment);
}

function formatFileSize($bytes) {
    return Functions::formatFileSize($bytes);
}

/**
 * CSS・JS・画像の URL に付けるバージョン（ファイルの更新日時。更新するとブラウザのキャッシュが切り替わる）
 * @param string $filePath src からの相対パス（例: 'assets/js/admin.js'）
 * @return int|string 更新日時。ファイルが無ければ '1.0.0'
 */
function getAssetVersion($filePath) {
    $fullPath = __DIR__ . '/' . $filePath;
    return file_exists($fullPath) ? filemtime($fullPath) : '1.0.0';
}

function getAdminVideoList() {
    return Functions::getAdminVideoList();
}

function getAdminVideoListSorted($sort = 'new') {
    return Functions::getAdminVideoListSorted($sort);
}

function getAdminVideoListPaginated($sort = 'new', $page = 1, $perPage = 10) {
    return Functions::getAdminVideoListPaginated($sort, $page, $perPage);
}

// 認証関連の関数（admin_functions.phpから移動）
function secureSession() {
    // セッションが既に開始されている場合は設定を変更しない
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    
    // セッション設定
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.cookie_samesite', 'Lax'); // 他のサイトからの送信には Cookie を付けない（ブラウザの既定と同じ）
    
    // セッション開始
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function requireAuthentication() {
    if (!isUserAuthenticated()) {
        // リメンバーミーで復元を試みる（セキュリティモジュール読み込み済みの場合）
        if (function_exists('checkRememberMe')) {
            checkRememberMe();
            if (isUserAuthenticated()) {
                return;
            }
        }
        // 現在のURLをセッションに保存（ログイン後に戻るため）
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '/';
        
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
        // 動画視聴ページ（v=パラメータ）やトップページの場合
        if (isset($_GET['v']) || 
            $currentUrl === '/' || 
            $currentUrl === '/index.php' || 
            strpos($currentUrl, 'index.php') !== false) {
            
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
}

function isPasswordProtectionEnabled(): bool {
    // JSON（src/data/settings.json）を最優先
    if (method_exists('Config', 'getSettingsJsonPath')) {
        $path = Config::getSettingsJsonPath();
        if (!is_file($path) || !is_readable($path)) {
            // 設定が無い場合は保護OFF（公開）
            return false;
        }
        $raw = file_get_contents($path);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['security']['password_protection'])) {
                return filter_var($decoded['security']['password_protection'], FILTER_VALIDATE_BOOLEAN);
            }
        }
    }
    // ランタイム設定から取得（新キー優先、旧キーは反転）
    if (Config::has('security.password_protection')) {
        return filter_var(Config::get('security.password_protection'), FILTER_VALIDATE_BOOLEAN);
    }
    return false;
}

function requireViewerAccess(): void {
    $protected = isPasswordProtectionEnabled();
    if ($protected && !isUserAuthenticated()) {
        requireAuthentication();
    }
}

function isUserAuthenticated() {
    $authenticated = isset($_SESSION['user_authenticated']) && $_SESSION['user_authenticated'] === true;
    
    // セッションタイムアウトチェック
    if ($authenticated && isset($_SESSION['user_login_time'])) {
        $sessionAge = time() - $_SESSION['user_login_time'];
        $adminSessionLifetime = (int)Config::get('security.admin_session_lifetime', 30 * 24 * 3600);
        if ($sessionAge > $adminSessionLifetime) {
            // セッションが期限切れ
            userLogout();
            $authenticated = false;
        }
    }
    
    return $authenticated;
}

function userLogout() {
    unset($_SESSION['user_authenticated']);
    unset($_SESSION['user_role']);
    unset($_SESSION['user_login_time']);
    unset($_SESSION['user_ip']);
    unset($_SESSION['csrf_token']);
    
    // リメンバーミークッキーを削除
    clearRememberMeCookie();
    
    session_destroy();
}

function isSafeRedirectUrl($url) {
    // 相対URLのみ許可
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        return false;
    }
    
    // 危険なパスを除外
    $dangerousPaths = ['../', '..\\', 'php://', 'data://', 'file://'];
    foreach ($dangerousPaths as $dangerousPath) {
        if (strpos($url, $dangerousPath) !== false) {
            return false;
        }
    }
    
    // クエリパラメータを除去してパス部分のみをチェック
    $pathOnly = parse_url($url, PHP_URL_PATH);
    if ($pathOnly === null) {
        $pathOnly = $url;
    }
    
    // 許可されたファイル拡張子のみ（パス部分のみチェック）
    $allowedExtensions = ['php', 'html', 'htm'];
    $pathInfo = pathinfo($pathOnly);
    if (isset($pathInfo['extension']) && !in_array(strtolower($pathInfo['extension']), $allowedExtensions)) {
        return false;
    }
    
    return true;
}

function clearRememberMeCookie() {
    $cookieName = Config::get('security.remember_me_cookie_name', 'MyTube_remember');
    if (isset($_COOKIE[$cookieName])) {
        // Cookie属性をログイン時と合わせて確実に削除
        $secure = isset($_SERVER['HTTPS']);
        $httponly = true;
        $samesite = 'Lax';
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
        unset($_COOKIE[$cookieName]);
    }
}

// フォーマット関連の関数
function formatDuration($duration) {
    // 旧実装やメタデータが文字列のままでも安全に処理できるように秒へ正規化
    $seconds = null;
    if (is_int($duration)) {
        $seconds = $duration;
    } elseif (is_string($duration)) {
        $trimmed = trim($duration);
        if ($trimmed !== '' && ctype_digit($trimmed)) {
            $seconds = (int)$trimmed;
        } elseif (strpos($trimmed, ':') !== false) {
            $parts = explode(':', $trimmed);
            if (count($parts) === 3) {
                $seconds = ((int)$parts[0]) * 3600 + ((int)$parts[1]) * 60 + (int)$parts[2];
            } elseif (count($parts) === 2) {
                $seconds = ((int)$parts[0]) * 60 + (int)$parts[1];
            }
        }
    }
    return Functions::formatDuration($seconds);
}

function formatDate($dateString) {
    return Functions::formatDate($dateString);
}

/**
 * ワンタイムパスワードを生成する
 * @return string 生成されたパスワード
 */
function generateOneTimePassword() {
    // 16文字のランダムな文字列を生成（UUID風だが短縮版）
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < 16; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/**
 * 動画のワンタイムパスワードを取得または生成する
 * @param string $videoBasename 動画のベース名（拡張子なし）
 * @return array パスワード情報
 */
function getOrGenerateVideoPassword($videoBasename) {
    $metadata = getVideoMetadata($videoBasename);
    $currentTime = time();
    $oneDayInSeconds = 24 * 60 * 60;
    
    // 既存のパスワードがあるかチェック
    if (isset($metadata['share_password']) && isset($metadata['share_password_expires'])) {
        $expires = strtotime($metadata['share_password_expires']);
        
        // 期限が切れていない場合は既存のパスワードを返す
        if ($expires > $currentTime) {
            return [
                'password' => $metadata['share_password'],
                'expires' => $metadata['share_password_expires'],
                'is_new' => false
            ];
        }
    }
    
    // 新しいパスワードを生成
    $newPassword = generateOneTimePassword();
    $expiresTime = date('Y-m-d H:i:s', $currentTime + $oneDayInSeconds);
    
    // 共有パスワードの項目だけを排他して書き込む（再生数等の同時更新を消さないように）
    $saved = saveVideoMetadata($videoBasename, [
        'share_password' => $newPassword,
        'share_password_expires' => $expiresTime,
        'share_password_created' => date('Y-m-d H:i:s', $currentTime)
    ]);
    if ($saved) {
        return [
            'password' => $newPassword,
            'expires' => $expiresTime,
            'is_new' => true
        ];
    }
    
    return false;
}

/**
 * ワンタイムパスワードを検証する
 * @param string $videoBasename 動画のベース名（拡張子なし）
 * @param string $password 検証するパスワード
 * @return bool 有効なパスワードかどうか
 */
function validateVideoPassword($videoBasename, $password) {
    $metadata = getVideoMetadata($videoBasename);

    if (!isset($metadata['share_password']) || !isset($metadata['share_password_expires'])) {
        return false;
    }

    // パスワードが一致するかチェック
    if (!is_string($password) || $password === '' || !hash_equals((string)$metadata['share_password'], $password)) {
        return false;
    }

    // 期限が切れていないかチェック
    $expires = strtotime($metadata['share_password_expires']);
    $currentTime = time();

    return $expires > $currentTime;
}

/**
 * 共有リンクで閲覧中の動画について、動画ファイル・サムネイルの取得を許可する（セッションに記録）
 * @param string $videoBasename 動画のベース名（拡張子なし）
 * @param string $password 検証済みのワンタイムパスワード
 */
function grantSharedMediaAccess($videoBasename, $password) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['shared_media'][$videoBasename] = $password;
}

/**
 * 共有リンク経由で動画ファイル・サムネイルの取得が許可されているか
 * （記録したパスワードを毎回検証するため、期限切れ後は取得できない）
 * @param string $videoBasename 動画のベース名（拡張子なし）
 * @return bool
 */
function hasSharedMediaAccess($videoBasename) {
    $password = $_SESSION['shared_media'][$videoBasename] ?? null;
    return is_string($password) && validateVideoPassword($videoBasename, $password);
}

/**
 * 共有リンクの URL を組み立てる（index.php への直接リンク）
 * パスワードを付けると、ログインしなくても見られるリンクになる
 * @param string $videoFile 動画のファイル名（拡張子つき。以前は .mp4 に決め打ちで、MOV などでは開けなかった）
 */
function buildShareUrl(string $videoFile, string $password = ''): string {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/index.php?v=' . urlencode($videoFile);
    return $password !== '' ? $url . '&share=' . urlencode($password) : $url;
}

/**
 * 共有リンク（ログインなしで24時間見られる）を生成する。期限内なら同じリンクを返す
 * @param string $videoFile 動画のファイル名（拡張子つき）
 * @return string|false 共有リンク
 */
function generateShareLink($videoFile) {
    $passwordInfo = getOrGenerateVideoPassword(pathinfo($videoFile, PATHINFO_FILENAME));
    if (!$passwordInfo) {
        return false;
    }
    return buildShareUrl($videoFile, (string)$passwordInfo['password']);
}

/**
 * 通常の動画リンク（ワンタイムパスワードなし。見るにはログインが必要）を生成する
 * @param string $videoFile 動画のファイル名（拡張子つき）
 */
function generateNormalShareLink($videoFile) {
    return buildShareUrl($videoFile);
}

/**
 * 今使える共有リンクの一覧（管理画面の「共有中のリンク」用）。期限の近い順
 * @return array [['video', 'title', 'expires', 'remaining'(秒), 'url', 'thumb'], ...]
 */
function getActiveShareLinks(): array {
    $now = time();
    $links = [];
    foreach (getVideoFiles() as $videoFile) {
        $basename = pathinfo($videoFile, PATHINFO_FILENAME);
        $metadata = getVideoMetadata($basename);
        if (empty($metadata['share_password']) || empty($metadata['share_password_expires'])) {
            continue;
        }
        $expires = strtotime((string)$metadata['share_password_expires']);
        if ($expires === false || $expires <= $now) {
            continue;
        }
        $thumbPath = "thumbnails/{$basename}.jpg";
        $links[] = [
            'video' => $videoFile,
            'title' => (string)($metadata['title'] ?? ''),
            'expires' => (string)$metadata['share_password_expires'],
            'remaining' => $expires - $now,
            'url' => buildShareUrl($videoFile, (string)$metadata['share_password']),
            'thumb' => is_file(__DIR__ . '/' . $thumbPath) ? $thumbPath . '?v=' . filemtime(__DIR__ . '/' . $thumbPath) : 'images/default-thumbnail-small.svg',
        ];
    }
    usort($links, fn($a, $b) => $a['remaining'] <=> $b['remaining']);
    return $links;
}

/**
 * 共有リンクを期限前に無効にする
 * すでに開いている人が動画の続きを読み込めないよう、発行済みの動画の専用 URL も消す
 * @param string $videoFile 動画のファイル名（拡張子つき）
 */
function revokeShareLink(string $videoFile): bool {
    $saved = saveVideoMetadata(pathinfo($videoFile, PATHINFO_FILENAME), [
        'share_password' => null,
        'share_password_expires' => null,
        'share_password_created' => null,
    ]);
    if ($saved) {
        removeMediaUrlsFor($videoFile);
    }
    return (bool)$saved;
}