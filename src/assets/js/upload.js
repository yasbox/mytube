// 動画のアップロードページ（upload.php）の動き。Resumable.js で分割して送信する
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    // Utility to read meta by name
    function readMeta(name) {
      var el = document.querySelector('meta[name="' + name + '"]');
      return el ? el.getAttribute('content') : null;
    }

    // Configs from meta
    var maxUploadBytesStr = readMeta('upload-max-bytes');
    var chunkSizeBytesStr = readMeta('upload-chunk-bytes');
    var maxUploadBytes = maxUploadBytesStr ? parseInt(maxUploadBytesStr, 10) : 0;
    var chunkSizeBytes = chunkSizeBytesStr ? parseInt(chunkSizeBytesStr, 10) : (2 * 1024 * 1024);

    // Globals
    var resumable;
    var uploadStartTime;
    var uploadSpeed = 0;
    var lastUploadedBytes = 0;
    var lastTime = 0;

    // DOM elements
    var videoInput = document.getElementById('video-input');
    var fileName = document.getElementById('file-name');
    var videoPreview = document.getElementById('video-preview');
    var previewVideo = document.getElementById('preview-video');
    var infoFilename = document.getElementById('info-filename');
    var infoSize = document.getElementById('info-size');
    var infoType = document.getElementById('info-type');
    var infoDuration = document.getElementById('info-duration');
    var uploadForm = document.getElementById('upload-form');
    var uploadButton = document.getElementById('upload-button');
    var uploadProgress = document.getElementById('upload-progress');
    var progressText = document.getElementById('progress-text');
    var progressPercentage = document.getElementById('progress-percentage');
    var progressBar = document.getElementById('progress-bar');
    var uploadedSize = document.getElementById('uploaded-size');
    var remainingTime = document.getElementById('remaining-time');
    var cancelUpload = document.getElementById('cancel-upload');
    var dropArea = document.getElementById('drop-area');
    var pageDropOverlay = null;
    var dragCounter = 0;

    if (!videoInput || !uploadForm) {
      return;
    }

    var droppedFile = null;

    // Events
    // Drag & Drop support (use only the first file)
    // Prevent default browser behavior when dropping files outside target
    ['dragover', 'drop'].forEach(function(evtName){
      document.addEventListener(evtName, function(e){
        var dt = e.dataTransfer;
        if (dt && dt.types && (dt.types.indexOf ? dt.types.indexOf('Files') !== -1 : dt.types.contains && dt.types.contains('Files'))) {
          e.preventDefault();
        }
      });
    });

    function hasFilesInDataTransfer(e){
      var dt = e && e.dataTransfer;
      if (!dt || !dt.types) return false;
      if (typeof dt.types.indexOf === 'function') return dt.types.indexOf('Files') !== -1;
      if (typeof dt.types.contains === 'function') return dt.types.contains('Files');
      return false;
    }

    function ensurePageOverlay(){
      if (pageDropOverlay) return pageDropOverlay;
      pageDropOverlay = document.createElement('div');
      pageDropOverlay.id = 'page-drop-overlay';
      pageDropOverlay.className = 'hidden';
      pageDropOverlay.innerHTML = '\n        <div class="page-drop-overlay__inner">\n          <div class="page-drop-overlay__icon" aria-hidden="true">⬆</div>\n          <div class="page-drop-overlay__text">ここに動画をドロップ</div>\n          <div class="page-drop-overlay__sub">複数選択された場合は先頭の1ファイルのみアップロードします</div>\n        </div>\n      ';
      document.body.appendChild(pageDropOverlay);
      return pageDropOverlay;
    }

    function showPageOverlay(){
      ensurePageOverlay();
      pageDropOverlay.classList.remove('hidden');
    }

    function hidePageOverlay(){
      if (pageDropOverlay) pageDropOverlay.classList.add('hidden');
    }

    // Document-level handlers for full-page drop
    document.addEventListener('dragenter', function(e){
      if (!hasFilesInDataTransfer(e)) return;
      dragCounter++;
      showPageOverlay();
    });
    document.addEventListener('dragleave', function(e){
      if (!hasFilesInDataTransfer(e)) return;
      dragCounter = Math.max(0, dragCounter - 1);
      if (dragCounter === 0) hidePageOverlay();
    });
    document.addEventListener('dragover', function(e){
      if (!hasFilesInDataTransfer(e)) return;
      e.preventDefault();
      showPageOverlay();
    });
    document.addEventListener('drop', function(e){
      if (!hasFilesInDataTransfer(e)) return;
      e.preventDefault();
      dragCounter = 0;
      hidePageOverlay();
      var files = (e.dataTransfer && e.dataTransfer.files) ? e.dataTransfer.files : null;
      if (!files || files.length === 0) return;
      var file = files[0];
      if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
        if (typeof window.showNotification === 'function') {
          window.showNotification('選択されたファイルは最大アップロードサイズを超えています。最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size), 'error');
        } else {
          alert('選択されたファイルは最大アップロードサイズを超えています。\n最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size));
        }
        return;
      }
      try {
        var dt = new DataTransfer();
        dt.items.add(file);
        videoInput.files = dt.files;
        var changeEvent = new Event('change');
        videoInput.dispatchEvent(changeEvent);
      } catch (_) {
        droppedFile = file;
        if (fileName) {
          fileName.textContent = file.name;
          fileName.classList.remove('hidden');
        }
        showVideoPreview(file);
      }
    });

    if (dropArea) {
      ['dragenter', 'dragover'].forEach(function(evtName){
        dropArea.addEventListener(evtName, function(e){
          e.preventDefault();
          e.stopPropagation();
          dropArea.classList.add('drag-over');
        });
      });
      ['dragleave', 'dragend', 'drop'].forEach(function(evtName){
        dropArea.addEventListener(evtName, function(e){
          e.preventDefault();
          e.stopPropagation();
          dropArea.classList.remove('drag-over');
        });
      });
      dropArea.addEventListener('drop', function(e){
        var files = (e.dataTransfer && e.dataTransfer.files) ? e.dataTransfer.files : null;
        if (!files || files.length === 0) return;
        var file = files[0];
        // size pre-check
        if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
          if (typeof window.showNotification === 'function') {
            window.showNotification('選択されたファイルは最大アップロードサイズを超えています。最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size), 'error');
          } else {
            alert('選択されたファイルは最大アップロードサイズを超えています。\n最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size));
          }
          return;
        }
        try {
          var dt = new DataTransfer();
          dt.items.add(file);
          videoInput.files = dt.files;
          var changeEvent = new Event('change');
          videoInput.dispatchEvent(changeEvent);
        } catch (_) {
          // Fallback: just preview without binding to input
          droppedFile = file;
          if (fileName) {
            fileName.textContent = file.name;
            fileName.classList.remove('hidden');
          }
          showVideoPreview(file);
        }
      });
    }
    videoInput.addEventListener('change', function(e) {
      var file = e.target.files[0];
      if (file) {
        if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
          if (typeof window.showNotification === 'function') {
            window.showNotification('選択されたファイルは最大アップロードサイズを超えています。最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size), 'error');
          } else {
            alert('選択されたファイルは最大アップロードサイズを超えています。\n最大: ' + formatFileSize(maxUploadBytes) + ' / 選択: ' + formatFileSize(file.size));
          }
          e.target.value = '';
          if (fileName) fileName.classList.add('hidden');
          if (videoPreview) videoPreview.classList.add('hidden');
          return;
        }
        if (fileName) {
          fileName.textContent = file.name;
          fileName.classList.remove('hidden');
        }
        showVideoPreview(file);
      } else {
        if (fileName) fileName.classList.add('hidden');
        if (videoPreview) videoPreview.classList.add('hidden');
      }
    });

    uploadForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var file = videoInput.files[0] || droppedFile;
      if (!file) {
        if (typeof window.showNotification === 'function') {
          window.showNotification('ファイルを選択してください', 'error');
        } else {
          alert('ファイルを選択してください');
        }
        return;
      }
      startUpload(file);
      droppedFile = null;
    });

    if (cancelUpload) {
      cancelUpload.addEventListener('click', function() {
        if (resumable && confirm('アップロードをキャンセルしますか？')) {
          var currentIdentifier = null;
          if (resumable.files && resumable.files.length > 0) {
            currentIdentifier = resumable.files[0].uniqueIdentifier;
          }

          resumable.cancel();

          if (currentIdentifier) {
            var cancelCsrfMeta = document.querySelector('meta[name="csrf-token"]');
            var cancelCsrf = cancelCsrfMeta ? cancelCsrfMeta.getAttribute('content') : '';
            var bodyStr = 'action=cancel_upload&resumableIdentifier=' + encodeURIComponent(currentIdentifier)
              + '&csrf_token=' + encodeURIComponent(cancelCsrf || '');
            fetch('upload_api.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: bodyStr
            }).catch(function(){ /* ignore */ });
          }

          if (uploadProgress) uploadProgress.classList.add('hidden');

          var titleInput = document.getElementById('title-input');
          var commentInput = document.getElementById('comment-input');
          if (titleInput) titleInput.parentElement.style.display = 'block';
          if (commentInput) commentInput.parentElement.style.display = 'block';

          uploadButton.disabled = false;
          uploadButton.textContent = 'アップロード';
        }
      });
    }

    function showVideoPreview(file) {
      if (infoFilename) infoFilename.textContent = file.name;
      if (infoSize) infoSize.textContent = formatFileSize(file.size);
      if (infoType) infoType.textContent = file.type || '不明';
      var videoURL = URL.createObjectURL(file);
      if (previewVideo) {
        previewVideo.src = videoURL;
        previewVideo.addEventListener('loadedmetadata', function () {
          var duration = Math.floor(previewVideo.duration);
          var minutes = Math.floor(duration / 60);
          var seconds = duration % 60;
          if (infoDuration) infoDuration.textContent = minutes + ':' + String(seconds).padStart(2, '0');
        }, { once: true });
      }
      if (videoPreview) videoPreview.classList.remove('hidden');
    }

    function startUpload(file) {
      if (maxUploadBytes > 0 && file.size > maxUploadBytes) {
        showErrorMessage('ファイルサイズが上限を超えています（最大 ' + formatFileSize(maxUploadBytes) + '）');
        return;
      }

      var titleInput = document.getElementById('title-input');
      var commentInput = document.getElementById('comment-input');
      if (titleInput) titleInput.parentElement.style.display = 'none';
      if (commentInput) commentInput.parentElement.style.display = 'none';

      uploadButton.disabled = true;
      uploadButton.textContent = 'アップロード中...';
      if (uploadProgress) uploadProgress.classList.remove('hidden');

      // Initialize Resumable
      if (typeof Resumable === 'undefined') {
        showErrorMessage('アップロード機能の読み込みに失敗しました');
        return;
      }

      resumable = new Resumable({
        target: 'upload_api.php',
        chunkSize: chunkSizeBytes,
        simultaneousUploads: 3,
        testChunks: true,
        throttleProgressCallbacks: 1,
        method: 'multipart',
        // 通信の一時的な失敗は2秒おきに5回まで送り直す（既定は間を空けずに100回で、止まったように見えていた）
        maxChunkRetries: 5,
        chunkRetryInterval: 2000,
        // ログイン切れ（401・403）やサイズ超過（413）は送り直しても通らないので、すぐにエラーにする
        permanentErrors: [400, 401, 403, 404, 409, 413, 415, 500, 501]
      });

      var csrfMeta = document.querySelector('meta[name="csrf-token"]');
      var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
      var title = (document.getElementById('title-input') || {}).value || '';
      var comment = (document.getElementById('comment-input') || {}).value || '';
      resumable.opts.query = {
        title: title.trim(),
        comment: comment.trim(),
        csrf_token: csrfToken
      };

      resumable.on('fileAdded', function(){ resumable.upload(); });
      resumable.on('fileProgress', function(file){ updateProgress(file); });
      resumable.on('fileSuccess', function(file, response){
        try {
          var result = JSON.parse(response);
          // 動画の登録まで完了した応答（video_id あり）のときだけ成功とする
          if (result.success && result.video_id) {
            showSuccessMessage(result.video_id);
          } else {
            showErrorMessage(result.message && !result.success ? result.message : '動画の登録を確認できませんでした。管理パネルで確認してください');
          }
        } catch(e) {
          showErrorMessage('アップロードに失敗しました');
        }
      });
      // fileError のときも Resumable.js が error を発火するため、通知はこちらで1回だけ出す
      resumable.on('error', function(message){
        var text = 'アップロードに失敗しました';
        try {
          var result = JSON.parse(message);
          if (result && /CSRF|認証が必要/.test(result.message || '')) {
            text = 'ログインの有効期限が切れた可能性があります。ページを再読み込みしてから、もう一度アップロードしてください';
          } else if (result && result.message) {
            text = 'アップロードに失敗しました: ' + result.message;
          }
        } catch(e) { /* JSON 以外の応答は既定の文言 */ }
        showErrorMessage(text);
      });

      resumable.addFile(file);

      uploadStartTime = Date.now();
      lastUploadedBytes = 0;
      lastTime = uploadStartTime;
      if (progressText) progressText.textContent = 'アップロードを開始しています...';
      if (progressPercentage) progressPercentage.textContent = '0%';
      if (progressBar) progressBar.style.width = '0%';
      if (uploadedSize) uploadedSize.textContent = '0 B / ' + formatFileSize(file.size);
      if (remainingTime) remainingTime.textContent = '計算中...';
      resumable.upload();
    }

    function updateProgress(file) {
      var progress = file.progress() * 100;
      var uploadedBytes = file.size * file.progress();
      if (progressBar) progressBar.style.width = progress + '%';
      if (progressPercentage) progressPercentage.textContent = Math.round(progress) + '%';
      if (Math.round(progress) % 5 === 0 || progress === 100) {
        if (uploadedSize) uploadedSize.textContent = formatFileSize(uploadedBytes) + ' / ' + formatFileSize(file.size);
      }

      var currentTime = Date.now();
      var timeDiff = (currentTime - lastTime) / 1000;
      if (timeDiff > 0.5) {
        var bytesDiff = uploadedBytes - lastUploadedBytes;
        uploadSpeed = bytesDiff / timeDiff;
        var remainingBytes = file.size - uploadedBytes;
        var remainingSeconds = remainingBytes / uploadSpeed;
        if (remainingSeconds > 0 && remainingSeconds < Infinity && uploadSpeed > 0) {
          var minutes = Math.floor(remainingSeconds / 60);
          var seconds = Math.floor(remainingSeconds % 60);
          if (remainingTime) remainingTime.textContent = minutes + ':' + String(seconds).padStart(2, '0');
        } else {
          if (remainingTime) remainingTime.textContent = '計算中...';
        }
        lastUploadedBytes = uploadedBytes;
        lastTime = currentTime;
      }
      if (progressText) {
        if (progress < 100) progressText.textContent = 'アップロード中... ' + Math.round(progress) + '%';
        else progressText.textContent = '完了処理中...';
      }
    }

    function showSuccessMessage(videoId) {
      if (uploadProgress) uploadProgress.classList.add('hidden');
      uploadButton.style.display = 'none';
      var videoPlayUrl = 'index.php';
      if (videoId) videoPlayUrl = 'index.php?v=' + encodeURIComponent(videoId);
      var successMessage = document.createElement('div');
      successMessage.className = 'upload-done';
      successMessage.id = 'upload-done';
      successMessage.innerHTML =
        '<h3 class="upload-done__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>アップロードが完了しました</h3>' +
        '<p>動画ページで再生できます。</p>' +
        '<div class="upload-done__actions">' +
          '<a href="' + videoPlayUrl + '" class="btn btn--primary">動画を見る</a>' +
          '<a href="admin.php" class="btn">動画の管理へ</a>' +
          '<button type="button" class="btn" onclick="continueUpload()">続けてアップロード</button>' +
        '</div>';
      uploadForm.parentNode.insertBefore(successMessage, uploadForm.nextSibling);
    }

    function showErrorMessage(message) {
      if (typeof window.showNotification === 'function') {
        window.showNotification(message, 'error');
      }
      if (uploadProgress) uploadProgress.classList.add('hidden');
      var titleInput = document.getElementById('title-input');
      var commentInput = document.getElementById('comment-input');
      if (titleInput) titleInput.parentElement.style.display = 'block';
      if (commentInput) commentInput.parentElement.style.display = 'block';
      uploadButton.disabled = false;
      uploadButton.textContent = 'もう一度アップロード';
    }

    function continueUpload() {
      var doneMessage = document.getElementById('upload-done');
      if (doneMessage) doneMessage.remove();
      uploadForm.reset();
      var titleInput = document.getElementById('title-input');
      var commentInput = document.getElementById('comment-input');
      if (titleInput) titleInput.parentElement.style.display = 'block';
      if (commentInput) commentInput.parentElement.style.display = 'block';
      if (fileName) fileName.classList.add('hidden');
      if (videoPreview) videoPreview.classList.add('hidden');
      uploadButton.style.display = '';
      uploadButton.disabled = false;
      uploadButton.textContent = 'アップロード';
      uploadProgress.classList.add('hidden');
      if (progressBar) progressBar.style.width = '0%';
      if (progressPercentage) progressPercentage.textContent = '0%';
      if (progressText) progressText.textContent = '準備中...';
      if (uploadedSize) uploadedSize.textContent = '0 B';
      if (remainingTime) remainingTime.textContent = '計算中...';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // expose
    window.continueUpload = continueUpload;
  });
})();


