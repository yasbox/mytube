// Header related: theme and mobile menu, admin logout

const ThemeManager = {
  init: function() {
    this.loadTheme();
    this.createThemeToggle();
    this.updateThemeToggleButton(this.getCurrentTheme());
  },
  loadTheme: function() {
    const metaDefault = document.querySelector('meta[name="default-theme"]')?.content || 'light';
    // localStorage 未設定時のみ meta を尊重（既に data-theme が HTML で適用済みの場合はそれを優先）
    const preApplied = document.documentElement.getAttribute('data-theme');
    const savedTheme = localStorage.getItem('theme') || preApplied || metaDefault || 'light';
    this.setTheme(savedTheme);
  },
  setTheme: function(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    // ユーザー操作による切り替え時のみ保存（toggleTheme 経由）
  },
  getCurrentTheme: function() {
    return localStorage.getItem('theme') || 'light';
  },
  toggleTheme: function() {
    const currentTheme = this.getCurrentTheme();
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    this.setTheme(newTheme);
    this.updateThemeToggleButton(newTheme);
    // ここでのみ保存（ユーザー操作）
    localStorage.setItem('theme', newTheme);
  },
  createThemeToggle: function() {
    const existingToggle = document.getElementById('theme-toggle');
    if (existingToggle) existingToggle.remove();
  },
  updateThemeToggleButton: function(theme) {
    const headerToggle = document.getElementById('theme-toggle-header');
    const mobileToggle = document.getElementById('theme-toggle-header-mobile');
    const icon = theme === 'dark' ? '☀️' : '🌙';
    const text = theme === 'dark' ? 'ライトモード' : 'ダークモード';
    if (headerToggle) {
      const iconElement = headerToggle.querySelector('.theme-icon');
      const textElement = headerToggle.querySelector('.theme-text');
      if (iconElement) iconElement.textContent = icon;
      if (textElement) textElement.textContent = text;
    }
    if (mobileToggle) {
      const iconElement = mobileToggle.querySelector('.theme-icon');
      const textElement = mobileToggle.querySelector('.theme-text');
      if (iconElement) iconElement.textContent = icon;
      if (textElement) textElement.textContent = text;
    }
  }
};

function toggleMobileMenu() {
  const menu = document.getElementById('mobile-menu');
  const button = document.getElementById('mobile-menu-button');
  const icon = document.getElementById('hamburger-icon');
  const lines = icon ? icon.querySelectorAll('span') : [];
  const menuContent = document.getElementById('mobile-menu-content');
  const headerEl = document.querySelector('header.glass-effect-header');
  if (!menu || !button || !menuContent) return;
  if (menu.classList.contains('hidden')) {
    menu.classList.remove('hidden');
    button.classList.add('menu-open');
    requestAnimationFrame(() => { menuContent.classList.add('menu-open'); });
    if (headerEl) headerEl.classList.add('menu-open');
    document.body.classList.add('menu-open');
    document.body.style.overflow = 'hidden';
  } else {
    menuContent.classList.remove('menu-open');
    setTimeout(() => {
      menu.classList.add('hidden');
      button.classList.remove('menu-open');
      if (headerEl) headerEl.classList.remove('menu-open');
      document.body.classList.remove('menu-open');
      document.body.style.overflow = '';
    }, 300);
  }
}

document.addEventListener('click', function(event) {
  const menu = document.getElementById('mobile-menu');
  const button = document.getElementById('mobile-menu-button');
  const icon = document.getElementById('hamburger-icon');
  const lines = icon ? icon.querySelectorAll('span') : [];
  const menuContent = document.getElementById('mobile-menu-content');
  const headerEl = document.querySelector('header.glass-effect-header');
  if (!menu || !button || !menuContent) return;
  if (!menu.contains(event.target) && !button.contains(event.target)) {
    menuContent.classList.remove('menu-open');
    setTimeout(() => {
      menu.classList.add('hidden');
      button.classList.remove('menu-open');
      if (headerEl) headerEl.classList.remove('menu-open');
      document.body.classList.remove('menu-open');
      document.body.style.overflow = '';
    }, 300);
  }
});

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const menu = document.getElementById('mobile-menu');
    const headerEl = document.querySelector('header.glass-effect-header');
    if (menu && !menu.classList.contains('hidden')) toggleMobileMenu();
  }
});

async function adminLogout() {
  if (!confirm('ログアウトしますか？')) return;
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('admin_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=admin_logout&csrf_token=' + encodeURIComponent(csrf)
    });
    const data = await response.json();
    if (data.success) {
      showNotification(data.message, 'success');
      setTimeout(() => { window.location.href = 'login.php'; }, 1000);
    } else {
      showNotification(data.message, 'error');
    }
  } catch (_) {
    showNotification('ログアウトに失敗しました', 'error');
  }
}

async function logout() { await adminLogout(); }

document.addEventListener('DOMContentLoaded', function() {
  ThemeManager.init();
  // ヘッダー高さを計測してCSS変数に反映
  try {
    const header = document.querySelector('header.glass-effect-header');
    if (header) {
      const updateHeaderHeight = () => {
        const h = header.getBoundingClientRect().height;
        document.documentElement.style.setProperty('--header-height', h + 'px');
      };
      updateHeaderHeight();
      // リサイズやテーマ切替で高さが変わる可能性に対応
      window.addEventListener('resize', updateHeaderHeight);
      const resizeObserver = new ResizeObserver(updateHeaderHeight);
      resizeObserver.observe(header);
    }
  } catch (_) { /* noop */ }

  // オーバーレイ（#mobile-menu）を body 直下へ移動して backdrop-filter を安定動作させる
  try {
    const mobileMenu = document.getElementById('mobile-menu');
    if (mobileMenu && document.body && mobileMenu.parentElement !== document.body) {
      document.body.appendChild(mobileMenu);
    }
  } catch (_) { /* noop */ }
});

// expose
window.ThemeManager = ThemeManager;
window.toggleMobileMenu = toggleMobileMenu;
window.adminLogout = adminLogout;
window.logout = logout;


