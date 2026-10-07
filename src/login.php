<?php
// エラー出力を抑制（ログはサーバ設定側で管理）
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// PHPのログ出力先設定はphp.iniに委譲

// 共通関数をインクルード
require_once 'functions.php';
require_once 'admin_functions.php';
require_once 'security.php';

// セッション開始（リメンバーミー機能のため）
secureSession();

// リメンバーミー（クッキー）からの自動ログインを最初に試行
if (function_exists('checkRememberMe')) {
    if (!isUserAuthenticated()) {
        checkRememberMe();
        if (isUserAuthenticated()) {
            // 既にログイン状態に復元できたらトップへ
            $redirectUrl = $_SESSION['redirect_after_login'] ?? 'index.php';
            if (isset($_SESSION['redirect_after_login']) && !isSafeRedirectUrl($redirectUrl)) {
                $redirectUrl = 'index.php';
            }
            if (ob_get_level()) { ob_end_clean(); }
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

// 文字コードを明示（日本語パスワード対応）
header('Content-Type: text/html; charset=UTF-8');

// デバッグ用の直接出力は無効化（本番想定）

// 既にログインしている場合は適切なページにリダイレクト
if (isUserAuthenticated()) {
    // 保存されたリダイレクト先がある場合はそこに移動、なければトップページに移動
    $redirectUrl = $_SESSION['redirect_after_login'] ?? 'index.php';
    
    // リダイレクト先が安全かチェック
    if (isset($_SESSION['redirect_after_login']) && !isSafeRedirectUrl($redirectUrl)) {
        $redirectUrl = 'index.php';
    }
    
    unset($_SESSION['redirect_after_login']); // セッションから削除
    
    // 出力バッファをクリアしてリダイレクト
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Location: ' . $redirectUrl);
    exit;
}

// ログイン処理
$errorMessage = '';
$loginResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF検証に失敗したらパスワードを照合しない
    // （他サイトから閲覧者を勝手にログインさせる攻撃を防ぐ。ページを長く開いたままで
    //   セッションが切れた場合もここに来るため、再ログインを促す文言にする）
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errorMessage = 'ページの有効期限が切れました。もう一度ログインしてください';
    } else {
        $password = $_POST['password'] ?? '';
        $rememberMe = true; // 常にリメンバーミー機能を有効にする

        // セッションの内容はログに出さない（CSRF トークンや共有リンクのパスワードが含まれるため）
        $loginResult = login($password, $rememberMe);

        if ($loginResult && $loginResult['success']) {
            // ログイン成功
            // 保存されたリダイレクト先がある場合はそこに移動、なければトップページに移動
            $redirectUrl = $_SESSION['redirect_after_login'] ?? 'index.php';

            // リダイレクト先が安全かチェック
            if (isset($_SESSION['redirect_after_login']) && !isSafeRedirectUrl($redirectUrl)) {
                $redirectUrl = 'index.php';
            }

            unset($_SESSION['redirect_after_login']); // セッションから削除

            // 出力バッファをクリアしてリダイレクト
            if (ob_get_level()) {
                ob_end_clean();
            }

            // 直接、保存先（安全確認済）へリダイレクト
            header('Location: ' . $redirectUrl);
            exit;
        }
        $errorMessage = loginFailureMessage($loginResult);
    }
}

// ページ設定
$pageTitle = 'ログイン - ' . Config::get('app.name', 'MyTube');
$showUploadButton = false;
$showAdminButton = false;
$showHomeButton = true; // モバイルメニューにテーマ切り替えボタンを表示するためtrueに設定
$isAdminPage = false;
?>
<!DOCTYPE html>
<html lang="ja">
<?php 
  // スタイルはCSSに移行済み
  $additionalStyles = ($additionalStyles ?? '');
  include 'head.php'; 
?>
<body class="min-h-screen flex flex-col">
  <?php include 'header.php'; ?>

  <!-- メインコンテンツ -->
  <div class="w-full max-w-none mx-auto p-2 md:p-4 lg:p-6 xl:p-8 px-4 md:px-8 lg:px-12 xl:px-16 2xl:px-20 3xl:px-24 4xl:px-32">
    <div class="text-center py-8 md:py-16 lg:py-24 animate-fade-in max-w-4xl mx-auto">
      <div class="rounded-xl md:rounded-2xl lg:rounded-3xl p-8 md:p-12 lg:p-16 max-w-md mx-auto">
        <!-- ロゴ -->
        <?php
          // サイトロゴ（設定画面で登録した場合はそのロゴ）。最大 80px 表示のため、高解像度端末向けに 512px の画像を使う
          $loginLogoRel = is_file(__DIR__ . '/data/branding/logo-square.png') ? 'data/branding/logo-square.png' : 'images/logo.png';
        ?>
        <img src="<?= htmlspecialchars($loginLogoRel) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $loginLogoRel) ?>" alt="<?= htmlspecialchars(Config::get('app.name', 'MyTube')) ?>" class="mx-auto mb-6 md:mb-8 lg:mb-10 w-14 h-14 md:w-16 md:h-16 lg:w-20 lg:h-20 object-contain">
        
        <h1 class="font-bold mb-4 md:mb-6 lg:mb-8" style="color: var(--text-primary);"><?= htmlspecialchars(Config::get('app.name', 'MyTube')) ?></h1>
        
        <!-- 管理者パスワード未設定の案内（設定するまで管理者としてログインできない） -->
        <?php if (!isAdminPasswordConfigured()): ?>
        <div class="bg-yellow-500/20 border border-yellow-500/50 rounded-lg p-3 md:p-4 mb-4 md:mb-6 text-left text-sm md:text-base" style="color: var(--text-primary);">
          管理者パスワードが設定されていないため、管理者としてログインできません。
          サーバーの <code>.env</code> に <code>ADMIN_PASSWORD</code> を設定してください（既定値の admin123 は使えません）。
          閲覧用のパスワードではログインできます。
        </div>
        <?php endif ?>

        <!-- エラーメッセージ -->
        <?php if ($errorMessage): ?>
        <div class="bg-red-500/20 border border-red-500/50 text-red-300 rounded-lg p-3 md:p-4 mb-4 md:mb-6">
          <div class="flex items-center">
            <svg class="w-4 h-4 md:w-5 md:h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <span class="text-sm md:text-base"><?= htmlspecialchars($errorMessage) ?></span>
          </div>
        </div>
        <?php endif ?>
        
         <!-- ログインフォーム -->
         <form method="POST" accept-charset="UTF-8" class="space-y-4 md:space-y-6">
          <?php $loginCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($loginCsrf, ENT_QUOTES, 'UTF-8') ?>">
          <div>
            <label for="password" class="block text-sm md:text-base font-medium mb-2 text-left" style="color: var(--text-primary);">パスワード</label>
            <div class="relative">
              <input 
                type="password"
                id="password" 
                name="password" 
                required
                lang="ja"
                inputmode="text"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                class="w-full pr-12 px-4 py-3 md:px-6 md:py-4 rounded-lg transition-all duration-300 login-input password-masked"
                placeholder="パスワードを入力"
                autocomplete="current-password"
              >
              <button type="button" id="toggle-password-visibility" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-200" aria-label="表示切替">
                <!-- eye icon (show) -->
                <svg id="icon-eye" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                <!-- eye-off icon (hide) -->
                <svg id="icon-eye-off" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.056 10.056 0 012.44-4.362M6.18 6.18A9.956 9.956 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.06 10.06 0 01-4.132 5.09M3 3l18 18M9.88 9.88A3 3 0 0012 15a3 3 0 002.12-.88"/>
                </svg>
              </button>
            </div>
          </div>
          
          <button 
            type="submit"
            class="w-full px-6 py-3 md:px-8 md:py-4 bg-gradient-to-r from-blue-500 to-purple-600 text-white font-medium rounded-lg hover:from-blue-600 hover:to-purple-700 transform hover:scale-105 transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
            style="--tw-ring-offset-color: var(--primary-bg);"
          >
            <div class="flex items-center justify-center">
              <svg class="w-4 h-4 md:w-5 md:h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
              </svg>
              <span class="text-sm md:text-base">ログイン</span>
            </div>
          </button>
        </form>

        <!-- 復旧端末（自動登録済み端末のみ表示。管理者パスワードを環境変数で管理している場合は使えないため非表示） -->
        <?php
          $recoveryCookie = $_COOKIE['MyTube_recovery_device'] ?? '';
          $hasRecovery = false;
          if (isRecoveryDeviceEnabled() && is_string($recoveryCookie) && $recoveryCookie !== '') {
            $path = Config::getRecoveryDevicesPath();
            if (is_readable($path)) {
              $raw = @file_get_contents($path);
              $data = $raw !== false ? json_decode($raw, true) : null;
              if (is_array($data) && isset($data['tokens']) && is_array($data['tokens'])) {
                $hash = hash('sha256', $recoveryCookie);
                foreach ($data['tokens'] as $entry) {
                  if (($entry['hash'] ?? '') === $hash) { $hasRecovery = true; break; }
                }
              }
            }
          }
        ?>
        <?php if ($hasRecovery): ?>
        <?php if (!empty($_SESSION['flash_success']) || !empty($_SESSION['flash_error'])): ?>
          <div class="mt-4">
            <?php if (!empty($_SESSION['flash_success'])): ?>
              <div class="rounded-lg p-3 mb-3 text-white font-medium" style="background-color:#16a34a;">
                <?php echo htmlspecialchars($_SESSION['flash_success']); ?>
              </div>
            <?php unset($_SESSION['flash_success']); endif; ?>
            <?php if (!empty($_SESSION['flash_error'])): ?>
              <div class="rounded-lg p-3 text-white font-medium" style="background-color:#dc2626;">
                <?php echo htmlspecialchars($_SESSION['flash_error']); ?>
              </div>
            <?php unset($_SESSION['flash_error']); endif; ?>
          </div>
        <?php endif; ?>
        <div class="mt-6 md:mt-8 text-left">
          <details>
            <summary class="cursor-pointer text-sm md:text-base underline">管理者パスワードを再設定</summary>
            <div class="mt-3 p-3 rounded-lg" style="background: var(--card-bg); border: 1px solid var(--card-border);">
              <form method="POST" action="recovery_device.php" class="space-y-3">
                <div>
                  <label class="block text-sm mb-1">新しいパスワード（8文字以上）</label>
                  <input type="password" name="new_password" class="w-full px-4 py-2 rounded-lg login-input" required>
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">再設定</button>
              </form>
            </div>
          </details>
        </div>
        <?php endif; ?>
        
        
        <!-- セキュリティ情報 -->
        <div class="mt-6 md:mt-8 lg:mt-10 pt-4 md:pt-6 border-t" style="border-color: var(--card-border);">
          <div class="flex items-center justify-center text-xs md:text-sm" style="color: var(--text-muted);">
            <svg class="w-3 h-3 md:w-4 md:h-4 mr-1 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
            </svg>
            <span>セキュアな接続で保護されています</span>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php include 'footer.php'; ?>
</body>
</html> 