// Index / watch page logic

// State
if (window.CocoState === undefined) {
  window.CocoState = {
    imageObserver: null,
    cardObserver: null,
    offset: 0,
    limit: 20,
    loading: false,
    allLoaded: false,
    scrollCheckInProgress: false,
    lastScrollTop: 0,
    scrollDirection: 'down',
    sort: 'new'
  };
}

let currentVideo = '';
let uniqueCountup = false;
let hasCountedView = false;
let imageObserver = window.CocoState.imageObserver;
let cardObserver = window.CocoState.cardObserver;
let offset = window.CocoState.offset;
const limit = window.CocoState.limit;
let loading = window.CocoState.loading;
let allLoaded = window.CocoState.allLoaded;
let scrollCheckInProgress = window.CocoState.scrollCheckInProgress;
let lastScrollTop = window.CocoState.lastScrollTop;
let scrollDirection = window.CocoState.scrollDirection;

function getCookieValue(name) {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) return parts.pop().split(';').shift();
  return null;
}

(function initSortFromCookie() {
  const cookieSort = getCookieValue('sort_preference');
  if (cookieSort && ['new', 'popular', 'views', 'likes'].includes(cookieSort)) {
    window.CocoState.sort = cookieSort;
  }
})();

function initLazyLoading() {
  if (imageObserver) imageObserver.disconnect();
  imageObserver = PerformanceUtils.createIntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const img = entry.target;
        if (img.dataset.src) {
          img.src = img.dataset.src;
          img.classList.add('loaded');
          img.removeAttribute('data-src');
          imageObserver.unobserve(img);
        }
      }
    });
  });
  document.querySelectorAll('img[src*="thumbnails/"]').forEach(img => {
    if (!img.dataset.src) {
      img.dataset.src = img.src;
      img.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIwIiBoZWlnaHQ9IjE4MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjMzc0MTUxIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzlDQTNBRiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkxvYWRpbmcuLi48L3RleHQ+PC9zdmc+';
      img.classList.add('lazy-image');
      imageObserver.observe(img);
    }
  });
}

function initCardAnimations() {
  if (cardObserver) cardObserver.disconnect();
  cardObserver = PerformanceUtils.createIntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const card = entry.target;
        card.style.opacity = '0';
        card.style.transform = 'translateY(10px)';
        requestAnimationFrame(() => {
          card.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out';
          card.style.opacity = '1';
          card.style.transform = 'translateY(0)';
        });
        cardObserver.unobserve(card);
      }
    });
  });
  document.querySelectorAll('.card-hover').forEach(card => { cardObserver.observe(card); });
}

function initImageErrorHandling() {
  document.querySelectorAll('img').forEach(img => {
    if (!img.hasAttribute('data-error-handled')) {
      img.setAttribute('data-error-handled', 'true');
      img.addEventListener('error', function() {
        if (this.src.includes('thumbnails/')) {
          const rect = this.getBoundingClientRect();
          if (rect.width <= 128 || rect.height <= 80) this.src = 'images/default-thumbnail-small.svg';
          else this.src = 'images/default-thumbnail.svg';
        } else {
          this.src = 'images/default-thumbnail.svg';
        }
      });
    }
  });
}

function initLikeButtonState() {
  const uniqueLikeCountup = window.uniqueLikeCountupSetting || false;
  if (uniqueLikeCountup && currentVideo) {
    const likeCountKey = `liked_${currentVideo}`;
    const isLiked = sessionStorage.getItem(likeCountKey);
    if (isLiked) {
      const likeButton = document.getElementById('like-button');
      const likeIcon = document.getElementById('like-icon');
      if (likeButton && likeIcon) {
        likeButton.classList.remove('bg-white/10');
        likeButton.classList.add('bg-red-500/20', 'text-red-400', 'liked');
        likeIcon.classList.remove('fill-none');
        likeIcon.classList.add('fill-current');
      }
    }
  }
}

function initVideoPlayer() {
  const videoPlayer = document.getElementById('video-player');
  if (!videoPlayer) return;
  currentVideo = document.querySelector('meta[name="current-video"]')?.content || '';
  uniqueCountup = window.uniqueCountupSetting || false;
  hasCountedView = false;
  initLikeButtonState();
  function attemptAutoplay() {
    const shouldAutoplay = (typeof window.autoplayEnabledSetting === 'boolean') ? window.autoplayEnabledSetting : true;
    if (!shouldAutoplay) return;
    if (videoPlayer.readyState >= 2) {
      const playPromise = videoPlayer.play();
      if (playPromise !== undefined) {
        playPromise.catch(() => {
          videoPlayer.muted = true;
          videoPlayer.play().catch(() => {});
        });
      }
    }
  }
  // 再生数は、実際に再生が始まったときにページを開くごとに1回だけ数える
  // （'play' だと一時停止→再開・シーク（スマホでは一時停止→再開になる）・見直しのたびに数えていた。
  //   'playing' はブラウザに止められた自動再生では発生しないため、見ていないのに数えることもない）
  videoPlayer.addEventListener('playing', function() {
    if (currentVideo && !hasCountedView) incrementViewCount(currentVideo);
  });
  setTimeout(attemptAutoplay, 100);
}

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
        updateSidebarViewCount(videoFile, data.views);
        if (uniqueCount) sessionStorage.setItem(viewCountKey, 'true'); // 制限 ON のときは同じタブの間は数えない
      } else {
        showNotification('再生数の更新に失敗しました', 'error');
      }
    })
    .catch(() => {});
}

function updateSidebarViewCount(videoFile, newCount) {
  const sidebarCards = document.querySelectorAll('.sidebar .card-hover');
  sidebarCards.forEach(card => {
    const link = card.querySelector('a');
    if (link && link.href.includes('v=' + encodeURIComponent(videoFile))) {
      const viewCountElement = card.querySelector('.video-count-info');
      if (viewCountElement) viewCountElement.textContent = newCount.toLocaleString();
    }
  });
}

function toggleLike(videoFile) {
  const uniqueLike = window.uniqueLikeCountupSetting;
  const likeCountKey = `liked_${videoFile}`;
  if (uniqueLike && sessionStorage.getItem(likeCountKey)) return;
  const formData = new FormData();
  formData.append('action', 'toggle_like');
  formData.append('video_file', videoFile);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  if (csrf) formData.append('csrf_token', csrf);
  if (window.isSharedAccess && window.sharePassword) formData.append('share_password', window.sharePassword);
  const likeButton = document.getElementById('like-button');
  const likeIcon = document.getElementById('like-icon');
  const likeCountElement = document.getElementById('like-count');
  if (likeButton) { likeButton.disabled = true; likeButton.style.pointerEvents = 'none'; likeButton.classList.remove('animate-bounce', 'animate-pulse', 'liked'); }
  if (likeButton && likeIcon) {
    likeButton.classList.add('animate-bounce');
    const handleAnimationEnd = () => { likeButton.classList.remove('animate-bounce'); likeButton.removeEventListener('animationend', handleAnimationEnd); };
    likeButton.addEventListener('animationend', handleAnimationEnd);
    createRippleEffect(likeButton);
    createFloatingHearts(likeButton);
  }
  fetch('./index.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        if (uniqueLike) sessionStorage.setItem(likeCountKey, 'true');
        if (likeCountElement) {
          likeCountElement.textContent = data.likes.toLocaleString();
          likeCountElement.classList.add('like-count-animate');
          setTimeout(() => { likeCountElement.classList.remove('like-count-animate'); }, 500);
        }
        if (likeButton && likeIcon) {
          likeButton.classList.remove('bg-white/10');
          likeButton.classList.add('bg-red-500/20', 'text-red-400', 'liked');
          likeIcon.classList.remove('fill-none');
          likeIcon.classList.add('fill-current');
          likeButton.classList.remove('animate-bounce');
          likeButton.classList.add('animate-pulse');
          const handlePulseAnimationEnd = () => { likeButton.classList.remove('animate-pulse'); likeButton.removeEventListener('animationend', handlePulseAnimationEnd); };
          likeButton.addEventListener('animationend', handlePulseAnimationEnd);
          setTimeout(() => { if (likeButton.classList.contains('animate-pulse')) likeButton.classList.remove('animate-pulse'); }, 1500);
        }
        updateSidebarLikeCount(videoFile, data.likes);
        if (uniqueLike) sessionStorage.setItem(likeCountKey, 'true');
      } else {
        showNotification(data.message || 'いいねの更新に失敗しました', 'error');
        if (likeButton) likeButton.classList.remove('animate-bounce', 'animate-pulse');
      }
    })
    .catch(() => { if (likeButton) likeButton.classList.remove('animate-bounce', 'animate-pulse'); })
    .finally(() => { if (likeButton) setTimeout(() => { likeButton.disabled = false; likeButton.style.pointerEvents = 'auto'; }, 300); });
}

function createRippleEffect(button) {
  const ripple = document.createElement('div');
  ripple.className = 'like-ripple';
  const rect = button.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height);
  const centerX = rect.left + rect.width / 2 - size / 2;
  const centerY = rect.top + rect.height / 2 - size / 2;
  ripple.style.width = ripple.style.height = size + 'px';
  ripple.style.left = centerX + 'px';
  ripple.style.top = centerY + 'px';
  document.body.appendChild(ripple);
  setTimeout(() => { if (ripple.parentNode) ripple.parentNode.removeChild(ripple); }, 600);
}

function createFloatingHearts(button) {
  const heartSVG = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>`;
  for (let i = 0; i < 5; i++) {
    const heart = document.createElement('div');
    heart.className = 'floating-heart';
    heart.setAttribute('data-index', String(i));
    heart.innerHTML = heartSVG;
    const colors = ['#ef4444', '#f87171', '#fca5a5', '#fecaca', '#fef2f2'];
    heart.style.color = colors[i % colors.length];
    const buttonRect = button.getBoundingClientRect();
    const centerX = buttonRect.left + buttonRect.width / 2;
    const centerY = buttonRect.top + buttonRect.height / 2;
    const randomX = (Math.random() - 0.5) * 30;
    const randomY = Math.random() * 15;
    heart.style.left = `${centerX + randomX}px`;
    heart.style.top = `${centerY + randomY}px`;
    document.body.appendChild(heart);
    setTimeout(() => { if (heart.parentNode) heart.parentNode.removeChild(heart); }, 2100);
  }
}

function updateSidebarLikeCount(videoFile, newCount) {
  const sidebarCards = document.querySelectorAll('.sidebar .card-hover');
  sidebarCards.forEach(card => {
    const link = card.querySelector('a');
    if (link && link.href.includes('v=' + encodeURIComponent(videoFile))) {
      const likeElements = card.querySelectorAll('.video-count-info');
      if (likeElements.length >= 2) likeElements[1].textContent = newCount.toLocaleString();
    }
  });
}

function changeSort(sortType) {
  const currentScrollTop = window.pageYOffset || document.documentElement.scrollTop;
  document.cookie = `sort_preference=${sortType}; path=/; max-age=${30 * 24 * 60 * 60}`;
  window.CocoState.sort = sortType;
  const newBtn = document.getElementById('sort-new-btn');
  const popularBtn = document.getElementById('sort-popular-btn');
  const viewsBtn = document.getElementById('sort-views-btn');
  const likesBtn = document.getElementById('sort-likes-btn');
  [newBtn, popularBtn, viewsBtn, likesBtn].forEach(btn => { if (btn) btn.classList.remove('active'); });
  if (sortType === 'new' && newBtn) newBtn.classList.add('active');
  else if (sortType === 'popular' && popularBtn) popularBtn.classList.add('active');
  else if (sortType === 'views' && viewsBtn) viewsBtn.classList.add('active');
  else if (sortType === 'likes' && likesBtn) likesBtn.classList.add('active');
  resetVideoList(currentScrollTop);
}

function resetVideoList(savedScrollTop = null) {
  offset = 0; loading = false; allLoaded = false; scrollCheckInProgress = false;
  const list = document.getElementById('video-list');
  const loadingElement = document.getElementById('video-list-loading');
  if (savedScrollTop !== null) {
    const currentHeight = list.scrollHeight;
    const videoListContainer = list.parentElement;
    const placeholder = document.createElement('div');
    placeholder.id = 'scroll-placeholder';
    placeholder.className = 'sorting-placeholder';
    placeholder.style.height = currentHeight + 'px';
    placeholder.style.minHeight = currentHeight + 'px';
    placeholder.style.width = '100%';
    placeholder.style.position = 'relative';
    placeholder.style.overflow = 'hidden';
    const loadingIndicator = document.createElement('div');
    loadingIndicator.className = 'absolute top-4 left-1/2 transform -translate-x-1/2 flex items-center justify-center';
    loadingIndicator.innerHTML = `<div class="flex items-center space-x-2 md:space-x-3 video-meta-info sorting-indicator rounded-xl px-3 md:px-6 py-3 md:py-4 shadow-lg whitespace-nowrap"><div class="animate-spin rounded-full h-5 w-5 md:h-6 md:w-6 border-2 border-blue-500 border-t-transparent flex-shrink-0"></div><span class="font-medium text-sm md:text-base">並び替え中...</span></div>`;
    placeholder.appendChild(loadingIndicator);
    list.classList.add('video-list-transition', 'fade-out');
    setTimeout(() => {
      list.style.display = 'none';
      list.classList.remove('video-list-transition', 'fade-out');
      videoListContainer.insertBefore(placeholder, list);
      if (loadingElement) { loadingElement.style.display = 'none'; }
      list.innerHTML = '';
      loadVideosWithScrollRestore(savedScrollTop, () => {
        requestAnimationFrame(() => {
          placeholder.classList.add('fade-out');
          setTimeout(() => {
            if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
            list.style.display = '';
            list.classList.add('video-list-transition', 'fade-in');
            setTimeout(() => { list.classList.remove('video-list-transition', 'fade-in'); }, 300);
            if (loadingElement) { loadingElement.style.display = ''; }
          }, 300);
        });
      });
    }, 300);
  } else {
    list.innerHTML = '';
    if (loadingElement) { loadingElement.style.display = ''; loadingElement.textContent = '読み込み中...'; }
    loadVideos();
  }
}

function renderVideoCard(video) {
  const isActive = video.isActive;
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, function(tag) {
    const chars = {'&':'&amp;','<':'&lt;','>':'&gt;','\'':'&#39;','"':'&quot;'}; return chars[tag] || tag;
  });
  const title = esc(video.title || 'タイトルなし');
  const views = Number(video.views).toLocaleString();
  const likes = Number(video.likes).toLocaleString();
  const likeRate = video.views > 0 ? Math.round((video.likes / video.views) * 1000) / 10 : 0;
  let durationDisplay = '';
  if (video.duration && video.duration.includes(':')) {
    const parts = video.duration.split(':');
    if (parts.length === 3) {
      const hours = parseInt(parts[0]);
      const minutes = parseInt(parts[1]);
      const seconds = parseInt(parts[2]);
      durationDisplay = hours > 0 ? `${hours}:${minutes.toString().padStart(2,'0')}:${seconds.toString().padStart(2,'0')}` : `${minutes}:${seconds.toString().padStart(2,'0')}`;
    } else if (parts.length === 2) {
      durationDisplay = video.duration;
    }
  }
  const shareParam = window.isSharedAccess && window.sharePassword ? `&share=${encodeURIComponent(window.sharePassword)}` : '';
  return `
    <div class="group overflow-hidden optimize-rendering video-card transform transition-all duration-300 rounded-lg">
      <a href="?v=${encodeURIComponent(video.video)}${shareParam}" class="block">
        <div class="relative w-full aspect-video overflow-hidden">
          <img class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110 lazy-image video-thumbnail" data-src="${esc(video.thumb)}" alt="${title}" loading="lazy" src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIwIiBoZWlnaHQ9IjE4MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjMzc0MTUxIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzlDQTNBRiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkxvYWRpbmcuLi48L3RleHQ+PC9zdmc+">
          ${durationDisplay ? `<div class="absolute bottom-2 right-2 bg-black/80 text-white text-sm md:text-xs px-2 py-1 rounded-md backdrop-blur-sm">${esc(durationDisplay)}</div>` : ''}
          ${isActive ? `<div class="absolute inset-0 bg-gradient-to-t from-blue-500/30 to-transparent flex items-center justify-center"><div class="bg-blue-500/90 backdrop-blur-sm rounded-full p-2"><svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div></div>` : ''}
        </div>
        <div class="p-2 md:p-2 lg:p-3">
          <h4 class="video-title-main text-base md:text-base lg:text-lg xl:text-xl font-semibold mb-1 line-clamp-2 transition-colors duration-300 break-words min-h-[2.5rem] md:min-h-[3rem] lg:min-h-[3.5rem] xl:min-h-[4rem]">${title}</h4>
          <div class="flex items-center text-sm md:text-sm lg:text-base xl:text-lg video-meta-info mb-2 min-w-0">
            <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 xl:w-6 xl:h-6 mr-1.5 md:mr-2 lg:mr-3 flex-shrink-0 video-meta-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            <span class="truncate">${esc((video.upload_date || '').toString().split(' ')[0])}</span>
          </div>
          <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center space-x-2 md:space-x-3 lg:space-x-4 xl:space-x-5 flex-wrap">
              <div class="flex items-center text-sm md:text-sm lg:text-base xl:text-lg min-w-0">
                <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 xl:w-6 xl:h-6 mr-1 video-meta-icon flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                <span class="font-medium video-count-info truncate">${views}</span>
              </div>
              <div class="flex items-center text-sm md:text-sm lg:text-base xl:text-lg min-w-0">
                <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 xl:w-6 xl:h-6 mr-1 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                <span class="font-medium video-count-info truncate">${likes}</span>
              </div>
            </div>
            ${likeRate > 0 ? `<div class="flex items-center text-sm md:text-sm lg:text-base flex-shrink-0"><div class="flex items-center video-like-rate-badge px-2 py-1 rounded-full" title="いいね率（いいね数 ÷ 再生数）"><span class="video-like-rate-text text-xs opacity-75 mr-1">いいね率</span><span class="video-like-rate-text font-medium">${likeRate}%</span></div></div>` : ''}
          </div>
        </div>
      </a>
    </div>`;
}

function loadVideos() { loadVideosInternal(); }
function loadVideosWithScrollRestore(savedScrollTop, cb = null) { loadVideosInternal(savedScrollTop, cb); }
function loadVideosInternal(savedScrollTop = null, callback = null) {
  if (loading || allLoaded || scrollCheckInProgress) return;
  loading = true; scrollCheckInProgress = true;
  const loadingElement = document.getElementById('video-list-loading');
  if (loadingElement) loadingElement.style.display = '';
  const sort = window.CocoState.sort || 'new';
  const apiUrl = `./index.php?action=list_videos&offset=${offset}&limit=${limit}&sort=${encodeURIComponent(sort)}`;
  fetch(apiUrl)
    .then(async res => { if (!res.ok) { const text = await res.text(); throw new Error(`HTTP ${res.status}: ${text.slice(0,200)}`); } return res.json(); })
    .then(videos => {
      if (videos.length < limit) allLoaded = true;
      offset += videos.length;
      const list = document.getElementById('video-list');
      if (list && videos.length > 0) {
        const fragment = document.createDocumentFragment();
        videos.forEach(video => { const tempDiv = document.createElement('div'); tempDiv.innerHTML = renderVideoCard(video); fragment.appendChild(tempDiv.firstElementChild); });
        list.appendChild(fragment);
        requestAnimationFrame(() => {
          const newImages = list.querySelectorAll('img.lazy-image[data-src]');
          if (newImages.length > 0 && imageObserver) newImages.forEach(img => imageObserver.observe(img));
          const newCards = list.querySelectorAll('.card-hover');
          if (newCards.length > 0 && cardObserver) newCards.forEach(card => cardObserver.observe(card));
          if (savedScrollTop !== null) window.scrollTo({ top: savedScrollTop, behavior: 'instant' });
          if (callback && typeof callback === 'function') callback();
        });
      } else {
        if (savedScrollTop !== null) requestAnimationFrame(() => { window.scrollTo({ top: savedScrollTop, behavior: 'instant' }); });
        if (callback && typeof callback === 'function') requestAnimationFrame(callback);
      }
      if (loadingElement) { if (allLoaded) loadingElement.textContent = 'すべて表示しました'; else loadingElement.style.display = ''; }
      loading = false; scrollCheckInProgress = false;
      if (!allLoaded) requestAnimationFrame(fillScreenIfNeeded);
    })
    .catch(() => {
      loading = false; scrollCheckInProgress = false;
      const loadingElement = document.getElementById('video-list-loading');
      if (loadingElement) loadingElement.textContent = '読み込みに失敗しました';
      if (savedScrollTop !== null) requestAnimationFrame(() => { window.scrollTo({ top: savedScrollTop, behavior: 'instant' }); });
      if (callback && typeof callback === 'function') requestAnimationFrame(callback);
    });
}

function checkScrollPosition() {
  if (loading || allLoaded || scrollCheckInProgress) return;
  const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
  const windowHeight = window.innerHeight;
  const documentHeight = document.documentElement.scrollHeight;
  scrollDirection = scrollTop > lastScrollTop ? 'down' : 'up';
  lastScrollTop = scrollTop;
  if (scrollDirection === 'down' && (scrollTop + windowHeight) >= (documentHeight - 500)) {
    loadVideos();
  }
}

// 一覧が画面の高さに足りずスクロールできないときは、続きを読み込む
// （続きはスクロールで読み込むため、大きなモニターで列が多いと最初の分しか表示されなくなる）
function fillScreenIfNeeded() {
  if (loading || allLoaded || scrollCheckInProgress) return;
  if (document.documentElement.scrollHeight - window.innerHeight < 500) loadVideos();
}

const debouncedScrollCheck = PerformanceUtils.debounce(checkScrollPosition, 100);

function initializeIndexPage() {
  initLazyLoading();
  initCardAnimations();
  initImageErrorHandling();
  initVideoPlayer();
  initLikeButtonState();
  loadVideos();
  let currentUrl = window.location.href;
  setInterval(() => {
    if (window.location.href !== currentUrl) {
      currentUrl = window.location.href;
      currentVideo = document.querySelector('meta[name="current-video"]')?.content || '';
      setTimeout(() => { initLikeButtonState(); }, 100);
    }
  }, 1000);
}

document.addEventListener('DOMContentLoaded', function() {
  initializeIndexPage();
  // Initialize share buttons
  setTimeout(() => {
    initShareButton();
    initShareLinkButton();
    initShareLinkHelpPopover();
  }, 100);
  // Fallback: also try on window load
  window.addEventListener('load', function() {
    setTimeout(() => {
      initShareButton();
      initShareLinkButton();
      initShareLinkHelpPopover();
      initManualCopyButton();
    }, 200);
  });
  // Periodic check for dynamically added buttons (max 10 tries)
  let shareButtonCheckCount = 0;
  const maxShareButtonChecks = 10;
  (function checkShareButtonPeriodically(){
    if (shareButtonCheckCount >= maxShareButtonChecks) return;
    const shareButton = document.getElementById('share-button');
    const shareLinkButton = document.getElementById('share-link-button');
    if (shareButton && !shareButton._initialized) { shareButton._initialized = true; initShareButton(); }
    if (shareLinkButton && !shareLinkButton._initialized) { shareLinkButton._initialized = true; initShareLinkButton(); }
    const manualCopy = document.getElementById('manual-copy-copy');
    if (manualCopy && !manualCopy._initialized) { manualCopy._initialized = true; initManualCopyButton(); }
    if (!shareButton && !shareLinkButton) {
      shareButtonCheckCount++;
      setTimeout(checkShareButtonPeriodically, 500);
    }
  })();
});

window.toggleLike = toggleLike;
window.incrementViewCount = incrementViewCount;
window.changeSort = changeSort;

window.addEventListener('scroll', debouncedScrollCheck, { passive: true });
window.addEventListener('resize', PerformanceUtils.debounce(fillScreenIfNeeded, 200), { passive: true });
document.addEventListener('visibilitychange', function() {
  if (document.visibilityState === 'visible') initLikeButtonState();
});

// Share functions and initializers
async function shareVideo(videoFile, title) {
  try {
    const currentUrl = window.location.origin + window.location.pathname + '?v=' + encodeURIComponent(videoFile);
    const appNameMeta = document.querySelector('meta[name="app-name"]');
    const appName = appNameMeta ? appNameMeta.getAttribute('content') : 'MyTube';
    const shareText = title ? `${title} - ${appName}` : `${appName}で動画を視聴中`;
    copyToClipboard(currentUrl);
    if (navigator.share) {
      navigator.share({ title: shareText, text: shareText, url: currentUrl }).catch(() => {});
    }
  } catch (_) {
    const currentUrl = window.location.origin + window.location.pathname + '?v=' + encodeURIComponent(videoFile);
    copyToClipboard(currentUrl);
  }
}

async function generateShareLink(videoFile, title) {
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: `action=generate_share_link&video_file=${encodeURIComponent(videoFile)}&csrf_token=${encodeURIComponent(csrf)}`
    });
    const data = await response.json();
    if (data.success) {
      const url = data.share_link;
      const copyResult = await (async () => {
        try { return await copyToClipboard(url); } catch (_) { return false; }
      })();
      // 共有リンクは成功時でも常に表示
      (function showShareLinkArea() {
        const area = document.getElementById('manual-copy-area');
        const urlEl = document.getElementById('manual-copy-url');
        if (area && urlEl) { urlEl.textContent = url; area.classList.remove('hidden'); }
      })();
      // 共有シートも起動（対応環境のみ）
      try {
        if (navigator.share) {
          const appNameMeta = document.querySelector('meta[name="app-name"]');
          const appName = appNameMeta ? appNameMeta.getAttribute('content') : 'MyTube';
          const shareText = title ? `${title} - ${appName}` : `${appName}で動画を視聴中`;
          navigator.share({ title: shareText, text: shareText, url }).catch(() => {});
        }
      } catch (_) {}
      const isSecure = data.is_secure || false;
      showNotification(isSecure ? 'ワンタイムパスワード付きの共有リンクが生成されました' : '動画リンクが生成されました（認証が必要）', isSecure ? 'success' : 'info');
    } else {
      const currentUrl = window.location.origin + window.location.pathname + '?v=' + encodeURIComponent(videoFile);
      const copyResult = await (async () => {
        try { return await copyToClipboard(currentUrl); } catch (_) { return false; }
      })();
      if (!copyResult && window.isIOS && window.isIOS()) {
        const area = document.getElementById('manual-copy-area');
        const urlEl = document.getElementById('manual-copy-url');
        if (area && urlEl) { urlEl.textContent = currentUrl; area.classList.remove('hidden'); }
      }
      try {
        if (navigator.share) {
          const appNameMeta = document.querySelector('meta[name="app-name"]');
          const appName = appNameMeta ? appNameMeta.getAttribute('content') : 'MyTube';
          const shareText = title ? `${title} - ${appName}` : `${appName}で動画を視聴中`;
          navigator.share({ title: shareText, text: shareText, url: currentUrl }).catch(() => {});
        }
      } catch (_) {}
      showNotification('共有リンクの生成に失敗しました。現在のページURLをコピーしました。', 'warning');
    }
  } catch (_) {
    const currentUrl = window.location.origin + window.location.pathname + '?v=' + encodeURIComponent(videoFile);
    const copyResult = await (async () => {
      try { return await copyToClipboard(currentUrl); } catch (_) { return false; }
    })();
    if (!copyResult && window.isIOS && window.isIOS()) {
      const area = document.getElementById('manual-copy-area');
      const urlEl = document.getElementById('manual-copy-url');
      if (area && urlEl) { urlEl.textContent = currentUrl; area.classList.remove('hidden'); }
    }
    try {
      if (navigator.share) {
        const appNameMeta = document.querySelector('meta[name="app-name"]');
        const appName = appNameMeta ? appNameMeta.getAttribute('content') : 'MyTube';
        const shareText = title ? `${title} - ${appName}` : `${appName}で動画を視聴中`;
        navigator.share({ title: shareText, text: shareText, url: currentUrl }).catch(() => {});
      }
    } catch (_) {}
    showNotification('共有リンクの生成に失敗しました。現在のページURLをコピーしました。', 'warning');
  }
}

function initShareButton() {
  const shareButton = document.getElementById('share-button');
  if (shareButton) {
    const videoFile = shareButton.getAttribute('data-video');
    const title = shareButton.getAttribute('data-title');
    shareButton.removeEventListener('click', shareButton._shareClickHandler);
    shareButton._shareClickHandler = function(e) { e.preventDefault(); shareVideo(videoFile, title); };
    shareButton.addEventListener('click', shareButton._shareClickHandler);
  }
}

function initShareLinkButton() {
  const shareLinkButton = document.getElementById('share-link-button');
  if (shareLinkButton) {
    const videoFile = shareLinkButton.getAttribute('data-video');
    const title = shareLinkButton.getAttribute('data-title');
    shareLinkButton.removeEventListener('click', shareLinkButton._shareLinkClickHandler);
    shareLinkButton._shareLinkClickHandler = function(e) { e.preventDefault(); generateShareLink(videoFile, title); };
    shareLinkButton.addEventListener('click', shareLinkButton._shareLinkClickHandler);
  }
}

function initManualCopyButton() {
  const btn = document.getElementById('manual-copy-copy');
  const urlEl = document.getElementById('manual-copy-url');
  if (!btn || !urlEl) return;
  btn.removeEventListener('click', btn._manualCopyHandler);
  btn._manualCopyHandler = function(e) {
    e.preventDefault();
    const text = urlEl.textContent || '';
    if (!text) return;
    // ユーザー操作直後の文脈でコピー
    const result = copyToClipboard(text);
    // 非同期/同期両対応
    if (result && typeof result.then === 'function') {
      result.then((ok) => { if (!ok) showManualCopyNotification(text); });
    }
  };
  btn.addEventListener('click', btn._manualCopyHandler);
}

// Admin-only: share-link help popover
function initShareLinkHelpPopover() {
  const helpBtn = document.getElementById('share-link-help');
  const originalPop = document.getElementById('share-link-popover');
  if (!helpBtn || !originalPop) return;
  // 二重初期化防止
  if (helpBtn._shareHelpInitialized) return;
  helpBtn._shareHelpInitialized = true;

  let isOpen = false;
  const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
  let portal = null;
  let teardownFns = [];

  function createPortalIfNeeded() {
    if (portal) return;
    portal = document.createElement('div');
    portal.id = 'share-link-popover-portal';
    // 継承: hidden クラスは削除した状態で反映
    portal.className = originalPop.className.replace(/\bhidden\b/, '').trim();
    portal.style.position = 'fixed';
    portal.style.zIndex = '9999';
    portal.style.maxWidth = 'min(18rem, calc(100vw - 1rem))';
    portal.innerHTML = originalPop.innerHTML;
    document.body.appendChild(portal);
  }

  function positionPortal() {
    if (!portal) return;
    // 一旦表示してサイズ計測
    const prevVisibility = portal.style.visibility;
    const prevDisplay = portal.style.display;
    portal.style.visibility = 'hidden';
    portal.style.display = 'block';

    const btnRect = helpBtn.getBoundingClientRect();
    const portalWidth = portal.offsetWidth;
    const portalHeight = portal.offsetHeight;
    const margin = 8;
    let top = btnRect.bottom + margin;
    let left = Math.min(
      window.innerWidth - portalWidth - margin,
      Math.max(margin, btnRect.right - portalWidth)
    );
    // 画面下に収まらない場合は上側に表示
    if (top + portalHeight > window.innerHeight - margin) {
      const aboveTop = btnRect.top - margin - portalHeight;
      if (aboveTop >= margin) {
        top = aboveTop;
      } else {
        // それでも収まらない場合は高さを制限してスクロール
        top = Math.max(margin, aboveTop);
        portal.style.maxHeight = (window.innerHeight - margin * 2) + 'px';
        portal.style.overflow = 'auto';
      }
    }
    portal.style.top = top + 'px';
    portal.style.left = left + 'px';

    portal.style.visibility = prevVisibility || 'visible';
    portal.style.display = prevDisplay || 'block';
  }

  function destroyPortal() {
    if (portal && portal.parentNode) {
      portal.parentNode.removeChild(portal);
    }
    portal = null;
  }

  function open() {
    if (isOpen) return;
    // 元の要素はクリップされるので使わず、ポータル表示
    createPortalIfNeeded();
    positionPortal();
    helpBtn.setAttribute('aria-expanded', 'true');
    isOpen = true;
    // スクロールやリサイズで位置更新/クローズ
    const onScroll = () => { if (isOpen) positionPortal(); };
    const onResize = () => { if (isOpen) positionPortal(); };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onResize);
    teardownFns.push(() => window.removeEventListener('scroll', onScroll));
    teardownFns.push(() => window.removeEventListener('resize', onResize));
  }
  function close() {
    if (!isOpen) return;
    destroyPortal();
    helpBtn.setAttribute('aria-expanded', 'false');
    isOpen = false;
    // 後処理
    teardownFns.forEach(fn => { try { fn(); } catch (_) {} });
    teardownFns = [];
  }
  function toggle(e) {
    e?.preventDefault?.();
    isOpen ? close() : open();
  }

  // クリックのみで開閉（ホバーやフォーカスでは開かない）
  helpBtn.addEventListener('click', toggle);

  // 外側クリックで閉じる（デスクトップ/タッチ共通）
  document.addEventListener('click', (e) => {
    if (!isOpen) return;
    const t = e.target;
    if (t === helpBtn || helpBtn.contains(t) || (portal && (t === portal || portal.contains(t)))) return;
    close();
  });

  // ESC to close
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
}

// expose for compatibility
window.shareVideo = shareVideo;


