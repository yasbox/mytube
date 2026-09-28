<?php
/**
 * 動画・サムネイルの配信
 *
 * .htaccess で videos/* と thumbnails/* へのリクエストをここへ回し、閲覧権限を確認する。
 * パスワード保護が有効なときに、URL を知っているだけで動画を取得できないようにするため。
 *
 * 許可した場合は、期限付きの専用 URL（media/<期間>/<トークン>/<ファイル名>）へリダイレクトし、
 * ファイル自体は Web サーバーの静的配信に任せる。
 * （Xserver では前段の nginx が Range ヘッダーを PHP に渡さないため、PHP で配信すると
 *   動画のシーク再生ができない。静的配信なら nginx/Apache が Range に対応する）
 * 専用 URL を作れない環境（リンクを作成できないファイルシステム等）では PHP で直接配信する。
 */
require_once __DIR__ . '/init_web.php';

/**
 * 専用 URL の署名に使う秘密鍵（インスタンスごとに自動生成し data/secure に保存）
 * 保存できない場合は null（毎回変わる鍵では URL が安定しないため、専用 URL を使わない）
 */
function getMediaUrlSecret(): ?string
{
    $path = dirname(Config::getSecureAdminPasswordPath()) . DIRECTORY_SEPARATOR . 'media_url.key';
    $secret = is_readable($path) ? trim((string)@file_get_contents($path)) : '';
    if (strlen($secret) >= 32) {
        return $secret;
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, bin2hex(random_bytes(32)), LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }
    @unlink($tmp);
    // 同時に生成された場合も、保存された方に揃える
    $saved = is_readable($path) ? trim((string)@file_get_contents($path)) : '';
    return strlen($saved) >= 32 ? $saved : null;
}

/**
 * 期限付きの専用 URL を用意する
 * media/<期間番号>/<トークン>/<ファイル名> に元ファイルへのリンクを作り、そのパスを返す。
 * 期間は media_url_ttl 秒ごとに切り替わり、1つ前の期間までを残すため、
 * 発行した URL は最短 ttl 秒・最長 2×ttl 秒有効。作成できない場合は null。
 */
function createMediaUrl(string $kind, string $name): ?string
{
    $secret = getMediaUrlSecret();
    if ($secret === null) {
        return null;
    }
    $source = __DIR__ . '/' . $kind . '/' . $name;
    $ttl = max(60, (int)Config::get('security.media_url_ttl', 21600));
    $period = intdiv(time(), $ttl);
    // ファイルが差し替えられたら URL も変わるよう更新日時も含める
    $token = substr(hash_hmac('sha256', $period . '|' . $kind . '|' . $name . '|' . filemtime($source), $secret), 0, 32);
    $baseDir = __DIR__ . '/media';
    $dir = $baseDir . '/' . $period . '/' . $token;
    $link = $dir . '/' . $name;

    if (!is_file($link)) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        // ハードリンクを優先（Web サーバーのシンボリックリンク設定に依存しない）。使えなければシンボリックリンク
        if (!@link($source, $link) && !is_file($link)) {
            @unlink($link);
            if (!@symlink('../../../' . $kind . '/' . $name, $link) || !is_file($link)) {
                @unlink($link);
                return null;
            }
        }
        cleanupMediaUrls($baseDir, $period);
    }
    return 'media/' . $period . '/' . $token . '/' . rawurlencode($name);
}

/**
 * 期限切れの専用 URL（2つ前以前の期間）を削除する
 */
function cleanupMediaUrls(string $baseDir, int $currentPeriod): void
{
    foreach (scandir($baseDir) ?: [] as $entry) {
        if (ctype_digit($entry) && (int)$entry < $currentPeriod - 1) {
            removeMediaUrlTree($baseDir . '/' . $entry);
        }
    }
}

function removeMediaUrlTree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        // リンク先（元の動画）はたどらず、リンク自体だけを消す
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            removeMediaUrlTree($path);
        }
    }
    @rmdir($dir);
}

function mediaError(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit;
}

/**
 * 閲覧権限の確認
 * - 非公開動画: 管理者のみ
 * - パスワード保護が無効: 誰でも
 * - パスワード保護が有効: ログイン済み、または共有リンクで該当動画を開いた閲覧者
 */
function canAccessMedia(string $basename): bool
{
    if (isUserAuthenticated() && isAdmin()) {
        return true;
    }
    $metadata = getVideoMetadata($basename);
    if (($metadata['is_public'] ?? true) === false) {
        return false;
    }
    if (!isPasswordProtectionEnabled()) {
        return true;
    }
    return isUserAuthenticated() || hasSharedMediaAccess($basename);
}

/**
 * ファイルを PHP で直接送信する（専用 URL を作れない環境向け。Range・条件付きリクエスト対応）
 * 前段のプロキシが Range ヘッダーを渡さない環境では、動画のシーク再生ができない点に注意
 */
function sendMediaFile(string $path, string $mime): void
{
    $size = (int)filesize($path);
    $mtime = (int)filemtime($path);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
    $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', 'Off');

    // セッション開始時に付く no-cache 系ヘッダーを外し、閲覧者のブラウザにのみキャッシュさせる
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: private, max-age=86400');
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Last-Modified: ' . $lastModified);
    header('ETag: ' . $etag);

    $ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifNoneMatch !== '' ? $ifNoneMatch === $etag : ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
        http_response_code(304);
        exit;
    }

    $start = 0;
    $end = $size - 1;
    $partial = false;
    $range = trim($_SERVER['HTTP_RANGE'] ?? '');
    $ifRange = trim($_SERVER['HTTP_IF_RANGE'] ?? '');
    $rangeApplicable = $ifRange === '' || $ifRange === $etag || $ifRange === $lastModified;
    // 単一レンジのみ対応（複数レンジ・不正な形式は無視して全体を返す）
    if ($range !== '' && $rangeApplicable && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            // bytes=-N（末尾から N バイト）
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min((int)$m[2], $size - 1);
            }
        }
        if ($start >= $size || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $partial = true;
    }

    $length = $size > 0 ? $end - $start + 1 : 0;
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Content-Length: ' . $length);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) {
        exit;
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        exit;
    }
    @set_time_limit(0);
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, (int)min(1048576, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

$kind = (string)($_GET['kind'] ?? '');
$name = (string)($_GET['name'] ?? '');
if (!in_array($kind, ['videos', 'thumbnails'], true) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
    mediaError(404);
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($kind === 'videos') {
    $allowed = Config::get('features.upload.allowed_formats', ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv', 'flv']);
    $mime = Config::get("video.mime_types.$ext", 'application/octet-stream');
} else {
    $allowed = ['jpg'];
    $mime = 'image/jpeg';
}
if (!in_array($ext, $allowed, true)) {
    mediaError(404);
}

$path = __DIR__ . '/' . $kind . '/' . $name;
if (!is_file($path)) {
    mediaError(404);
}

$allowedAccess = canAccessMedia(pathinfo($name, PATHINFO_FILENAME));
// 配信中に同じセッションの他のリクエストを待たせないよう、ここでセッションを閉じる
session_write_close();
if (!$allowedAccess) {
    mediaError(403);
}

$mediaUrl = createMediaUrl($kind, $name);
if ($mediaUrl !== null) {
    // サブディレクトリに設置されていても正しい位置を指すよう、絶対パスでリダイレクトする
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header_remove('Pragma');
    header_remove('Expires');
    // リダイレクト自体は閲覧者のブラウザにだけ短時間キャッシュさせる（共有キャッシュには載せない）
    header('Cache-Control: private, max-age=300');
    header('Location: ' . $basePath . '/' . $mediaUrl, true, 302);
    exit;
}

// 専用 URL を作れない環境では PHP で直接配信する
sendMediaFile($path, $mime);
