<?php
require_once __DIR__ . '/init_web.php';


// APIエンドポイントへのPOSTリクエストは既存の api.php に委譲
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    include 'api.php';
    exit;
}

// 終了した動画変換の後処理（管理画面で進捗を見ていなくても変換後の動画が一覧に出るように）
finalizeFinishedConversions();

// 共有リンクのGETリクエストの場合は、api.phpを実行せずに通常のページ処理を続行
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['share'])) {
    // 共有リンクのGETリクエストの場合は、ここで処理を続行
    // 明示的に通常のページ処理を続行することを示す
    $isShareLinkRequest = true;
} else {
    // 共有リンクでない場合のみ、APIエンドポイントの処理を続行
    $isShareLinkRequest = false;
}

// API: 動画リスト取得（JSON）
if (isset($_GET['action']) && $_GET['action'] === 'list_videos') {
    header('Content-Type: application/json; charset=UTF-8');
    // 認証必須（保護ON時のみ）
    if (isPasswordProtectionEnabled() && !isUserAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 20;
    $sort = $_GET['sort'] ?? ($_COOKIE['sort_preference'] ?? 'new');
    if (!in_array($sort, ['new', 'popular', 'views', 'likes'], true)) {
        $sort = 'new';
    }

    $all = getSortedVideos($sort);
    $slice = array_slice($all, $offset, $limit);

    $mapped = array_map(function($v) {
        return [
            'video' => $v['filename'] ?? ($v['id'] ?? ''),
            'title' => (string)($v['title'] ?? ''),
            'views' => (int)($v['views'] ?? 0),
            'likes' => (int)($v['likes'] ?? 0),
            'upload_date' => (string)($v['upload_date'] ?? ''),
            'thumb' => (string)($v['thumbnail'] ?? ''),
            'duration' => formatDuration($v['duration'] ?? null),
            'isActive' => false,
        ];
    }, $slice);

    echo json_encode($mapped, JSON_UNESCAPED_UNICODE);
    exit;
}

// 動画一覧を取得（構造化データ）
$videos = getSortedVideos();

// ファイル名だけの配列（旧レイアウト互換）
$videoFiles = array_values(array_filter(array_map(function($v){
    return isset($v['filename']) ? (string)$v['filename'] : null;
}, $videos)));

// ソート表示用（UIのアクティブ表示に使用）
$sort = $_COOKIE['sort_preference'] ?? 'new';

// 再生対象（?v=...）
$currentVideo = isset($_GET['v']) ? basename((string)$_GET['v']) : null;

// 動画ファイルの存在確認
if ($currentVideo) {
    $videoPath = "videos/{$currentVideo}";
    if (!file_exists($videoPath)) {
        error_log("index.php: 動画ファイルが見つかりません: {$videoPath}");
        $currentVideo = null;
    }
}

$currentVideoIndex = $currentVideo ? array_search($currentVideo, $videoFiles, true) : false;
if ($currentVideoIndex === false && $currentVideo) {
    error_log("index.php: 動画ファイルがリストに存在しません: {$currentVideo}");
    $currentVideo = null;
    $currentVideoIndex = null;
}

// 共有リンク認証チェック
// 有効なワンタイムパスワード付きの共有リンクの場合のみ、ログインなしで該当動画を閲覧できる
$sharePassword = isset($_GET['share']) ? (string)$_GET['share'] : '';
$isSharedAccess = false;
$isAuthenticatedUser = isUserAuthenticated();

if (!$isAuthenticatedUser && $sharePassword !== '' && $currentVideo) {
    $currentBasename = pathinfo($currentVideo, PATHINFO_FILENAME);
    if (validateVideoPassword($currentBasename, $sharePassword)) {
        $isSharedAccess = true;
        // 動画ファイル・サムネイルの配信（media.php）でも閲覧を許可する
        grantSharedMediaAccess($currentBasename, $sharePassword);
    }
}

// 認証チェック（保護ON時のみ、共有リンクの場合はスキップ）
if (!$isSharedAccess) {
    requireViewerAccess();
}

// 現在の動画が非公開の場合のアクセス制御（保護とは別軸）
if ($currentVideo && isPasswordProtectionEnabled()) {
    $currentBasename = pathinfo($currentVideo, PATHINFO_FILENAME);
    $currentMetadata = getVideoMetadata($currentBasename);
    if (($currentMetadata['is_public'] ?? true) === false) {
        // 非公開動画へのアクセスを拒否
        http_response_code(404);
        $pageTitle = '動画が見つかりません - ' . Config::get('app.name', 'MyTube');
        $pageCss = 'index';
        include 'head.php';
        include 'header.php';
        ?>
        <div class="flex flex-col items-center justify-center min-h-screen px-4">
            <div class="text-center">
                <h1 class="font-bold mb-4">動画が見つかりません</h1>
                <p class="text-lg mb-8">この動画は非公開に設定されているか、存在しません。</p>
                <a href="index.php" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200" style="background-color: var(--blue-600);">
                    トップページに戻る
                </a>
            </div>
        </div>
        <?php include 'footer.php'; ?>
        </body>
        </html>
        <?php
        exit;
    }
}

// ランダム動画（サイドバー）
$randomVideos = [];
if (count($videoFiles) > 1 && $currentVideo) {
    $availableVideos = array_values(array_filter($videoFiles, fn($v) => $v !== $currentVideo));
    if (count($availableVideos) > 0) {
        shuffle($availableVideos);
        $randomVideos = array_slice($availableVideos, 0, min(8, count($availableVideos)));
    }
}

// 現在の動画情報
$currentBasename = $currentVideo ? pathinfo($currentVideo, PATHINFO_FILENAME) : '';
$currentThumbPath = $currentVideo ? "thumbnails/{$currentBasename}.jpg" : '';
$currentThumbUrl = ($currentThumbPath && file_exists($currentThumbPath)) ? ($currentThumbPath . '?v=' . filemtime($currentThumbPath)) : '';
$currentMetadata = $currentVideo ? getVideoMetadata($currentBasename) : ['title' => '', 'comment' => '', 'views' => 0, 'likes' => 0, 'upload_date' => null, 'duration' => null];
$currentTitle = $currentMetadata['title'] ?? '';
$currentComment = $currentMetadata['comment'] ?? '';
$viewCount = (int)($currentMetadata['views'] ?? 0);
$likeCount = (int)($currentMetadata['likes'] ?? 0);
$currentUploadDate = $currentVideo ? (($currentMetadata['upload_date'] ?? null) ? date('Y-m-d', strtotime($currentMetadata['upload_date'])) : (file_exists("videos/$currentVideo") ? date('Y-m-d', filemtime("videos/$currentVideo")) : '')) : '';

// 管理者ページフラグを設定（設定ページへのリンク表示用）
$isAdminPage = isAdmin();

// ページタイトル
if ($currentVideo && $currentTitle) {
    $pageTitle = htmlspecialchars($currentTitle) . ' - ' . Config::get('app.name', 'MyTube');
} else {
    $pageTitle = Config::get('app.name', 'MyTube');
}
$pageCss = 'index';
?>

<!DOCTYPE html>
<html lang="ja">
  <?php 
    $indexJsVersion = getAssetVersion('assets/js/index-page.js');
    $additionalScripts = (
      ($additionalScripts ?? '') .
      "\n  <script src=\"assets/js/index-page.js?v={$indexJsVersion}\" defer></script>"
    );
    include 'head.php'; 
  ?>
  <body class="min-h-screen flex flex-col">
  <?php include 'header.php'; ?>
  
  <!-- 設定をメタタグ経由でJSへ伝達 -->
  <?php
    $hasViewsUniqueCountup = Config::has('features.views.unique_countup');
    $hasLikesUniqueCountup = Config::has('features.likes.unique_countup');
    $viewsUniqueCountup = Config::get('features.views.unique_countup', false);
    $likesUniqueCountup = Config::get('features.likes.unique_countup', false);
    $autoplayEnabled = Config::get('features.autoplay', true);
  ?>
  <meta name="unique-countup" content="<?= $viewsUniqueCountup ? 'true' : 'false' ?>">
  <meta name="unique-like-countup" content="<?= $likesUniqueCountup ? 'true' : 'false' ?>">
  <meta name="autoplay-enabled" content="<?= $autoplayEnabled ? 'true' : 'false' ?>">
  <meta name="is-shared-access" content="<?= $isSharedAccess ? 'true' : 'false' ?>">
  <meta name="share-password" content="<?= $isSharedAccess ? htmlspecialchars($sharePassword, ENT_QUOTES, 'UTF-8') : '' ?>">

  <!-- メインコンテンツ -->
  <?php if ($currentVideo): ?>
    <?php 
      $videoMimeTypes = [
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg',
        'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'flv' => 'video/x-flv'
      ];
      $ext = strtolower(pathinfo($currentVideo, PATHINFO_EXTENSION));
      $mimeType = $videoMimeTypes[$ext] ?? 'video/mp4';
    ?>
    <div class="flex flex-col lg:flex-row w-full max-w-[1920px] mx-auto px-4 md:px-8 lg:px-12 xl:px-16 2xl:px-20 3xl:px-24 gap-4 overflow-visible mt-2 md:mt-2">
      <!-- メインエリア（動画プレイヤーと動画情報） -->
      <div class="flex-1 min-w-0 flex flex-col max-w-full overflow-hidden">
        <div class="animate-fade-in w-full pt-2 md:pt-4 lg:pt-6">
          <!-- 動画プレイヤー -->
          <div class="video-player-container w-full aspect-video relative max-w-full rounded-lg md:rounded-xl lg:rounded-2xl overflow-hidden mb-4">
            <video id="video-player" controls <?= $autoplayEnabled ? 'autoplay' : '' ?> class="absolute top-0 left-0 w-full h-full object-contain bg-black block" poster="<?= $currentThumbUrl ?: 'images/default-thumbnail.svg' ?>" preload="metadata">
              <source src="videos/<?= urlencode($currentVideo) ?>" type="video/mp4">
              お使いのブラウザは動画の再生に対応していません。
            </video>
          </div>
          
          <!-- 動画情報 -->
          <div class="video-info-container md:backdrop-blur-md md:rounded-xl mb-6">
            <?php if ($isSharedAccess): ?>
            <div class="mb-4 p-3 bg-blue-100 border-blue-300 border rounded-lg" style="background-color: var(--blue-100); border-color: var(--blue-300);">
              <div class="flex items-center gap-2 text-blue-800" style="color: var(--blue-800);">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
                <span class="text-sm font-medium">
                  ワンタイムパスワード付き共有リンクでアクセス中 - この動画は認証なしで閲覧できます
                </span>
              </div>
            </div>
            <?php endif ?>
            <div class="flex flex-col md:flex-row md:items-start md:justify-between">
              <div class="flex-1 min-w-0">
                <div class="mb-4">
                  <h1 class="video-title-current font-bold leading-tight m-0"><?= htmlspecialchars($currentTitle) ?: 'タイトルなし' ?></h1>
                </div>
                <div class="flex justify-between items-center mb-4 flex-wrap gap-4">
                  <div class="flex items-center gap-6 flex-wrap">
                    <div class="flex items-center gap-1">
                      <span class="text-lg font-semibold video-title-main"><?= number_format($viewCount) ?></span>
                      <span class="text-sm video-meta-info">回再生</span>
                    </div>
                    <div class="flex items-center gap-1">
                      <span class="text-sm video-meta-info font-medium">公開日:</span>
                      <span class="text-sm video-meta-info"><?= $currentUploadDate ?></span>
                    </div>
                    <?php if (!empty($currentMetadata['duration'])): ?>
                    <div class="flex items-center gap-1">
                      <span class="text-sm video-meta-info font-medium">長さ:</span>
                      <span class="text-sm video-meta-info"><?= formatDuration($currentMetadata['duration']) ?></span>
                    </div>
                    <?php endif ?>
                  </div>
                  <div class="flex items-center">
                    <div class="flex items-center gap-3">
                      <button id="like-button" class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 cursor-pointer border-none bg-white/10 video-button-text hover:bg-red-500/20 hover:text-red-400 hover:-translate-y-0.5 active:scale-95 relative overflow-visible" onclick="toggleLike('<?= urlencode($currentVideo) ?>')">
                        <svg id="like-icon" class="w-5 h-5 transition-all duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                        <span id="like-count" class="font-medium"><?= number_format($likeCount) ?></span>
                      </button>
                      <?php if (!$isSharedAccess): ?>
                      <button id="share-button" data-video="<?= urlencode($currentVideo) ?>" data-title="<?= htmlspecialchars($currentTitle) ?>" class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 cursor-pointer border-none bg-blue-500/10 video-button-text hover:bg-blue-500/20 hover:-translate-y-0.5 active:scale-95" style="background-color: var(--blue-500-10);">
                        <svg class="w-5 h-5 transition-all duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.367 2.684 3 3 0 00-5.367-2.684z"></path></svg>
                        <span class="font-medium">共有</span>
                      </button>
                      <?php if (isAdmin() && isPasswordProtectionEnabled()): ?>
                      <button id="share-link-button" data-video="<?= urlencode($currentVideo) ?>" data-title="<?= htmlspecialchars($currentTitle) ?>" class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 cursor-pointer border-none bg-green-500/10 video-button-text hover:bg-green-500/20 hover:-translate-y-0.5 active:scale-95" style="background-color: var(--green-500-10);">
                        <svg class="w-5 h-5 transition-all duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                        <span class="font-medium">共有リンク</span>
                      </button>
                      <?php endif ?>
                      <?php endif ?>
                    </div>
                  </div>
                  <?php if (isAdmin() && isPasswordProtectionEnabled()): ?>
                  <div class="w-full mt-1 flex justify-end">
                    <button id="share-link-help" type="button" aria-haspopup="dialog" aria-controls="share-link-popover" aria-expanded="false" class="px-2 py-1 rounded text-xs underline video-button-text hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-blue-500" title="共有リンクの仕様">共有リンクとは？</button>
                    <div id="share-link-popover" class="hidden absolute right-0 top-full mt-2 w-72 max-w-[calc(100vw-2rem)] px-3 py-3 rounded-lg shadow-lg text-sm z-50 bg-black/80 text-white" role="dialog" aria-label="共有リンクの仕様" tabindex="-1">
                      <div class="space-y-1 leading-relaxed">
                        <p>24時間有効の閲覧用リンクを発行します。</p>
                        <p>受け取ったユーザーはログイン不要でこの動画を閲覧できます。</p>
                      </div>
                    </div>
                  </div>
                  <div id="manual-copy-area" class="w-full mt-2 hidden">
                    <div class="video-info-container rounded-lg p-3">
                      <div class="text-xs md:text-sm video-meta-info flex items-start gap-2">
                        <div class="flex-1 min-w-0">
                          <span class="font-medium">共有リンク：</span>
                          <span id="manual-copy-url" class="break-all"></span>
                        </div>
                        <button id="manual-copy-copy" type="button" class="px-2 py-1 rounded text-xs video-button-text hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-blue-500 whitespace-nowrap" aria-label="コピー" title="コピー">
                          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <rect x="9" y="9" width="11" height="11" rx="2" ry="2" stroke-width="2"></rect>
                            <rect x="4" y="4" width="11" height="11" rx="2" ry="2" stroke-width="2"></rect>
                          </svg>
                        </button>
                      </div>
                    </div>
                  </div>
                  <?php endif ?>
                </div>
                <?php if ($currentComment): ?>
                  <div class="border-t pt-4"><div class="text-base leading-relaxed video-meta-info whitespace-pre-wrap break-words"><?= htmlspecialchars(trim($currentComment)) ?></div></div>
                <?php endif ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- サイドバー（ランダム動画） -->
      <div class="w-full lg:w-1/3 xl:w-1/3 2xl:w-1/4 3xl:w-1/4 4xl:w-1/5 flex flex-col flex-shrink-0 overflow-y-auto">
        <div class="mobile-sidebar-header flex-shrink-0">
          <h3 class="font-semibold video-title-main my-2 md:my-3 lg:my-4 flex items-center">
            <svg class="w-5 h-5 md:w-6 md:h-6 lg:w-7 lg:h-7 mr-2 md:mr-3 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--accent-color);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
            おすすめ動画
          </h3>
        </div>
        <div class="flex flex-col space-y-3 md:space-y-4">
          <?php if ($isSharedAccess): ?>
            <!-- 共有リンクアクセス時は非表示（未認証ユーザーのみ） -->
            <div class="video-info-container rounded-lg md:rounded-xl lg:rounded-2xl p-4 md:p-6 lg:p-8 text-center">
              <div class="w-12 h-12 md:w-16 md:h-16 lg:w-20 lg:h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4" style="background-color: var(--blue-100);">
                <svg class="w-6 h-6 md:w-8 md:h-8 lg:w-10 lg:h-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--blue-600);">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
              </div>
              <p class="video-meta-info text-sm md:text-base lg:text-lg font-medium text-gray-600 mb-2" style="color: var(--gray-600);">共有リンクでアクセス中</p>
              <p class="video-meta-info text-xs md:text-sm lg:text-base text-gray-500" style="color: var(--gray-500);">おすすめ動画は表示できません</p>
            </div>
          <?php elseif (!empty($randomVideos)): ?>
            <?php foreach ($randomVideos as $video): 
              $basename = pathinfo($video, PATHINFO_FILENAME);
              $thumbPath = "thumbnails/{$basename}.jpg";
              $thumbUrl = file_exists($thumbPath) ? ($thumbPath . '?v=' . filemtime($thumbPath)) : 'images/default-thumbnail-small.svg';
              $metadata = getVideoMetadata($basename);
              $title = $metadata['title'] ?? 'タイトルなし';
              $uploadDate = $metadata['upload_date'] ? date('Y-m-d', strtotime($metadata['upload_date'])) : (file_exists("videos/$video") ? date('Y-m-d', filemtime("videos/$video")) : '');
              $videoViewCount = $metadata['views'] ?? 0;
              $videoLikeCount = $metadata['likes'] ?? 0;
              $videoDuration = $metadata['duration'] ?? null;
              $durationDisplay = formatDuration($videoDuration);
            ?>
              <div class="recommended-video-card rounded-lg md:rounded-xl overflow-hidden transition-transform duration-200 mobile-video-card">
                <a href="?v=<?= urlencode($video) ?><?= $isSharedAccess ? '&share=' . urlencode($sharePassword) : '' ?>" class="block">
                  <div class="flex min-h-0 items-start">
                    <div class="relative w-32 md:w-28 lg:w-32 xl:w-36 2xl:w-40 flex-shrink-0 rounded-lg overflow-hidden aspect-video self-start">
                      <img class="w-full h-full object-cover object-center" src="<?= $thumbUrl ?>" alt="<?= htmlspecialchars($title) ?>" loading="lazy">
                      <?php if (!empty($videoDuration)): ?>
                      <div class="video-duration-badge absolute bottom-1 right-1 text-xs px-1 py-0.5 rounded backdrop-blur-sm"><?= $durationDisplay ?></div>
                      <?php endif ?>
                    </div>
                    <div class="flex-1 px-2 md:px-3 lg:px-4 min-w-0">
                      <h4 class="video-title-sidebar font-bold mb-2 md:mb-3 truncate"><?= htmlspecialchars($title) ?: 'タイトルなし' ?></h4>
                      <div class="flex flex-col space-y-1 md:space-y-2 text-sm md:text-sm lg:text-base video-meta-info">
                        <div class="flex items-center">
                          <svg class="w-4 h-4 md:w-3 md:h-3 lg:w-4 lg:h-4 mr-1 flex-shrink-0 video-meta-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                          <span class="truncate text-sm md:text-sm"><?= $uploadDate ?></span>
                        </div>
                        <div class="flex items-center space-x-2 md:space-x-3 lg:space-x-4">
                          <div class="flex items-center min-w-0">
                            <svg class="w-4 h-4 md:w-3 md:h-3 lg:w-4 lg:h-4 mr-1 flex-shrink-0 video-meta-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <span class="font-medium video-count-info truncate text-sm md:text-sm"><?= number_format($videoViewCount) ?></span>
                          </div>
                          <div class="flex items-center min-w-0">
                            <svg class="w-4 h-4 md:w-3 md:h-3 lg:w-4 lg:h-4 mr-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                            <span class="font-medium video-count-info truncate text-sm md:text-sm"><?= number_format($videoLikeCount) ?></span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </a>
              </div>
            <?php endforeach ?>
          <?php else: ?>
            <div class="video-info-container rounded-lg md:rounded-xl lg:rounded-2xl p-4 md:p-6 lg:p-8 text-center">
              <p class="video-meta-info text-xs md:text-sm lg:text-base">おすすめ動画がありません</p>
            </div>
          <?php endif ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <?php if (empty($videos)): ?>
      <div class="max-w-[1920px] mx-auto p-2 md:p-4 lg:p-6 xl:p-8 px-4 md:px-8 lg:px-12 xl:px-16 2xl:px-20 3xl:px-24">
        <div class="text-center py-8 md:py-16 lg:py-24 animate-fade-in max-w-4xl mx-auto">
          <div class="video-info-container rounded-xl md:rounded-2xl lg:rounded-3xl p-8 md:p-12 lg:p-16 max-w-2xl mx-auto">
            <div class="w-16 h-16 md:w-20 md:h-20 lg:w-24 lg:h-24 empty-video-icon-bg rounded-full flex items-center justify-center mx-auto mb-4 md:mb-6 lg:mb-8">
              <svg class="w-8 h-8 md:w-10 md:h-10 lg:w-12 lg:h-12 video-meta-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
            </div>
            <h2 class="font-bold video-title-main mb-3 md:mb-4 lg:mb-6">まだ動画がありません</h2>
          </div>
        </div>
      </div>
    <?php endif ?>
  <?php endif ?>

  <!-- 動画一覧セクション（ページ下部） -->
  <?php if ($isSharedAccess): ?>
    <!-- 共有リンクアクセス時は非表示（未認証ユーザーのみ） -->
    <div class="w-full max-w-[1920px] mx-auto p-2 md:p-4 lg:p-6 xl:p-8 px-4 md:px-8 lg:px-12 xl:px-16 2xl:px-20 3xl:px-24 <?= $currentVideo ? 'mt-8 lg:mt-12' : 'mt-4 lg:mt-8' ?>">
      <div class="video-info-container rounded-xl md:rounded-2xl lg:rounded-3xl p-8 md:p-12 lg:p-16 max-w-2xl mx-auto text-center">
        <div class="w-16 h-16 md:w-20 md:h-20 lg:w-24 lg:h-24 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4 md:mb-6 lg:mb-8" style="background-color: var(--blue-100);">
                      <svg class="w-8 h-8 md:w-10 md:h-10 lg:w-12 lg:h-12 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--blue-600);">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
          </svg>
        </div>
        <h2 class="font-bold video-title-main mb-3 md:mb-4 lg:mb-6">共有リンクでアクセス中</h2>
        <p class="text-sm md:text-base lg:text-lg video-meta-info text-gray-600 mb-2" style="color: var(--gray-600);">動画一覧は表示できません</p>
        <p class="text-xs md:text-sm lg:text-base video-meta-info text-gray-500" style="color: var(--gray-500);">共有された動画のみ閲覧可能です</p>
      </div>
    </div>
  <?php elseif (!empty($videos)): ?>
  <div class="w-full max-w-[1920px] mx-auto p-2 md:p-4 lg:p-6 xl:p-8 px-4 md:px-8 lg:px-12 xl:px-16 2xl:px-20 3xl:px-24 <?= $currentVideo ? 'mt-8 lg:mt-12' : 'mt-4 lg:mt-8' ?>">
    <div class="mb-6 lg:mb-8 w-full">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div class="flex items-center mb-4 md:mb-0">
          <div class="w-8 h-8 md:w-10 md:h-10 lg:w-12 lg:h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center mr-3 md:mr-4 lg:mr-6" style="background: linear-gradient(to right, var(--blue-600), var(--accent-color));">
            <svg class="w-4 h-4 md:w-5 md:h-5 lg:w-6 lg:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
          </div>
          <div class="flex items-center gap-5">
            <h3 class="mb-0 font-bold video-title-main">動画一覧</h3>
            <div class="text-xs md:text-sm lg:text-base video-meta-info"><?= count($videos) ?>件の動画</div>
          </div>
        </div>
        <div class="flex w-full md:w-auto space-x-4 md:space-x-6 sort-actions">
          <button id="sort-new-btn" class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75 <?= $sort === 'new' ? 'active' : '' ?>" onclick="changeSort('new')">
            <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
            </svg>
            <span class="hidden sm:inline">新しい順</span>
            <span class="sm:hidden">新着</span>
          </button>
          <button id="sort-popular-btn" class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75 <?= $sort === 'popular' ? 'active' : '' ?>" onclick="changeSort('popular')">
            <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
            </svg>
            <span class="hidden sm:inline">人気順</span>
            <span class="sm:hidden">人気</span>
          </button>
          <button id="sort-views-btn" class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75 <?= $sort === 'views' ? 'active' : '' ?>" onclick="changeSort('views')">
            <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            <span class="hidden sm:inline">再生数順</span>
            <span class="sm:hidden">再生</span>
          </button>
          <button id="sort-likes-btn" class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75 <?= $sort === 'likes' ? 'active' : '' ?>" onclick="changeSort('likes')">
            <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
            </svg>
            <span class="hidden sm:inline">いいね数順</span>
            <span class="sm:hidden">いいね</span>
          </button>
        </div>
      </div>
    </div>
    <div id="video-list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4 md:gap-6 video-list-container scroll-optimized"></div>
    <div id="video-list-loading" class="text-center py-4 lg:py-8 video-meta-info text-sm lg:text-base">読み込み中...</div>
  </div>
  <?php endif ?>
  <?php include 'footer.php'; ?>
  </body>
</html>

