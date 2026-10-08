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
    // 実際に適用中のテーマを返す（保存がない場合はサイトの既定テーマが適用されているため、
    // localStorage だけで判定すると既定がダークのときに最初の切り替えが効かなかった）
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
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
    // アイコン（月・太陽）は CSS が data-theme で切り替える。メニューの文字だけ更新する
    const text = theme === 'dark' ? 'ライトモード' : 'ダークモード';
    document.querySelectorAll('#theme-toggle-header-mobile .theme-text').forEach(el => { el.textContent = text; });
    const headerToggle = document.getElementById('theme-toggle-header');
    if (headerToggle) headerToggle.title = text + 'に切り替え';
  }
};

function toggleMobileMenu() {
  const menu = document.getElementById('mobile-menu');
  const button = document.getElementById('mobile-menu-button');
  const menuContent = document.getElementById('mobile-menu-content');
  const headerEl = document.getElementById('site-header');
  if (!menu || !button || !menuContent) return;
  if (menu.classList.contains('hidden')) {
    menu.classList.remove('hidden');
    button.classList.add('menu-open');
    requestAnimationFrame(() => { menuContent.classList.add('menu-open'); });
    if (headerEl) headerEl.classList.add('menu-open');
    document.body.classList.add('menu-open');
  } else {
    menuContent.classList.remove('menu-open');
    setTimeout(() => {
      menu.classList.add('hidden');
      button.classList.remove('menu-open');
      if (headerEl) headerEl.classList.remove('menu-open');
      document.body.classList.remove('menu-open');
    }, 150);
  }
}

// スマホ: 虫めがねボタンでヘッダーを検索欄に切り替える
function toggleHeaderSearch(open) {
  const headerEl = document.getElementById('site-header');
  if (!headerEl) return;
  headerEl.classList.toggle('is-searching', open);
  if (open) {
    const input = headerEl.querySelector('.site-search__input');
    if (input) { input.focus(); input.select(); }
  }
}

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    const menu = document.getElementById('mobile-menu');
    if (menu && !menu.classList.contains('hidden')) toggleMobileMenu();
    toggleHeaderSearch(false);
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
    const header = document.getElementById('site-header');
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
window.toggleHeaderSearch = toggleHeaderSearch;
window.adminLogout = adminLogout;
window.logout = logout;


