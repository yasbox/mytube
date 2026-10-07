<?php
require_once __DIR__ . '/init_web.php';

function respond_and_exit(bool $success, string $message): void {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  $wantsJson = stripos($accept, 'application/json') !== false;
  if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
  }
  // HTMLフォームからの呼び出し時はログインページへリダイレクトしてフラッシュ表示
  if (session_status() !== PHP_SESSION_ACTIVE) { secureSession(); }
  if ($success) {
    $_SESSION['flash_success'] = $message;
  } else {
    $_SESSION['flash_error'] = $message;
  }
  header('Location: login.php');
  exit;
}

// 機能が無効化されている場合は終了
if (filter_var(($_ENV['RECOVERY_DEVICE_DISABLED'] ?? $_SERVER['RECOVERY_DEVICE_DISABLED'] ?? getenv('RECOVERY_DEVICE_DISABLED') ?: 'false'), FILTER_VALIDATE_BOOLEAN)) {
  respond_and_exit(false, '復旧機能は無効化されています');
}

// 環境変数管理の環境では不可
$envAdmin = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: null;
if (is_string($envAdmin) && $envAdmin !== '') {
  respond_and_exit(false, 'この環境では復旧機能は利用できません');
}

$new = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
if (mb_strlen($new, 'UTF-8') < 8) {
  respond_and_exit(false, 'パスワードは8文字以上で入力してください');
}
if (trim($new) === INSECURE_DEFAULT_ADMIN_PASSWORD) {
  respond_and_exit(false, '既定のパスワード（admin123）は使えません');
}

// クッキー照合
$cookie = $_COOKIE['MyTube_recovery_device'] ?? '';
if (!is_string($cookie) || $cookie === '') {
  respond_and_exit(false, '復旧端末ではありません');
}
$hash = hash('sha256', $cookie);
$path = Config::getRecoveryDevicesPath();
if (!is_readable($path)) {
  respond_and_exit(false, '復旧端末ではありません');
}
$raw = @file_get_contents($path);
$data = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($data) || !isset($data['tokens']) || !is_array($data['tokens'])) {
  respond_and_exit(false, '復旧端末ではありません');
}
$exists = false;
foreach ($data['tokens'] as &$entry) {
  if (($entry['hash'] ?? '') === $hash) {
    $exists = true;
    $entry['last_used'] = time();
    break;
  }
}
unset($entry);
if (!$exists) {
  respond_and_exit(false, '復旧端末ではありません');
}

// 管理者パスワードを更新（ハッシュにして保存）
if (!Config::saveAdminPassword($new)) {
  respond_and_exit(false, 'パスワードの保存に失敗しました');
}

// ファイル更新（last_used保存）
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json !== false) {
  $tmp2 = $path . '.tmp';
  @file_put_contents($tmp2, $json, LOCK_EX);
  @rename($tmp2, $path);
}

// RememberMe無効化
if (function_exists('clearRememberMeCookie')) { clearRememberMeCookie(); }

respond_and_exit(true, 'パスワードを再設定しました。新しいパスワードでログインしてください');


