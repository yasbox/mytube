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
$pageTitle = Config::get('app.name', 'MyTube') . ' - 設定';
$pageCss = 'admin'; // admin.cssを読み込む

// admin.jsファイルのバージョン取得（共通関数を使用）
// 関数が存在しない場合のフォールバック
if (!function_exists('getAssetVersion')) {
    function getAssetVersion($filePath)
    {
        $fullPath = __DIR__ . '/' . $filePath;
        if (file_exists($fullPath)) {
            return filemtime($fullPath);
        }
        return '1.0.0'; // デフォルトバージョン
    }
}

$adminJsVersion = getAssetVersion('assets/js/admin.js');
$settingsJsVersion = getAssetVersion('assets/js/settings.js');
$additionalScripts = '<script src="assets/js/admin.js?v=' . $adminJsVersion . '" defer></script>';
include 'head.php';
?>

<body class="min-h-screen flex flex-col">
    <?php
    // ヘッダー設定
    $pageTitle = '設定';
    $showUploadButton = true;
    $showAdminButton = false; // 現在のページなので非表示
    $showHomeButton = true; // モバイルメニューにホームリンクを表示
    $isAdminPage = true;
    include 'header.php';
    ?>

    <!-- メインコンテンツ -->
    <div class="admin-page-container max-w-none mx-auto p-0 md:p-4 lg:p-6 xl:p-8">

        <!-- 設定セクション -->
        <div class="admin-card p-4 md:p-6 lg:p-8 mb-6 md:mb-8">
            <h2 class="section-title font-bold mb-8 md:mb-12">設定</h2>
            <?php
            // 初期状態をサーバ側で反映
            $isPublicMode = true;
            $currentUserPassword = '';
            $likesUniqueCountup = false;
            $viewsUniqueCountup = false;
            $autoplayEnabled = true;
            if (class_exists('Config') && method_exists('Config', 'getSettingsJsonPath')) {
                $settingsPath = Config::getSettingsJsonPath();
                if (is_file($settingsPath) && is_readable($settingsPath)) {
                    $raw = file_get_contents($settingsPath);
                    if ($raw !== false) {
                        $dec = json_decode($raw, true);
                        if (is_array($dec)) {
                            $protected = filter_var($dec['security']['password_protection'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            $isPublicMode = !$protected;
                            $currentUserPassword = (string)($dec['security']['user_password'] ?? '');
                            $likesUniqueCountup = filter_var($dec['features']['likes']['unique_countup'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            $viewsUniqueCountup = filter_var($dec['features']['views']['unique_countup'] ?? false, FILTER_VALIDATE_BOOLEAN);
                            $autoplayEnabled = filter_var($dec['features']['autoplay'] ?? Config::get('features.autoplay', true), FILTER_VALIDATE_BOOLEAN);
                        }
                    }
                }
            }
            ?>
            <div class="space-y-16">
                <!-- サイト情報セクション（最上部） -->
                <div class="space-y-12 settings-section">
                    <h3 class="font-semibold border-b border-gray-200 pb-3">サイト設定</h3>
                    <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-3 sm:items-center">
                        <label for="site-name-input" class="font-bold settings-label">サイト名</label>
                        <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-3">
                            <?php $siteName = Config::get('app.name', 'MyTube'); ?>
                            <input id="site-name-input" type="text" class="px-4 py-3 rounded-lg admin-input w-full sm:w-80" value="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" spellcheck="false">
                            <button id="save-site-name-btn" class="px-5 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 whitespace-nowrap w-full sm:w-auto">変更</button>
                        </div>
                    </div>

                    <!-- サイト説明（サイト名の下に配置） -->
                    <div class="flex flex-col space-y-6">
                        <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-3 sm:items-start">
                            <label for="site-description-input" class="font-bold settings-label mt-1 whitespace-nowrap flex-shrink-0">サイト説明</label>
                            <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-3 w-full">
                                <?php $siteDescription = (string)Config::get('app.description', ''); ?>
                                <textarea id="site-description-input" class="px-4 py-3 rounded-lg admin-input w-full sm:w-[40rem] min-h-[84px]" autocomplete="off" spellcheck="false" placeholder="サイトの説明文を入力（任意）"><?= htmlspecialchars($siteDescription, ENT_QUOTES, 'UTF-8') ?></textarea>
                                <button id="save-site-description-btn" class="px-5 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 whitespace-nowrap w-full sm:w-auto self-start">変更</button>
                            </div>
                        </div>
                        <p class="text-sm ml-0 leading-relaxed settings-description w-full">この説明はメタデータ（description）として使用されます。（見た目上は表示されません）</p>
                    </div>
                    <!-- デフォルトテーマ設定（サイト情報） -->
                    <div class="flex flex-col space-y-6">
                        <div class="flex items-center justify-start space-x-6">
                            <label class="font-bold settings-label" for="theme-select">デフォルトテーマ</label>
                            <?php $defaultTheme = Config::get('ui.theme', 'light');
                            $defaultTheme = in_array(strtolower($defaultTheme), ['light', 'dark']) ? strtolower($defaultTheme) : 'light'; ?>
                            <select id="theme-select" class="px-4 py-3 rounded-lg admin-input w-40">
                                <option value="light" <?= $defaultTheme === 'light' ? 'selected' : '' ?>>ライト</option>
                                <option value="dark" <?= $defaultTheme === 'dark' ? 'selected' : '' ?>>ダーク</option>
                            </select>
                        </div>
                        <p class="text-sm ml-0 leading-relaxed settings-description">初回訪問や未設定時に適用するテーマを選択します</p>
                    </div>
                </div>
                <!-- 保護設定セクション -->
                <div class="space-y-12 settings-section">
                    <h3 class="font-semibold border-b border-gray-200 pb-3">セキュリティ設定</h3>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- 保護設定ブロック -->
                        <div class="flex flex-col space-y-6">
                            <div class="flex items-center justify-between md:justify-start md:space-x-6">
                                <span class="font-bold settings-label">パスワードで保護する</span>
                                <label class="inline-flex items-center cursor-pointer">
                                    <input id="public-mode-toggle" type="checkbox" class="sr-only peer" <?= $isPublicMode ? '' : 'checked' ?>>
                                    <div class="w-11 h-6 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:border after:rounded-full after:h-5 after:w-5 after:transition-all relative toggle-switch"></div>
                                </label>
                            </div>
                            <p class="text-sm ml-0 leading-relaxed settings-description">ON: サイト全体の閲覧にパスワード認証が必要 / OFF: 認証なしで閲覧可能</p>
                        </div>

                        <!-- パスワードブロック -->
                        <div id="user-password-block" class="<?= $isPublicMode ? 'blocked-section' : '' ?> pl-4 md:pl-6">
                            <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-3 sm:items-center">
                                <label for="user-password-input" class="font-bold settings-label">パスワード</label>
                                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-3">
                                    <?php /* $currentUserPassword は上で決定済み */ ?>
                                    <input id="user-password-input" type="text" class="px-4 py-3 rounded-lg admin-input w-full sm:w-64" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="latin" pattern="[ -~]+" title="半角英数字と記号のみ" value="">
                                    <button id="save-settings-btn" class="px-5 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 whitespace-nowrap w-full sm:w-auto">変更</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 機能設定セクション -->
                <div class="space-y-12 settings-section">
                    <h3 class="font-semibold border-b border-gray-200 pb-3">機能設定</h3>

                    <!-- 自動再生（単独ブロック） -->
                    <div class="space-y-6">
                        <h4 class="font-semibold text-muted">動画再生</h4>
                        <div class="pl-4 flex items-center justify-between md:justify-start md:space-x-6">
                            <span class="font-bold settings-label">動画の自動再生</span>
                            <label class="inline-flex items-center cursor-pointer">
                                <input id="autoplay-toggle" type="checkbox" class="sr-only peer" <?= $autoplayEnabled ? 'checked' : '' ?>>
                                <div class="w-11 h-6 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:border after:rounded-full after:h-5 after:w-5 after:transition-all relative toggle-switch"></div>
                            </label>
                        </div>
                    </div>

                    <!-- カウント制限（いいね／再生数） -->
                    <div class="space-y-6">
                        <h4 class="font-semibold text-muted">カウント制限</h4>
                        <div class="pl-4 grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- いいね設定 -->
                            <div class="flex flex-col space-y-6">
                                <div class="flex items-center justify-between md:justify-start md:space-x-6">
                                    <span class="font-bold settings-label">いいねの重複カウントを制限</span>
                                    <label class="inline-flex items-center cursor-pointer">
                                        <input id="likes-unique-toggle" type="checkbox" class="sr-only peer" <?= $likesUniqueCountup ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:border after:rounded-full after:h-5 after:w-5 after:transition-all relative toggle-switch"></div>
                                    </label>
                                </div>
                                <p class="text-sm ml-0 leading-relaxed settings-description">ON: 重複いいねを制限 / OFF: 重複いいねを許可</p>
                            </div>

                            <!-- 再生数設定 -->
                            <div class="flex flex-col space-y-6">
                                <div class="flex items-center justify-between md:justify-start md:space-x-6">
                                    <span class="font-bold settings-label">再生数の重複カウントを制限</span>
                                    <label class="inline-flex items-center cursor-pointer">
                                        <input id="views-unique-toggle" type="checkbox" class="sr-only peer" <?= $viewsUniqueCountup ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:border after:rounded-full after:h-5 after:w-5 after:transition-all relative toggle-switch"></div>
                                    </label>
                                </div>
                                <p class="text-sm ml-0 leading-relaxed settings-description">ON: 重複再生を制限 / OFF: 重複再生を許可</p>
                            </div>
                        </div>
                    </div>


                </div>

            </div>
        </div>
    </div>

    <script src="assets/js/settings.js?v=<?= $settingsJsVersion ?>" defer></script>
    <?php include 'footer.php'; ?>
</body>

</html>