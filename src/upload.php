<?php
require_once __DIR__ . '/init_web.php';
require_once __DIR__ . '/video_converter.php';

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

// 新しいアップロードセッションの開始（GETリクエストで新しいページアクセス時）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['converting']) && !isset($_GET['conversion_status'])) {
    // 古い変換状態をクリア
    if (isset($_SESSION['conversion_status'])) {
        unset($_SESSION['conversion_status']);
    }
    if (isset($_SESSION['conversion_cleanup_time'])) {
        unset($_SESSION['conversion_cleanup_time']);
    }
}

// 変換進捗取得API
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['conversion_status'])) {
    header('Content-Type: application/json');
    
    // 古い変換状態をクリア（5分以上経過している場合）
    if (isset($_SESSION['conversion_cleanup_time']) && time() > $_SESSION['conversion_cleanup_time']) {
        unset($_SESSION['conversion_status']);
        unset($_SESSION['conversion_cleanup_time']);
        echo json_encode(['status' => 'expired']);
        exit;
    }
    
    // 完了済みの変換状態をクリア（1分後に自動削除）
    if (isset($_SESSION['conversion_status']) && $_SESSION['conversion_status']['status'] === 'completed') {
        if (!isset($_SESSION['conversion_cleanup_time'])) {
            $_SESSION['conversion_cleanup_time'] = time() + 60; // 1分後にクリア
        }
    }
    
    if (isset($_SESSION['conversion_status'])) {
        echo json_encode($_SESSION['conversion_status']);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    // CSRF検証
    if (function_exists('verifyCSRFToken')) {
        $token = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($token)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'CSRF検証に失敗しました']);
            exit;
        }
    }
    $file = $_FILES['video'];
    $title = trim($_POST['title'] ?? '');
    $comment = trim($_POST['comment'] ?? '');
    // MIME厳格判定
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $allowedFormats = Config::get('features.upload.allowed_formats', ['mp4','webm','ogg','avi','mov','mkv','flv']);
    $allowed = array_map(fn($f) => Config::get("video.mime_types.$f", "video/$f"), $allowedFormats);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['mp4','webm','ogg','avi','mov','mkv','flv'];
    if ($file['error'] === 0 && in_array($mime, $allowed, true) && in_array($ext, $allowedExt, true)) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $basename = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $name = "{$basename}.{$ext}";
        $path = "videos/{$name}";
        move_uploaded_file($file['tmp_name'], $path);

        // メタデータを保存
        $metadata = [
            'title' => $title,
            'comment' => $comment,
            'upload_date' => date('Y-m-d H:i:s'),
            'views' => 0,
            'likes' => 0
        ];
        saveVideoMetadata($basename, $metadata);
        
        // サムネイルを生成（すべての動画ファイル）
        $thumbnailPath = "thumbnails/{$basename}.jpg";
        if (!file_exists($thumbnailPath)) {
            // thumbnailsディレクトリが存在しない場合は作成
            if (!is_dir('thumbnails')) {
                mkdir('thumbnails', 0755, true);
            }
            
            // FFmpegコマンドを実行（長辺1000px／アップスケールなし）
            $ffmpeg = (string)Config::get('storage.ffmpeg_path', '/usr/bin/ffmpeg');
            $vf = "scale='if(gte(iw,ih),min(iw,1000),-2)':'if(gte(iw,ih),-2,min(ih,1000))'";
            $ffmpegCmd = $ffmpeg . " -i " . escapeshellarg($path) . " -ss 00:00:01 -vframes 1 -vf " . escapeshellarg($vf) . " -y " . escapeshellarg($thumbnailPath) . " 2>&1";
            $output = shell_exec($ffmpegCmd);
            
            if (file_exists($thumbnailPath)) {
                // サムネイル生成成功
            } else {
                // サムネイル生成失敗
            }
        } else {
            // サムネイルは既に存在
        }
        
        // アップロード完了画面にリダイレクト
        header('Location: upload.php?uploaded=1');
        exit;
    }
}

// 変換機能は管理パネルから実行するため、ここでは変換処理を行わない

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

        <!-- 成功メッセージ -->
        <?php if (isset($_GET['uploaded'])): ?>
          <div id="success-message" class="mt-6 md:mt-8 p-4 md:p-6 video-info-container rounded-lg animate-fade-in">
            <div class="flex items-center">
              <svg class="w-6 h-6 md:w-7 md:h-7 text-green-400 mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
              </svg>
              <div>
                <h3 class="video-title-main font-medium">アップロード完了</h3>
                <p class="video-meta-info text-base md:text-lg">動画が正常にアップロードされました。</p>
              </div>
            </div>
            <div class="mt-4 md:mt-6 flex flex-wrap gap-3">
              <button onclick="continueUpload()" class="px-4 py-2 bg-green-600 text-white text-sm md:text-base rounded-lg hover:bg-green-700 transition-colors duration-200 flex items-center">
                <svg class="w-4 h-4 md:w-5 md:h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                続けてアップロードする
              </button>
              <a href="admin.php" class="px-4 py-2 bg-blue-600 text-white text-sm md:text-base rounded-lg hover:bg-blue-700 transition-colors duration-200">
                管理パネルへ
              </a>
              <a href="index.php" class="px-4 py-2 bg-gray-600 text-white text-sm md:text-base rounded-lg hover:bg-gray-700 transition-colors duration-200">
                トップページへ
              </a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 設定をメタタグで渡す -->
  <meta name="upload-max-bytes" content="<?= (int)Config::get('features.upload.max_size_bytes', 0) ?>">
  <meta name="upload-chunk-bytes" content="<?= (int)Config::get('features.upload.chunk_size_bytes', 2 * 1024 * 1024) ?>">
  <!-- Resumable.jsライブラリとアップロード用スクリプト -->
  <script src="assets/js/resumable.js" defer></script>
  <?php $uploadJsPath = __DIR__ . '/assets/js/upload.js'; $uploadJsVersion = file_exists($uploadJsPath) ? filemtime($uploadJsPath) : '1.0.0'; ?>
  <script src="assets/js/upload.js?v=<?= $uploadJsVersion ?>" defer></script>
  
  <!-- 旧インラインJS削除済み（実行無効化） -->
  <script type="application/json" id="upload-inline-removed">
    // グローバル変数
    let resumable;
    let uploadStartTime;
    let uploadSpeed = 0;
    let lastUploadedBytes = 0;
    let lastTime = 0;
    
    // DOM要素の取得
    const videoInput = document.getElementById('video-input');
    const fileName = document.getElementById('file-name');
    const videoPreview = document.getElementById('video-preview');
    const previewVideo = document.getElementById('preview-video');
    const infoFilename = document.getElementById('info-filename');
    const infoSize = document.getElementById('info-size');
    const infoType = document.getElementById('info-type');
    const infoDuration = document.getElementById('info-duration');
    const uploadForm = document.getElementById('upload-form');
    const uploadButton = document.getElementById('upload-button');
    const uploadProgress = document.getElementById('upload-progress');
    const progressText = document.getElementById('progress-text');
    const progressPercentage = document.getElementById('progress-percentage');
    const progressBar = document.getElementById('progress-bar');
    const uploadedSize = document.getElementById('uploaded-size');
    const remainingTime = document.getElementById('remaining-time');
    const cancelUpload = document.getElementById('cancel-upload');
    // サーバ設定から最大アップロードサイズ（バイト）
    const maxUploadBytes = <?= (int)Config::get('features.upload.max_size_bytes', 0) ?>;
    
    // ファイル選択時の処理
    videoInput.addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        // 事前サイズチェック（超過時は即時に弾く）
        if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
          alert('選択されたファイルは最大アップロードサイズを超えています。\n最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size));
          e.target.value = '';
          fileName.classList.add('hidden');
          videoPreview.classList.add('hidden');
          return;
        }
        // ファイル名を表示
        fileName.textContent = file.name;
        fileName.classList.remove('hidden');
        
        // プレビューを表示
        showVideoPreview(file);
        
        // サイズは事前チェック済み
      } else {
        fileName.classList.add('hidden');
        videoPreview.classList.add('hidden');
      }
    });
    
    // 動画プレビューを表示
    function showVideoPreview(file) {
      // ファイル情報を設定
      infoFilename.textContent = file.name;
      infoSize.textContent = formatFileSize(file.size);
      infoType.textContent = file.type || '不明';
      
      // 動画URLを作成
      const videoURL = URL.createObjectURL(file);
      previewVideo.src = videoURL;
      
      // 再生時間を取得
      previewVideo.addEventListener('loadedmetadata', function() {
        const duration = Math.floor(previewVideo.duration);
        const minutes = Math.floor(duration / 60);
        const seconds = duration % 60;
        infoDuration.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
      });
      
      videoPreview.classList.remove('hidden');
    }
    
    // ファイルサイズのフォーマット
    function formatFileSize(bytes) {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // フォーム送信時の処理
    uploadForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const file = videoInput.files[0];
      if (!file) {
        alert('ファイルを選択してください');
        return;
      }
      
      // アップロード開始
      startUpload(file);
    });
    
      // アップロード開始
    function startUpload(file) {
      // 開始直前の二重チェック
      if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
        showErrorMessage('ファイルサイズが上限を超えています（最大 ' + formatFileSize(maxUploadBytes) + '）');
        return;
      }
      
      // タイトルとコメントの入力フォームを非表示にする
      const titleInput = document.getElementById('title-input');
      const commentInput = document.getElementById('comment-input');
      const titleLabel = titleInput.previousElementSibling;
      const commentLabel = commentInput.previousElementSibling;
      
      if (titleInput && titleLabel) {
        titleInput.parentElement.style.display = 'none';
      }
      if (commentInput && commentLabel) {
        commentInput.parentElement.style.display = 'none';
      }
      
      // UIを更新
      uploadButton.disabled = true;
      uploadButton.innerHTML = `
        <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        アップロード中...
      `;
      uploadProgress.classList.remove('hidden');
      
          // Resumable.jsを初期化
       const chunkSizeBytes = <?= (int)Config::get('features.upload.chunk_size_bytes', 2 * 1024 * 1024) ?>;
               resumable = new Resumable({
          target: 'upload_api.php',
          chunkSize: chunkSizeBytes,
          simultaneousUploads: 3,
          testChunks: true,
          throttleProgressCallbacks: 1,
          method: 'multipart'
        });

      // メタデータを設定（addFileより前に！）
      const title = document.getElementById('title-input').value.trim();
      const comment = document.getElementById('comment-input').value.trim();
      // CSRFトークンを含める
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const csrfToken = csrfMeta ? csrfMeta.content : '';
      resumable.opts.query = {
        title: title,
        comment: comment,
        csrf_token: csrfToken
      };

      // イベントリスナー
      resumable.on('fileAdded', function(file) {
        resumable.upload(); // ←ここでアップロード開始
      });
      resumable.on('fileProgress', function(file) {
        updateProgress(file);
      });
                           resumable.on('fileSuccess', function(file, response) {
         try {
           const result = JSON.parse(response);
           if (result.success) {
             showSuccessMessage(result.video_id);
           } else {
             showErrorMessage('アップロードに失敗しました');
           }
         } catch (e) {
           showErrorMessage('アップロードに失敗しました');
         }
       });
             resumable.on('fileError', function(file, message) {
         showErrorMessage('アップロードに失敗しました');
       });
       resumable.on('complete', function() {
         // 完了時の処理
       });
       resumable.on('error', function(message, file) {
         showErrorMessage('アップロードに失敗しました');
       });
      
      // キャンセルイベントリスナーを追加
      resumable.on('cancel', function() {
        // キャンセル時の処理は別途実装
      });

      resumable.addFile(file); // ←最後にaddFile

      // アップロード開始
      uploadStartTime = Date.now();
      lastUploadedBytes = 0;
      lastTime = uploadStartTime;
      
      // 初期表示を設定
      progressText.textContent = 'アップロードを開始しています...';
      progressPercentage.textContent = '0%';
      progressBar.style.width = '0%';
      uploadedSize.textContent = '0 B / ' + formatFileSize(file.size);
      remainingTime.textContent = '計算中...';
      
      resumable.upload();
    }
    
    // プログレス更新
    function updateProgress(file) {
      const progress = file.progress() * 100;
      const uploadedBytes = file.size * file.progress();
      
      // プログレスバーを更新
      progressBar.style.width = progress + '%';
      progressPercentage.textContent = Math.round(progress) + '%';
      
      // ファイルサイズの表示を全体の値に変更（更新頻度を制限）
      if (Math.round(progress) % 5 === 0 || progress === 100) {
        uploadedSize.textContent = formatFileSize(uploadedBytes) + ' / ' + formatFileSize(file.size);
      }
      
      // 速度と残り時間を計算（更新頻度を制限）
      const currentTime = Date.now();
      const timeDiff = (currentTime - lastTime) / 1000; // 秒
      
      if (timeDiff > 0.5) { // 0.5秒ごとに更新
        const bytesDiff = uploadedBytes - lastUploadedBytes;
        uploadSpeed = bytesDiff / timeDiff; // バイト/秒
        
        const remainingBytes = file.size - uploadedBytes;
        const remainingSeconds = remainingBytes / uploadSpeed;
        
        if (remainingSeconds > 0 && remainingSeconds < Infinity && uploadSpeed > 0) {
          const minutes = Math.floor(remainingSeconds / 60);
          const seconds = Math.floor(remainingSeconds % 60);
          remainingTime.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        } else {
          remainingTime.textContent = '計算中...';
        }
        
        lastUploadedBytes = uploadedBytes;
        lastTime = currentTime;
      }
      
      // プログレステキストを更新
      if (progress < 100) {
        progressText.textContent = `アップロード中... ${Math.round(progress)}%`;
      } else {
        progressText.textContent = '完了処理中...';
      }
    }
    
                   // 成功メッセージを表示
      function showSuccessMessage(videoId) {
        uploadProgress.classList.add('hidden');
        // アップロードボタンを非表示にする
        uploadButton.style.display = 'none';
        
        // video_idが渡された場合は動画再生ページへのリンクを生成
        let videoPlayUrl = 'index.php'; // デフォルトのURL
        if (videoId) {
          videoPlayUrl = `index.php?v=${videoId}`;
        }
        
        // 成功メッセージを表示
        const successMessage = document.createElement('div');
        successMessage.className = 'mt-6 md:mt-8 p-4 md:p-6 video-info-container rounded-lg animate-fade-in';
        successMessage.innerHTML = `
          <div class="flex items-center">
            <svg class="w-6 h-6 md:w-7 md:h-7 text-green-400 mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <div>
              <h3 class="video-title-main font-medium">アップロード完了</h3>
              <p class="video-meta-info text-base md:text-lg">動画が正常にアップロードされました。</p>
            </div>
          </div>
                                        <div class="mt-4 md:mt-6">
                        <!-- モバイル時は縦並び、デスクトップ時は横並び -->
                        <div class="flex flex-col md:flex-row gap-3 md:gap-4">
                          <!-- 動画を再生ボタン -->
                          <a href="${videoPlayUrl}" class="w-full md:w-auto px-4 py-3 md:py-2 bg-gradient-to-r from-indigo-500 to-indigo-600 text-white text-base md:text-sm rounded-lg hover:from-indigo-600 hover:to-indigo-700 transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <svg class="w-5 h-5 md:w-4 md:h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            動画を再生
                          </a>
                          
                          <!-- 管理パネルへボタン -->
                          <a href="admin.php" class="w-full md:w-auto px-4 py-3 md:py-2 bg-gradient-to-r from-slate-600 to-slate-700 text-white text-base md:text-sm rounded-lg hover:from-slate-700 hover:to-slate-800 transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <svg class="w-5 h-5 md:w-4 md:h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            管理パネルへ
                          </a>
                          
                          <!-- 続けてアップロードするボタン（PC時は同じ行に配置） -->
                          <button onclick="continueUpload()" class="w-full md:w-auto px-4 py-3 md:py-2 bg-gradient-to-r from-emerald-500 to-emerald-600 text-white text-base md:text-sm rounded-lg hover:from-emerald-600 hover:to-emerald-700 transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                            <svg class="w-5 h-5 md:w-4 md:h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            続けてアップロードする
                          </button>
                        </div>
                      </div>
        `;
        
        uploadForm.parentNode.insertBefore(successMessage, uploadForm.nextSibling);
      }
    
         // エラーメッセージを表示
     function showErrorMessage(message) {
       uploadProgress.classList.add('hidden');
       
       // タイトルとコメントの入力フォームを再表示する
       const titleInput = document.getElementById('title-input');
       const commentInput = document.getElementById('comment-input');
       
       if (titleInput) {
         titleInput.parentElement.style.display = 'block';
       }
       if (commentInput) {
         commentInput.parentElement.style.display = 'block';
       }
       
       uploadButton.disabled = false;
       uploadButton.innerHTML = `
         <svg class="w-6 h-6 md:w-7 md:w-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
           <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
         </svg>
         アップロード失敗
       `;
       uploadButton.classList.remove('upload-button', 'from-green-600', 'to-green-700');
       uploadButton.classList.add('upload-button', 'from-red-600', 'to-red-700');
       

     }
    
    // キャンセルボタンの処理
    cancelUpload.addEventListener('click', function() {
      if (resumable && confirm('アップロードをキャンセルしますか？')) {
        // 現在のresumableIdentifierを取得（修正版）
        let currentIdentifier = null;
        if (resumable.files && resumable.files.length > 0) {
          currentIdentifier = resumable.files[0].uniqueIdentifier;
        }
        
        // Resumable.jsのキャンセル
        resumable.cancel();
        
        // サーバー側のtempファイルを削除
        if (currentIdentifier) {
          const bodyStr = `action=cancel_upload&resumableIdentifier=${encodeURIComponent(currentIdentifier)}`;
          
                  fetch('upload_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: bodyStr
        })
          .then(response => response.json())
          .then(data => {
            // 成功時は何もしない（エラー時のみコンソールに出力）
          })
          .catch(error => {
            // 一時ファイルのクリーンアップに失敗
          });
        }
        
        // UIをリセット
        uploadProgress.classList.add('hidden');
        
        // タイトルとコメントの入力フォームを再表示する
        const titleInput = document.getElementById('title-input');
        const commentInput = document.getElementById('comment-input');
        
        if (titleInput) {
          titleInput.parentElement.style.display = 'block';
        }
        if (commentInput) {
          commentInput.parentElement.style.display = 'block';
        }
        
        uploadButton.disabled = false;
        uploadButton.innerHTML = `
          <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
          </svg>
          アップロード
        `;
      }
    });

    // 続けてアップロードする関数
    function continueUpload() {
      // 成功メッセージを削除（アップロード完了メッセージと3つのボタンすべて）
      const successMessage = document.getElementById('success-message');
      if (successMessage) {
        successMessage.remove();
      }
      
      // 動的に作成された成功メッセージも削除
      const dynamicSuccessMessages = document.querySelectorAll('.video-info-container.animate-fade-in');
      dynamicSuccessMessages.forEach(message => {
        if (message.querySelector('h3') && message.querySelector('h3').textContent.includes('アップロード完了')) {
          message.remove();
        }
      });
      
      // フォームをリセット
      uploadForm.reset();
      
      // タイトルとコメントの入力フォームを再表示する
      const titleInput = document.getElementById('title-input');
      const commentInput = document.getElementById('comment-input');
      
      if (titleInput) {
        titleInput.parentElement.style.display = 'block';
      }
      if (commentInput) {
        commentInput.parentElement.style.display = 'block';
      }
      
      // ファイル名とプレビューをクリア
      fileName.classList.add('hidden');
      videoPreview.classList.add('hidden');
      
      // アップロードボタンを元の状態に戻す
      uploadButton.style.display = 'block';
      uploadButton.disabled = false;
      uploadButton.innerHTML = `
        <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
        </svg>
        アップロード
      `;
      uploadButton.classList.remove('upload-button', 'from-green-600', 'to-green-700', 'from-red-600', 'to-red-700');
      uploadButton.classList.add('upload-button');
      
      // プログレスバーをリセット
      uploadProgress.classList.add('hidden');
      progressBar.style.width = '0%';
      progressPercentage.textContent = '0%';
      progressText.textContent = '準備中...';
      uploadedSize.textContent = '0 B';
      remainingTime.textContent = '計算中...';
      
      // ページの先頭にスクロール
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  </script>
<?php include 'footer.php'; ?>
</body>

</html>
