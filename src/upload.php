<?php
require_once __DIR__ . '/init_web.php';

// 表示用にバイトを自動的に KB/MB/GB に変換
if (!function_exists('formatBytesForDisplay')) {
    function formatBytesForDisplay(int $bytes): string {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int)floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));
        $value = $bytes / (1024 ** $i);
        // 1GB以上は小数1桁、1MB以上は小数1桁、1KB以上は小数0-1桁、Bは整数
        if ($i === 0) {
            $formatted = number_format((int)round($value));
        } else {
            $formatted = number_format($value, $value >= 10 ? 0 : 1);
        }
        return $formatted . ' ' . $units[$i];
    }
}

// 管理者認証チェック
requireAdminAuthentication();

// ヘッダー設定
$pageTitle = '動画のアップロード - ' . Config::get('app.name', 'MyTube');
$showUploadButton = false; // 現在のページなので非表示
$showAdminButton = isAdminAuthenticated();
$showHomeButton = true;
$isAdminPage = true;
$pageCss = 'admin';

$maxBytes = (int)Config::get('features.upload.max_size_bytes', 0);
$maxDisplay = $maxBytes > 0 ? formatBytesForDisplay($maxBytes) : '';
?>
<!DOCTYPE html>
<html lang="ja">
<?php include 'head.php'; ?>
<body class="min-h-screen flex flex-col">
  <?php include 'header.php'; ?>

  <main class="studio">
    <?php $adminTab = 'upload'; $adminTitle = '動画のアップロード'; include 'admin_nav.php'; ?>

    <div class="upload-card">
      <form id="upload-form" novalidate>
        <!-- ファイルの選択（ドラッグ＆ドロップにも対応） -->
        <label id="drop-area" for="video-input" class="dropzone">
          <input type="file" name="video" accept="video/*,.mp4,.webm,.ogg,.avi,.mov,.mkv,.flv" id="video-input">
          <span class="dropzone__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
          </span>
          <span class="dropzone__title">動画ファイルをドラッグ＆ドロップ</span>
          <span class="btn btn--primary">ファイルを選択</span>
          <span class="dropzone__help">
            対応形式: MP4, WebM, OGG, AVI, MOV, MKV, FLV<?php if ($maxDisplay !== ''): ?><br>最大 <?= htmlspecialchars($maxDisplay, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </span>
        </label>

        <!-- 選んだ動画のプレビュー -->
        <div id="video-preview" class="upload-file hidden" style="margin-top: 16px;">
          <video id="preview-video" controls muted playsinline></video>
          <div class="upload-file__info">
            <div id="info-filename" class="upload-file__name"></div>
            <div><span>サイズ</span><b id="info-size"></b></div>
            <div><span>形式</span><b id="info-type"></b></div>
            <div><span>長さ</span><b id="info-duration">読み込み中...</b></div>
          </div>
        </div>
        <div id="file-name" class="hidden"></div>

        <div class="upload-form">
          <div class="field">
            <label class="field__label" for="title-input">タイトル（任意）</label>
            <input type="text" name="title" id="title-input" class="input" placeholder="動画のタイトル" autocomplete="off" maxlength="200">
          </div>
          <div class="field">
            <label class="field__label" for="comment-input">説明（任意）</label>
            <textarea name="comment" id="comment-input" class="textarea" rows="4" placeholder="動画の説明"></textarea>
          </div>

          <!-- アップロードの進み具合 -->
          <div id="upload-progress" class="upload-progress hidden">
            <div class="upload-progress__row">
              <span id="progress-text">準備中...</span>
              <b id="progress-percentage">0%</b>
            </div>
            <div class="progress"><div id="progress-bar" class="progress__bar"></div></div>
            <div class="upload-progress__details">
              <div><span>送信済み</span><b id="uploaded-size">0 B</b></div>
              <div><span>残り時間</span><b id="remaining-time">計算中...</b></div>
            </div>
            <div class="upload-progress__foot">
              <button type="button" id="cancel-upload" class="btn">キャンセル</button>
            </div>
          </div>

          <div>
            <button type="submit" id="upload-button" class="btn btn--primary btn--large">アップロード</button>
          </div>
          <?php $uploadCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($uploadCsrf, ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </form>
    </div>
  </main>

  <!-- 設定をメタタグで渡す -->
  <meta name="upload-max-bytes" content="<?= $maxBytes ?>">
  <meta name="upload-chunk-bytes" content="<?= (int)Config::get('features.upload.chunk_size_bytes', 2 * 1024 * 1024) ?>">
  <script src="assets/js/resumable.js" defer></script>
  <script src="assets/js/upload.js?v=<?= getAssetVersion('assets/js/upload.js') ?>" defer></script>
<?php include 'footer.php'; ?>
</body>

</html>
