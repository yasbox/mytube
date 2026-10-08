// Common utilities shared across pages

// Performance utilities
const PerformanceUtils = {
  throttle: function(func, limit) {
    let inThrottle;
    return function() {
      const args = arguments;
      const context = this;
      if (!inThrottle) {
        func.apply(context, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    }
  },
  debounce: function(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  },
  createIntersectionObserver: function(callback, options = {}) {
    return new IntersectionObserver(callback, {
      root: null,
      rootMargin: '50px',
      threshold: 0.1,
      ...options
    });
  }
};

// Notifications（全ページ共通。admin.js・settings.js・upload.js からもこれを使う）
function showNotification(message, type = 'info') {
  let notificationContainer = document.getElementById('notification-container');
  if (!notificationContainer) {
    notificationContainer = document.createElement('div');
    notificationContainer.id = 'notification-container';
    notificationContainer.className = 'fixed top-4 right-4 z-[10001] space-y-2';
    notificationContainer.style.zIndex = '10001';
    document.body.appendChild(notificationContainer);
  }

  const notification = document.createElement('div');
  notification.className = `notification px-4 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;
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
  notificationContainer.appendChild(notification);
  setTimeout(() => { notification.classList.remove('translate-x-full'); }, 100);
  setTimeout(() => {
    notification.classList.add('translate-x-full');
    setTimeout(() => {
      if (notification.parentNode) notification.remove();
      if (notificationContainer.children.length === 0) notificationContainer.remove();
    }, 300);
  }, 3000);
}

// ファイルサイズを読みやすい形式にする（例: 1536 → "1.5 KB"）
function formatFileSize(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  bytes = Math.max(Number(bytes) || 0, 0);
  const pow = Math.min(Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024)), units.length - 1);
  return Math.round(bytes / Math.pow(1024, pow) * 100) / 100 + ' ' + units[pow];
}

// 「3日前」のような相対的な日時（YouTube と同じ表し方。PHP の formatRelativeTime と同じ規則）
function formatRelativeTime(dateString) {
  const time = new Date(String(dateString || '').replace(' ', 'T')).getTime();
  if (!isFinite(time)) return '';
  const diff = Math.max(0, (Date.now() - time) / 1000);
  if (diff < 60) return 'たった今';
  if (diff < 3600) return Math.floor(diff / 60) + '分前';
  if (diff < 86400) return Math.floor(diff / 3600) + '時間前';
  const days = Math.floor(diff / 86400);
  if (days < 7) return days + '日前';
  if (days < 30) return Math.floor(days / 7) + '週間前';
  if (days < 365) return Math.floor(days / 30) + 'か月前';
  return Math.floor(days / 365) + '年前';
}

// Clipboard helpers
function isIOS() {
  try {
    const ua = window.navigator.userAgent || '';
    const iOSDevice = /iPad|iPhone|iPod/.test(ua);
    const iPadOnMac = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    return iOSDevice || iPadOnMac;
  } catch (_) {
    return false;
  }
}

function iosCopyToClipboard(text) {
  try { window.lastCopiedText = text; } catch (_) {}
  const input = document.createElement('input');
  input.value = text;
  input.setAttribute('readonly', '');
  input.style.position = 'fixed';
  input.style.top = '0';
  input.style.left = '0';
  input.style.opacity = '0';
  input.style.zIndex = '10000';
  document.body.appendChild(input);
  input.focus();
  input.select();
  try { input.setSelectionRange(0, input.value.length); } catch (_) {}
  let success = false;
  try {
    success = document.execCommand('copy');
  } catch (_) {
    success = false;
  }
  document.body.removeChild(input);
  if (success) { showCopyNotification(); } else { showManualCopyNotification(text); }
  return success;
}

function copyToClipboard(text) {
  try { window.lastCopiedText = text; } catch (_) {}
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text).then(() => {
      showCopyNotification();
      return true;
    }).catch(() => {
      if (isIOS()) return iosCopyToClipboard(text);
      return fallbackCopyToClipboard(text);
    });
  } else {
    if (isIOS()) return iosCopyToClipboard(text);
    return fallbackCopyToClipboard(text);
  }
}

function fallbackCopyToClipboard(text) {
  try { window.lastCopiedText = text; } catch (_) {}
  const textArea = document.createElement('textarea');
  textArea.value = text;
  textArea.style.position = 'fixed';
  textArea.style.top = '0';
  textArea.style.left = '0';
  textArea.style.opacity = '0';
  textArea.style.zIndex = '10000';
  document.body.appendChild(textArea);
  textArea.focus();
  textArea.select();
  let success = false;
  try {
    success = document.execCommand('copy');
    if (success) {
      showCopyNotification();
    } else {
      showManualCopyNotification(text);
    }
  } catch (_) {
    showManualCopyNotification(text);
  }
  if (textArea.parentNode) document.body.removeChild(textArea);
  return success;
}

function showManualCopyNotification(text) {
  // Prefer inline area under "共有リンクとは？" if present
  const area = document.getElementById('manual-copy-area');
  const urlEl = document.getElementById('manual-copy-url');
  if (area && urlEl) {
    urlEl.textContent = text;
    area.classList.remove('hidden');
    // あわせて通知も出す
    try { showNotification('コピーに失敗しました。共有リンクを表示しました。', 'warning'); } catch (_) {}
    return;
  }
  // Fallback: toast notification
  const existingNotification = document.getElementById('copy-notification');
  if (existingNotification) existingNotification.remove();
  const notification = document.createElement('div');
  notification.id = 'copy-notification';
  notification.className = 'fixed top-4 right-4 bg-yellow-600 text-white px-4 py-2 rounded-lg shadow-lg z-[10001] transform transition-all duration-300 opacity-0 translate-y-2 max-w-sm';
  notification.style.zIndex = '10001';
  notification.innerHTML = `
    <div class="flex items-start space-x-2">
      <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
      </svg>
      <div>
        <div class="font-medium">コピーに失敗しました</div>
        <div class="text-xs mt-1 break-all">${text}</div>
      </div>
    </div>`;
  document.body.appendChild(notification);
  requestAnimationFrame(() => { notification.classList.remove('opacity-0', 'translate-y-2'); });
  setTimeout(() => {
    notification.classList.add('opacity-0', 'translate-y-2');
    setTimeout(() => { if (notification.parentNode) notification.remove(); }, 300);
  }, 5000);
}

function showCopyNotification() {
  // 成功時も手動コピーエリアはそのまま保持
  showNotification('URLをコピーしました', 'success');
}

// Server config via meta
function initServerConfigFromMeta() {
  try {
    const readMeta = (name) => {
      const el = document.querySelector(`meta[name="${name}"]`);
      return el ? el.getAttribute('content') : null;
    };
    const uniqueCountup = readMeta('unique-countup');
    const uniqueLikeCountup = readMeta('unique-like-countup');
    const autoplayEnabled = readMeta('autoplay-enabled');
    const isShared = readMeta('is-shared-access');
    const sharePwd = readMeta('share-password');
    if (uniqueCountup !== null) window.uniqueCountupSetting = uniqueCountup === 'true';
    if (uniqueLikeCountup !== null) window.uniqueLikeCountupSetting = uniqueLikeCountup === 'true';
    if (autoplayEnabled !== null) window.autoplayEnabledSetting = autoplayEnabled === 'true';
    if (isShared !== null) window.isSharedAccess = isShared === 'true';
    if (sharePwd !== null) window.sharePassword = sharePwd || null;
  } catch (_) {}
}

// Login helper
function initLoginPasswordToggle() {
  const input = document.getElementById('password');
  const btn = document.getElementById('toggle-password-visibility');
  const iconEye = document.getElementById('icon-eye');
  const iconEyeOff = document.getElementById('icon-eye-off');
  if (btn && input) {
    btn.addEventListener('click', function() {
      const isPasswordType = input.getAttribute('type') === 'password';
      if (isPasswordType) {
        // 表示する
        input.setAttribute('type', 'text');
        input.classList.remove('password-masked');
        if (iconEye) iconEye.classList.add('hidden');
        if (iconEyeOff) iconEyeOff.classList.remove('hidden');
      } else {
        // 非表示にする
        input.setAttribute('type', 'password');
        // マスク用クラスはtype=passwordでは不要だが念のため付与
        input.classList.add('password-masked');
        if (iconEye) iconEye.classList.remove('hidden');
        if (iconEyeOff) iconEyeOff.classList.add('hidden');
      }
      input.focus();
    });
  }
}

// Bootstrap common on DOM load
document.addEventListener('DOMContentLoaded', function() {
  initServerConfigFromMeta();
  initLoginPasswordToggle();
});

// Expose for other scripts
window.PerformanceUtils = PerformanceUtils;
window.showNotification = showNotification;
window.formatFileSize = formatFileSize;
window.formatRelativeTime = formatRelativeTime;
window.copyToClipboard = copyToClipboard;
window.fallbackCopyToClipboard = fallbackCopyToClipboard;
window.showManualCopyNotification = showManualCopyNotification;
window.showCopyNotification = showCopyNotification;
window.initServerConfigFromMeta = initServerConfigFromMeta;
window.initLoginPasswordToggle = initLoginPasswordToggle;
window.isIOS = isIOS;


