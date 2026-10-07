<?php
require_once __DIR__ . '/init_web.php';

// 管理者権限チェック
requireAdminAuthentication();

// APIリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    include 'admin_api.php';
    exit;
}

// デバッグ出力は無効化
?>
<!DOCTYPE html>
<html lang="ja">
<?php
$pageTitle = Config::get('app.name', 'MyTube') . ' - 管理パネル';
$pageCss = 'admin'; // admin.cssを読み込む

$adminJsVersion = getAssetVersion('assets/js/admin.js');
$additionalScripts = '<script src="assets/js/admin.js?v=' . $adminJsVersion . '" defer></script>';
include 'head.php';
?>

<body class="min-h-screen flex flex-col admin-page">
    <?php
    // ヘッダー設定
    $pageTitle = '管理パネル';
    $showUploadButton = true;
    $showAdminButton = false; // 現在のページなので非表示
    $showHomeButton = true; // モバイルメニューにホームリンクを表示
    $isAdminPage = true;
    include 'header.php';
    ?>

    <!-- メインコンテンツ -->
    <div class="admin-page-container max-w-none mx-auto p-0 md:p-4 lg:p-6 xl:p-8">
        <!-- 統計情報セクション -->
        <div class="admin-card p-4 md:p-6 lg:p-8 pb-2 md:pb-2 lg:pb-2">
            <h2 class="section-title font-bold mb-4 md:mb-6">統計情報</h2>
            <div class="stats-grid grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 md:gap-4">
                <div class="stats-card p-3 md:p-4 transition-all duration-300">
                    <div class="flex flex-row items-center">
                        <div class="stats-icon w-12 h-12 md:w-14 md:h-14 flex-shrink-0 bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg flex items-center justify-center mr-3 md:mr-4">
                            <svg class="w-6 h-6 md:w-7 md:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <div class="text-left min-w-0">
                            <p class="stats-label text-xs sm:text-sm mb-0.5 whitespace-nowrap">
                                <span class="hidden sm:inline">総動画数</span>
                                <span class="sm:hidden">動画数</span>
                            </p>
                            <p id="total-videos" class="stats-value text-lg sm:text-xl md:text-2xl font-bold whitespace-nowrap">-</p>
                        </div>
                    </div>
                </div>

                <div class="stats-card p-3 md:p-4 transition-all duration-300">
                    <div class="flex flex-row items-center">
                        <div class="stats-icon w-12 h-12 md:w-14 md:h-14 flex-shrink-0 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center mr-3 md:mr-4">
                            <svg class="w-6 h-6 md:w-7 md:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </div>
                        <div class="text-left min-w-0">
                            <p class="stats-label text-xs sm:text-sm mb-0.5 whitespace-nowrap">
                                <span class="hidden sm:inline">総再生数</span>
                                <span class="sm:hidden">再生数</span>
                            </p>
                            <p id="total-views" class="stats-value text-lg sm:text-xl md:text-2xl font-bold whitespace-nowrap">-</p>
                        </div>
                    </div>
                </div>

                <div class="stats-card p-3 md:p-4 transition-all duration-300">
                    <div class="flex flex-row items-center">
                        <div class="stats-icon w-12 h-12 md:w-14 md:h-14 flex-shrink-0 bg-gradient-to-r from-pink-500 to-pink-600 rounded-lg flex items-center justify-center mr-3 md:mr-4">
                            <svg class="w-6 h-6 md:w-7 md:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                            </svg>
                        </div>
                        <div class="text-left min-w-0">
                            <p class="stats-label text-xs sm:text-sm mb-0.5 whitespace-nowrap">
                                <span class="hidden sm:inline">総いいね数</span>
                                <span class="sm:hidden">いいね数</span>
                            </p>
                            <p id="total-likes" class="stats-value text-lg sm:text-xl md:text-2xl font-bold whitespace-nowrap">-</p>
                        </div>
                    </div>
                </div>

                <div class="stats-card p-3 md:p-4 transition-all duration-300">
                    <div class="flex flex-row items-center">
                        <div class="stats-icon w-12 h-12 md:w-14 md:h-14 flex-shrink-0 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center mr-3 md:mr-4">
                            <svg class="w-6 h-6 md:w-7 md:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                            </svg>
                        </div>
                        <div class="text-left min-w-0">
                            <p class="stats-label text-xs sm:text-sm mb-0.5 whitespace-nowrap">
                                <span class="hidden sm:inline">総容量</span>
                                <span class="sm:hidden">容量</span>
                            </p>
                            <p id="total-size" class="stats-value text-lg sm:text-xl md:text-2xl font-bold whitespace-nowrap">-</p>
                        </div>
                    </div>
                </div>

                <div class="stats-card p-3 md:p-4 transition-all duration-300">
                    <div class="flex flex-row items-center">
                        <div class="stats-icon w-12 h-12 md:w-14 md:h-14 flex-shrink-0 bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-lg flex items-center justify-center mr-3 md:mr-4">
                            <svg class="w-6 h-6 md:w-7 md:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div class="text-left min-w-0">
                            <p class="stats-label text-xs sm:text-sm mb-0.5 whitespace-nowrap">
                                <span class="hidden sm:inline">平均再生数</span>
                                <span class="sm:hidden">平均再生</span>
                            </p>
                            <p id="average-views" class="stats-value text-lg sm:text-xl md:text-2xl font-bold whitespace-nowrap">-</p>
                        </div>
                    </div>
                </div>

                <div class="stats-card p-3 md:p-4 transition-all duration-300">
                    <div class="flex flex-row items-center">
                        <div class="stats-icon w-12 h-12 md:w-14 md:h-14 flex-shrink-0 bg-gradient-to-r from-indigo-500 to-indigo-600 rounded-lg flex items-center justify-center mr-3 md:mr-4">
                            <svg class="w-6 h-6 md:w-7 md:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                            </svg>
                        </div>
                        <div class="text-left min-w-0">
                            <p class="stats-label text-xs sm:text-sm mb-0.5 whitespace-nowrap">
                                <span class="hidden sm:inline">平均いいね数</span>
                                <span class="sm:hidden">平均いいね</span>
                            </p>
                            <p id="average-likes" class="stats-value text-lg sm:text-xl md:text-2xl font-bold whitespace-nowrap">-</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- 動画管理セクション -->
        <div class="admin-card p-4 md:p-6 lg:p-8">
            <div class="section-header flex flex-col md:flex-row md:items-center md:justify-between mb-4 md:mb-6 space-y-3 md:space-y-0">
                <h2 class="section-title w-full md:w-auto font-bold">動画管理</h2>
                <!-- ソートボタン -->
                <div class="flex w-full md:w-auto space-x-4 md:space-x-6">
                    <button
                        id="sort-new-btn"
                        class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75 active"
                        onclick="changeSort('new')">
                        <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                        <span class="hidden sm:inline">新しい順</span>
                        <span class="sm:hidden">新着</span>
                    </button>
                    <button
                        id="sort-popular-btn"
                        class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75"
                        onclick="changeSort('popular')">
                        <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                        </svg>
                        <span class="hidden sm:inline">人気順</span>
                        <span class="sm:hidden">人気</span>
                    </button>
                    <button
                        id="sort-views-btn"
                        class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75"
                        onclick="changeSort('views')">
                        <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        <span class="hidden sm:inline">再生数順</span>
                        <span class="sm:hidden">再生</span>
                    </button>
                    <button
                        id="sort-likes-btn"
                        class="flex items-center text-sm md:text-base font-medium transition-all duration-300 hover:opacity-75"
                        onclick="changeSort('likes')">
                        <svg class="w-4 h-4 md:w-4 md:h-4 lg:w-5 lg:h-5 mr-2 md:mr-2 lg:mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                        </svg>
                        <span class="hidden sm:inline">いいね数順</span>
                        <span class="sm:hidden">いいね</span>
                    </button>
                </div>
            </div>

            <!-- 動画一覧テーブル -->
            <div class="admin-table-container overflow-x-auto">
                <!-- 初期ローディング表示 -->
                <div id="initial-loading" class="text-center py-8 md:py-12">
                    <div class="inline-flex flex-col items-center space-y-4">
                        <div class="animate-spin rounded-full h-12 w-12 md:h-16 md:w-16 border-b-2 border-blue-500"></div>
                        <p class="text-lg md:text-xl font-medium text-gray-300">動画一覧を読み込み中...</p>
                    </div>
                </div>
                
                <table class="admin-table w-full text-sm md:text-base hidden" id="video-table">
                    <thead>
                        <tr class="border-b border-gray-700/50">
                            <th class="text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">編集</th>
                            <th class="text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">再生数</th>
                            <th class="text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">いいね数</th>
                            <th class="text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">アップロード日</th>
                            <th class="text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">ファイルサイズ</th>
                            <th class="desktop-actions-column text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">形式</th>
                            <th class="desktop-actions-column text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">変換</th>
                            <th class="desktop-actions-column text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">公開</th>
                            <th class="desktop-actions-column text-left py-3 md:py-4 px-2 md:px-4 font-medium whitespace-nowrap overflow-hidden text-ellipsis">削除</th>
                        </tr>
                    </thead>
                    <tbody id="video-table-body">
                        <!-- 動画データがここに動的に挿入されます -->
                    </tbody>
                </table>
            </div>

            <!-- 無限スクロール用のローディング表示 -->
            <div id="infinite-loading" class="text-center py-4 md:py-6 hidden">
                <div class="inline-flex items-center px-4 py-2 font-semibold leading-6 shadow rounded-md">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    読み込み中...
                </div>
            </div>

            <!-- すべて読み込み完了メッセージ -->
            <div id="all-loaded-message" class="text-center py-4 md:py-6 hidden">
                <p class="text-sm md:text-base">すべての動画を表示しました</p>
            </div>
        </div>


    </div>

    <!-- 変換プログレスモーダル -->
    <div id="conversion-progress-modal" class="fixed inset-0 z-50 hidden">
        <!-- オーバーレイ -->
        <div class="absolute inset-0 bg-black bg-opacity-75 transition-opacity duration-300"></div>

        <!-- モーダルコンテンツ -->
        <div class="relative min-h-screen flex items-center justify-center p-4">
            <div class="conversion-modal rounded-xl md:rounded-2xl lg:rounded-3xl p-6 md:p-8 lg:p-10 max-w-md md:max-w-lg lg:max-w-xl w-full shadow-2xl">
                <!-- ヘッダー -->
                <div class="flex items-center justify-between mb-6">
                    <h3 class="font-bold conversion-modal-header">動画変換中</h3>
                    <button id="close-conversion-modal" class="conversion-modal-close hover:bg-gray-700/50 rounded-lg transition-colors duration-200 p-1" onclick="closeConversionModal()">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- 動画情報 -->
                <div class="mb-6 conversion-modal-content">
                    <div class="flex items-center space-x-3 mb-3">
                        <div class="w-16 h-12 conversion-modal-thumbnail rounded overflow-hidden flex-shrink-0">
                            <img id="conversion-thumbnail" src="images/default-thumbnail-small.svg" alt="サムネイル" class="w-full h-full object-cover">
                        </div>
                        <div class="flex-1 min-w-0">
                            <p id="conversion-title" class="font-medium text-sm md:text-base truncate conversion-modal-title">動画タイトル</p>
                            <p id="conversion-filename" class="text-xs md:text-sm truncate conversion-modal-filename">ファイル名</p>
                        </div>
                    </div>
                </div>

                <!-- プログレスバー -->
                <div class="mb-6">
                    <div class="flex justify-between items-center mb-2">
                        <span id="conversion-status" class="text-sm md:text-base conversion-modal-title">変換を開始しています...</span>
                        <span id="conversion-percentage" class="text-sm md:text-base font-medium conversion-modal-title">0%</span>
                    </div>
                    <div class="w-full conversion-modal-progress-bg rounded-full h-3 md:h-4">
                        <div id="conversion-progress-bar" class="conversion-modal-progress-bar h-3 md:h-4 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                    </div>
                </div>

                <!-- 詳細情報 -->
                <div class="mb-6 p-4 conversion-modal-details rounded-lg">
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="conversion-modal-details-label">ファイル形式</p>
                            <p id="conversion-format" class="font-medium conversion-modal-details-value">-</p>
                        </div>
                        <div>
                            <p class="conversion-modal-details-label">処理時間</p>
                            <p id="conversion-time" class="font-medium conversion-modal-details-value">-</p>
                        </div>
                    </div>
                </div>

                <!-- ボタン -->
                <div class="flex justify-end space-x-3">
                    <button id="cancel-conversion-btn" onclick="cancelConversion()" class="px-4 py-2 conversion-modal-cancel-btn text-sm md:text-base rounded-lg transition-colors duration-200">
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php include 'footer.php'; ?>
</body>

</html>