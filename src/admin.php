<?php
require_once __DIR__ . '/init_web.php';

// 管理者権限チェック
requireAdminAuthentication();

// APIリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    include 'admin_api.php';
    exit;
}

// デバッグ出力は無効化
?>
<!DOCTYPE html>
<html lang="ja">
<?php
$pageTitle = '動画の管理 - ' . Config::get('app.name', 'MyTube');
$pageCss = 'admin'; // admin.cssを読み込む

$adminJsVersion = getAssetVersion('assets/js/admin.js');
$additionalScripts = '<script src="assets/js/admin.js?v=' . $adminJsVersion . '" defer></script>';
include 'head.php';
?>

<body class="min-h-screen flex flex-col">
    <?php
    $showUploadButton = true;
    $showAdminButton = false; // 現在のページなので非表示
    $showHomeButton = true;
    $isAdminPage = true;
    include 'header.php';
    ?>

    <main class="studio">
        <?php $adminTab = 'videos'; $adminTitle = '動画の管理'; include 'admin_nav.php'; ?>

        <!-- 統計 -->
        <section class="studio-section">
            <div class="stat-grid">
                <div class="stat"><div class="stat__label">動画</div><div class="stat__value" id="total-videos">-</div></div>
                <div class="stat"><div class="stat__label">総再生数</div><div class="stat__value" id="total-views">-</div></div>
                <div class="stat"><div class="stat__label">総いいね数</div><div class="stat__value" id="total-likes">-</div></div>
                <div class="stat"><div class="stat__label">容量</div><div class="stat__value" id="total-size">-</div></div>
                <div class="stat"><div class="stat__label">平均再生数</div><div class="stat__value" id="average-views">-</div></div>
                <div class="stat"><div class="stat__label">平均いいね数</div><div class="stat__value" id="average-likes">-</div></div>
            </div>
        </section>

        <!-- 共有中のリンク（使えるリンクがあるときだけ表示） -->
        <section class="studio-section hidden" id="share-links" aria-labelledby="share-links-title">
            <div class="studio-section__head">
                <h2 class="studio-section__title" id="share-links-title">共有中のリンク</h2>
            </div>
            <p class="share-links__note">ログインしなくても見られるリンクです。期限が来ると自動で使えなくなります。</p>
            <div class="share-links" id="share-links-list"></div>
        </section>

        <!-- 動画の一覧 -->
        <section class="studio-section">
            <div class="studio-section__head">
                <h2 class="studio-section__title">動画</h2>
                <div class="chips" role="toolbar" aria-label="並べ替え">
                    <?php foreach (['new' => '新しい順', 'popular' => '人気順', 'views' => '再生数順', 'likes' => 'いいね数順'] as $sortKey => $sortLabel): ?>
                    <button type="button" class="chip <?= $sortKey === 'new' ? 'active' : '' ?>" data-sort="<?= $sortKey ?>" onclick="changeSort('<?= $sortKey ?>')" aria-pressed="<?= $sortKey === 'new' ? 'true' : 'false' ?>"><?= $sortLabel ?></button>
                    <?php endforeach ?>
                </div>
            </div>

            <div class="vlist">
                <div class="vrow vrow--head" aria-hidden="true">
                    <div>動画</div>
                    <div>公開状態</div>
                    <div>投稿日</div>
                    <div class="vrow__num">再生数</div>
                    <div class="vrow__num">いいね</div>
                    <div></div>
                </div>
                <div id="video-table-body"></div>
            </div>
            <div id="initial-loading" class="list-status">動画を読み込んでいます...</div>
            <div id="infinite-loading" class="list-status hidden">読み込み中...</div>
            <div id="all-loaded-message" class="list-status hidden">すべての動画を表示しました</div>
        </section>
    </main>

    <!-- 変換の進み具合 -->
    <div id="conversion-progress-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="conversion-modal-title">
        <div class="modal__panel">
            <div class="modal__head">
                <h2 class="modal__title" id="conversion-modal-title">動画を変換しています</h2>
                <button type="button" id="close-conversion-modal" class="icon-btn" onclick="closeConversionModal()" aria-label="閉じる">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal__body">
                <div class="conv-video">
                    <img id="conversion-thumbnail" src="images/default-thumbnail-small.svg" alt="">
                    <div class="min-w-0">
                        <div id="conversion-title" class="conv-video__title">動画タイトル</div>
                        <div id="conversion-filename" class="conv-video__file">ファイル名</div>
                    </div>
                </div>
                <div>
                    <div class="conv-status">
                        <span id="conversion-status">変換を開始しています...</span>
                        <span id="conversion-percentage">0%</span>
                    </div>
                    <div class="progress"><div id="conversion-progress-bar" class="progress__bar"></div></div>
                </div>
                <div class="conv-details">
                    <div><span>元の形式</span><b id="conversion-format">-</b></div>
                    <div><span>経過時間</span><b id="conversion-time">-</b></div>
                </div>
            </div>
            <div class="modal__foot">
                <button type="button" id="cancel-conversion-btn" class="btn" onclick="cancelConversion()">変換を止める</button>
            </div>
        </div>
    </div>
<?php include 'footer.php'; ?>
</body>

</html>
