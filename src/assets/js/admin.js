// 動画の管理ページ（admin.php）の動き
// 統計・動画の一覧（並べ替え・続きの読み込み）・編集・公開切り替え・変換・削除・サムネイルの差し替え

// HTML に埋め込む値のエスケープ（タイトル等に記号が含まれても表示・動作が崩れないように）
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function postAdmin(params) {
    const body = new URLSearchParams({ ...params, csrf_token: csrfToken() });
    const response = await fetch('admin_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    });
    return response.json();
}

// 動画一覧の行を取得（ファイル名はセレクタ用にエスケープ）
function findVideoRow(videoFile) {
    return document.querySelector(`.vrow[data-filename="${CSS.escape(videoFile)}"]`);
}

const ICONS = {
    edit: '<path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
    convert: '<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>',
    delete: '<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
    public: '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/>',
    private: '<rect x="5" y="11" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V7a4 4 0 018 0v4"/>'
};
const svg = name => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${ICONS[name]}</svg>`;

function visibilityButtonHtml(isPublic) {
    return `<button type="button" data-action="visibility" class="visibility-btn ${isPublic ? '' : 'is-private'}" title="${isPublic ? '非公開にする' : '公開する'}">${svg(isPublic ? 'public' : 'private')}<span>${isPublic ? '公開' : '非公開'}</span></button>`;
}

// ===== 動画の行 =====
function createVideoRow(video, videoId) {
    const row = document.createElement('div');
    row.id = `view-${videoId}`;
    row.className = 'vrow';
    const isPublic = video.is_public !== false;
    const title = video.title || 'タイトルなし';
    const comment = video.comment || '';
    const extension = video.filename.split('.').pop().toUpperCase();
    const isMp4 = extension === 'MP4';
    const thumbSrc = video.has_thumbnail ? (video.thumb_url || `thumbnails/${video.basename}.jpg`) : 'images/default-thumbnail-small.svg';
    const date = (video.upload_date || '').split(' ')[0];
    const duration = video.duration ? formatDurationText(video.duration) : '';

    // 編集ダイアログで使う値は data 属性に持たせる
    Object.entries({
        filename: video.filename, title: video.title || '', comment, views: String(video.views), likes: String(video.likes),
        'file-size': video.file_size, 'upload-date': video.upload_date, basename: video.basename,
        'has-thumbnail': String(video.has_thumbnail), 'thumb-url': video.thumb_url || '', 'is-public': String(isPublic)
    }).forEach(([key, value]) => row.setAttribute(`data-${key}`, value));

    row.innerHTML = `
        <div class="vrow__video">
            <div class="vrow__thumb" data-action="play" title="動画ページで再生">
                <img class="thumb-bg" src="${escapeHtml(thumbSrc)}" alt="" loading="lazy">
                <img class="thumb-img" src="${escapeHtml(thumbSrc)}" alt="" loading="lazy">
                ${duration ? `<span class="duration-badge">${escapeHtml(duration)}</span>` : ''}
            </div>
            <div class="vrow__text">
                <button type="button" class="vrow__title" data-action="play" title="${escapeHtml(title)}">${escapeHtml(title)}</button>
                <div class="vrow__sub" title="${escapeHtml(comment)}">${escapeHtml(extension)} ・ ${escapeHtml(video.file_size)}${comment ? ' ・ ' + escapeHtml(comment) : ''}</div>
                <div class="vrow__meta-sp">${isPublic ? '公開' : '非公開'} ・ ${escapeHtml(date)} ・ 再生 ${escapeHtml(video.views.toLocaleString())} ・ いいね ${escapeHtml(video.likes.toLocaleString())}</div>
            </div>
        </div>
        <div class="vrow__col-visibility">${visibilityButtonHtml(isPublic)}</div>
        <div class="vrow__date">${escapeHtml(date)}</div>
        <div class="vrow__num">${escapeHtml(video.views.toLocaleString())}</div>
        <div class="vrow__num">${escapeHtml(video.likes.toLocaleString())}</div>
        <div class="vrow__actions">
            ${visibilityButtonHtml(isPublic)}
            <button type="button" class="icon-btn" data-action="edit" title="編集" aria-label="編集">${svg('edit')}</button>
            <button type="button" class="icon-btn" data-action="convert" id="conversion-btn-${videoId}" title="${isMp4 ? '再エンコード（MP4を作り直す）' : 'MP4に変換'}" aria-label="${isMp4 ? '再エンコード' : 'MP4に変換'}">${svg('convert')}</button>
            <button type="button" class="icon-btn" data-action="delete" title="削除" aria-label="削除">${svg('delete')}</button>
        </div>`;

    row.querySelectorAll('[data-action="play"]').forEach(el => el.addEventListener('click', () => playVideo(video.filename)));
    row.querySelectorAll('[data-action="visibility"]').forEach(el => el.addEventListener('click', () => toggleVideoVisibility(video.filename)));
    row.querySelector('[data-action="edit"]').addEventListener('click', () => toggleEditMode(videoId));
    row.querySelector('[data-action="convert"]').addEventListener('click', () => startVideoConversion(video.filename, title));
    row.querySelector('[data-action="delete"]').addEventListener('click', () => deleteVideo(video.filename, title));
    return row;
}

function formatDurationText(seconds) {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(total / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

// ===== 統計 =====
async function loadStats() {
    try {
        const data = await (await fetch('admin_api.php?action=get_stats')).json();
        if (!data.success) return;
        const stats = data.stats;
        document.getElementById('total-videos').textContent = stats.total_videos.toLocaleString();
        document.getElementById('total-views').textContent = stats.total_views.toLocaleString();
        document.getElementById('total-likes').textContent = stats.total_likes.toLocaleString();
        document.getElementById('total-size').textContent = formatFileSize(stats.total_size);
        document.getElementById('average-views').textContent = stats.average_views;
        document.getElementById('average-likes').textContent = stats.average_likes;
    } catch (error) {
        console.error('統計情報の読み込みに失敗しました:', error);
    }
}

// ===== 一覧（並べ替え・続きの読み込み） =====
let currentSort = 'new';
let currentPage = 0;
let isLoading = false;
let hasMorePages = true;
const videosPerPage = 20;

function setStatus(id, show) {
    document.getElementById(id)?.classList.toggle('hidden', !show);
}

async function loadVideoPage(reset = false) {
    if (isLoading || (!reset && !hasMorePages)) return;
    isLoading = true;
    const body = document.getElementById('video-table-body');
    if (reset) {
        currentPage = 0;
        hasMorePages = true;
        body.innerHTML = '';
        setStatus('initial-loading', true);
        setStatus('all-loaded-message', false);
    } else {
        setStatus('infinite-loading', true);
    }
    try {
        const data = await (await fetch(`admin_api.php?action=get_admin_videos_paginated&sort=${encodeURIComponent(currentSort)}&page=${currentPage + 1}&per_page=${videosPerPage}`)).json();
        if (data.success) {
            data.videos.forEach(video => body.appendChild(createVideoRow(video, video.filename.replace(/[^a-zA-Z0-9]/g, '_'))));
            currentPage = data.pagination.current_page;
            hasMorePages = data.pagination.has_next_page;
            setStatus('all-loaded-message', !hasMorePages && body.children.length > 0);
        }
    } catch (error) {
        console.error('動画一覧の読み込みに失敗しました:', error);
    } finally {
        isLoading = false;
        setStatus('initial-loading', false);
        setStatus('infinite-loading', false);
        // 画面が埋まらずスクロールできないときは続きを読み込む
        if (hasMorePages && document.documentElement.scrollHeight - window.innerHeight < 400) loadVideoPage();
    }
}

function changeSort(sort) {
    if (currentSort === sort) return;
    currentSort = sort;
    document.querySelectorAll('.chips .chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.sort === sort);
        chip.setAttribute('aria-pressed', chip.dataset.sort === sort ? 'true' : 'false');
    });
    loadVideoPage(true);
}

function initInfiniteScroll() {
    window.addEventListener('scroll', () => {
        if (document.documentElement.scrollHeight - (window.scrollY + window.innerHeight) < 400) loadVideoPage();
    }, { passive: true });
}

// ===== 操作 =====
function playVideo(videoFilename) {
    window.open(`index.php?v=${encodeURIComponent(videoFilename)}`, '_blank');
}

async function deleteVideo(videoFile, videoTitle) {
    if (!confirm(`「${videoTitle}」を削除しますか？\nこの操作は取り消せません。`)) return;
    try {
        const data = await postAdmin({ action: 'delete_video', video: videoFile });
        showNotification(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            findVideoRow(videoFile)?.remove();
            loadStats();
        }
    } catch (error) {
        showNotification('削除に失敗しました', 'error');
    }
}

async function toggleVideoVisibility(videoFile) {
    try {
        const data = await postAdmin({ action: 'toggle_visibility', video: videoFile });
        if (!data.success) {
            showNotification(data.message, 'error');
            return;
        }
        showNotification(data.message, 'success');
        const row = findVideoRow(videoFile);
        if (row) {
            const isPublic = !!data.is_public;
            row.setAttribute('data-is-public', String(isPublic));
            row.querySelectorAll('[data-action="visibility"]').forEach(button => {
                const replacement = document.createElement('div');
                replacement.innerHTML = visibilityButtonHtml(isPublic);
                const newButton = replacement.firstElementChild;
                newButton.addEventListener('click', () => toggleVideoVisibility(videoFile));
                button.replaceWith(newButton);
            });
            const meta = row.querySelector('.vrow__meta-sp');
            if (meta) meta.textContent = meta.textContent.replace(/^(公開|非公開)/, isPublic ? '公開' : '非公開');
        }
    } catch (error) {
        showNotification('公開状態の更新に失敗しました', 'error');
    }
}

// ===== 変換 =====
async function startVideoConversion(videoFile, videoTitle) {
    const isMp4 = videoFile.split('.').pop().toLowerCase() === 'mp4';
    if (!confirm(`「${videoTitle}」を${isMp4 ? '再エンコード' : 'MP4に変換'}しますか？\n長い動画はサーバーの過負荷により失敗する可能性があります。`)) return;
    try {
        const data = await postAdmin({ action: 'start_conversion', video: videoFile });
        if (data.success) {
            showNotification(data.message, 'success');
            showConversionModal(videoFile, videoTitle);
            startConversionProgressMonitoring(videoFile);
        } else {
            showNotification(data.message, 'error');
        }
    } catch (error) {
        showNotification('変換開始に失敗しました', 'error');
    }
}

function showConversionModal(videoFile, videoTitle) {
    document.getElementById('conversion-title').textContent = videoTitle;
    document.getElementById('conversion-filename').textContent = videoFile;
    document.getElementById('conversion-format').textContent = videoFile.split('.').pop().toUpperCase();
    const row = findVideoRow(videoFile);
    document.getElementById('conversion-thumbnail').src = row?.querySelector('.vrow__thumb .thumb-img')?.src || 'images/default-thumbnail-small.svg';
    document.getElementById('conversion-progress-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    window.conversionStartTime = Date.now();
}

function startConversionProgressMonitoring(videoFile) {
    const finish = (message, type, reload) => {
        clearInterval(window.conversionProgressInterval);
        showNotification(message, type);
        setTimeout(() => { closeConversionModal(); if (reload) loadVideoPage(true); }, 2000);
    };
    window.conversionProgressInterval = setInterval(async () => {
        try {
            const data = await (await fetch(`admin_api.php?action=get_conversion_progress&video=${encodeURIComponent(videoFile)}`)).json();
            if (!data.success) return finish(data.message, 'error', false);
            updateConversionProgress(data.progress, data.message);
            if (data.status === 'completed') finish('変換が完了しました', 'success', true);
            else if (data.status === 'failed') finish('変換に失敗しました', 'error', false);
        } catch (error) {
            finish('進捗取得に失敗しました', 'error', false);
        }
    }, 1000);
}

function updateConversionProgress(progress, message) {
    document.getElementById('conversion-progress-bar').style.width = `${progress}%`;
    document.getElementById('conversion-percentage').textContent = `${progress}%`;
    document.getElementById('conversion-status').textContent = message;
    if (window.conversionStartTime) {
        const elapsed = Math.floor((Date.now() - window.conversionStartTime) / 1000);
        document.getElementById('conversion-time').textContent = `${Math.floor(elapsed / 60)}:${String(elapsed % 60).padStart(2, '0')}`;
    }
}

async function cancelConversion() {
    if (!confirm('変換を止めますか？\n変換中のプロセスが停止されます。')) return;
    try {
        const data = await postAdmin({ action: 'stop_conversion', video: document.getElementById('conversion-filename').textContent });
        showNotification(data.success ? '変換を止めました' : data.message, data.success ? 'success' : 'error');
    } catch (error) {
        showNotification('変換を止められませんでした', 'error');
    }
    closeConversionModal();
}

function closeConversionModal() {
    if (window.conversionProgressInterval) {
        clearInterval(window.conversionProgressInterval);
        window.conversionProgressInterval = null;
    }
    document.getElementById('conversion-progress-modal').classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('conversion-progress-bar').style.width = '0%';
    document.getElementById('conversion-percentage').textContent = '0%';
    document.getElementById('conversion-status').textContent = '変換を開始しています...';
    document.getElementById('conversion-time').textContent = '-';
    window.conversionStartTime = null;
}

// ===== 編集ダイアログ =====
function toggleEditMode(videoId) {
    const row = document.getElementById(`view-${videoId}`);
    if (!row) return;
    showEditModal({
        filename: row.dataset.filename,
        title: row.dataset.title,
        comment: row.dataset.comment,
        basename: row.dataset.basename,
        has_thumbnail: row.dataset.hasThumbnail === 'true',
        thumb_url: row.dataset.thumbUrl || ''
    });
}

function showEditModal(video) {
    document.getElementById('edit-modal')?.remove();
    const safeVideoId = video.filename.replace(/[^a-zA-Z0-9]/g, '_');
    const thumb = video.has_thumbnail ? (video.thumb_url || `thumbnails/${video.basename}.jpg`) : 'images/default-thumbnail-small.svg';
    document.body.insertAdjacentHTML('beforeend', `
        <div id="edit-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
            <div class="modal__panel">
                <div class="modal__head">
                    <h2 class="modal__title" id="edit-modal-title">動画の詳細</h2>
                    <button type="button" class="icon-btn" data-action="close" aria-label="閉じる">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal__body">
                    <div class="field">
                        <label class="field__label" for="modal-title-${safeVideoId}">タイトル</label>
                        <input type="text" id="modal-title-${safeVideoId}" class="input" placeholder="タイトルを入力">
                    </div>
                    <div class="field">
                        <label class="field__label" for="modal-comment-${safeVideoId}">説明</label>
                        <textarea id="modal-comment-${safeVideoId}" class="textarea" rows="5" placeholder="動画の説明を入力"></textarea>
                    </div>
                    <div class="field">
                        <span class="field__label">サムネイル</span>
                        <div class="edit-thumb" data-action="pick-thumbnail" title="クリックしてサムネイルを差し替え">
                            <img class="thumb-bg" src="${escapeHtml(thumb)}" alt="">
                            <img class="thumb-img" src="${escapeHtml(thumb)}" alt="">
                            <span class="edit-thumb__label">クリックして変更</span>
                        </div>
                        <input type="file" id="thumbnail-file-${safeVideoId}" accept="image/jpeg,image/png,image/webp" class="hidden">
                        <div class="edit-thumb-foot">
                            <span class="field__help">画像をクリックで差し替え（JPEG / PNG / WebP・最大5MB）</span>
                            <button type="button" class="btn btn--ghost" data-action="regen-thumbnail">動画から作り直す</button>
                        </div>
                    </div>
                </div>
                <div class="modal__foot">
                    <button type="button" class="btn btn--ghost" data-action="close">キャンセル</button>
                    <button type="button" class="btn btn--primary" id="modal-update-btn">保存</button>
                </div>
            </div>
        </div>`);

    const modal = document.getElementById('edit-modal');
    const thumbnailInput = document.getElementById(`thumbnail-file-${safeVideoId}`);
    modal.querySelectorAll('[data-action="close"]').forEach(el => el.addEventListener('click', closeEditModal));
    modal.querySelector('[data-action="pick-thumbnail"]').addEventListener('click', () => thumbnailInput.click());
    thumbnailInput.addEventListener('change', () => uploadThumbnail(video.filename, video.basename));
    modal.querySelector('[data-action="regen-thumbnail"]').addEventListener('click', () => regenerateThumbnail(video.filename));
    document.getElementById('modal-update-btn').addEventListener('click', () => updateMetadataFromModal(video.filename));
    modal.addEventListener('click', e => { if (e.target === modal) closeEditModal(); });
    document.addEventListener('keydown', handleModalKeydown);

    document.getElementById(`modal-title-${safeVideoId}`).value = video.title === 'タイトルなし' ? '' : (video.title || '');
    document.getElementById(`modal-comment-${safeVideoId}`).value = video.comment || '';
    document.getElementById(`modal-title-${safeVideoId}`).focus();
}

function closeEditModal() {
    document.getElementById('edit-modal')?.remove();
    document.removeEventListener('keydown', handleModalKeydown);
}

function handleModalKeydown(event) {
    if (event.key === 'Escape') closeEditModal();
}

async function updateMetadataFromModal(videoFile) {
    const safeVideoId = videoFile.replace(/[^a-zA-Z0-9]/g, '_');
    const titleInput = document.getElementById(`modal-title-${safeVideoId}`);
    const commentInput = document.getElementById(`modal-comment-${safeVideoId}`);
    const button = document.getElementById('modal-update-btn');
    if (!titleInput || !commentInput) return;
    button.disabled = true;
    button.textContent = '保存中...';
    try {
        const data = await postAdmin({ action: 'update_metadata', video: videoFile, title: titleInput.value.trim(), comment: commentInput.value.trim() });
        showNotification(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            closeEditModal();
            loadVideoPage(true);
        }
    } catch (error) {
        showNotification('更新に失敗しました', 'error');
    } finally {
        if (document.body.contains(button)) {
            button.disabled = false;
            button.textContent = '保存';
        }
    }
}

// サムネイルの差し替え（長辺1000pxにサーバ側で縮小・JPEG化）
async function uploadThumbnail(videoFile, basename) {
    const safeId = videoFile.replace(/[^a-zA-Z0-9]/g, '_');
    const input = document.getElementById(`thumbnail-file-${safeId}`);
    const file = input?.files?.[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        showNotification('JPEG/PNG/WebP のみアップロードできます', 'error');
        return;
    }
    if (file.size > 5 * 1024 * 1024) {
        showNotification('ファイルサイズが大きすぎます（最大5MB）', 'error');
        return;
    }
    try {
        const form = new FormData();
        form.append('action', 'upload_thumbnail');
        form.append('video', videoFile);
        form.append('csrf_token', csrfToken());
        form.append('thumbnail', file);
        const data = await (await fetch('admin_api.php', { method: 'POST', body: form })).json();
        if (!data.success) {
            showNotification(data.message || 'アップロードに失敗しました', 'error');
            return;
        }
        showNotification('サムネイルを更新しました', 'success');
        applyThumbnailUrl(videoFile, data.thumbnail_url);
    } catch (e) {
        showNotification('サムネイルのアップロードに失敗しました', 'error');
    }
}

// サムネイルを動画から自動で作り直す（差し替えた画像を元に戻すときなど）
async function regenerateThumbnail(videoFile) {
    if (!confirm('今のサムネイルを、動画から自動で作ったものに置き換えます。よろしいですか？')) return;
    const button = document.querySelector('#edit-modal [data-action="regen-thumbnail"]');
    if (button) { button.disabled = true; button.textContent = '作り直し中...'; }
    try {
        const data = await postAdmin({ action: 'regenerate_thumbnail', video: videoFile });
        showNotification(data.message || (data.success ? 'サムネイルを作り直しました' : 'サムネイルを作り直せませんでした'), data.success ? 'success' : 'error');
        if (data.success) applyThumbnailUrl(videoFile, data.thumbnail_url);
    } catch (e) {
        showNotification('サムネイルを作り直せませんでした', 'error');
    } finally {
        if (button && document.body.contains(button)) { button.disabled = false; button.textContent = '動画から作り直す'; }
    }
}

// 新しいサムネイルを、編集ダイアログと一覧の行に反映する
function applyThumbnailUrl(videoFile, url) {
    document.querySelectorAll('#edit-modal .edit-thumb img').forEach(img => img.setAttribute('src', url));
    const row = document.getElementById(`view-${videoFile.replace(/[^a-zA-Z0-9]/g, '_')}`);
    if (row) {
        row.querySelectorAll('.vrow__thumb img').forEach(img => img.setAttribute('src', url));
        row.setAttribute('data-thumb-url', url);
        row.setAttribute('data-has-thumbnail', 'true');
    }
}

// ===== 共有中のリンク =====
// 残り時間（「3時間12分」「45分」）
function formatRemaining(seconds) {
    const minutes = Math.max(1, Math.ceil(seconds / 60));
    const h = Math.floor(minutes / 60), m = minutes % 60;
    return h > 0 ? `${h}時間${m > 0 ? m + '分' : ''}` : `${m}分`;
}

async function loadShareLinks() {
    const section = document.getElementById('share-links');
    const list = document.getElementById('share-links-list');
    if (!section || !list) return;
    try {
        const data = await (await fetch('admin_api.php?action=list_share_links')).json();
        const links = data.success ? data.links : [];
        const loadedAt = Date.now();
        list.innerHTML = links.map(link => `
            <div class="share-link" data-video="${escapeHtml(link.video)}" data-url="${escapeHtml(link.url)}" data-expires-at="${loadedAt + link.remaining * 1000}">
                <img class="share-link__thumb" src="${escapeHtml(link.thumb)}" alt="" loading="lazy">
                <div class="share-link__text">
                    <div class="share-link__title">${escapeHtml(link.title || 'タイトルなし')}</div>
                    <div class="share-link__meta" title="${escapeHtml(link.expires)} まで">残り <span class="share-link__remaining">${formatRemaining(link.remaining)}</span></div>
                </div>
                <div class="share-link__actions">
                    <button type="button" class="btn" data-action="copy-share">コピー</button>
                    <button type="button" class="btn btn--ghost" data-action="revoke-share">無効にする</button>
                </div>
            </div>`).join('');
        section.classList.toggle('hidden', links.length === 0);
    } catch (e) {
        section.classList.add('hidden');
    }
}

// 残り時間を1分ごとに更新し、期限が来たものは消す
function tickShareLinks() {
    document.querySelectorAll('.share-link').forEach(row => {
        const remaining = (Number(row.dataset.expiresAt) - Date.now()) / 1000;
        if (remaining <= 0) row.remove();
        else row.querySelector('.share-link__remaining').textContent = formatRemaining(remaining);
    });
    if (!document.querySelector('.share-link')) document.getElementById('share-links')?.classList.add('hidden');
}

async function revokeShareLink(row) {
    const title = row.querySelector('.share-link__title').textContent;
    if (!confirm(`「${title}」の共有リンクを無効にしますか？
渡したリンクはすぐに使えなくなります。`)) return;
    try {
        const data = await postAdmin({ action: 'revoke_share_link', video: row.dataset.video });
        showNotification(data.message || (data.success ? '共有リンクを無効にしました' : '共有リンクを無効にできませんでした'), data.success ? 'success' : 'error');
        if (data.success) { row.remove(); tickShareLinks(); }
    } catch (e) {
        showNotification('共有リンクを無効にできませんでした', 'error');
    }
}

function initShareLinks() {
    const list = document.getElementById('share-links-list');
    if (!list) return;
    list.addEventListener('click', e => {
        const button = e.target.closest('button[data-action]');
        const row = button?.closest('.share-link');
        if (!row) return;
        if (button.dataset.action === 'copy-share') copyToClipboard(row.dataset.url);
        else if (button.dataset.action === 'revoke-share') revokeShareLink(row);
    });
    loadShareLinks();
    setInterval(tickShareLinks, 60 * 1000);
}

// ===== 初期化 =====
function initializeAdmin() {
    if (!document.getElementById('video-table-body')) return;
    loadStats();
    initShareLinks();
    loadVideoPage(true);
    initInfiniteScroll();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAdmin);
} else {
    initializeAdmin();
}

window.changeSort = changeSort;
window.closeConversionModal = closeConversionModal;
window.cancelConversion = cancelConversion;
