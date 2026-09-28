<?php
/**
 * 動画・サムネイルの配信
 *
 * .htaccess で videos/* と thumbnails/* へのリクエストをここへ回し、閲覧権限を確認してから返す。
 * パスワード保護が有効なときに、URL を知っているだけで動画を取得できないようにするため。
 * 動画のシーク再生のため Range リクエストに対応する。
 */
require_once __DIR__ . '/init_web.php';

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
 * ファイルを送信する（Range・条件付きリクエスト対応）
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

sendMediaFile($path, $mime);
