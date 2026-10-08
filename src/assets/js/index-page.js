// トップページ・動画ページの動き
// - 一覧: 並べ替え（チップ）・検索・続きの自動読み込み・途中まで見た動画の赤いバー
// - 動画ページ: 再生数・いいね・共有・続きから再生・次の動画の自動再生・説明の「もっと見る」

const LIST_LIMIT = 20;
const listState = { offset: 0, loading: false, allLoaded: false, sort: 'new', query: '' };
let currentVideo = '';
let hasCountedView = false;

function getCookieValue(name) {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) return parts.pop().split(';').shift();
  return null;
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\'': '&#39;', '"': '&quot;' }[ch]));
}

function formatDurationText(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(total / 3600), m = Math.floor((total % 3600) / 60), s = total % 60;
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

// ===== 途中まで見た位置の記録（このブラウザの localStorage にだけ保存） =====
const WatchProgress = {
  KEY: 'mytube_progress_v1',
  MAX_ENTRIES: 300,
  load() {
    try { return JSON.parse(localStorage.getItem(this.KEY) || '{}') || {}; } catch (_) { return {}; }
  },
  get(video) {
    const entry = this.load()[video];
    return Array.isArray(entry) ? { time: entry[0], duration: entry[1] } : null;
  },
  // 見た割合（0〜1）。記録がなければ 0
  ratio(video) {
    const p = this.get(video);
    return p && p.duration > 0 ? Math.min(1, p.time / p.duration) : 0;
  },
  save(video, time, duration) {
    if (!video || !(duration > 0)) return;
    try {
      const all = this.load();
      // 最後まで（残り15秒未満・95%以上）見たら「見終わった」として最後まで塗る
      const finished = duration - time < 15 || time / duration > 0.95;
      if (time < 5 && !all[video]) return;
      all[video] = [finished ? duration : Math.floor(time), Math.floor(duration), Date.now()];
      const keys = Object.keys(all);
      if (keys.length > this.MAX_ENTRIES) {
        keys.sort((a, b) => (all[a][2] || 0) - (all[b][2] || 0)).slice(0, keys.length - this.MAX_ENTRIES).forEach(k => delete all[k]);
      }
      localStorage.setItem(this.KEY, JSON.stringify(all));
    } catch (_) { /* 保存できない環境では何もしない */ }
  }
};

function progressBarHtml(video) {
  const ratio = WatchProgress.ratio(video);
  return ratio > 0 ? `<div class="watch-progress"><span style="width:${(ratio * 100).toFixed(1)}%"></span></div>` : '';
}

// サーバーが描いた「次の動画」のサムネイルにも赤いバーを付ける
function decorateRelatedProgress() {
  document.querySelectorAll('.related-item[data-video]').forEach(item => {
    const thumb = item.querySelector('.thumb');
    if (thumb && !thumb.querySelector('.watch-progress')) thumb.insertAdjacentHTML('beforeend', progressBarHtml(item.dataset.video));
  });
}

// ===== 一覧 =====
function renderVideoCard(video) {
  const title = escapeHtml(video.title || 'タイトルなし');
  const href = `?v=${encodeURIComponent(video.video)}`;
  const uploaded = video.upload_date || '';
  const duration = video.duration && video.duration !== '0:00' ? `<span class="duration-badge">${escapeHtml(video.duration)}</span>` : '';
  return `
    <div class="video-card">
      <a href="${href}" class="thumb" tabindex="-1" aria-hidden="true">
        <img class="thumb-bg" src="${escapeHtml(video.thumb)}" alt="" loading="lazy">
        <img class="thumb-img" src="${escapeHtml(video.thumb)}" alt="" loading="lazy">
        ${duration}
        ${progressBarHtml(video.video)}
      </a>
      <div class="video-card__body">
        <a href="${href}" class="video-card__title" title="${title}">${title}</a>
        <div class="video-card__meta">
          <span>${Number(video.views || 0).toLocaleString()}回視聴</span>
          <span class="video-card__likes" title="いいね"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>${Number(video.likes || 0).toLocaleString()}<span class="sr-only">いいね</span></span>
          ${uploaded ? `<span title="${escapeHtml(uploaded.split(' ')[0])}">${escapeHtml(formatRelativeTime(uploaded))}</span>` : ''}
        </div>
      </div>
    </div>`;
}

function setListStatus(text) {
  const el = document.getElementById('video-list-loading');
  if (el) el.textContent = text;
}

function loadVideos() {
  const list = document.getElementById('video-list');
  if (!list || listState.loading || listState.allLoaded) return;
  listState.loading = true;
  setListStatus('読み込み中...');
  const params = new URLSearchParams({ action: 'list_videos', offset: listState.offset, limit: LIST_LIMIT, sort: listState.sort });
  if (listState.query) params.set('q', listState.query);
  fetch(`./index.php?${params}`)
    .then(res => { if (!res.ok) throw new Error(`HTTP ${res.status}`); return res.json(); })
    .then(videos => {
      if (videos.length < LIST_LIMIT) listState.allLoaded = true;
      listState.offset += videos.length;
      list.insertAdjacentHTML('beforeend', videos.map(renderVideoCard).join(''));
      setListStatus(listState.allLoaded ? '' : '読み込み中...');
      listState.loading = false;
      if (!listState.allLoaded) requestAnimationFrame(fillScreenIfNeeded);
    })
    .catch(() => {
      listState.loading = false;
      setListStatus('読み込みに失敗しました');
    });
}

// 下までスクロールしたら続きを読み込む
function checkScrollPosition() {
  if (document.documentElement.scrollHeight - (window.scrollY + window.innerHeight) < 600) loadVideos();
}

// 一覧が画面の高さに足りずスクロールできないときは、続きを読み込む（大きなモニターで列が多い場合など）
function fillScreenIfNeeded() {
  if (document.documentElement.scrollHeight - window.innerHeight < 600) loadVideos();
}

function changeSort(sortType) {
  if (listState.sort === sortType) return;
  document.cookie = `sort_preference=${sortType}; path=/; max-age=${30 * 24 * 60 * 60}`;
  listState.sort = sortType;
  document.querySelectorAll('.chips-bar .chip').forEach(chip => {
    chip.classList.toggle('active', chip.dataset.sort === sortType);
    chip.setAttribute('aria-pressed', chip.dataset.sort === sortType ? 'true' : 'false');
  });
  const list = document.getElementById('video-list');
  if (!list) return;
  list.innerHTML = '';
  Object.assign(listState, { offset: 0, loading: false, allLoaded: false });
  loadVideos();
}

// ===== 動画ページ =====
function initVideoPlayer() {
  const player = document.getElementById('video-player');
  if (!player) return;
  currentVideo = document.querySelector('meta[name="current-video"]')?.content || '';
  hasCountedView = false;
  initLikeButtonState();

  // 再生数は、実際に再生が始まったときにページを開くごとに1回だけ数える
  // （'play' だと一時停止→再開・シーク（スマホでは一時停止→再開になる）・見直しのたびに数えていた。
  //   'playing' はブラウザに止められた自動再生では発生しないため、見ていないのに数えることもない）
  player.addEventListener('playing', () => {
    if (currentVideo && !hasCountedView) incrementViewCount(currentVideo);
  });

  // 続きから再生: 途中まで見ていたら、その位置から再生する
  const saved = WatchProgress.get(currentVideo);
  const resumeAt = saved && saved.time >= 5 && saved.time < saved.duration - 15 ? saved.time : 0;
  const applyResume = () => {
    if (resumeAt > 0 && resumeAt < player.duration - 5) {
      player.currentTime = resumeAt;
      showPlayerToast(`${formatDurationText(resumeAt)} から再生しています`, '最初から', () => { player.currentTime = 0; player.play().catch(() => {}); });
    }
  };
  if (player.readyState >= 1) applyResume(); else player.addEventListener('loadedmetadata', applyResume, { once: true });

  // 見た位置を記録する（5秒ごと・一時停止・ページを離れるとき）
  let lastSaved = 0;
  const saveProgress = () => WatchProgress.save(currentVideo, player.currentTime, player.duration);
  player.addEventListener('timeupdate', () => {
    if (Math.abs(player.currentTime - lastSaved) >= 5) { lastSaved = player.currentTime; saveProgress(); }
  });
  player.addEventListener('pause', saveProgress);
  window.addEventListener('pagehide', saveProgress);
  player.addEventListener('ended', () => { saveProgress(); startUpNext(); });

  // 自動再生（サイトの設定がオン、または「次の動画」から来たとき）
  const params = new URLSearchParams(location.search);
  const shouldAutoplay = params.get('autoplay') === '1' || (typeof window.autoplayEnabledSetting === 'boolean' ? window.autoplayEnabledSetting : true);
  if (shouldAutoplay) {
    // 音付きの自動再生をブラウザに止められたら、音を消して再生し「音を出す」ボタンを出す
    const tryPlay = () => {
      player.play().catch(() => {
        player.muted = true;
        player.play().then(() => showUnmuteButton(player)).catch(() => {});
      });
    };
    if (player.readyState >= 2) tryPlay(); else player.addEventListener('canplay', tryPlay, { once: true });
  }
}

function showPlayerToast(message, actionLabel, onAction) {
  const container = document.getElementById('watch-player');
  if (!container) return;
  container.querySelector('.player-toast')?.remove();
  const toast = document.createElement('div');
  toast.className = 'player-toast';
  toast.innerHTML = `<span>${escapeHtml(message)}</span>${actionLabel ? `<button type="button">${escapeHtml(actionLabel)}</button>` : ''}`;
  if (actionLabel) toast.querySelector('button').addEventListener('click', () => { onAction(); toast.remove(); });
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 8000);
}

// 音を消して自動再生したときの「音を出す」ボタン（プレイヤーの左上。音が出たら消える）
function showUnmuteButton(player) {
  const container = document.getElementById('watch-player');
  if (!container || container.querySelector('.unmute-btn')) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'unmute-btn';
  btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z"/></svg><span>音を出す</span>';
  btn.addEventListener('click', () => { player.muted = false; });
  const onVolume = () => {
    if (!player.muted) { btn.remove(); player.removeEventListener('volumechange', onVolume); }
  };
  player.addEventListener('volumechange', onVolume);
  container.appendChild(btn);
}

// ===== 次の動画の自動再生 =====
const AUTONEXT_KEY = 'mytube_autonext';
function isAutoNextOn() {
  try { return localStorage.getItem(AUTONEXT_KEY) !== '0'; } catch (_) { return true; }
}
function initAutoNextToggle() {
  const toggle = document.getElementById('autoplay-next-toggle');
  if (!toggle) return;
  toggle.checked = isAutoNextOn();
  toggle.addEventListener('change', () => {
    try { localStorage.setItem(AUTONEXT_KEY, toggle.checked ? '1' : '0'); } catch (_) {}
  });
}
function startUpNext() {
  const next = document.querySelector('.related-item[data-video]');
  const container = document.getElementById('watch-player');
  if (!next || !container || !isAutoNextOn() || window.isSharedAccess) return;
  const title = next.querySelector('.related-item__title')?.textContent || '';
  const url = next.getAttribute('href') + '&autoplay=1';
  let remaining = 5;
  const overlay = document.createElement('div');
  overlay.className = 'upnext-overlay';
  overlay.innerHTML = `
    <div class="upnext-overlay__label">次の動画</div>
    <div class="upnext-overlay__title">${escapeHtml(title)}</div>
    <div class="upnext-overlay__count"><span>${remaining}</span> 秒後に再生します</div>
    <div class="upnext-overlay__buttons">
      <button type="button" class="upnext-overlay__cancel">キャンセル</button>
      <button type="button" class="upnext-overlay__play">今すぐ再生</button>
    </div>`;
  container.appendChild(overlay);
  const timer = setInterval(() => {
    remaining -= 1;
    overlay.querySelector('.upnext-overlay__count span').textContent = remaining;
    if (remaining <= 0) { clearInterval(timer); location.href = url; }
  }, 1000);
  overlay.querySelector('.upnext-overlay__cancel').addEventListener('click', () => { clearInterval(timer); overlay.remove(); });
  overlay.querySelector('.upnext-overlay__play').addEventListener('click', () => { clearInterval(timer); location.href = url; });
}

// ===== 説明の「もっと見る」 =====
function initDescription() {
  const box = document.getElementById('watch-desc');
  const text = document.getElementById('watch-desc-text');
  const toggle = document.getElementById('watch-desc-toggle');
  if (!box || !text || !toggle) return;
  box.classList.add('is-collapsible');
  // 3行に収まるなら折りたたまない
  if (text.scrollHeight <= text.clientHeight + 2) {
    box.classList.remove('is-collapsible');
    return;
  }
  toggle.classList.remove('hidden');
  const setOpen = open => {
    box.classList.toggle('is-open', open);
    toggle.textContent = open ? '一部を表示' : 'もっと見る';
  };
  box.addEventListener('click', e => {
    if (!box.classList.contains('is-open') && !e.target.closest('a')) setOpen(true);
  });
  toggle.addEventListener('click', e => {
    e.stopPropagation();
    setOpen(!box.classList.contains('is-open'));
  });
}

// ===== 再生数・いいね =====
function incrementViewCount(videoFile) {
  const uniqueCount = window.uniqueCountupSetting;
  const viewCountKey = `viewed_${videoFile}`;
  if (uniqueCount && sessionStorage.getItem(viewCountKey)) { hasCountedView = true; return; }
  hasCountedView = true; // 応答を待つ間に再生し直しても二重に送らない
  const formData = new FormData();
  formData.append('action', 'increment_view');
  formData.append('video_file', videoFile);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (csrf) formData.append('csrf_token', csrf);
  if (window.isSharedAccess && window.sharePassword) formData.append('share_password', window.sharePassword);
  fetch('./index.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const viewCountElement = document.getElementById('view-count');
        if (viewCountElement) viewCountElement.textContent = data.views.toLocaleString();
        if (uniqueCount) sessionStorage.setItem(viewCountKey, 'true'); // 制限 ON のときは同じタブの間は数えない
      } else {
        showNotification('再生数の更新に失敗しました', 'error');
      }
    })
    .catch(() => {});
}

function initLikeButtonState() {
  if (!window.uniqueLikeCountupSetting || !currentVideo) return;
  if (sessionStorage.getItem(`liked_${currentVideo}`)) document.getElementById('like-button')?.classList.add('liked');
}

function toggleLike(videoFile) {
  const uniqueLike = window.uniqueLikeCountupSetting;
  const likeCountKey = `liked_${videoFile}`;
  if (uniqueLike && sessionStorage.getItem(likeCountKey)) return;
  const likeButton = document.getElementById('like-button');
  const likeCountElement = document.getElementById('like-count');
  if (likeButton) {
    likeButton.disabled = true;
    likeButton.classList.remove('like-pop');
    void likeButton.offsetWidth; // アニメーションをやり直すため
    likeButton.classList.add('like-pop');
  }
  const formData = new FormData();
  formData.append('action', 'toggle_like');
  formData.append('video_file', videoFile);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (csrf) formData.append('csrf_token', csrf);
  if (window.isSharedAccess && window.sharePassword) formData.append('share_password', window.sharePassword);
  fetch('./index.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        if (uniqueLike) sessionStorage.setItem(likeCountKey, 'true');
        if (likeCountElement) likeCountElement.textContent = data.likes.toLocaleString();
        likeButton?.classList.add('liked');
      } else {
        showNotification(data.message || 'いいねの更新に失敗しました', 'error');
      }
    })
    .catch(() => showNotification('いいねの更新に失敗しました', 'error'))
    .finally(() => { if (likeButton) setTimeout(() => { likeButton.disabled = false; }, 300); });
}

// ===== 共有 =====
function shareText(title) {
  const appName = document.querySelector('meta[name="app-name"]')?.getAttribute('content') || 'MyTube';
  return title ? `${title} - ${appName}` : appName;
}

function shareVideo(videoFile, title) {
  const url = window.location.origin + window.location.pathname + '?v=' + encodeURIComponent(videoFile);
  copyToClipboard(url);
  if (navigator.share) navigator.share({ title: shareText(title), text: shareText(title), url }).catch(() => {});
}

function showShareLinkArea(url) {
  const area = document.getElementById('manual-copy-area');
  const urlEl = document.getElementById('manual-copy-url');
  if (area && urlEl) { urlEl.textContent = url; area.classList.remove('hidden'); }
}

async function generateShareLink(videoFile, title) {
  const fallbackUrl = window.location.origin + window.location.pathname + '?v=' + encodeURIComponent(videoFile);
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: `action=generate_share_link&video_file=${encodeURIComponent(videoFile)}&csrf_token=${encodeURIComponent(csrf)}`
    });
    const data = await response.json();
    if (!data.success) throw new Error('failed');
    const url = data.share_link;
    try { await copyToClipboard(url); } catch (_) {}
    showShareLinkArea(url); // 共有リンクは成功時でも常に表示
    if (navigator.share) navigator.share({ title: shareText(title), text: shareText(title), url }).catch(() => {});
    const isSecure = data.is_secure || false;
    showNotification(isSecure ? 'ワンタイムパスワード付きの共有リンクが生成されました' : '動画リンクが生成されました（認証が必要）', isSecure ? 'success' : 'info');
  } catch (_) {
    let copied = false;
    try { copied = await copyToClipboard(fallbackUrl); } catch (_) {}
    if (!copied && window.isIOS && window.isIOS()) showShareLinkArea(fallbackUrl);
    showNotification('共有リンクの生成に失敗しました。現在のページURLをコピーしました。', 'warning');
  }
}

function initShareButtons() {
  const shareButton = document.getElementById('share-button');
  if (shareButton) {
    shareButton.addEventListener('click', e => { e.preventDefault(); shareVideo(shareButton.dataset.video, shareButton.dataset.title); });
  }
  const shareLinkButton = document.getElementById('share-link-button');
  if (shareLinkButton) {
    shareLinkButton.addEventListener('click', e => { e.preventDefault(); generateShareLink(shareLinkButton.dataset.video, shareLinkButton.dataset.title); });
  }
  const copyButton = document.getElementById('manual-copy-copy');
  const urlEl = document.getElementById('manual-copy-url');
  if (copyButton && urlEl) {
    copyButton.addEventListener('click', e => {
      e.preventDefault();
      const text = urlEl.textContent || '';
      if (!text) return;
      const result = copyToClipboard(text);
      if (result && typeof result.then === 'function') result.then(ok => { if (!ok) showManualCopyNotification(text); });
    });
  }
  initShareLinkHelpPopover();
}

// 管理者のみ: 「共有リンクとは？」の説明（クリックで開閉）
function initShareLinkHelpPopover() {
  const helpBtn = document.getElementById('share-link-help');
  const pop = document.getElementById('share-link-popover');
  if (!helpBtn || !pop) return;
  let portal = null;
  const close = () => {
    portal?.remove();
    portal = null;
    helpBtn.setAttribute('aria-expanded', 'false');
  };
  const open = () => {
    portal = document.createElement('div');
    portal.className = pop.className.replace(/\bhidden\b/, '').trim();
    portal.innerHTML = pop.innerHTML;
    Object.assign(portal.style, { position: 'fixed', zIndex: '9999', maxWidth: 'min(18rem, calc(100vw - 1rem))' });
    document.body.appendChild(portal);
    const r = helpBtn.getBoundingClientRect();
    portal.style.top = `${Math.min(r.bottom + 8, window.innerHeight - portal.offsetHeight - 8)}px`;
    portal.style.left = `${Math.max(8, Math.min(window.innerWidth - portal.offsetWidth - 8, r.right - portal.offsetWidth))}px`;
    helpBtn.setAttribute('aria-expanded', 'true');
  };
  helpBtn.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); portal ? close() : open(); });
  document.addEventListener('click', e => { if (portal && !portal.contains(e.target)) close(); });
  window.addEventListener('scroll', close, { passive: true });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
}

// ===== 初期化 =====
document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('video-list');
  if (list) {
    const cookieSort = getCookieValue('sort_preference');
    if (['new', 'popular', 'views', 'likes'].includes(cookieSort)) listState.sort = cookieSort;
    listState.query = list.dataset.query || '';
    loadVideos();
    window.addEventListener('scroll', PerformanceUtils.debounce(checkScrollPosition, 100), { passive: true });
    window.addEventListener('resize', PerformanceUtils.debounce(fillScreenIfNeeded, 200), { passive: true });
  }
  initVideoPlayer();
  initAutoNextToggle();
  initDescription();
  decorateRelatedProgress();
  initShareButtons();
});

// 戻るボタンで一覧に戻ったとき（ページがキャッシュから表示される）にも赤いバーを最新にする
window.addEventListener('pageshow', e => {
  if (!e.persisted) return;
  document.querySelectorAll('.video-card').forEach(card => {
    const link = card.querySelector('a.thumb');
    const video = link ? new URLSearchParams(link.getAttribute('href').slice(1)).get('v') : '';
    if (!video) return;
    link.querySelector('.watch-progress')?.remove();
    link.insertAdjacentHTML('beforeend', progressBarHtml(video));
  });
  document.querySelectorAll('.related-item .watch-progress').forEach(el => el.remove());
  decorateRelatedProgress();
});

// 画像が読み込めないときは既定のサムネイルにする
document.addEventListener('error', e => {
  const img = e.target;
  if (img.tagName === 'IMG' && img.closest('.thumb') && !img.dataset.fallback) {
    img.dataset.fallback = '1';
    img.src = 'images/default-thumbnail.svg';
  }
}, true);

window.toggleLike = toggleLike;
window.incrementViewCount = incrementViewCount;
window.changeSort = changeSort;
window.shareVideo = shareVideo;
