// Upload page script extracted from inline JS
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
          uploadButton.innerHTML = '\n          <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>\n          </svg>\n          アップロード\n        ';
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

    function formatFileSize(bytes) {
      if (bytes === 0) return '0 B';
      var k = 1024;
      var sizes = ['B', 'KB', 'MB', 'GB'];
      var i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
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
      uploadButton.innerHTML = '\n        <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>\n        </svg>\n        アップロード中...\n      ';
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
        method: 'multipart'
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
          if (result && result.message) text = 'アップロードに失敗しました: ' + result.message;
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
      successMessage.className = 'mt-6 md:mt-8 p-4 md:p-6 video-info-container rounded-lg animate-fade-in';
      successMessage.innerHTML = '\n        <div class="flex items-center">\n          <svg class="w-6 h-6 md:w-7 md:h-7 text-green-400 mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>\n          </svg>\n          <div>\n            <h3 class="video-title-main font-medium">アップロード完了</h3>\n            <p class="video-meta-info text-base md:text-lg">動画が正常にアップロードされました。</p>\n          </div>\n        </div>\n        <div class="mt-4 md:mt-6">\n          <div class="flex flex-col md:flex-row gap-3 md:gap-4">\n            <a href="' + videoPlayUrl + '" class="w-full md:w-auto px-4 py-3 md:py-2 bg-gradient-to-r from-indigo-500 to-indigo-600 text-white text-base md:text-sm rounded-lg hover:from-indigo-600 hover:to-indigo-700 transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">\n              <svg class="w-5 h-5 md:w-4 md:h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>\n              </svg>\n              動画を再生\n            </a>\n            <a href="admin.php" class="w-full md:w-auto px-4 py-3 md:py-2 bg-gradient-to-r from-slate-600 to-slate-700 text-white text-base md:text-sm rounded-lg hover:from-slate-700 hover:to-slate-800 transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">\n              <svg class="w-5 h-5 md:w-4 md:h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>\n                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>\n              </svg>\n              管理パネルへ\n            </a>\n            <button onclick="continueUpload()" class="w-full md:w-auto px-4 py-3 md:py-2 bg-gradient-to-r from-emerald-500 to-emerald-600 text-white text-base md:text-sm rounded-lg hover:from-emerald-600 hover:to-emerald-700 transition-all duration-200 flex items-center justify-center shadow-md hover:shadow-lg transform hover:-translate-y-0.5">\n              <svg class="w-5 h-5 md:w-4 md:h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>\n              </svg>\n              続けてアップロードする\n            </button>\n          </div>\n        </div>\n      ';
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
      uploadButton.innerHTML = '\n        <svg class="w-6 h-6 md:w-7 md:w-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>\n        </svg>\n        アップロード失敗\n      ';
      uploadButton.classList.remove('upload-button', 'from-green-600', 'to-green-700');
      uploadButton.classList.add('upload-button', 'from-red-600', 'to-red-700');
    }

    function continueUpload() {
      var successMessage = document.getElementById('success-message');
      if (successMessage) successMessage.remove();
      var dynamicSuccessMessages = document.querySelectorAll('.video-info-container.animate-fade-in');
      dynamicSuccessMessages.forEach(function(message) {
        var h3 = message.querySelector('h3');
        if (h3 && h3.textContent.indexOf('アップロード完了') !== -1) {
          message.remove();
        }
      });
      uploadForm.reset();
      var titleInput = document.getElementById('title-input');
      var commentInput = document.getElementById('comment-input');
      if (titleInput) titleInput.parentElement.style.display = 'block';
      if (commentInput) commentInput.parentElement.style.display = 'block';
      if (fileName) fileName.classList.add('hidden');
      if (videoPreview) videoPreview.classList.add('hidden');
      uploadButton.style.display = 'block';
      uploadButton.disabled = false;
      uploadButton.innerHTML = '\n        <svg class="w-6 h-6 md:w-7 md:h-7 inline mr-2 md:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">\n          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>\n        </svg>\n        アップロード\n      ';
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


