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
$pageCss = 'home';
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

  <main class="login-page">
    <div class="login-card">
      <?php
        // サイトロゴ（設定画面で登録した場合はそのロゴ）。高解像度端末向けに 512px の画像を使う
        $loginLogoRel = is_file(__DIR__ . '/data/branding/logo-square.png') ? 'data/branding/logo-square.png' : 'images/logo.png';
      ?>
      <img src="<?= htmlspecialchars($loginLogoRel) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $loginLogoRel) ?>" alt="" class="login-card__logo">
      <h1 class="login-card__title"><?= htmlspecialchars(Config::get('app.name', 'MyTube')) ?></h1>
      <p class="login-card__lead">パスワードを入力してください</p>

      <!-- 管理者パスワード未設定の案内（設定するまで管理者としてログインできない） -->
      <?php if (!isAdminPasswordConfigured()): ?>
      <div class="login-message login-message--warning">
        管理者パスワードが設定されていないため、管理者としてログインできません。
        サーバーの <code>.env</code> に <code>ADMIN_PASSWORD</code> を設定してください（既定値の admin123 は使えません）。
        閲覧用のパスワードではログインできます。
      </div>
      <?php endif ?>

      <?php if ($errorMessage): ?>
      <div class="login-message login-message--error" role="alert"><?= htmlspecialchars($errorMessage) ?></div>
      <?php endif ?>

      <form method="POST" accept-charset="UTF-8">
        <?php $loginCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : ''; ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($loginCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="login-field">
          <label for="password">パスワード</label>
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
            class="login-input password-masked"
            placeholder="パスワード"
            autocomplete="current-password"
          >
          <button type="button" id="toggle-password-visibility" class="icon-btn login-toggle" aria-label="パスワードの表示を切り替え">
            <svg id="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            <svg id="icon-eye-off" class="hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.056 10.056 0 012.44-4.362M6.18 6.18A9.956 9.956 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.06 10.06 0 01-4.132 5.09M3 3l18 18M9.88 9.88A3 3 0 0012 15a3 3 0 002.12-.88"/>
            </svg>
          </button>
        </div>
        <button type="submit" class="login-submit">ログイン</button>
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
        <?php if (!empty($_SESSION['flash_success'])): ?>
          <div class="login-message login-message--success" style="margin-top: 16px;"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
          <div class="login-message login-message--error" style="margin-top: 16px;"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); endif; ?>
        <details class="login-recovery">
          <summary>管理者パスワードを再設定</summary>
          <form method="POST" action="recovery_device.php">
            <div class="login-field">
              <label for="recovery-new-password">新しいパスワード（8文字以上）</label>
              <input type="password" id="recovery-new-password" name="new_password" class="login-input" required>
            </div>
            <button type="submit" class="login-submit">再設定</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  </main>
<?php include 'footer.php'; ?>
</body>
</html>
