<?php
// 先頭で最低限のセットアップ（致命的エラーもJSONで返す）
header('Content-Type: application/json');
if (!ob_get_level()) { ob_start(); }

// 一時的なデバッグログは削除（環境設定のログに委譲）

// セッション開始（すでに開始済みでも安全）
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// ローカルエラー設定（ログのみ）
ini_set('display_errors', '0');
error_reporting(E_ALL);
// ローカルデバッグロガー
if (!function_exists('log_error')) { function log_error(string $message): void { /* no-op */ } }
if (!function_exists('log_debug')) { function log_debug(string $message, array $ctx = []): void { /* no-op */ } }
// グローバルエラーハンドラ（500の原因特定用）
set_error_handler(function ($severity, $message, $file, $line) {
    log_error("upload_api.php PHP error [{$severity}]: {$message} in {$file}:{$line}");
});
set_exception_handler(function ($ex) {
    log_error('upload_api.php Uncaught exception: ' . $ex->getMessage() . ' at ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['success' => false, 'message' => 'サーバー内部エラー', 'error' => $ex->getMessage()]);
    exit;
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_error('upload_api.php FATAL: ' . $e['message'] . ' at ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
        while (ob_get_level()) { ob_end_clean(); }
        echo json_encode(['success' => false, 'message' => 'サーバー内部エラー', 'error' => $e['message']]);
    }
});
// CORSヘッダー（管理APIのためワイルドカードを使用しない）
// 必要に応じて許可オリジンを環境設定から取得
$allowedOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
// デフォルトは同一オリジンのみ
if (!empty($allowedOrigin) && strpos($allowedOrigin, $_SERVER['HTTP_HOST']) !== false) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
// 依存ファイルを後ろで読み込み（ハンドラ登録後）

// アプリケーション初期化
require_once __DIR__ . '/core/Bootstrap.php';
Bootstrap::init();

// 共通関数をインクルード
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_functions.php';
require_once __DIR__ . '/video_converter.php';
require_once __DIR__ . '/security.php';

if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

// リメンバーミーによる自動ログイン（APIでもセッション復元）
if (function_exists('checkRememberMe')) {
    checkRememberMe();
}

function requireCsrfTokenForRequest() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $token = '';
    if ($method === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    } else if ($method === 'GET') {
        $token = $_GET['csrf_token'] ?? '';
    }
    if (!function_exists('verifyCSRFToken') || verifyCSRFToken($token)) {
        return; // OK
    }
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF検証に失敗しました']);
    exit;
}

// Resumable.js の識別子（「サイズ-ファイル名」から英数字・_・- 以外を除いたもの）か
// temp_uploads/ 配下のディレクトリ名に使うため、パス区切り等を含むものは受け付けない
function isValidResumableIdentifier($identifier): bool {
    return is_string($identifier) && preg_match('/^[0-9A-Za-z_-]{1,200}$/', $identifier) === 1;
}

// OPTIONSリクエストの処理（プリフライトは常に許可）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

// 管理者認証チェック（API用: リダイレクトせずJSONで返す）
if (!isAdminAuthenticated()) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}
if (!isAdmin()) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '管理者権限が必要です']);
    exit;
}

// ここまででCORS/認証前処理は完了

// チャンクアップロード処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'cancel_upload')) {
    requireCsrfTokenForRequest();
    $response = ['success' => false, 'message' => ''];
    
    
    try {
        // 受信ログは出力しない
        // Resumable.jsからのパラメータを取得
        $resumableIdentifier = $_POST['resumableIdentifier'] ?? '';
        $resumableFilename = $_POST['resumableFilename'] ?? '';
        $resumableChunkNumber = intval($_POST['resumableChunkNumber'] ?? 0);
        $resumableTotalChunks = intval($_POST['resumableTotalChunks'] ?? 0);
        $resumableChunkSize = intval($_POST['resumableChunkSize'] ?? 0);
        $resumableTotalSize = intval($_POST['resumableTotalSize'] ?? 0);
        
        // 必須パラメータのチェック
        if (empty($resumableIdentifier) || empty($resumableFilename)) {
            throw new Exception('必要なパラメータが不足しています');
        }
        if (!isValidResumableIdentifier($resumableIdentifier)) {
            throw new Exception('パラメータが不正です');
        }
        
        // ファイル形式のチェック
        $ext = strtolower(pathinfo($resumableFilename, PATHINFO_EXTENSION));
        $allowedExtensions = ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv', 'flv'];
        if (!in_array($ext, $allowedExtensions)) {
            throw new Exception('サポートされていないファイル形式です');
        }
        
        // 最大サイズ検証（.env の UPLOAD_MAX_SIZE を反映）
        $maxBytesCfg = (int)Config::get('features.upload.max_size_bytes', 0);
        if ($maxBytesCfg > 0 && $resumableTotalSize > $maxBytesCfg) {
            $maxMbDisp = (int)ceil($maxBytesCfg / (1024 * 1024));
            throw new Exception('ファイルサイズが上限を超えています（最大 ' . $maxMbDisp . 'MB）');
        }
        
        // 一時ディレクトリを作成
        $tempDir = "temp_uploads/{$resumableIdentifier}";
        if (!is_dir($tempDir)) {
            $mk = @mkdir($tempDir, 0755, true);
            if (!$mk && !is_dir($tempDir)) {
                throw new Exception('一時ディレクトリの作成に失敗しました: ' . $tempDir);
            }
        }
        
        // チャンクファイルのパス
        $chunkPath = "{$tempDir}/chunk_{$resumableChunkNumber}";
        
        // アップロードされたファイルを保存
        if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
            if (move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath)) {
                // 保存ログは出力しない
                // チャンク情報を記録
                $chunkInfo = [
                    'chunk_number' => $resumableChunkNumber,
                    'total_chunks' => $resumableTotalChunks,
                    'chunk_size' => $resumableChunkSize,
                    'total_size' => $resumableTotalSize,
                    'filename' => $resumableFilename,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
                
                file_put_contents("{$tempDir}/chunk_info.json", json_encode($chunkInfo, JSON_PRETTY_PRINT));
                
                // すべてのチャンクが揃っているかを常に確認し、揃っていれば結合を実行
                $finalizeResult = tryFinalizeUpload($tempDir, $resumableTotalChunks, $resumableTotalSize, $resumableFilename, $_POST['title'] ?? '', $_POST['comment'] ?? '');
                if ($finalizeResult !== false) {
                    // 結合成功時の詳細ログは出力しない
                    $response = $finalizeResult;
                } else {
                    // 待機ログは出力しない
                    $response = [
                        'success' => true,
                        'message' => "チャンク {$resumableChunkNumber} がアップロードされました"
                    ];
                }
            } else {
                // 失敗詳細ログは環境ログへ（ここでは抑制）
                throw new Exception('チャンクファイルの保存に失敗しました (upload_max_filesize / post_max_size / permissions を確認)');
            }
        } else {
            throw new Exception('ファイルがアップロードされていません');
        }
        
    } catch (Exception $e) {
        error_log('upload_api.php: exception ' . $e->getMessage());
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// チャンクの存在確認（Resumable.js用）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['resumableChunkNumber'])) {
    requireCsrfTokenForRequest();
    $resumableIdentifier = $_GET['resumableIdentifier'] ?? '';
    $resumableChunkNumber = intval($_GET['resumableChunkNumber'] ?? 0);
    $resumableTotalSize = intval($_GET['resumableTotalSize'] ?? 0);
    $resumableTotalChunks = intval($_GET['resumableTotalChunks'] ?? 0);
    $resumableFilename = (string)($_GET['resumableFilename'] ?? '');
    
    // アップロード前サイズ検証（上限超過なら即時拒否）
    $maxBytesCfg = (int)Config::get('features.upload.max_size_bytes', 0);
    if ($maxBytesCfg > 0 && $resumableTotalSize > $maxBytesCfg) {
        http_response_code(413); // Payload Too Large
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'ファイルサイズが上限を超えています']);
        exit;
    }
    
    if (isValidResumableIdentifier($resumableIdentifier) && $resumableChunkNumber > 0) {
        $tempDir = "temp_uploads/{$resumableIdentifier}";
        $chunkPath = "{$tempDir}/chunk_{$resumableChunkNumber}";
        
        if (file_exists($chunkPath)) {
            // チャンクが存在する場合
            // 存在確認の詳細ログは出力しない
            http_response_code(200);
            header('Content-Type: application/json');
            // すべて揃っていればここで最終結合を試みる（再開時に最後のPOSTが来ないケース対策）
            $finalizeResult = false;
            if ($resumableTotalChunks > 0 && $resumableFilename !== '') {
                $finalizeResult = tryFinalizeUpload($tempDir, $resumableTotalChunks, $resumableTotalSize, $resumableFilename, '', '');
                if ($finalizeResult !== false) {
                    // GET 側での結合成功時の詳細ログは出力しない
                    echo json_encode($finalizeResult);
                    exit;
                }
            }
            echo json_encode(['success' => true, 'message' => 'チャンクが存在します']);
        } else {
            // チャンクが存在しない場合（Resumable.jsが期待する状態）
            // 欠落ログは出力しない
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'チャンクが存在しません']);
        }
    } else {
        // パラメータが不正
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'パラメータが不正です']);
    }
    exit;
}

// アップロードキャンセルAPI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_upload') {
    requireCsrfTokenForRequest();
    $resumableIdentifier = $_POST['resumableIdentifier'] ?? '';
    
    if (!isValidResumableIdentifier($resumableIdentifier)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'resumableIdentifierが不正です']);
        exit;
    }
    
    $tempDir = "temp_uploads/{$resumableIdentifier}";
    
    if (is_dir($tempDir)) {
        // 一時ディレクトリとその中身を削除
        cleanupTempFiles($tempDir);
        $response = ['success' => true, 'message' => 'アップロードをキャンセルしました'];
    } else {
        $response = ['success' => true, 'message' => 'アップロードをキャンセルしました'];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

/**
 * チャンクを結合してファイルを完成させる
 */
function combineChunks($tempDir, $filename, $totalChunks) {
    // ファイル名はベース名のみを許可
    $base = basename($filename);
    // 一時ファイル名で作成しておく
    $finalPath = 'videos/' . $base;
    
    // 最終ファイルを開く
    $finalFile = fopen($finalPath, 'wb');
    if (!$finalFile) {
        return false;
    }
    
    try {
        // 各チャンクを順番に結合
        for ($i = 1; $i <= $totalChunks; $i++) {
            $chunkPath = "{$tempDir}/chunk_{$i}";
            
            if (!file_exists($chunkPath)) {
                fclose($finalFile);
                unlink($finalPath);
                return false;
            }
            
            $chunkContent = file_get_contents($chunkPath);
            if ($chunkContent === false) {
                fclose($finalFile);
                unlink($finalPath);
                return false;
            }
            
            fwrite($finalFile, $chunkContent);
        }
        
        fclose($finalFile);
        return $finalPath;
        
    } catch (Exception $e) {
        fclose($finalFile);
        if (file_exists($finalPath)) {
            unlink($finalPath);
        }
        return false;
    }
}

/**
 * すべてのチャンクが揃っていれば結合し、保存・メタ生成まで行う
 * 揃っていなければ false を返す
 */
function tryFinalizeUpload(string $tempDir, int $totalChunks, int $totalSize, string $originalFilename, string $title, string $comment) {
    // 1..N すべて存在するか確認
    $missing = [];
    for ($i = 1; $i <= $totalChunks; $i++) {
        $cp = "{$tempDir}/chunk_{$i}";
        if (!is_file($cp)) { $missing[] = $i; }
    }
    if (!empty($missing)) {
        // 未到着があるためまだ確定しない
        // デバッグログは抑制
        return false;
    }

    // 合計サイズの概算検証（オプション）
    $sum = 0;
    for ($i = 1; $i <= $totalChunks; $i++) {
        $sum += (int)@filesize("{$tempDir}/chunk_{$i}");
    }
    if ($totalSize > 0 && $sum > 0 && abs($sum - $totalSize) > max(1024, (int)ceil($totalSize * 0.01))) {
        // 合計サイズが大きく乖離 → 結合を保留
        // デバッグログは抑制
        return false;
    }

    // 結合実行
    $safeFinal = combineChunks($tempDir, basename($originalFilename), $totalChunks);
    if (!$safeFinal || !is_file($safeFinal) || filesize($safeFinal) <= 0) {
        // デバッグログは抑制
        return false;
    }

    // ベース名生成
    $basename = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $newFilename = $basename . '.' . $ext;
    $newPath = 'videos/' . $newFilename;

    // メタ保存（先にメタを作っておく）
    $metadata = [
        'title' => $title,
        'comment' => $comment,
        'upload_date' => date('Y-m-d H:i:s'),
        'views' => 0,
        'likes' => 0
    ];
    saveVideoMetadata($basename, $metadata);

    // 移動
    if (!@rename($safeFinal, $newPath)) {
        // デバッグログは抑制
        return false;
    }

    // メタ生成・サムネ生成（失敗しても動画保存は完了）
    @generateVideoMetadata($newPath, $basename);
    @generateThumbnail($newPath, 'thumbnails/' . $basename . '.jpg');

    // 一時ファイル削除
    cleanupTempFiles($tempDir);

    $response = [
        'success' => true,
        'message' => 'アップロードが完了しました',
        'filename' => $newFilename,
        'video_id' => $newFilename
    ];
    return $response;
}

/**
 * サムネイルを生成
 */
if (!function_exists('uploadapi_generate_thumbnail')) {
    function uploadapi_generate_thumbnail($videoPath, $basename) {
        $baseDir = __DIR__;
        $thumbDir = $baseDir . DIRECTORY_SEPARATOR . 'thumbnails';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0755, true);
        }
        $thumbnailPath = $thumbDir . DIRECTORY_SEPARATOR . $basename . '.jpg';
        // 動画パスを絶対パスへ
        $videoAbs = $videoPath;
        if (!preg_match('/^\//', $videoAbs) && !preg_match('/^[A-Za-z]:\\\\/', $videoAbs)) {
            $videoAbs = $baseDir . DIRECTORY_SEPARATOR . ltrim($videoAbs, '/\\');
        }
        // shell_exec が使えない環境では生成不可
        if (!function_exists('shell_exec')) {
            return false;
        }
        // FFmpeg バイナリの決定（Config優先）
        $ffmpegBin = (string)Config::get('storage.ffmpeg_path', 'ffmpeg');

        // 生成試行（シーク位置と -ss の位置を変えて複数試す）
        $vf = "scale='if(gte(iw,ih),min(iw,1000),-2)':'if(gte(iw,ih),-2,min(ih,1000))'";
        $timeCandidates = ['00:00:01', '00:00:03', '00:00:00.500'];
        foreach ($timeCandidates as $ts) {
            // 1) 先頭シーク（高速）
            $cmd1 = escapeshellarg($ffmpegBin) . ' -y -ss ' . escapeshellarg($ts) . ' -i ' . escapeshellarg($videoAbs)
                . ' -frames:v 1 -vf ' . escapeshellarg($vf) . ' ' . escapeshellarg($thumbnailPath) . ' 2>&1';
            @shell_exec($cmd1);
            if (@is_file($thumbnailPath) && @filesize($thumbnailPath) > 0) {
                @chmod($thumbnailPath, 0644);
                return true;
            }
            // 2) 後段シーク（精確）
            $cmd2 = escapeshellarg($ffmpegBin) . ' -y -i ' . escapeshellarg($videoAbs) . ' -ss ' . escapeshellarg($ts)
                . ' -frames:v 1 -vf ' . escapeshellarg($vf) . ' ' . escapeshellarg($thumbnailPath) . ' 2>&1';
            @shell_exec($cmd2);
            if (@is_file($thumbnailPath) && @filesize($thumbnailPath) > 0) {
                @chmod($thumbnailPath, 0644);
                return true;
            }
        }
        // フォールバック: 既存のサムネイル関数があれば明示パスで呼び出す
        if (function_exists('generateThumbnail')) {
            $ok = @generateThumbnail($videoAbs, $thumbnailPath);
            if ($ok) { return true; }
        }
        return false;
    }
}

/**
 * 一時ファイルを削除
 */
function cleanupTempFiles($tempDir) {
    if (is_dir($tempDir)) {
        $files = glob("{$tempDir}/*");
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        rmdir($tempDir);
    }
}
?> 