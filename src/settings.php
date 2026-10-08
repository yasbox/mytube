<?php
require_once __DIR__ . '/init_web.php';

// 管理者権限チェック
requireAdminAuthentication();

// APIリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    include 'admin_api.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<?php
$pageTitle = '設定 - ' . Config::get('app.name', 'MyTube');
$pageCss = 'admin'; // admin.cssを読み込む

include 'head.php';
?>

<body class="min-h-screen flex flex-col">
    <?php
    $showUploadButton = true;
    $showAdminButton = false;
    $showHomeButton = true;
    $isAdminPage = true;
    include 'header.php';

    // 初期状態をサーバ側で反映
    $isPublicMode = true;
    $likesUniqueCountup = false;
    $viewsUniqueCountup = false;
    $autoplayEnabled = true;
    if (class_exists('Config') && method_exists('Config', 'getSettingsJsonPath')) {
        $settingsPath = Config::getSettingsJsonPath();
        if (is_file($settingsPath) && is_readable($settingsPath)) {
            $raw = file_get_contents($settingsPath);
            $dec = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($dec)) {
                $isPublicMode = !filter_var($dec['security']['password_protection'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $likesUniqueCountup = filter_var($dec['features']['likes']['unique_countup'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $viewsUniqueCountup = filter_var($dec['features']['views']['unique_countup'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $autoplayEnabled = filter_var($dec['features']['autoplay'] ?? Config::get('features.autoplay', true), FILTER_VALIDATE_BOOLEAN);
            }
        }
    }
    $siteName = Config::get('app.name', 'MyTube');
    $siteDescription = (string)Config::get('app.description', '');
    $defaultTheme = strtolower((string)Config::get('ui.theme', 'light')) === 'dark' ? 'dark' : 'light';
    $brandUserLogoRel = 'data/branding/logo-square.png';
    $brandLogoRel = is_file(__DIR__ . '/' . $brandUserLogoRel) ? $brandUserLogoRel : 'images/logo.png';
    $brandLogoPreviewSrc = $brandLogoRel . '?v=' . getAssetVersion($brandLogoRel);
    $envAdmin = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');
    $adminPasswordManagedByEnv = is_string($envAdmin) && $envAdmin !== '';
    ?>

    <main class="studio">
        <?php $adminTab = 'settings'; $adminTitle = '設定'; include 'admin_nav.php'; ?>

        <!-- サイト -->
        <section class="settings-card">
            <h2 class="settings-card__title">サイト</h2>
            <div class="setting">
                <label class="setting__label" for="site-name-input">サイト名</label>
                <div class="setting__control">
                    <div class="setting__row">
                        <input id="site-name-input" type="text" class="input" value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" spellcheck="false">
                        <button type="button" id="save-site-name-btn" class="btn btn--primary">保存</button>
                    </div>
                </div>
            </div>
            <div class="setting">
                <label class="setting__label" for="site-description-input">サイトの説明<small>検索結果などに使われる説明文です（画面には表示されません）</small></label>
                <div class="setting__control">
                    <textarea id="site-description-input" class="textarea" autocomplete="off" spellcheck="false" placeholder="サイトの説明（任意）" style="max-width: 560px;"><?= htmlspecialchars($siteDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div><button type="button" id="save-site-description-btn" class="btn btn--primary">保存</button></div>
                </div>
            </div>
            <div class="setting">
                <span class="setting__label">サイトのロゴ<small>正方形・512×512 以上がおすすめ（PNG / JPEG / WebP）</small></span>
                <div class="setting__control">
                    <div class="setting__row setting__row--logo">
                        <div class="logo-preview">
                            <img id="brand-logo-preview" alt="ロゴのプレビュー" src="<?= htmlspecialchars($brandLogoPreviewSrc, ENT_QUOTES, 'UTF-8') ?>">
                            <span id="brand-logo-preview-placeholder" style="display:none;">プレビュー</span>
                        </div>
                        <input id="brand-logo-file" type="file" accept="image/png,image/jpeg,image/webp" class="hidden">
                        <div class="logo-actions">
                            <button id="select-brand-logo-btn" type="button" class="btn">画像を選択</button>
                            <button id="upload-brand-logo-btn" type="button" class="btn btn--primary">保存</button>
                            <button id="reset-brand-logo-btn" type="button" class="btn btn--ghost">元に戻す</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="setting">
                <label class="setting__label" for="theme-select">最初のテーマ<small>初めて開いた人に表示するテーマ（各自が切り替えた場合はそちらを優先）</small></label>
                <div class="setting__control">
                    <div class="setting__row">
                        <select id="theme-select" class="select" style="max-width: 200px;">
                            <option value="light" <?= $defaultTheme === 'light' ? 'selected' : '' ?>>ライト</option>
                            <option value="dark" <?= $defaultTheme === 'dark' ? 'selected' : '' ?>>ダーク</option>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <!-- セキュリティ -->
        <section class="settings-card">
            <h2 class="settings-card__title">セキュリティ</h2>
            <div class="setting">
                <span class="setting__label">パスワードで保護する<small>オンにすると、見るときにパスワードが必要になります</small></span>
                <div class="setting__control setting__switch">
                    <label class="switch">
                        <input id="public-mode-toggle" type="checkbox" <?= $isPublicMode ? '' : 'checked' ?>>
                        <span class="switch__track" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
            <div class="setting" id="user-password-block-row">
                <label class="setting__label" for="user-password-input">閲覧用のパスワード<small>見る人に伝えるパスワード（半角英数字と記号）</small></label>
                <div class="setting__control">
                    <div id="user-password-block" class="setting__row <?= $isPublicMode ? 'blocked-section' : '' ?>">
                        <input id="user-password-input" type="text" class="input" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="latin" pattern="[ -~]+" title="半角英数字と記号のみ" value="">
                        <button type="button" id="save-settings-btn" class="btn btn--primary">保存</button>
                    </div>
                </div>
            </div>
            <?php if (!$adminPasswordManagedByEnv): ?>
            <div class="setting">
                <span class="setting__label">管理者パスワードの変更<small>8文字以上</small></span>
                <div class="setting__control">
                    <div class="setting__row"><input id="admin-current-pw" type="password" class="input" placeholder="現在のパスワード" autocomplete="current-password"></div>
                    <div class="setting__row"><input id="admin-new-pw" type="password" class="input" placeholder="新しいパスワード" autocomplete="new-password"></div>
                    <div class="setting__row">
                        <button type="button" id="change-admin-pw-btn" class="btn btn--primary">変更</button>
                        <button type="button" id="toggle-admin-pw-visibility" class="btn btn--ghost">パスワードを表示</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <!-- 再生とカウント -->
        <section class="settings-card">
            <h2 class="settings-card__title">再生とカウント</h2>
            <div class="setting">
                <span class="setting__label">動画の自動再生<small>動画ページを開いたら自動で再生を始めます</small></span>
                <div class="setting__control setting__switch">
                    <label class="switch">
                        <input id="autoplay-toggle" type="checkbox" <?= $autoplayEnabled ? 'checked' : '' ?>>
                        <span class="switch__track" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
            <div class="setting">
                <span class="setting__label">再生数の重複カウントを制限<small>オンにすると、同じ人がブラウザのタブを閉じるまでは1回だけ数えます</small></span>
                <div class="setting__control setting__switch">
                    <label class="switch">
                        <input id="views-unique-toggle" type="checkbox" <?= $viewsUniqueCountup ? 'checked' : '' ?>>
                        <span class="switch__track" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
            <div class="setting">
                <span class="setting__label">いいねの重複カウントを制限<small>オンにすると、同じ人がブラウザのタブを閉じるまでは1回だけ数えます</small></span>
                <div class="setting__control setting__switch">
                    <label class="switch">
                        <input id="likes-unique-toggle" type="checkbox" <?= $likesUniqueCountup ? 'checked' : '' ?>>
                        <span class="switch__track" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
        </section>
    </main>

    <script src="assets/js/settings.js?v=<?= getAssetVersion('assets/js/settings.js') ?>" defer></script>
    <?php include 'footer.php'; ?>
</body>

</html>
