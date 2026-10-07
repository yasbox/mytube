<?php
require_once __DIR__ . '/init_api.php';

// CSRF検証（状態変更系POST）
function requireCsrfOnPost() {
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

// 管理者ログインAPI（認証チェックの前に配置）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_login') {
    requireCsrfOnPost();
    $password = $_POST['password'] ?? '';
    $rememberMe = true; // 常にリメンバーミー機能を有効にする
    
    $loginResult = login($password, $rememberMe);
    
    if ($loginResult['success']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'ログインしました', 'role' => $loginResult['role']]);
    } else {
        header('Content-Type: application/json');
        if (!empty($loginResult['locked'])) {
            http_response_code(429);
            header('Retry-After: ' . (int)$loginResult['retry_after']);
        }
        echo json_encode(['success' => false, 'message' => loginFailureMessage($loginResult)]);
    }
    exit;
}

// ログアウトAPI（認証チェックの前に配置）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_logout') {
    requireCsrfOnPost();
    logout();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'ログアウトしました']);
    exit;
}

// 管理者権限チェック（ログインAPIとログアウトAPIの後ろに配置）
if (!isUserAuthenticated()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

if (!isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '管理者権限が必要です']);
    exit;
}

// 終了した動画変換の後処理（進捗を見ていなくても変換後の動画が登録されるように）
finalizeFinishedConversions();

// 旧: リカバリーコード生成APIは廃止
// 管理者パスワード変更API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_admin_password') {
    requireCsrfOnPost();

    // 環境変数で管理されている場合は変更不可
    $envAdmin = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: null;
    if (is_string($envAdmin) && $envAdmin !== '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'この環境ではADMIN_PASSWORDが環境変数で管理されているため、UIからは変更できません']);
        exit;
    }

    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');

    // 入力検証
    if (trim($current) === '' || trim($new) === '') {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => '現在のパスワードと新しいパスワードを入力してください']);
        exit;
    }
    // 強度チェック: 8文字以上（複雑性の必須条件なし）
    $lengthOk = mb_strlen($new, 'UTF-8') >= 8;
    if (!$lengthOk) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'パスワードは8文字以上で入力してください']);
        exit;
    }

    // 現在のパスワード確認
    if (!verifyAdminPassword($current)) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '現在のパスワードが正しくありません']);
        exit;
    }

    // ハッシュにしてファイルへ保存（環境変数が無い環境のみ使用）
    if (!Config::saveAdminPassword($new)) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '設定ファイルの書き込みに失敗しました']);
        exit;
    }

    // RememberMe無効化（署名の鍵が変わるため、他の端末のログイン保持も無効になる）
    if (function_exists('clearRememberMeCookie')) {
        clearRememberMeCookie();
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => '管理者パスワードを変更しました']);
    exit;
}

// サムネイル差し替えAPI（画像→JPEG/長辺最大1000px/品質=軽め）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_thumbnail') {
    requireCsrfOnPost();
    
    // 認証は既に上で確認済み
    $videoFile = $_POST['video'] ?? '';
    if ($videoFile === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    // 動画の存在確認
    $videos = getVideoFiles();
    if (!in_array($videoFile, $videos, true)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '動画ファイルが見つかりません']);
        exit;
    }
    
    // ファイル受領確認
    if (!isset($_FILES['thumbnail'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'サムネイル画像がアップロードされていません']);
        exit;
    }
    
    $file = $_FILES['thumbnail'];
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        header('Content-Type: application/json');
        http_response_code(400);
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg = 'アップロードに失敗しました (error=' . (string)$err . ')';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    
    // サイズ上限（5MB）
    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        header('Content-Type: application/json');
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'ファイルサイズが大きすぎます（最大5MB）']);
        exit;
    }
    
    // MIME検査（実体）
    $tmpName = $file['tmp_name'];
    if (!is_uploaded_file($tmpName)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '不正なアップロードです']);
        exit;
    }
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);
    $allowed = ['image/jpeg', 'image/png', 'image/webp']; // GIFは除外
    if (!in_array($mime, $allowed, true)) {
        header('Content-Type: application/json');
        http_response_code(415);
        echo json_encode(['success' => false, 'message' => '対応していない画像形式です（JPEG/PNG/WebPのみ）']);
        exit;
    }
    
    // 画像として解釈できるか＆ピクセル上限（12MP）
    $imgInfo = @getimagesize($tmpName);
    if ($imgInfo === false || !isset($imgInfo[0], $imgInfo[1])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '画像として読み込めません']);
        exit;
    }
    $w = (int)$imgInfo[0];
    $h = (int)$imgInfo[1];
    $pixels = $w * $h;
    if ($pixels > 12000000) { // 12MP
        header('Content-Type: application/json');
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => '画像の解像度が大きすぎます（12MP以下にしてください）']);
        exit;
    }
    
    // 一時保存先
    $tempDir = __DIR__ . '/temp_uploads';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'img';
    $tempPath = $tempDir . '/' . uniqid('thumb_', true) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', strtolower($ext));
    if (!@move_uploaded_file($tmpName, $tempPath)) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '一時ファイルの保存に失敗しました']);
        exit;
    }
    
    // 出力パス
    $basename = pathinfo($videoFile, PATHINFO_FILENAME);
    $thumbDir = __DIR__ . '/thumbnails';
    if (!is_dir($thumbDir)) {
        @mkdir($thumbDir, 0755, true);
    }
    $outputPath = $thumbDir . '/' . $basename . '.jpg';
    
    // FFmpegで長辺1000pxへ縮小（比率維持／アップスケールなし）＋ JPEG化（軽め品質）
    $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
    // width>=height の場合は width=min(iw,1000)、高さは自動（偶数）。縦長は高さ=min(ih,1000)
    $vf = "scale='if(gte(iw,ih),min(iw,1000),-2)':'if(gte(iw,ih),-2,min(ih,1000))'";
    $cmd = $ffmpeg
        . ' -y -i ' . escapeshellarg($tempPath)
        . ' -vf ' . escapeshellarg($vf)
        . ' -q:v 6 '
        . escapeshellarg($outputPath) . ' 2>&1';
    $out = shell_exec($cmd);
    
    // 一時ファイル削除
    @unlink($tempPath);
    
    if (!file_exists($outputPath) || filesize($outputPath) <= 0) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'サムネイルの生成に失敗しました']);
        exit;
    }
    
    // 成功レスポンス（キャッシュバスター付与）
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'サムネイルを更新しました',
        'thumbnail_url' => 'thumbnails/' . $basename . '.jpg?v=' . time()
    ]);
    exit;
}

// サイトロゴアップロード（正方形化→ロゴ/ファビコン群生成）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_brand_logo') {
    requireCsrfOnPost();

    if (!isset($_FILES['brand_logo'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '画像がアップロードされていません']);
        exit;
    }

    $file = $_FILES['brand_logo'];
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        header('Content-Type: application/json');
        http_response_code(400);
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        echo json_encode(['success' => false, 'message' => 'アップロードに失敗しました (error=' . (string)$err . ')']);
        exit;
    }

    // サイズ上限（5MB）
    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        header('Content-Type: application/json');
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'ファイルサイズが大きすぎます（最大5MB）']);
        exit;
    }

    $tmpName = $file['tmp_name'];
    if (!is_uploaded_file($tmpName)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '不正なアップロードです']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        header('Content-Type: application/json');
        http_response_code(415);
        echo json_encode(['success' => false, 'message' => '対応していない画像形式です（PNG/JPEG/WebPのみ）']);
        exit;
    }

    $imgInfo = @getimagesize($tmpName);
    if ($imgInfo === false || !isset($imgInfo[0], $imgInfo[1])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '画像として読み込めません']);
        exit;
    }

    // 一時保存
    $tempDir = __DIR__ . '/temp_uploads';
    if (!is_dir($tempDir)) { @mkdir($tempDir, 0755, true); }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'img';
    $tempPath = $tempDir . '/' . uniqid('brand_', true) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', strtolower($ext));
    if (!@move_uploaded_file($tmpName, $tempPath)) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '一時ファイルの保存に失敗しました']);
        exit;
    }

    // 出力先
    $brandDir = __DIR__ . '/data/branding';
    if (!is_dir($brandDir)) { @mkdir($brandDir, 0755, true); }
    $square = $brandDir . '/logo-square.png';
    $logo48 = $brandDir . '/logo-48.png';
    $logo96 = $brandDir . '/logo-96.png';
    $fav16 = $brandDir . '/favicon-16x16.png';
    $fav32 = $brandDir . '/favicon-32x32.png';
    $apple = $brandDir . '/apple-touch-icon.png';
    $a192 = $brandDir . '/android-chrome-192x192.png';
    $a512 = $brandDir . '/android-chrome-512x512.png';

    $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
    // 正方形512px
    $vfSquare = "crop='min(iw,ih)':'min(iw,ih)',scale=512:512:flags=lanczos";
    $cmd1 = $ffmpeg . ' -y -i ' . escapeshellarg($tempPath) . ' -vf ' . escapeshellarg($vfSquare) . ' -map_metadata -1 ' . escapeshellarg($square) . ' 2>&1';
    $out1 = shell_exec($cmd1);

    // 派生生成
    $gen = function($in, $w, $h, $out) use ($ffmpeg) {
        $vf = 'scale=' . (int)$w . ':' . (int)$h . ':flags=lanczos';
        $cmd = $ffmpeg . ' -y -i ' . escapeshellarg($in) . ' -vf ' . escapeshellarg($vf) . ' -map_metadata -1 ' . escapeshellarg($out) . ' 2>&1';
        return shell_exec($cmd);
    };
    $gen($square, 48, 48, $logo48);
    $gen($square, 96, 96, $logo96);
    $gen($square, 16, 16, $fav16);
    $gen($square, 32, 32, $fav32);
    $gen($square, 180, 180, $apple);
    $gen($square, 192, 192, $a192);
    $gen($square, 512, 512, $a512);

    // 一時ファイル削除
    @unlink($tempPath);

    // 成功判定（最低限）
    if (!file_exists($square) || !file_exists($fav32)) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '画像の生成に失敗しました']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'サイトロゴを更新しました']);
    exit;
}

// サイトロゴリセット（ユーザー生成ファイル削除）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_brand_logo') {
    requireCsrfOnPost();
    $brandDir = __DIR__ . '/data/branding';
    $targets = [
        $brandDir . '/logo-square.png',
        $brandDir . '/logo-48.png',
        $brandDir . '/logo-96.png',
        $brandDir . '/favicon-16x16.png',
        $brandDir . '/favicon-32x32.png',
        $brandDir . '/apple-touch-icon.png',
        $brandDir . '/android-chrome-192x192.png',
        $brandDir . '/android-chrome-512x512.png',
    ];
    $ok = true;
    foreach ($targets as $p) {
        if (is_file($p)) {
            $ok = @unlink($p) && $ok;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'ブランド画像をリセットしました']);
    exit;
}

// 設定取得API（管理パネル用）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_settings') {
    $settingsPath = Config::getSettingsJsonPath();
    $jsonProtect = null;
    $jsonUserPw = null;
    $jsonLikesUnique = null;
    $jsonViewsUnique = null;
    $jsonAutoplay = null;
    $jsonAppName = null;
    $jsonUiTheme = null;
    $jsonAppDescription = null;
    if (is_file($settingsPath) && is_readable($settingsPath)) {
        $rawJson = file_get_contents($settingsPath);
        if ($rawJson !== false) {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                if (array_key_exists('password_protection', $decoded['security'])) {
                    $jsonProtect = $decoded['security']['password_protection'];
                }
                $jsonUserPw = $decoded['security']['user_password'] ?? null;
                if (isset($decoded['features']['likes']['unique_countup'])) {
                    $jsonLikesUnique = $decoded['features']['likes']['unique_countup'];
                }
                if (isset($decoded['features']['views']['unique_countup'])) {
                    $jsonViewsUnique = $decoded['features']['views']['unique_countup'];
                }
                if (isset($decoded['features']['autoplay'])) {
                    $jsonAutoplay = $decoded['features']['autoplay'];
                }
                if (isset($decoded['app']['name'])) {
                    $jsonAppName = (string)$decoded['app']['name'];
                }
                if (isset($decoded['ui']['theme'])) {
                    $jsonUiTheme = (string)$decoded['ui']['theme'];
                }
                if (isset($decoded['app']['description'])) {
                    $jsonAppDescription = (string)$decoded['app']['description'];
                }
            }
        }
    }
    // 設定（なければConfig）
    if ($jsonProtect === null) {
        $jsonProtect = Config::get('security.password_protection', false);
    }
    if ($jsonLikesUnique === null) {
        $jsonLikesUnique = Config::get('features.likes.unique_countup', false);
    }
    if ($jsonViewsUnique === null) {
        $jsonViewsUnique = Config::get('features.views.unique_countup', false);
    }
    if ($jsonAutoplay === null) {
        $jsonAutoplay = Config::get('features.autoplay', true);
    }
    $appName = $jsonAppName !== null ? $jsonAppName : (string)Config::get('app.name', 'MyTube');
    $uiTheme = $jsonUiTheme !== null ? strtolower($jsonUiTheme) : (string)Config::get('ui.theme', 'light');
    if (!in_array($uiTheme, ['light', 'dark'], true)) { $uiTheme = 'light'; }
    $appDescription = $jsonAppDescription !== null ? (string)$jsonAppDescription : (string)Config::get('app.description', '');
    $normalized = filter_var($jsonProtect, FILTER_VALIDATE_BOOLEAN);
    // ユーザーパスワードを返す（settings.jsonが優先、なければConfig）
    $userPwRaw = $jsonUserPw !== null ? (string)$jsonUserPw : (string)Config::get('security.user_password', '');
    $settings = [
        // 新構造: 明示的に password_protection を返す
        'password_protection' => $normalized,
        'user_password' => (string)$userPwRaw,
        'features' => [
            'autoplay' => filter_var($jsonAutoplay, FILTER_VALIDATE_BOOLEAN),
            'likes' => [
                'unique_countup' => filter_var($jsonLikesUnique, FILTER_VALIDATE_BOOLEAN)
            ],
            'views' => [
                'unique_countup' => filter_var($jsonViewsUnique, FILTER_VALIDATE_BOOLEAN)
            ]
        ],
        'app' => [
            'name' => $appName,
            'description' => $appDescription
        ],
        'ui' => [
            'theme' => $uiTheme
        ]
    ];
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit;
}

// 設定保存API（settings.php用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    requireCsrfOnPost();
    $settingsJson = $_POST['settings'] ?? '';
    
    if (empty($settingsJson)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '設定データが指定されていません']);
        exit;
    }
    
    $settings = json_decode($settingsJson, true);
    if (!is_array($settings)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '無効な設定データです']);
        exit;
    }
    
    // 設定をJSONに永続化
    $settingsPath = Config::getSettingsJsonPath();
    $dir = dirname($settingsPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $current = [];
    if (is_file($settingsPath)) {
        $raw = file_get_contents($settingsPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $current = $decoded;
        }
    }
    
    // 新しい設定を反映
    if (isset($settings['password_protection'])) {
        $current['security']['password_protection'] = $settings['password_protection'];
    }
    if (isset($settings['user_password'])) {
        $candidatePw = (string)$settings['user_password'];
        // ASCIIのみ許可（半角スペース〜チルダ）。日本語など非ASCIIが含まれる場合はエラー
        if (!preg_match('/^[\x20-\x7E]+$/', $candidatePw)) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'パスワードは半角英数字と記号のみで入力してください']);
            exit;
        }
        $current['security']['user_password'] = $candidatePw;
    }
    if (isset($settings['likes_unique_countup'])) {
        $current['features']['likes']['unique_countup'] = $settings['likes_unique_countup'];
    }
    if (isset($settings['views_unique_countup'])) {
        $current['features']['views']['unique_countup'] = $settings['views_unique_countup'];
    }
    if (isset($settings['autoplay'])) {
        $current['features']['autoplay'] = filter_var($settings['autoplay'], FILTER_VALIDATE_BOOLEAN);
    }
    
    // JSONファイルに保存
    $json = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '設定のシリアライズに失敗しました']);
        exit;
    }
    
    $tmp = $settingsPath . '.tmp';
    $bytes = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes === false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '設定ファイルの書き込みに失敗しました']);
        exit;
    }
    
    $renamed = @rename($tmp, $settingsPath);
    if (!$renamed) {
        @unlink($tmp);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '設定ファイルの更新に失敗しました']);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => '設定を保存しました']);
    exit;
}

// 設定更新API（管理パネル用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    requireCsrfOnPost();

    
    $passwordProtection = null;
    if (isset($_POST['password_protection'])) {
        $passwordProtection = (strtolower($_POST['password_protection']) === '1' || strtolower($_POST['password_protection']) === 'true');
    }
    $userPassword = isset($_POST['user_password']) ? (string)$_POST['user_password'] : null;
    $likesUniqueCountup = null;
    if (isset($_POST['features.likes.unique_countup'])) {
        $likesUniqueCountup = (strtolower($_POST['features.likes.unique_countup']) === '1' || strtolower($_POST['features.likes.unique_countup']) === 'true');
    } elseif (isset($_POST['features_likes_unique_countup'])) {
        $likesUniqueCountup = (strtolower($_POST['features_likes_unique_countup']) === '1' || strtolower($_POST['features_likes_unique_countup']) === 'true');
    }
    $viewsUniqueCountup = null;
    if (isset($_POST['features.views.unique_countup'])) {
        $viewsUniqueCountup = (strtolower($_POST['features.views.unique_countup']) === '1' || strtolower($_POST['features.views.unique_countup']) === 'true');
    } elseif (isset($_POST['features_views_unique_countup'])) {
        $viewsUniqueCountup = (strtolower($_POST['features_views_unique_countup']) === '1' || strtolower($_POST['features_views_unique_countup']) === 'true');
    }

    $autoplayEnabled = null;
    if (isset($_POST['features.autoplay'])) {
        $autoplayEnabled = (strtolower($_POST['features.autoplay']) === '1' || strtolower($_POST['features.autoplay']) === 'true');
    } elseif (isset($_POST['features_autoplay'])) {
        $autoplayEnabled = (strtolower($_POST['features_autoplay']) === '1' || strtolower($_POST['features_autoplay']) === 'true');
    }

    // アプリ設定: サイト名/説明
    $appNameUpdate = null;
    $appDescriptionUpdate = null;
    $appNameUnset = false;
    // UI: デフォルトテーマ
    $uiThemeUpdate = null;
    if (isset($_POST['ui.theme'])) {
        $uiThemeUpdate = strtolower((string)$_POST['ui.theme']);
    } elseif (isset($_POST['ui_theme'])) {
        $uiThemeUpdate = strtolower((string)$_POST['ui_theme']);
    }

    if (isset($_POST['app.name'])) {
        $appNameUpdate = trim((string)$_POST['app.name']);
    } elseif (isset($_POST['app_name'])) {
        $appNameUpdate = trim((string)$_POST['app_name']);
    }
    if (isset($_POST['app.description'])) {
        $appDescriptionUpdate = trim((string)$_POST['app.description']);
    } elseif (isset($_POST['app_description'])) {
        $appDescriptionUpdate = trim((string)$_POST['app_description']);
    }
    


    // 入力検証（最低限）
    $updates = [];
    if ($passwordProtection !== null) {
        $updates['security.password_protection'] = $passwordProtection;
    }
    if ($userPassword !== null) {
        // 空文字・空白のみはエラー
        if (trim($userPassword) === '') {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'パスワードを空にはできません']);
            exit;
        }
        // ASCIIのみ許可（半角スペース〜チルダ）。日本語など非ASCIIが含まれる場合はエラー
        if (!preg_match('/^[\x20-\x7E]+$/', $userPassword)) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'パスワードは半角英数字と記号のみで入力してください']);
            exit;
        }
        $updates['security.user_password'] = $userPassword;
    }
    if ($likesUniqueCountup !== null) {
        $updates['features.likes.unique_countup'] = $likesUniqueCountup;
    }
    if ($viewsUniqueCountup !== null) {
        $updates['features.views.unique_countup'] = $viewsUniqueCountup;
    }
    if ($autoplayEnabled !== null) {
        $updates['features.autoplay'] = $autoplayEnabled;
    }
    if ($appNameUpdate !== null) {
        if ($appNameUpdate === '') {
            // 空の場合は settings.json の app.name を削除し、.env/既定にフォールバック
            $appNameUnset = true;
        } else {
            $updates['app.name'] = $appNameUpdate;
        }
    }
    if ($appDescriptionUpdate !== null) {
        // 空は許容（空で非表示）
        $updates['app.description'] = $appDescriptionUpdate;
    }
    if ($uiThemeUpdate !== null) {
        if (!in_array($uiThemeUpdate, ['light', 'dark'], true)) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '無効なテーマ指定です']);
            exit;
        }
        $updates['ui.theme'] = $uiThemeUpdate;
    }

    // 送信内容・更新内容はログに出さない（閲覧者パスワードが含まれるため）

    // 設定をJSONに永続化（app/data/settings.json）
    $settingsPath = Config::getSettingsJsonPath();
    $dir = dirname($settingsPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $current = [];
    if (is_file($settingsPath)) {
        $raw = file_get_contents($settingsPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $current = $decoded;
        }
    }

    // ネストキーを配列に反映
    foreach ($updates as $dotKey => $value) {
        $keys = explode('.', $dotKey);
        $ref = &$current;
        foreach ($keys as $idx => $k) {
            if ($idx === count($keys) - 1) {
                $ref[$k] = $value;
            } else {
                if (!isset($ref[$k]) || !is_array($ref[$k])) { $ref[$k] = []; }
                $ref = &$ref[$k];
            }
        }
        // ランタイムにも反映
        Config::set($dotKey, $value);
    }

    if ($appNameUnset) {
        if (isset($current['app']['name'])) {
            unset($current['app']['name']);
        }
        // ランタイムは次リクエストで再解決されるためここでは未設定
    }


    // セキュリティ: JSON書き込みは原子的に
    $json = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log('Failed to serialize settings');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '設定のシリアライズに失敗しました']);
        exit;
    }
    

    
    $tmp = $settingsPath . '.tmp';
    $bytes = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes === false) {
        $err = error_get_last();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '設定ファイルの書き込みに失敗しました', 'path' => $tmp, 'error' => $err['message'] ?? '']);
        exit;
    }
    
    $renamed = @rename($tmp, $settingsPath);
    if (!$renamed) {
        // フォールバック: 既存を削除してコピー
        @unlink($settingsPath);
        $copied = @copy($tmp, $settingsPath);
        @unlink($tmp);
        if (!$copied) {
            $err = error_get_last();
            error_log('Failed to update settings file: ' . ($err['message'] ?? 'Unknown error'));
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => '設定ファイルの更新に失敗しました', 'path' => $settingsPath, 'error' => $err['message'] ?? '']);
            exit;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => '設定を更新しました',
        'updated_settings' => $updates + ($appNameUpdate !== null ? ['app.name' => ($appNameUnset ? null : $appNameUpdate)] : [])
    ]);
    exit;
}

// 動画削除API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_video') {
    requireCsrfOnPost();
    $videoFile = $_POST['video'] ?? '';
    
    if (empty($videoFile)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $result = deleteVideo($videoFile);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 動画メタデータ更新API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_metadata') {
    requireCsrfOnPost();
    $videoFile = $_POST['video'] ?? '';
    $title = $_POST['title'] ?? '';
    $comment = $_POST['comment'] ?? '';
    
    
    
    if (empty($videoFile)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $result = updateVideoMetadata($videoFile, $title, $comment);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 動画の公開/非公開切り替えAPI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_visibility') {
    requireCsrfOnPost();
    $videoFile = $_POST['video'] ?? '';
    
    if (empty($videoFile)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $result = toggleVideoVisibility($videoFile);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 動画統計情報取得API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_stats') {
    $stats = getVideoStats();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}

// 管理用動画一覧取得API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_admin_videos') {
    $videos = getAdminVideoList();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'videos' => $videos]);
    exit;
}

// ソート機能付き管理用動画一覧取得API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_admin_videos_sorted') {
    $sort = $_GET['sort'] ?? 'new';
    
    // ソートパラメータの検証
    if (!in_array($sort, ['new', 'popular', 'views', 'likes'])) {
        $sort = 'new';
    }
    
    $videos = getAdminVideoListSorted($sort);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'videos' => $videos, 'sort' => $sort]);
    exit;
}

// ページネーション機能付き管理用動画一覧取得API（無限スクロール用）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_admin_videos_paginated') {
    $sort = $_GET['sort'] ?? 'new';
    $page = intval($_GET['page'] ?? 1);
    $perPage = intval($_GET['per_page'] ?? 10);
    
    // パラメータの検証
    if (!in_array($sort, ['new', 'popular', 'views', 'likes'])) {
        $sort = 'new';
    }
    if ($page < 1) $page = 1;
    if ($perPage < 1 || $perPage > 50) $perPage = 10;
    
    $result = getAdminVideoListPaginated($sort, $page, $perPage);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 動画変換開始API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_conversion') {
    requireCsrfOnPost();
    $videoFile = $_POST['video'] ?? '';
    
    if (empty($videoFile)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $result = startVideoConversion($videoFile);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 変換進捗取得API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_conversion_progress') {
    $videoFile = $_GET['video'] ?? '';
    
    if (empty($videoFile)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $result = getConversionProgress($videoFile);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 変換停止API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'stop_conversion') {
    requireCsrfOnPost();
    $videoFile = $_POST['video'] ?? '';
    
    if (empty($videoFile)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '動画ファイルが指定されていません']);
        exit;
    }
    
    $result = stopVideoConversion($videoFile);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 無効なリクエストの場合
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => '無効なAPIエンドポイントです']);
exit;
?> 