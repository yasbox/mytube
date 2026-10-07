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
$pageTitle = '動画アップロード';
$showUploadButton = false; // 現在のページなので非表示
$showAdminButton = isAdminAuthenticated(); // ログインしている場合のみ表示
$showHomeButton = true; // モバイルメニューにホームリンクを表示
$isAdminPage = false;
// ページCSSを設定（index.cssを読み込むため）
$pageCss = 'index';

// 追加スタイル・スクリプトはCSS/JSへ統合済み
$additionalStyles = $additionalStyles ?? '';
$additionalScripts = $additionalScripts ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<?php 
// 管理者ページフラグを設定（設定ページへのリンク表示用）
$isAdminPage = true;
?>
<?php include 'head.php'; ?>
<body class="min-h-screen flex flex-col">
  <?php include 'header.php'; ?>

  <!-- メインコンテンツ -->
  <div class="main-content flex items-center justify-center p-4 pt-8 md:pt-12 lg:pt-16">
    <div class="w-full max-w-md md:max-w-2xl animate-fade-in">
      <div class="p-4 md:p-6 lg:p-10 animate-slide-up">
        <!-- 動画選択フォーム -->
        <form id="upload-form" class="space-y-6 md:space-y-8" novalidate>
          <?php
            $maxBytes = (int)Config::get('features.upload.max_size_bytes', 0);
            $maxDisplay = $maxBytes > 0 ? formatBytesForDisplay($maxBytes) : '';
          ?>
          <!-- ファイル選択 -->
          <div>
            <div class="file-input-wrapper w-full">
              <input type="file" name="video" accept="video/*,.mp4,.webm,.ogg,.avi,.mov,.mkv,.flv" id="video-input">
              <label id="drop-area" for="video-input" class="file-input-label w-full text-center block text-base md:text-lg py-3 md:py-4">
                <svg class="w-6 h-6 md:w-8 md:h-8 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                動画を選択
              </label>
            </div>
            <div id="file-name" class="mt-2 md:mt-3 text-base md:text-lg video-meta-info hidden"></div>
            <div class="mt-2 md:mt-3 text-sm md:text-base video-meta-info">
                <?php if ($maxBytes > 0): ?>
                <p class="video-meta-info text-sm md:text-base mb-2">最大アップロードサイズ: <strong><?= htmlspecialchars($maxDisplay, ENT_QUOTES, 'UTF-8') ?></strong></p>
                <?php endif; ?>
                <p class="video-meta-info text-sm md:text-base mb-4">
                  対応形式: MP4, WebM, OGG, AVI, MOV, MKV, FLV
                </p>
            </div>
            
            <!-- 動画プレビュー -->
            <div id="video-preview" class="mt-4 md:mt-6 hidden">
              <div class="video-info-container rounded-lg p-4 md:p-6">
                <h3 class="video-meta-info font-medium mb-3 md:mb-4 flex items-center">
                  <svg class="w-6 h-6 md:w-7 md:h-7 mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                  </svg>
                  プレビュー
                </h3>
                <div class="relative">
                  <video id="preview-video" controls class="w-full rounded-lg bg-black" style="max-height: 300px;">
                    お使いのブラウザは動画再生に対応していません。
                  </video>
                  <div id="video-info" class="mt-3 md:mt-4 text-base md:text-lg video-meta-info space-y-2 md:space-y-3">
                    <div class="flex justify-between items-center">
                      <span class="flex-shrink-0 mr-2 md:mr-3">ファイル名:</span>
                      <span id="info-filename" class="video-title-main truncate text-right min-w-0 flex-1"></span>
                    </div>
                    <div class="flex justify-between items-center">
                      <span class="flex-shrink-0 mr-2 md:mr-3">サイズ:</span>
                      <span id="info-size" class="video-title-main text-right"></span>
                    </div>
                    <div class="flex justify-between items-center">
                      <span class="flex-shrink-0 mr-2 md:mr-3">形式:</span>
                      <span id="info-type" class="video-title-main text-right"></span>
                    </div>
                    <div class="flex justify-between items-center">
                      <span class="flex-shrink-0 mr-2 md:mr-3">再生時間:</span>
                      <span id="info-duration" class="video-title-main text-right">読み込み中...</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- タイトル入力 -->
          <div>
            <label class="block video-meta-info text-base md:text-xl font-medium mb-3 md:mb-4">タイトル（任意）</label>
            <input 
              type="text" 
              name="title" 
              autocomplete="new-password"
              id="title-input"
              class="w-full px-4 md:px-5 py-3 md:py-4 upload-input rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 backdrop-blur-sm text-base md:text-lg"
              placeholder="動画のタイトルを入力"
            >
          </div>

          <!-- コメント入力 -->
          <div>
            <label class="block video-meta-info text-base md:text-xl font-medium mb-3 md:mb-4">コメント（任意）</label>
            <textarea 
              name="comment" 
              id="comment-input"
              rows="4"
              class="w-full px-4 md:px-5 py-3 md:py-4 upload-input rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 backdrop-blur-sm resize-none text-base md:text-lg"
              placeholder="動画についてのコメントや説明を入力"
            ></textarea>
          </div>

          <!-- アップロードプログレス -->
          <div id="upload-progress" class="hidden">
            <div class="video-info-container rounded-lg p-4 md:p-6">
              <h3 class="video-meta-info font-medium mb-3 md:mb-4 flex items-center">
                <svg class="w-6 h-6 md:w-7 md:h-7 mr-2 md:mr-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                アップロード中
              </h3>
              
              <!-- プログレスバー -->
              <div class="mb-4">
                <div class="flex justify-between items-center mb-2">
                  <span id="progress-text" class="video-meta-info text-sm md:text-base">準備中...</span>
                  <span id="progress-percentage" class="video-meta-info text-sm md:text-base font-medium">0%</span>
                </div>
                <div class="w-full progress-bg rounded-full h-3 md:h-4">
                  <div id="progress-bar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 md:h-4 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                </div>
              </div>
              
              <!-- 詳細情報 -->
              <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <p class="video-meta-info">アップロード済み</p>
                  <p id="uploaded-size" class="video-title-main font-medium">0 B</p>
                </div>
                <div>
                  <p class="video-meta-info">残り時間</p>
                  <p id="remaining-time" class="video-title-main font-medium">計算中...</p>
                </div>
              </div>
              
              <!-- キャンセルボタン -->
              <div class="mt-4 flex justify-end">
                <button type="button" id="cancel-upload" class="px-4 py-2 bg-red-600 text-white text-sm md:text-base rounded-lg hover:bg-red-700 transition-colors duration-200">
                  キャンセル
                </button>
              </div>
            </div>
          </div>

          <!-- アップロードボタン -->
          <button 
            type="submit" 
            id="upload-button"
            class="w-full upload-button font-semibold py-3 md:py-4 px-6 md:px-8 rounded-lg transform hover:scale-105 transition-all duration-300 shadow-lg hover:shadow-xl text-lg md:text-xl"
          >
            <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
            </svg>
            アップロード
          </button>
          <?php $uploadCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($uploadCsrf, ENT_QUOTES, 'UTF-8') ?>">
        </form>
      </div>
    </div>
  </div>

  <!-- 設定をメタタグで渡す -->
  <meta name="upload-max-bytes" content="<?= (int)Config::get('features.upload.max_size_bytes', 0) ?>">
  <meta name="upload-chunk-bytes" content="<?= (int)Config::get('features.upload.chunk_size_bytes', 2 * 1024 * 1024) ?>">
  <!-- Resumable.jsライブラリとアップロード用スクリプト -->
  <script src="assets/js/resumable.js" defer></script>
  <script src="assets/js/upload.js?v=<?= getAssetVersion('assets/js/upload.js') ?>" defer></script>
<?php include 'footer.php'; ?>
</body>

</html>
