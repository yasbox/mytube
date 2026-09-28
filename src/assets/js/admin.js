// 管理機能用のJavaScript

// HTML に埋め込む値のエスケープ（タイトル等に記号が含まれても表示・動作が崩れないように）
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// 動画一覧の行を取得（ファイル名はセレクタ用にエスケープ）
function findVideoRow(videoFile) {
    return document.querySelector(`tr[data-filename="${CSS.escape(videoFile)}"]`);
}

// 管理者ログイン
async function adminLogin() {
    const password = document.getElementById('admin-password').value;
    const rememberMe = true; // 常にリメンバーミー機能を有効にする
    
    if (!password) {
        showNotification('パスワードを入力してください', 'error');
        return;
    }
    
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=admin_login&password=${encodeURIComponent(password)}&remember_me=${rememberMe}&csrf_token=${encodeURIComponent(csrf)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('ログインに失敗しました', 'error');
    }
}

// 管理者ログアウト
async function adminLogout() {
    if (!confirm('ログアウトしますか？')) {
        return;
    }
    
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=admin_logout&csrf_token=${encodeURIComponent(csrf)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('ログアウトに失敗しました', 'error');
    }
}

// 動画削除
async function deleteVideo(videoFile, videoTitle) {
    if (!confirm(`「${videoTitle}」を削除しますか？\nこの操作は取り消せません。`)) {
        return;
    }
    
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete_video&video=${encodeURIComponent(videoFile)}&csrf_token=${encodeURIComponent(csrf)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('削除に失敗しました', 'error');
    }
}

// 動画再生ページに遷移
function playVideo(videoFilename) {
    // 動画再生ページに遷移（filenameは拡張子付き）
    window.open(`index.php?v=${encodeURIComponent(videoFilename)}`, '_blank');
}

// 動画変換開始
async function startVideoConversion(videoFile, videoTitle) {
    // ファイル拡張子を取得して適切なメッセージを決定
    const fileExtension = videoFile.split('.').pop().toLowerCase();
    const isMp4 = fileExtension === 'mp4';
    const actionText = isMp4 ? '再エンコード' : 'MP4に変換';
    
    if (!confirm(`「${videoTitle}」を${actionText}しますか？\n長い動画はサーバーの過負荷により失敗する可能性があります。`)) {
        return;
    }
    
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=start_conversion&video=${encodeURIComponent(videoFile)}&csrf_token=${encodeURIComponent(csrf)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            // 変換プログレスモーダルを表示
            showConversionModal(videoFile, videoTitle);
            // 変換進捗の監視を開始
            startConversionProgressMonitoring(videoFile);
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('変換開始に失敗しました', 'error');
    }
}

// 変換プログレスモーダルを表示
function showConversionModal(videoFile, videoTitle) {
    const modal = document.getElementById('conversion-progress-modal');
    const thumbnail = document.getElementById('conversion-thumbnail');
    const title = document.getElementById('conversion-title');
    const filename = document.getElementById('conversion-filename');
    const format = document.getElementById('conversion-format');
    
    // 動画情報を設定
    title.textContent = videoTitle;
    filename.textContent = videoFile;
    
    // ファイル拡張子を取得
    const fileExtension = videoFile.split('.').pop().toUpperCase();
    format.textContent = fileExtension;
    
    // サムネイルを設定
    const basename = videoFile.split('.').slice(0, -1).join('.');
    const thumbnailPath = `thumbnails/${basename}.jpg`;
    
    // サムネイルが存在するかチェック
    fetch(thumbnailPath, { method: 'HEAD' })
        .then(response => {
            if (response.ok) {
                thumbnail.src = thumbnailPath;
            } else {
                thumbnail.src = 'images/default-thumbnail-small.svg';
            }
        })
        .catch(() => {
            thumbnail.src = 'images/default-thumbnail-small.svg';
        });
    
    // モーダルを表示
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden'; // スクロールを無効化
    
    // 開始時刻を記録
    window.conversionStartTime = Date.now();
}

// 変換進捗監視開始
function startConversionProgressMonitoring(videoFile) {
    const progressInterval = setInterval(async () => {
        try {
            const response = await fetch(`admin_api.php?action=get_conversion_progress&video=${encodeURIComponent(videoFile)}`);
            const data = await response.json();
            
            if (data.success) {
                // プログレスバーを更新
                updateConversionProgress(data.progress, data.message);
                
                if (data.status === 'completed') {
                    clearInterval(progressInterval);
                    showNotification('変換が完了しました', 'success');
                    setTimeout(() => {
                        closeConversionModal();
                        window.location.reload();
                    }, 2000);
                } else if (data.status === 'failed') {
                    clearInterval(progressInterval);
                    showNotification('変換に失敗しました', 'error');
                    setTimeout(() => {
                        closeConversionModal();
                    }, 2000);
                }
            } else {
                clearInterval(progressInterval);
                showNotification(data.message, 'error');
                setTimeout(() => {
                    closeConversionModal();
                }, 2000);
            }
        } catch (error) {
            clearInterval(progressInterval);
            showNotification('進捗取得に失敗しました', 'error');
            setTimeout(() => {
                closeConversionModal();
            }, 2000);
        }
    }, 1000); // 1秒ごとに進捗を取得
    
    // インターバルIDを保存（キャンセル時に使用）
    window.conversionProgressInterval = progressInterval;
}

// 変換プログレスを更新
function updateConversionProgress(progress, message) {
    const progressBar = document.getElementById('conversion-progress-bar');
    const percentage = document.getElementById('conversion-percentage');
    const status = document.getElementById('conversion-status');
    const time = document.getElementById('conversion-time');
    
    // プログレスバーを更新
    progressBar.style.width = `${progress}%`;
    percentage.textContent = `${progress}%`;
    status.textContent = message;
    
    // 処理時間を更新
    if (window.conversionStartTime) {
        const elapsed = Math.floor((Date.now() - window.conversionStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        time.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
}

// 変換キャンセル
async function cancelConversion() {
    if (!confirm('変換をキャンセルしますか？\n変換中のプロセスが停止されます。')) {
        return;
    }
    
    try {
        // 現在変換中のファイルを取得（簡易的な実装）
        const filename = document.getElementById('conversion-filename').textContent;
        
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=stop_conversion&video=${encodeURIComponent(filename)}&csrf_token=${encodeURIComponent(csrf)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification('変換をキャンセルしました', 'success');
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('変換キャンセルに失敗しました', 'error');
    }
    
    // モーダルを閉じる
    closeConversionModal();
}

// 変換プログレスモーダルを閉じる
function closeConversionModal() {
    const modal = document.getElementById('conversion-progress-modal');
    
    // プログレス監視を停止
    if (window.conversionProgressInterval) {
        clearInterval(window.conversionProgressInterval);
        window.conversionProgressInterval = null;
    }
    
    // モーダルを非表示
    modal.classList.add('hidden');
    document.body.style.overflow = ''; // スクロールを有効化
    
    // プログレスバーをリセット
    const progressBar = document.getElementById('conversion-progress-bar');
    const percentage = document.getElementById('conversion-percentage');
    const status = document.getElementById('conversion-status');
    const time = document.getElementById('conversion-time');
    
    progressBar.style.width = '0%';
    percentage.textContent = '0%';
    status.textContent = '変換を開始しています...';
    time.textContent = '-';
    
    // 開始時刻をリセット
    window.conversionStartTime = null;
}

// メタデータ編集モーダル切り替え（モーダル版）
function toggleEditMode(videoId) {
    const video = getVideoData(videoId);
    if (video) {
        showEditModal(video);
    }
}

// 動画データを取得する関数
function getVideoData(videoId) {
    // 現在表示されている動画データから該当するものを探す
    const videoRow = document.getElementById(`view-${videoId}`);
    if (videoRow) {
        // データ属性から動画情報を取得
        const videoData = {
            filename: videoRow.getAttribute('data-filename'),
            title: videoRow.getAttribute('data-title'),
            comment: videoRow.getAttribute('data-comment'),
            views: parseInt(videoRow.getAttribute('data-views') || '0'),
            likes: parseInt(videoRow.getAttribute('data-likes') || '0'),
            file_size: videoRow.getAttribute('data-file-size'),
            upload_date: videoRow.getAttribute('data-upload-date'),
            basename: videoRow.getAttribute('data-basename'),
            has_thumbnail: videoRow.getAttribute('data-has-thumbnail') === 'true',
            is_public: videoRow.getAttribute('data-is-public') === 'true',
            thumb_url: videoRow.getAttribute('data-thumb-url') || ''
        };
        return videoData;
    }
    return null;
}

// 編集モーダルを表示
function showEditModal(video) {
    // 既存のモーダルがあれば削除
    const existingModal = document.getElementById('edit-modal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const safeVideoId = video.filename.replace(/[^a-zA-Z0-9]/g, '_');
    
    // タイトルとコメントの省略処理
    const title = video.title || 'タイトルなし';
    const comment = video.comment || '';
    const truncatedTitle = title.length > 40 ? title.substring(0, 40) + '...' : title;
    const truncatedComment = comment.length > 60 ? comment.substring(0, 60) + '...' : comment;
    
    const modalHTML = `
        <div id="edit-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto edit-modal rounded-2xl shadow-2xl">
                <!-- モーダルヘッダー -->
                <div class="sticky top-0 z-10 flex items-center justify-between p-3 md:p-6 edit-modal-header backdrop-blur-sm rounded-t-2xl min-h-[60px] md:min-h-0">
                    <div class="flex items-center space-x-2 md:space-x-3 flex-1 min-w-0">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold edit-modal-title truncate">動画情報の編集</h3>
                        </div>
                    </div>
                    <button type="button" data-action="close" class="p-1.5 md:p-2 edit-modal-close hover:bg-gray-700/50 rounded-lg transition-all duration-200 flex-shrink-0 ml-2">
                        <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- モーダルコンテンツ -->
                <div class="p-4 md:p-6 space-y-6 edit-modal-content">
                    <!-- サムネイル表示 -->
                    <div class="flex justify-center">
                        <div data-action="pick-thumbnail" class="bg-gray-700 rounded-2xl overflow-hidden cursor-pointer hover:opacity-80 transition-opacity duration-200 aspect-video edit-modal-thumb" title="クリックしてサムネイルを差し替え">
                            ${video.has_thumbnail ?
                                `<img src="${escapeHtml(video.thumb_url || ('thumbnails/' + video.basename + '.jpg'))}" alt="サムネイル" class="w-full h-full object-cover">` :
                                `<img src="images/default-thumbnail-small.svg" alt="デフォルトサムネイル" class="w-full h-full object-cover">`
                            }
                        </div>
                    </div>
                    <input type="file" id="thumbnail-file-${safeVideoId}" accept="image/jpeg,image/png,image/webp" class="hidden" />
                    <p class="text-sm md:text-base text-center">画像をクリックでサムネイルを変更できます。</br><span class="text-gray-400">対応形式: JPEG / PNG / WebP（最大5MB）</span></p>

                    <!-- 編集フォーム -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <label class="block text-base md:text-lg font-semibold edit-modal-label" for="modal-title-${safeVideoId}">
                                <svg class="w-5 h-5 inline mr-2 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                                タイトル
                            </label>
                            <input type="text" id="modal-title-${safeVideoId}" 
                                   class="w-full px-4 py-3 edit-modal-input rounded-lg text-base transition-all duration-200"
                                   placeholder="動画のタイトルを入力してください">
                        </div>
                        <div class="space-y-3">
                            <label class="block text-base md:text-lg font-semibold edit-modal-label" for="modal-comment-${safeVideoId}">
                                <svg class="w-5 h-5 inline mr-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                </svg>
                                コメント
                            </label>
                            <textarea id="modal-comment-${safeVideoId}" rows="4" 
                                      class="w-full px-4 py-3 edit-modal-textarea rounded-lg text-base resize-none transition-all duration-200"
                                      placeholder="動画のコメントを入力してください"></textarea>
                        </div>
                    </div>

                    
                </div>
                
                <!-- モーダルフッター -->
                <div class="sticky bottom-0 z-10 flex items-center justify-end space-x-2 md:space-x-3 p-3 md:p-6 edit-modal-footer backdrop-blur-sm rounded-b-2xl min-h-[60px] md:min-h-0">
                    <button type="button" data-action="close"
                            class="px-5 py-4 md:px-6 md:py-3 edit-modal-cancel-btn rounded-lg hover:shadow-xl active:shadow-lg transition-all duration-200 text-sm md:text-base font-semibold flex items-center shadow-lg flex-shrink-0">
                        <svg class="w-5 h-5 md:w-5 md:h-5 mr-1 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        <span class="hidden sm:inline">キャンセル</span>
                        <span class="sm:hidden">取消</span>
                    </button>
                    <button type="button" id="modal-update-btn"
                            class="px-5 py-4 md:px-6 md:py-3 edit-modal-update-btn rounded-lg hover:shadow-xl active:shadow-lg transition-all duration-200 text-sm md:text-base font-semibold flex items-center shadow-lg flex-shrink-0">
                        <svg class="w-5 h-5 md:w-5 md:h-5 mr-1 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span class="hidden sm:inline">更新</span>
                        <span class="sm:hidden">更新</span>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // モーダルをDOMに追加
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // 操作はイベントリスナーで登録する（ファイル名を HTML 属性の文字列に埋め込まない）
    const modalRoot = document.getElementById('edit-modal');
    const thumbnailInput = document.getElementById(`thumbnail-file-${safeVideoId}`);
    modalRoot.querySelectorAll('[data-action="close"]').forEach(el => el.addEventListener('click', closeEditModal));
    modalRoot.querySelector('[data-action="pick-thumbnail"]').addEventListener('click', () => thumbnailInput.click());
    thumbnailInput.addEventListener('change', () => uploadThumbnail(video.filename, video.basename));
    document.getElementById('modal-update-btn').addEventListener('click', () => updateMetadataFromModal(video.filename));

    // 入力フィールドに値を設定
    const titleInput = document.getElementById(`modal-title-${safeVideoId}`);
    const commentInput = document.getElementById(`modal-comment-${safeVideoId}`);
    
    if (titleInput) {
        const titleValue = video.title === 'タイトルなし' ? '' : (video.title || '');
        titleInput.value = titleValue;
    }
    
    if (commentInput) {
        const commentValue = video.comment || '';
        commentInput.value = commentValue;
    }
    
    // モーダル表示アニメーション
    const modal = document.getElementById('edit-modal');
    modal.style.opacity = '0';
    modal.style.transform = 'scale(0.9)';
    
    setTimeout(() => {
        modal.style.transition = 'all 0.3s ease-out';
        modal.style.opacity = '1';
        modal.style.transform = 'scale(1)';
    }, 10);
    
    // ESCキーでモーダルを閉じる
    document.addEventListener('keydown', handleModalKeydown);

    // オーバーレイ（モーダル外）クリックでモーダルを閉じる
    const overlay = document.getElementById('edit-modal');
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeEditModal();
            }
        });
    }
}

// モーダルを閉じる
function closeEditModal() {
    const modal = document.getElementById('edit-modal');
    if (modal) {
        modal.style.transition = 'all 0.3s ease-in';
        modal.style.opacity = '0';
        modal.style.transform = 'scale(0.9)';
        
        setTimeout(() => {
            modal.remove();
            document.removeEventListener('keydown', handleModalKeydown);
        }, 300);
    }
}

// モーダル用キーボードイベントハンドラー
function handleModalKeydown(event) {
    if (event.key === 'Escape') {
        closeEditModal();
    }
}

// モーダルからメタデータを更新
async function updateMetadataFromModal(videoFile) {
    const safeVideoId = videoFile.replace(/[^a-zA-Z0-9]/g, '_');
    
    // 入力フィールドを取得
    const titleInput = document.querySelector(`#modal-title-${safeVideoId}`);
    const commentInput = document.querySelector(`#modal-comment-${safeVideoId}`);
    
    if (!titleInput || !commentInput) {
        showNotification('入力フィールドが見つかりません', 'error');
        return;
    }
    
    // 入力値を取得
    let title = titleInput.value || '';
    let comment = commentInput.value || '';
    
    // トリム処理
    title = title.trim();
    comment = comment.trim();
    
    // タイトルが空でもそのまま更新する（確認ダイアログなし）
    
    // 送信するタイトル（空の場合は空文字列のまま）
    const finalTitle = title;
    
    try {
        // ローディング状態を表示
        const updateButton = document.getElementById('modal-update-btn');
        if (updateButton) {
            updateButton.disabled = true;
            updateButton.innerHTML = `
                <svg class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                更新中...
            `;
        }
        
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const requestBody = `action=update_metadata&video=${encodeURIComponent(videoFile)}&title=${encodeURIComponent(finalTitle)}&comment=${encodeURIComponent(comment)}&csrf_token=${encodeURIComponent(csrf)}`;
        
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: requestBody
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            closeEditModal();
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        console.error('Update error:', error);
        showNotification('更新に失敗しました', 'error');
    } finally {
        // ボタンを元に戻す
        const updateButton = document.getElementById('modal-update-btn');
        if (updateButton) {
            updateButton.disabled = false;
            updateButton.innerHTML = `
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                更新
            `;
        }
    }
}

// 統計情報の読み込み
async function loadStats() {
    try {
        const response = await fetch('admin_api.php?action=get_stats');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.stats;
            document.getElementById('total-videos').textContent = stats.total_videos;
            document.getElementById('total-views').textContent = stats.total_views.toLocaleString();
            document.getElementById('total-likes').textContent = stats.total_likes.toLocaleString();
            document.getElementById('total-size').textContent = formatFileSize(stats.total_size);
            document.getElementById('average-views').textContent = stats.average_views;
            document.getElementById('average-likes').textContent = stats.average_likes;
        }
    } catch (error) {
        console.error('統計情報の読み込みに失敗しました:', error);
    }
}

// ソート機能
let currentSort = 'new';

// 無限スクロール機能
let currentPage = 1;
let isLoading = false;
let hasMorePages = true;
let allVideos = [];
let videosPerPage = 20; // 1ページあたりの動画数

// 無限スクロールの初期化
function initInfiniteScroll() {
    // スクロールイベントリスナーを追加
    window.addEventListener('scroll', handleScroll);
    
    // Intersection Observerを使用したより効率的なスクロール検出
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !isLoading && hasMorePages) {
                loadMoreVideos();
            }
        });
    }, {
        rootMargin: '100px' // 100px手前で読み込み開始
    });
    
    // 監視対象の要素を設定
    const loadingElement = document.getElementById('infinite-loading');
    if (loadingElement) {
        observer.observe(loadingElement);
    }
}

// スクロールハンドラー
function handleScroll() {
    if (isLoading || !hasMorePages) return;
    
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const windowHeight = window.innerHeight;
    const documentHeight = document.documentElement.scrollHeight;
    
    // ページ下部から100px手前で読み込み開始
    if (scrollTop + windowHeight >= documentHeight - 100) {
        loadMoreVideos();
    }
}

// 追加動画の読み込み
async function loadMoreVideos() {
    if (isLoading || !hasMorePages) return;
    
    isLoading = true;
    showInfiniteLoading(true);
    
    try {
        const nextPage = currentPage + 1;
        const response = await fetch(`admin_api.php?action=get_admin_videos_paginated&sort=${currentSort}&page=${nextPage}&per_page=${videosPerPage}`);
        const data = await response.json();
        
        if (data.success) {
            const videoTableBody = document.getElementById('video-table-body');
            
            if (!videoTableBody) {
                console.error('loadMoreVideos: video-table-body要素が見つかりません');
                return;
            }
            
            // 新しい動画を追加
            data.videos.forEach(video => {
                const videoId = video.filename.replace(/[^a-zA-Z0-9]/g, '_');
                
                // 表示行を作成
                const viewRow = createVideoRow(video, videoId);
                const editRow = createEditRow(video, videoId);
                
                videoTableBody.appendChild(viewRow);
                videoTableBody.appendChild(editRow);
            });
            
            // ページネーション情報を更新
            currentPage = data.pagination.current_page;
            hasMorePages = data.pagination.has_next_page;
            
            // ページネーション情報を表示
            updatePaginationInfo(data.pagination);
            
            // すべて読み込み完了の場合
            if (!hasMorePages) {
                showAllLoadedMessage(true);
            }
        } else {
            console.error('loadMoreVideos: APIが失敗しました', data.message);
        }
    } catch (error) {
        console.error('追加動画の読み込みに失敗しました:', error);
    } finally {
        isLoading = false;
        showInfiniteLoading(false);
    }
}

// 初期ローディング表示の制御
function showInitialLoading(show) {
    const initialLoading = document.getElementById('initial-loading');
    const videoTable = document.getElementById('video-table');
    
    if (initialLoading && videoTable) {
        if (show) {
            initialLoading.classList.remove('hidden');
            videoTable.classList.add('hidden');
        } else {
            initialLoading.classList.add('hidden');
            videoTable.classList.remove('hidden');
        }
    }
}

// 無限スクロール用ローディング表示制御
function showInfiniteLoading(show) {
    const loadingElement = document.getElementById('infinite-loading');
    if (loadingElement) {
        loadingElement.classList.toggle('hidden', !show);
    }
}

// ページネーション情報の更新
function updatePaginationInfo(pagination) {
    const paginationInfo = document.getElementById('pagination-info');
    const currentPageInfo = document.getElementById('current-page-info');
    const totalVideosInfo = document.getElementById('total-videos-info');
    
    if (paginationInfo && currentPageInfo && totalVideosInfo) {
        currentPageInfo.textContent = `ページ ${pagination.current_page}`;
        totalVideosInfo.textContent = `全 ${pagination.total_videos} 件`;
        paginationInfo.classList.remove('hidden');
    }
}

// すべて読み込み完了メッセージの表示制御
function showAllLoadedMessage(show) {
    const messageElement = document.getElementById('all-loaded-message');
    if (messageElement) {
        messageElement.classList.toggle('hidden', !show);
    }
}

// 無限スクロール状態のリセット
function resetInfiniteScroll() {
    currentPage = 1;
    isLoading = false;
    hasMorePages = true;
    allVideos = [];
    
    // メッセージを非表示
    showInfiniteLoading(false);
    showAllLoadedMessage(false);
    
    // ページネーション情報を非表示
    const paginationInfo = document.getElementById('pagination-info');
    if (paginationInfo) {
        paginationInfo.classList.add('hidden');
    }
}

// ソート変更
async function changeSort(sort) {
    if (currentSort === sort) {
        return; // 同じソートの場合は何もしない
    }
    
    currentSort = sort;
    updateSortButtons(sort);
    
    // 無限スクロール状態をリセット
    resetInfiniteScroll();
    
    // 初期ローディングを表示
    showInitialLoading(true);
    
    // 新しいソートで最初のページを読み込み
    await loadVideoListSorted(sort);
}

// ソートボタンの状態更新
function updateSortButtons(activeSort) {
    const buttons = {
        'new': document.getElementById('sort-new-btn'),
        'popular': document.getElementById('sort-popular-btn'),
        'views': document.getElementById('sort-views-btn'),
        'likes': document.getElementById('sort-likes-btn')
    };
    
    Object.keys(buttons).forEach(sort => {
        const button = buttons[sort];
        if (button) {
            if (sort === activeSort) {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        }
    });
}

// ソート機能付き動画一覧の読み込み（無限スクロール対応）
async function loadVideoListSorted(sort = 'new') {
    try {
        // 初期ローディングを表示
        showInitialLoading(true);
        
        const response = await fetch(`admin_api.php?action=get_admin_videos_paginated&sort=${sort}&page=1&per_page=${videosPerPage}`);
        const data = await response.json();
        
        if (data.success) {
            const videoTableBody = document.getElementById('video-table-body');
            
            // テーブルをクリア
            if (videoTableBody) {
                videoTableBody.innerHTML = '';
            } else {
                console.error('loadVideoListSorted: video-table-body要素が見つかりません');
                return;
            }
            
            // 最初のページの動画を表示
            data.videos.forEach(video => {
                const videoId = video.filename.replace(/[^a-zA-Z0-9]/g, '_');
                
                // 表示行を作成
                const viewRow = createVideoRow(video, videoId);
                const editRow = createEditRow(video, videoId);
                
                videoTableBody.appendChild(viewRow);
                videoTableBody.appendChild(editRow);
            });
            
            // ページネーション情報を更新
            currentPage = data.pagination.current_page;
            hasMorePages = data.pagination.has_next_page;
            
            // ページネーション情報を表示
            updatePaginationInfo(data.pagination);
            
            // すべて読み込み完了の場合
            if (!hasMorePages) {
                showAllLoadedMessage(true);
            }
            
            // 初期ローディングを非表示にしてテーブルを表示
            showInitialLoading(false);
            
            // ローディングメッセージを非表示
            const loadingMessage = document.getElementById('loading-message');
            if (loadingMessage) {
                loadingMessage.style.display = 'none';
            }
        } else {
            console.error('loadVideoListSorted: APIが失敗しました', data.message);
            // エラー時もローディングを非表示
            showInitialLoading(false);
        }
    } catch (error) {
        console.error('動画一覧の読み込みに失敗しました:', error);
        // エラー時もローディングを非表示
        showInitialLoading(false);
    }
}

// 動画一覧の読み込み（元の関数）
async function loadVideoList() {
    try {
        const response = await fetch('admin_api.php?action=get_admin_videos');
        const data = await response.json();
        
        if (data.success) {
            const videoTableBody = document.getElementById('video-table-body');
            
            // テーブルをクリア
            if (videoTableBody) {
                videoTableBody.innerHTML = '';
            } else {
                console.error('loadVideoList: video-table-body要素が見つかりません');
                return;
            }
            
            data.videos.forEach((video, index) => {
                const videoId = video.filename.replace(/[^a-zA-Z0-9]/g, '_');
                
                // 表示行を作成
                const viewRow = createVideoRow(video, videoId);
                const editRow = createEditRow(video, videoId);
                
                videoTableBody.appendChild(viewRow);
                videoTableBody.appendChild(editRow);
            });
            
            // ローディングメッセージを非表示
            const loadingMessage = document.getElementById('loading-message');
            if (loadingMessage) {
                loadingMessage.style.display = 'none';
            }
        } else {
            console.error('loadVideoList: APIが失敗しました', data.message);
        }
    } catch (error) {
        console.error('動画一覧の読み込みに失敗しました:', error);
    }
}

// 動画行の作成
function createVideoRow(video, videoId) {
    const row = document.createElement('tr');
    row.id = `view-${videoId}`;
    row.className = 'border-b border-gray-700 transition-colors duration-200';
    
    // 動画データをdata属性として保存
    row.setAttribute('data-filename', video.filename);
    row.setAttribute('data-title', video.title || '');
    row.setAttribute('data-comment', video.comment || '');
    row.setAttribute('data-views', video.views.toString());
    row.setAttribute('data-likes', video.likes.toString());
    row.setAttribute('data-file-size', video.file_size);
    row.setAttribute('data-upload-date', video.upload_date);
    row.setAttribute('data-basename', video.basename);
    row.setAttribute('data-has-thumbnail', video.has_thumbnail.toString());
    row.setAttribute('data-thumb-url', (video.thumb_url || ''));
    row.setAttribute('data-is-public', (video.is_public !== false).toString());
    
    // タイトルとコメントの省略処理
    const title = video.title || 'タイトルなし';
    const comment = video.comment || '';
    const truncatedTitle = title.length > 40 ? title.substring(0, 40) + '...' : title;
    const truncatedComment = comment.length > 60 ? comment.substring(0, 60) + '...' : comment;
    
    // ファイル拡張子を取得
    const fileExtension = video.filename.split('.').pop().toLowerCase();
    const isMp4 = fileExtension === 'mp4';
    const thumbSrc = video.has_thumbnail
        ? (video.thumb_url || ('thumbnails/' + video.basename + '.jpg'))
        : 'images/default-thumbnail-small.svg';

    // 値はすべてエスケープし、操作はイベントリスナーで登録する（タイトルの記号でボタンが壊れないように）
    row.innerHTML = `
        <td class="px-1 md:px-2 py-2 md:py-3 whitespace-nowrap overflow-hidden text-ellipsis" data-label="動画">
            <div class="flex items-center space-x-2 md:space-x-3">
                <button type="button" data-action="edit" class="edit-thumb-btn p-1 md:p-2 admin-edit-btn rounded-lg transition-all duration-200" title="編集">
                    <svg class="w-4 h-4 md:w-6 md:h-6 lg:w-7 lg:h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </button>
                <div data-action="play" class="w-20 h-16 md:w-32 md:h-24 bg-gray-700 rounded overflow-hidden flex-shrink-0 cursor-pointer hover:opacity-80 transition-opacity duration-200" title="クリックして再生">
                    <img src="${escapeHtml(thumbSrc)}" alt="${video.has_thumbnail ? 'サムネイル' : 'デフォルトサムネイル'}" class="w-full h-full object-cover">
                </div>
                <div class="flex-1 min-w-0">
                    <div>
                        <p data-action="play" class="video-title-admin font-medium text-sm md:text-base lg:text-lg mb-1 cursor-pointer hover:text-blue-400 transition-colors duration-200" title="${escapeHtml(title)}">${escapeHtml(truncatedTitle)}</p>
                        <p class="video-comment-admin text-xs md:text-sm" title="${escapeHtml(comment)}">${escapeHtml(truncatedComment || 'コメントなし')}</p>
                    </div>
                </div>
            </div>
        </td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg admin-table-data whitespace-nowrap overflow-hidden text-ellipsis" data-label="再生数">${escapeHtml(video.views.toLocaleString())}</td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg admin-table-data whitespace-nowrap overflow-hidden text-ellipsis" data-label="いいね数">${escapeHtml(video.likes.toLocaleString())}</td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg admin-table-data whitespace-nowrap overflow-hidden text-ellipsis" data-label="アップロード日">${escapeHtml(video.upload_date)}</td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg admin-table-data whitespace-nowrap overflow-hidden text-ellipsis" data-label="ファイルサイズ">${escapeHtml(video.file_size)}</td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg admin-table-data whitespace-nowrap overflow-hidden text-ellipsis" data-label="形式">
            ${escapeHtml(fileExtension.toUpperCase())}
        </td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg whitespace-nowrap overflow-hidden text-ellipsis" data-label="変換">
            <button type="button" data-action="convert" id="conversion-btn-${videoId}" class="px-3 py-1 admin-convert-btn text-xs rounded transition-colors duration-200" title="${isMp4 ? 'MP4を再エンコード' : 'MP4に変換'}">
                ${isMp4 ? '再エンコード' : '変換'}
            </button>
        </td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg whitespace-nowrap overflow-hidden text-ellipsis" data-label="公開">
            <button type="button" data-action="visibility" class="px-3 py-1 ${video.is_public !== false ? 'admin-public-btn' : 'admin-private-btn'} text-xs rounded transition-colors duration-200" title="${video.is_public !== false ? '非公開にする' : '公開する'}">
                ${video.is_public !== false ? '公開' : '非公開'}
            </button>
        </td>
        <td class="px-4 md:px-4 py-2 md:py-3 text-sm md:text-base lg:text-lg whitespace-nowrap overflow-hidden text-ellipsis" data-label="削除">
            <button type="button" data-action="delete" class="action-button-mobile p-1 md:p-2 admin-delete-btn rounded-lg transition-all duration-200" title="削除">
                <svg class="w-4 h-4 md:w-6 md:h-6 lg:w-7 lg:h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </td>
    `;

    row.querySelector('[data-action="edit"]').addEventListener('click', () => toggleEditMode(videoId));
    row.querySelectorAll('[data-action="play"]').forEach(el => {
        el.addEventListener('click', () => playVideo(video.filename));
    });
    row.querySelector('[data-action="convert"]').addEventListener('click', () => startVideoConversion(video.filename, title));
    row.querySelector('[data-action="visibility"]').addEventListener('click', () => toggleVideoVisibility(video.filename, title));
    row.querySelector('[data-action="delete"]').addEventListener('click', () => deleteVideo(video.filename, title));

    return row;
}

// 編集行の作成（モーダル版では使用しないが、互換性のため残す）
function createEditRow(video, videoId) {
    // モーダル版では使用しないため、空の要素を返す
    const row = document.createElement('tr');
    row.id = `edit-${videoId}`;
    row.style.display = 'none';
    return row;
}

// ファイルサイズのフォーマット
function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    bytes = Math.max(bytes, 0);
    const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
    const powIndex = Math.min(pow, units.length - 1);
    bytes /= Math.pow(1024, powIndex);
    return Math.round(bytes * 100) / 100 + ' ' + units[powIndex];
}

// 通知表示機能（積み重ね版）
function showNotification(message, type = 'info') {
    // 通知コンテナを取得または作成
    let notificationContainer = document.getElementById('notification-container');
    if (!notificationContainer) {
        notificationContainer = document.createElement('div');
        notificationContainer.id = 'notification-container';
        notificationContainer.className = 'fixed top-4 right-4 z-[10001] space-y-2';
        notificationContainer.style.zIndex = '10001';
        document.body.appendChild(notificationContainer);
    }
    
    // 新しい通知を作成
    const notification = document.createElement('div');
    notification.className = `notification px-4 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;
    
    // タイプに応じてスタイルを設定
    switch (type) {
        case 'success':
            notification.className += ' bg-green-500 text-white';
            break;
        case 'error':
            notification.className += ' bg-red-500 text-white';
            break;
        case 'warning':
            notification.className += ' bg-yellow-500 text-white';
            break;
        default:
            notification.className += ' bg-blue-500 text-white';
    }
    
    notification.textContent = message;
    
    // 通知コンテナに追加
    notificationContainer.appendChild(notification);
    
    // アニメーション表示
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    // 3秒後に自動削除
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
            // 通知コンテナが空になったら削除
            if (notificationContainer.children.length === 0) {
                notificationContainer.remove();
            }
        }, 300);
    }, 3000);
}

// DOM要素の存在確認と初期化
function initializeAdmin() {
    // 動画管理ページかどうかを判定
    const videoTableBody = document.getElementById('video-table-body');
    const isVideoAdminPage = !!videoTableBody;
    
    if (isVideoAdminPage) {
        // 動画管理ページの場合
        // 初期ローディングを表示
        showInitialLoading(true);
        
        // 統計情報と動画一覧を読み込み
        loadStats();
        loadVideoListSorted('new');
        initInfiniteScroll();
        return true;
    } else {
        // 設定ページなどの場合
        console.log('動画管理ページではありません。設定ページ用の初期化を実行します。');
        return true;
    }
}

// DOMContentLoadedイベントで初期化
document.addEventListener('DOMContentLoaded', function() {
    initializeAdmin();
});

// window.onloadイベントでも初期化（バックアップ）
window.addEventListener('load', function() {
    initializeAdmin();
}); 



// 動画の公開/非公開切り替え
async function toggleVideoVisibility(videoFile, videoTitle) {
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('admin_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=toggle_visibility&video=${encodeURIComponent(videoFile)}&csrf_token=${encodeURIComponent(csrf)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            showNotification(data.message, 'success');
            
            // ボタンの状態を更新
            const button = findVideoRow(videoFile)?.querySelector('[data-action="visibility"]');
            if (button) {
                const isPublic = data.is_public;
                button.textContent = isPublic ? '公開' : '非公開';
                button.className = `px-3 py-1 ${isPublic ? 'admin-public-btn' : 'admin-private-btn'} text-xs rounded transition-colors duration-200`;
                button.title = isPublic ? '非公開にする' : '公開する';
                
                // data属性も更新
                const row = button.closest('tr');
                if (row) {
                    row.setAttribute('data-is-public', isPublic.toString());
                }
            }
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('公開状態の更新に失敗しました', 'error');
    }
}

// サムネイルアップロード（長辺1000pxにサーバ側で縮小・JPEG化）
async function uploadThumbnail(videoFile, basename) {
    const safeId = videoFile.replace(/[^a-zA-Z0-9]/g, '_');
    const input = document.querySelector(`#thumbnail-file-${safeId}`);
    if (!input || !input.files || input.files.length === 0) {
        showNotification('画像ファイルを選択してください', 'error');
        return;
    }
    const file = input.files[0];
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
        showNotification('JPEG/PNG/WebP のみアップロードできます', 'error');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        showNotification('ファイルサイズが大きすぎます（最大5MB）', 'error');
        return;
    }

    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const form = new FormData();
        form.append('action', 'upload_thumbnail');
        form.append('video', videoFile);
        form.append('csrf_token', csrf);
        form.append('thumbnail', file);

        const res = await fetch('admin_api.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) {
            showNotification('サムネイルを更新しました', 'success');
            // プレビュー差し替え（キャッシュバスター付きURL）
            const thumbPath = CSS.escape(`thumbnails/${basename}.jpg`);
            document.querySelectorAll(`img[src^="${thumbPath}"], img[src*="${thumbPath}"]`).forEach(img => {
                img.src = data.thumbnail_url;
            });
            // モーダル内のプレビューも更新（hiddenのinput連動のため再描画）
            const modalImg = document.querySelector('#edit-modal img[src*="thumbnails/"], #edit-modal img[alt="サムネイル"]');
            if (modalImg) modalImg.src = data.thumbnail_url;
            // 一覧行のデータ属性も更新（次回モーダル再表示時もv=を維持）
            const rows = document.querySelectorAll(`tr#view-${safeId}`);
            rows.forEach(row => {
                row.setAttribute('data-thumb-url', data.thumbnail_url);
                row.setAttribute('data-has-thumbnail', 'true');
            });
        } else {
            showNotification(data.message || 'アップロードに失敗しました', 'error');
        }
    } catch (e) {
        showNotification('サムネイルのアップロードに失敗しました', 'error');
    }
}