<?php
require_once __DIR__ . '/init_api.php';

// CSRF検証（状態変更系POST）
function requireCsrfOnPostApi() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($token)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF検証に失敗しました']);
            exit;
        }
    }
}
require_once 'video_converter.php';

// 共有リンクの認証チェック関数
function validateSharedAccess($videoFile, $sharePassword) {
    if (empty($sharePassword) || empty($videoFile)) {
        return false;
    }
    
    $basename = pathinfo($videoFile, PATHINFO_FILENAME);
    
    // ワンタイムパスワード付きの共有リンクの場合
    if (validateVideoPassword($basename, $sharePassword)) {
        return true;
    }
    
    // 通常の共有リンクの場合（空のパスワードは無効）
    if ($sharePassword === '') {
        return false;
    }
    
    // ここで通常の共有リンクの検証ロジックを追加（必要に応じて）
    return true;
}

// ログインAPIエンドポイント
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    requireCsrfOnPostApi();
    $password = $_POST['password'] ?? '';
    $rememberMe = true; // 常にリメンバーミー機能を有効にする
    
    $loginResult = login($password, $rememberMe);
    
    header('Content-Type: application/json');
    if ($loginResult['success']) {
        echo json_encode([
            'success' => true, 
            'role' => $loginResult['role'],
            'message' => 'ログインに成功しました'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'パスワードが正しくありません'
        ]);
    }
    exit;
}

// ログアウトAPIエンドポイント
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    requireCsrfOnPostApi();
    logout();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'ログアウトしました'
    ]);
    exit;
}

// 認証チェック（保護ON時のみ必要、ただし共有リンクの場合はスキップ）
$isSharedAccess = false;
$sharePassword = $_POST['share_password'] ?? $_GET['share_password'] ?? null;
$videoFile = $_POST['video_file'] ?? $_GET['video_file'] ?? '';

// 共有リンクアクセスの場合の認証チェック
if ($sharePassword && $videoFile) {
    $isSharedAccess = validateSharedAccess($videoFile, $sharePassword);
}

// 認証が必要な場合のみチェック（共有リンクの場合はスキップ）
if (isPasswordProtectionEnabled() && !isUserAuthenticated() && !$isSharedAccess) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => '認証が必要です']);
    exit;
}

// 再生回数更新のAPIエンドポイント
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'increment_view') {
    requireCsrfOnPostApi();
    
    $videoFile = $_POST['video_file'] ?? '';
    $videos = getVideoFiles();
    
    if ($videoFile && in_array($videoFile, $videos)) {
        $basename = pathinfo($videoFile, PATHINFO_FILENAME);
        $metadata = getVideoMetadata($basename);
        $newViews = $metadata['views'] + 1;
        
        saveVideoMetadata($basename, [
            'views' => $newViews,
            'title' => $metadata['title'],
            'comment' => $metadata['comment'] ?? '',
            'likes' => $metadata['likes'] ?? 0,
            'upload_date' => $metadata['upload_date'] ?? date('Y-m-d H:i:s')
        ]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'views' => $newViews]);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit;
}

// いいね更新のAPIエンドポイント
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_like') {
    requireCsrfOnPostApi();
    
    $videoFile = $_POST['video_file'] ?? '';
    $videos = getVideoFiles();
    
    if ($videoFile && in_array($videoFile, $videos)) {
        $basename = pathinfo($videoFile, PATHINFO_FILENAME);
        $metadata = getVideoMetadata($basename);
        
        $newLikes = $metadata['likes'] + 1;
        
        saveVideoMetadata($basename, [
            'likes' => $newLikes,
            'title' => $metadata['title'],
            'comment' => $metadata['comment'] ?? '',
            'views' => $metadata['views'] ?? 0,
            'upload_date' => $metadata['upload_date'] ?? date('Y-m-d H:i:s')
        ]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'likes' => $newLikes]);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit;
}

// 動画一覧APIエンドポイント
if (isset($_GET['action']) && $_GET['action'] === 'list_videos') {
    
    $offset = intval($_GET['offset'] ?? 0);
    $limit = intval($_GET['limit'] ?? 20);
    
    // Cookieからソート設定を取得
    $sortParam = $_COOKIE['sort_preference'] ?? 'new';
    
    // 動画ファイル一覧を取得（ソート済み）
    $videos = getSortedVideos($sortParam);
    
    $pagedVideos = array_slice($videos, $offset, $limit);
    $result = [];
    
    foreach ($pagedVideos as $video) {
        $basename = pathinfo($video, PATHINFO_FILENAME);
        $metadata = getVideoMetadata($basename);
        $thumb = "thumbnails/{$basename}.jpg";
        $result[] = [
            'video' => $video,
            'title' => $metadata['title'],
            'thumb' => file_exists($thumb) ? $thumb : Config::get('ui.default_thumbnail', 'images/default-thumbnail.svg'),
            'views' => $metadata['views'],
            'likes' => $metadata['likes'],
            'upload_date' => $metadata['upload_date'] ? date('Y-m-d', strtotime($metadata['upload_date'])) : date('Y-m-d', filemtime("videos/$video")),
            // APIは常に統一フォーマットを返す
            'duration' => formatDuration($metadata['duration'] ?? null),
            'isActive' => false, // フロントエンド側で判定
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 共有リンク生成API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_share_link') {
    requireCsrfOnPostApi();
    if (!isUserAuthenticated()) {
        echo json_encode(['success' => false, 'message' => '認証が必要です']);
        exit;
    }
    
    $videoFile = $_POST['video_file'] ?? '';
    if (empty($videoFile)) {
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $videoBasename = pathinfo($videoFile, PATHINFO_FILENAME);
    
    // 管理者の場合はワンタイムパスワード付きの共有リンクを生成
    if (isAdmin()) {
        $shareLink = generateShareLink($videoBasename);
        $message = 'ワンタイムパスワード付きの共有リンクが生成されました';
        $isSecure = true;
    } else {
        // 一般ユーザーの場合は通常の動画視聴リンクを生成（認証が必要）
        $shareLink = generateNormalShareLink($videoBasename);
        $message = '動画リンクが生成されました（認証が必要）';
        $isSecure = false;
    }
    
    if ($shareLink) {
        echo json_encode([
            'success' => true, 
            'share_link' => $shareLink,
            'message' => $message,
            'is_secure' => $isSecure
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'リンクの生成に失敗しました']);
    }
    exit;
}

// 無効なリクエストの場合
header('HTTP/1.1 404 Not Found');
echo json_encode(['error' => 'Invalid API endpoint']);
exit;
?> 