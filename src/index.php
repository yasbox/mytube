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

    $all = getSortedVideos($sort, normalizeSearchQuery($_GET['q'] ?? ''));
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

// トップページで選んでいる並べ替え（次の動画もこの順に並べる）
$sort = $_COOKIE['sort_preference'] ?? 'new';
if (!in_array($sort, ['new', 'popular', 'views', 'likes'], true)) {
    $sort = 'new';
}
$sortLabels = ['new' => '新しい順', 'popular' => '人気順', 'views' => '再生数順', 'likes' => 'いいね数順'];

// 動画一覧を取得（構造化データ。公開中のすべてを、選んでいる並べ替えの順で）
$videos = getSortedVideos($sort);

// 検索語（ヘッダーの検索欄から）と、一致した件数
$searchQuery = normalizeSearchQuery($_GET['q'] ?? '');
$resultCount = $searchQuery !== '' ? count(getSortedVideos('new', $searchQuery)) : count($videos);

// ファイル名だけの配列（旧レイアウト互換）
$videoFiles = array_values(array_filter(array_map(function($v){
    return isset($v['filename']) ? (string)$v['filename'] : null;
}, $videos)));


// 再生対象（?v=...）
$currentVideo = isset($_GET['v']) ? basename((string)$_GET['v']) : null;
$requestedVideoMissing = false; // ?v= で指定された動画が無い・見られない（トップページに案内を出す）

// 動画ファイルの存在確認
if ($currentVideo) {
    $videoPath = "videos/{$currentVideo}";
    if (!file_exists($videoPath)) {
        $currentVideo = null;
        $requestedVideoMissing = true;
    }
}

$currentVideoIndex = $currentVideo ? array_search($currentVideo, $videoFiles, true) : false;
// 非公開の動画は一覧に出ないが、管理者は管理画面の再生ボタンから開いて確かめられるようにする
$isPrivatePreview = $currentVideoIndex === false && $currentVideo
    && isUserAuthenticated() && isAdmin() && in_array($currentVideo, getVideoFiles(), true);
if ($currentVideoIndex === false && $currentVideo && !$isPrivatePreview) {
    $currentVideo = null;
    $requestedVideoMissing = true;
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


// 次の動画（サイドバー）: トップページの並び順で、今の動画の次から12本（最後まで行ったら先頭に戻る）
// 自動再生もこの先頭へ進むため、プレイリストのように一覧の順番どおりに見ていける
$nextVideos = [];
if ($currentVideo && count($videoFiles) > 0) {
    $position = array_search($currentVideo, $videoFiles, true);
    $start = $position === false ? 0 : $position + 1; // 非公開の動画（一覧にない）は先頭から
    $total = count($videoFiles);
    for ($i = 0; $i < $total && count($nextVideos) < 12; $i++) {
        $candidate = $videoFiles[($start + $i) % $total];
        if ($candidate !== $currentVideo) {
            $nextVideos[] = $candidate;
        }
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
    $pageTitle = $currentTitle . ' - ' . Config::get('app.name', 'MyTube');
} else {
    $pageTitle = Config::get('app.name', 'MyTube');
}
$pageCss = 'home';
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
  <?php
    $siteName = Config::get('app.name', 'MyTube');
    $squareLogo = is_file(__DIR__ . '/data/branding/logo-square.png') ? 'data/branding/logo-square.png' : 'images/logo.png';
  ?>
  <?php if ($currentVideo): ?>
    <?php
      $currentDurationSeconds = (int)($currentMetadata['duration'] ?? 0);
      $currentUploadDateTime = $currentMetadata['upload_date'] ?? (file_exists("videos/$currentVideo") ? date('Y-m-d H:i:s', filemtime("videos/$currentVideo")) : null);
    ?>
    <main class="watch" data-video="<?= htmlspecialchars($currentVideo) ?>">
      <div class="watch__primary">
        <!-- 動画プレイヤー -->
        <div class="watch-player" id="watch-player">
          <video id="video-player" controls playsinline <?= $autoplayEnabled ? 'autoplay' : '' ?> poster="<?= htmlspecialchars($currentThumbUrl ?: 'images/default-thumbnail.svg') ?>" preload="metadata">
            <source src="videos/<?= urlencode($currentVideo) ?>" type="video/mp4">
            お使いのブラウザは動画の再生に対応していません。
          </video>
        </div>

        <div class="watch-info">
          <?php if ($isSharedAccess): ?>
          <p class="watch-notice">共有リンクで表示しています（24時間有効）。この動画だけを見ることができます。</p>
          <?php endif ?>
          <?php if ($isPrivatePreview): ?>
          <p class="watch-badge">非公開（管理者だけが見られます）</p>
          <?php endif ?>

          <h1 class="watch-title"><?= htmlspecialchars($currentTitle) ?: 'タイトルなし' ?></h1>

          <div class="watch-row">
            <div class="watch-channel">
              <img src="<?= htmlspecialchars($squareLogo) ?>?v=<?= getAssetVersion($squareLogo) ?>" alt="" class="watch-channel__icon">
              <span class="watch-channel__name"><?= htmlspecialchars($siteName) ?></span>
            </div>
            <div class="watch-actions">
              <button id="like-button" type="button" class="pill-btn" onclick="toggleLike('<?= urlencode($currentVideo) ?>')" aria-label="いいね">
                <svg id="like-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                <span id="like-count"><?= number_format($likeCount) ?></span>
              </button>
              <?php if (!$isSharedAccess): ?>
              <button id="share-button" type="button" class="pill-btn" data-video="<?= urlencode($currentVideo) ?>" data-title="<?= htmlspecialchars($currentTitle) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7-7 7M21 12H9a6 6 0 00-6 6v1"/></svg>
                <span>共有</span>
              </button>
              <?php if (isAdmin() && isPasswordProtectionEnabled()): ?>
              <button id="share-link-button" type="button" class="pill-btn" data-video="<?= urlencode($currentVideo) ?>" data-title="<?= htmlspecialchars($currentTitle) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                <span>共有リンク</span>
              </button>
              <button id="share-link-help" type="button" class="icon-btn watch-help" aria-haspopup="dialog" aria-controls="share-link-popover" aria-expanded="false" title="共有リンクとは？" aria-label="共有リンクとは？">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M9.5 9.5a2.5 2.5 0 114 2c-.8.5-1.5 1-1.5 2M12 17h.01"/></svg>
              </button>
              <div id="share-link-popover" class="hidden watch-popover" role="dialog" aria-label="共有リンクの仕様" tabindex="-1">
                <p>24時間有効の閲覧用リンクを発行します。</p>
                <p>受け取った人はログインしなくても、この動画を見ることができます。</p>
              </div>
              <?php endif ?>
              <?php endif ?>
            </div>
          </div>

          <?php if (isAdmin() && isPasswordProtectionEnabled()): ?>
          <div id="manual-copy-area" class="watch-copy hidden">
            <span class="watch-copy__label">共有リンク</span>
            <span id="manual-copy-url" class="watch-copy__url"></span>
            <button id="manual-copy-copy" type="button" class="icon-btn" aria-label="コピー" title="コピー">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/></svg>
            </button>
          </div>
          <?php endif ?>

          <!-- 説明（再生数・投稿日つき。長いときは「もっと見る」で開く） -->
          <div class="watch-desc" id="watch-desc">
            <div class="watch-desc__meta">
              <span><span id="view-count"><?= number_format($viewCount) ?></span>回視聴</span>
              <?php if ($currentUploadDateTime): ?>
              <span title="<?= htmlspecialchars(date('Y年n月j日', strtotime($currentUploadDateTime))) ?>"><?= htmlspecialchars(formatRelativeTime($currentUploadDateTime)) ?></span>
              <?php endif ?>
            </div>
            <?php if (trim((string)$currentComment) !== ''): ?>
            <div class="watch-desc__text" id="watch-desc-text"><?= htmlspecialchars(trim($currentComment)) ?></div>
            <button type="button" class="watch-desc__toggle hidden" id="watch-desc-toggle">もっと見る</button>
            <?php endif ?>
          </div>
        </div>
      </div>

      <?php if (!$isSharedAccess): ?>
      <!-- 次の動画（関連動画） -->
      <aside class="watch__secondary">
        <?php if (!empty($nextVideos)): ?>
        <div class="upnext-head">
          <h2 class="upnext-head__title">次の動画 <span class="upnext-head__order"><?= htmlspecialchars($sortLabels[$sort]) ?></span></h2>
          <label class="switch upnext-switch" title="見終わったら次の動画を自動で再生します">
            <span>自動再生</span>
            <input type="checkbox" id="autoplay-next-toggle">
            <span class="switch__track" aria-hidden="true"></span>
          </label>
        </div>
        <div class="related-list">
          <?php foreach ($nextVideos as $video):
            $basename = pathinfo($video, PATHINFO_FILENAME);
            $thumbPath = "thumbnails/{$basename}.jpg";
            $thumbUrl = file_exists($thumbPath) ? ($thumbPath . '?v=' . filemtime($thumbPath)) : 'images/default-thumbnail-small.svg';
            $metadata = getVideoMetadata($basename);
            $title = $metadata['title'] ?? 'タイトルなし';
            $uploadDateTime = !empty($metadata['upload_date']) ? $metadata['upload_date'] : (file_exists("videos/$video") ? date('Y-m-d H:i:s', filemtime("videos/$video")) : null);
            $videoDuration = $metadata['duration'] ?? null;
          ?>
          <a href="?v=<?= urlencode($video) ?>" class="related-item" data-video="<?= htmlspecialchars($video) ?>">
            <div class="related-item__thumb thumb">
              <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy">
              <?php if (!empty($videoDuration)): ?>
              <span class="duration-badge"><?= formatDuration($videoDuration) ?></span>
              <?php endif ?>
            </div>
            <div class="related-item__body">
              <h3 class="related-item__title" title="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($title) ?: 'タイトルなし' ?></h3>
              <div class="related-item__meta">
                <span><?= number_format((int)($metadata['views'] ?? 0)) ?>回視聴</span>
                <?php if ($uploadDateTime): ?><span><?= htmlspecialchars(formatRelativeTime($uploadDateTime)) ?></span><?php endif ?>
              </div>
            </div>
          </a>
          <?php endforeach ?>
        </div>
        <?php endif ?>
      </aside>
      <?php endif ?>
    </main>

  <?php elseif ($isSharedAccess): ?>
    <main class="home"><p class="list-status">共有リンクでは動画一覧は表示できません。</p></main>

  <?php else: ?>
    <main class="home">
      <?php if ($requestedVideoMissing): ?>
      <p class="home-notice">お探しの動画は見つかりませんでした。削除されたか、非公開になっている可能性があります。</p>
      <?php endif ?>
      <div class="chips-bar" role="toolbar" aria-label="並べ替え">
        <?php foreach (['new' => '新しい順', 'popular' => '人気順', 'views' => '再生数順', 'likes' => 'いいね数順'] as $sortKey => $sortLabel): ?>
        <button type="button" class="chip <?= $sort === $sortKey ? 'active' : '' ?>" data-sort="<?= $sortKey ?>" onclick="changeSort('<?= $sortKey ?>')" aria-pressed="<?= $sort === $sortKey ? 'true' : 'false' ?>"><?= $sortLabel ?></button>
        <?php endforeach ?>
      </div>

      <?php if ($searchQuery !== ''): ?>
      <div class="search-summary">
        <span>「<?= htmlspecialchars($searchQuery) ?>」の検索結果 <?= number_format($resultCount) ?>件</span>
        <a href="index.php">検索をやめる</a>
      </div>
      <?php endif ?>

      <?php if (empty($videos)): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
          <p>まだ動画がありません</p>
        </div>
      <?php elseif ($resultCount === 0): ?>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
          <p>「<?= htmlspecialchars($searchQuery) ?>」に一致する動画はありません</p>
        </div>
      <?php else: ?>
        <div id="video-list" class="video-grid" data-query="<?= htmlspecialchars($searchQuery) ?>"></div>
        <div id="video-list-loading" class="list-status">読み込み中...</div>
      <?php endif ?>
    </main>
  <?php endif ?>
  <?php include 'footer.php'; ?>
  </body>
</html>
