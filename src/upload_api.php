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
                $finalizeResult = tryFinalizeUpload($tempDir, $resumableTotalChunks, $resumableTotalSize, $resumableFilename, (string)($_POST['title'] ?? ''), (string)($_POST['comment'] ?? ''));
                if ($finalizeResult !== false) {
                    if (empty($finalizeResult['success'])) {
                        // 登録に失敗したことを画面に伝える（200 だと Resumable.js は成功とみなす）
                        http_response_code(500);
                    }
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
        // 200 で返すと Resumable.js がチャンクの成功とみなし、失敗が画面に伝わらない
        http_response_code(500);
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
            // タイトル・コメントは Resumable.js が確認リクエストにも付けて送る
            $finalizeResult = false;
            if ($resumableTotalChunks > 0 && $resumableFilename !== '') {
                $finalizeResult = tryFinalizeUpload($tempDir, $resumableTotalChunks, $resumableTotalSize, $resumableFilename, (string)($_GET['title'] ?? ''), (string)($_GET['comment'] ?? ''));
                if ($finalizeResult !== false) {
                    if (empty($finalizeResult['success'])) {
                        http_response_code(500);
                    }
                    echo json_encode($finalizeResult);
                    exit;
                }
            }
            echo json_encode(['success' => true, 'message' => 'チャンクが存在します']);
        } elseif (($recent = readRecentFinalizeResult($resumableIdentifier)) !== null) {
            // 並行した確認の間に登録が完了し一時ファイルが消えた場合は、送り直させずに結果を返す
            header('Content-Type: application/json');
            echo json_encode($recent);
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
 * チャンクを一時ディレクトリ内で結合する
 * 元のファイル名は使わない（長い日本語名はファイル名の上限 255 バイトを超え、作成に失敗するため）
 * 成功時は結合したファイルのパス、失敗時は false
 */
function combineChunks($tempDir, $totalChunks) {
    $combinedPath = "{$tempDir}/combined";
    $out = @fopen($combinedPath, 'wb');
    if (!$out) {
        return false;
    }
    for ($i = 1; $i <= $totalChunks; $i++) {
        $in = @fopen("{$tempDir}/chunk_{$i}", 'rb');
        $copied = $in ? stream_copy_to_stream($in, $out) : false;
        if ($in) {
            fclose($in);
        }
        if ($copied === false) {
            fclose($out);
            @unlink($combinedPath);
            return false;
        }
    }
    if (!fclose($out)) {
        @unlink($combinedPath);
        return false;
    }
    return $combinedPath;
}

function allChunksPresent(string $tempDir, int $totalChunks): bool {
    if ($totalChunks <= 0) {
        return false;
    }
    for ($i = 1; $i <= $totalChunks; $i++) {
        if (!is_file("{$tempDir}/chunk_{$i}")) {
            return false;
        }
    }
    return true;
}

/**
 * 直前（2分以内）に同じ識別子で完了した登録結果を返す（なければ null）
 * 最後のチャンクが同時に届いた場合や、再開時のチャンク確認が並行した場合に、
 * 後から来たリクエストにも同じ結果を返して、画面に正しく完了を伝えるため
 */
function readRecentFinalizeResult(string $identifier): ?array {
    $path = "temp_uploads/{$identifier}.result.json";
    if (!is_file($path) || filemtime($path) < time() - 120) {
        return null;
    }
    $result = json_decode((string)@file_get_contents($path), true);
    if (!is_array($result) || empty($result['filename']) || !is_file('videos/' . basename($result['filename']))) {
        return null;
    }
    return $result;
}

/**
 * すべてのチャンクが揃っていれば結合し、保存・メタ生成まで行う
 * 戻り値: まだ揃っていなければ false、揃っていれば結果の配列（success が false なら登録失敗）
 */
function tryFinalizeUpload(string $tempDir, int $totalChunks, int $totalSize, string $originalFilename, string $title, string $comment) {
    if (!allChunksPresent($tempDir, $totalChunks)) {
        // 未到着があるためまだ確定しない
        return false;
    }

    // 同時に揃った別のリクエストと二重に登録しないよう排他する
    $identifier = basename($tempDir);
    $lock = @fopen("temp_uploads/{$identifier}.lock", 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        if (!allChunksPresent($tempDir, $totalChunks)) {
            // 待っている間に別のリクエストが登録を終えた
            return readRecentFinalizeResult($identifier) ?? false;
        }

        $fail = function (string $message) {
            error_log('upload_api.php: finalize failed: ' . $message);
            return ['success' => false, 'message' => $message];
        };

        // 合計サイズの検証
        $sum = 0;
        for ($i = 1; $i <= $totalChunks; $i++) {
            $sum += (int)@filesize("{$tempDir}/chunk_{$i}");
        }
        if ($totalSize > 0 && abs($sum - $totalSize) > max(1024, (int)ceil($totalSize * 0.01))) {
            return $fail('受信したファイルのサイズが一致しません。もう一度アップロードしてください');
        }

        // 拡張子は小文字にそろえる（.MOV 等も他の動画と同じ扱いにするため）
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'ogg', 'avi', 'mov', 'mkv', 'flv'], true)) {
            return $fail('サポートされていないファイル形式です');
        }

        $combined = combineChunks($tempDir, $totalChunks);
        if (!$combined || filesize($combined) <= 0) {
            return $fail('ファイルの結合に失敗しました（サーバーの空き容量や権限を確認してください）');
        }

        $basename = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $newFilename = $basename . '.' . $ext;
        $newPath = 'videos/' . $newFilename;
        if (!@rename($combined, $newPath)) {
            @unlink($combined);
            return $fail('動画の保存に失敗しました');
        }

        saveVideoMetadata($basename, [
            'title' => $title,
            'comment' => $comment,
            'upload_date' => date('Y-m-d H:i:s'),
            'views' => 0,
            'likes' => 0
        ]);

        // メタ生成・サムネ生成（失敗しても動画保存は完了）
        @generateVideoMetadata($newPath, $basename);
        @generateThumbnail($newPath, 'thumbnails/' . $basename . '.jpg');

        $response = [
            'success' => true,
            'message' => 'アップロードが完了しました',
            'filename' => $newFilename,
            'video_id' => $newFilename
        ];
        // 結果を先に記録してから一時ファイルを消す（消した直後の確認リクエストにも結果を返せるように）
        @file_put_contents("temp_uploads/{$identifier}.result.json", json_encode($response), LOCK_EX);
        cleanupTempFiles($tempDir);
        cleanupStaleUploadFiles();
        return $response;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * 不要になったアップロード用の一時ファイルを削除する（アップロード完了時に実行）
 * - 排他用・結果用ファイル: 1時間以上前のもの
 * - 中断・失敗したアップロードのチャンク: 最後の更新から7日以上たったもの
 *   （それまでは同じファイルを選び直せば続きから登録できる）
 */
function cleanupStaleUploadFiles() {
    foreach (array_merge(glob('temp_uploads/*.lock') ?: [], glob('temp_uploads/*.result.json') ?: []) as $file) {
        if (is_file($file) && filemtime($file) < time() - 3600) {
            @unlink($file);
        }
    }
    foreach (glob('temp_uploads/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (isValidResumableIdentifier(basename($dir)) && filemtime($dir) < time() - 7 * 86400) {
            cleanupTempFiles($dir);
        }
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