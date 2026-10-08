<?php
require_once __DIR__ . '/init_api.php';

require_once 'video_converter.php';

// 共有リンクの認証チェック関数（有効なワンタイムパスワードの場合のみ許可）
function validateSharedAccess($videoFile, $sharePassword) {
    if (!is_string($sharePassword) || $sharePassword === '' || !is_string($videoFile) || $videoFile === '') {
        return false;
    }

    $basename = pathinfo($videoFile, PATHINFO_FILENAME);
    return validateVideoPassword($basename, $sharePassword);
}

// ログインAPIエンドポイント
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    requireCsrfToken();
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
        if (!empty($loginResult['locked'])) {
            http_response_code(429);
            header('Retry-After: ' . (int)$loginResult['retry_after']);
        }
        echo json_encode([
            'success' => false,
            'message' => loginFailureMessage($loginResult)
        ]);
    }
    exit;
}

// ログアウトAPIエンドポイント
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    requireCsrfToken();
    logout();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'ログアウトしました'
    ]);
    exit;
}

// 認証チェック（保護ON時のみ必要、ただし共有リンクの場合はスキップ）
// 共有リンクで許可するのは、その動画の再生回数・いいねの更新のみ
$isSharedAccess = false;
$sharedActions = ['increment_view', 'toggle_like'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', $sharedActions, true)) {
    $isSharedAccess = validateSharedAccess($_POST['video_file'] ?? '', $_POST['share_password'] ?? '');
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
    requireCsrfToken();
    
    $videoFile = $_POST['video_file'] ?? '';
    $videos = getVideoFiles();
    
    if ($videoFile && in_array($videoFile, $videos)) {
        $basename = pathinfo($videoFile, PATHINFO_FILENAME);
        // 再生回数だけを排他して加算する（同時再生での取りこぼし・他の項目の上書きを防ぐ）
        $newViews = Functions::incrementViews($basename);

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
    requireCsrfToken();
    
    $videoFile = $_POST['video_file'] ?? '';
    $videos = getVideoFiles();
    
    if ($videoFile && in_array($videoFile, $videos)) {
        $basename = pathinfo($videoFile, PATHINFO_FILENAME);
        // いいね数だけを排他して加算する
        $newLikes = Functions::incrementLikes($basename);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'likes' => $newLikes]);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit;
}

// 共有リンク生成API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_share_link') {
    requireCsrfToken();
    if (!isUserAuthenticated()) {
        echo json_encode(['success' => false, 'message' => '認証が必要です']);
        exit;
    }
    
    $videoFile = basename((string)($_POST['video_file'] ?? ''));
    if ($videoFile === '') {
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    if (!in_array($videoFile, getVideoFiles(), true)) {
        echo json_encode(['success' => false, 'message' => '動画が見つかりません']);
        exit;
    }
    
    // 管理者の場合はワンタイムパスワード付きの共有リンクを生成
    if (isAdmin()) {
        $shareLink = generateShareLink($videoFile);
        $message = 'ワンタイムパスワード付きの共有リンクが生成されました';
        $isSecure = true;
    } else {
        // 一般ユーザーの場合は通常の動画視聴リンクを生成（認証が必要）
        $shareLink = generateNormalShareLink($videoFile);
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