<?php
require_once __DIR__ . '/init_web.php';

// リメンバーミーによる自動ログイン
if (function_exists('checkRememberMe')) {
    checkRememberMe();
}

// 管理者のみ閲覧可能
if (!isUserAuthenticated() || !isAdmin()) {
  header('Location: login.php');
  exit;
}

$pageTitle = '使い方 - ' . Config::get('app.name', 'MyTube');
$pageCss = 'index';
// 管理系ページとしてヘッダーに設定リンクを表示する
$isAdminPage = true;
include 'head.php';
?>

<body class="min-h-screen flex flex-col">
  <?php include 'header.php'; ?>

  <main class="max-w-5xl mx-auto px-4 md:px-8 lg:px-12 py-8 md:py-12 text-base md:text-lg lg:text-xl">
    <section class="mb-10">
      <h1 class="font-extrabold tracking-tight video-title-main mb-3">使い方ガイド</h1>
      <p class="video-meta-info text-base md:text-lg">このページは管理者向けの操作ガイドです。アプリ全体の使い方を簡単にまとめています。</p>
    </section>

    <section class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12">
      <a href="#upload" class="group rounded-xl p-5 video-info-container transition-all">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-blue-500/10" style="background-color: var(--blue-500-10);">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
          </div>
          <div class="text-xl font-bold">動画アップロード</div>
        </div>
        <p class="video-meta-info text-base md:text-lg">PCまたはスマホから動画を簡単にアップロードできます。</p>
      </a>
      <a href="#admin" class="group rounded-xl p-5 video-info-container transition-all">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-gray-500/10">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </div>
          <div class="text-xl font-bold">管理パネル</div>
        </div>
        <p class="video-meta-info text-base md:text-lg">管理・統計・設定ページ。動画の削除/編集などができます。</p>
      </a>
      <a href="#settings" class="group rounded-xl p-5 video-info-container transition-all">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-yellow-500/10">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.983 8.25a.75.75 0 01.75.75v.542a5.5 5.5 0 012.058.852l.383-.383a.75.75 0 011.06 0l.53.53a.75.75 0 010 1.06l-.383.383c.38.63.652 1.33.797 2.066h.542a.75.75 0 01.75.75v.75a.75.75 0 01-.75.75h-.542a5.5 5.5 0 01-.852 2.058l.383.383a.75.75 0 010 1.06l-.53.53a.75.75 0 01-1.06 0l-.383-.383a5.5 5.5 0 01-2.066.797v.542a.75.75 0 01-.75.75h-.75a.75.75 0 01-.75-.75v-.542a5.5 5.5 0 01-2.058-.852l-.383.383a.75.75 0 01-1.06 0l-.53-.53a.75.75 0 010-1.06l.383-.383A5.5 5.5 0 016.5 13.75H5.958a.75.75 0 01-.75-.75v-.75c0-.414.336-.75.75-.75H6.5c.145-.736.417-1.436.797-2.066l-.383-.383a.75.75 0 010-1.06l.53-.53a.75.75 0 011.06 0l.383.383a5.5 5.5 0 012.058-.852V9a.75.75 0 01.75-.75h.75z" />
            </svg>
          </div>
          <div class="text-xl font-bold">設定</div>
        </div>
        <p class="video-meta-info text-base md:text-lg">アプリ全体の各種設定（アクセス制御・機能制限）を変更できます。</p>
      </a>
      <a href="#share" class="group rounded-xl p-5 video-info-container transition-all">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-green-500/10" style="background-color: var(--green-500-10);">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
            </svg>
          </div>
          <div class="text-xl font-bold">共有リンク</div>
        </div>
        <p class="video-meta-info text-base md:text-lg">パスワード保護中でも、24時間有効のログイン不要で閲覧可能なリンクを発行できます。（管理者専用）</p>
      </a>
    </section>

    <section id="upload" class="mb-12">
      <h3 class="font-bold mb-3">動画アップロード</h3>
      <ol class="list-decimal pl-5 space-y-2 video-meta-info text-base md:text-lg">
        <li>ファイルを選択し、タイトル・コメントを入力してアップロードボタンを押します。</li>
        <li>対応形式は MP4（推奨）, WebM, OGG, AVI, MOV, MKV, FLV です。</li>
        <li>アップロードできる最大のファイルサイズは、<?= htmlspecialchars(number_format((int)Config::get('features.upload.max_size_mb', 500))) ?>MBです。</li>
      </ol>
    </section>

    <section id="admin" class="mb-12">
      <h3 class="font-bold mb-3">管理パネル</h3>
      <div class="space-y-3 video-meta-info text-base md:text-lg">
        <p>動画の管理を行うページです。</p>
        <ul class="list-disc pl-5 space-y-1">
          <li>統計の確認: 再生数・いいね数などの基本メトリクス</li>
          <li>動画の管理: 動画情報の編集、エンコード、公開/非公開の設定、動画の削除</li>
          <li>動画情報編集: 動画のタイトル・コメント・サムネイルを編集できます。</li>
          <li>エンコード: MP4以外の動画をMP4に変換します。ただし、長い動画の場合は時間がかかり失敗する可能性があります。また、サーバに負担をかけるためお控えください。</li>
        </ul>
        <p class="text-sm md:text-base text-gray-500">注: 一部の操作は取り消しできません。特に削除は慎重に実行してください。</p>
      </div>
    </section>

    <section id="settings" class="mb-12">
      <h3 class="font-bold mb-3">設定</h3>
      <div class="space-y-4 video-meta-info text-base md:text-lg">
        <div class="space-y-2">
          <h4 class="font-bold mb-1">サイト設定</h4>
          <ul class="list-disc pl-5 space-y-1">
            <li>サイト名を変更できます。</li>
            <li>サイトの説明文を変更できます。</li>
            <li>サイトのロゴを変更できます。</li>
            <li>サイトのデフォルトテーマを設定できます。</li>
          </ul>
        </div>
        <div class="space-y-2">
          <h4 class="font-bold mb-1">セキュリティ設定</h4>
          <ul class="list-disc pl-5 space-y-1">
            <li>パスワード保護: サイト全体をパスワードで保護します。</li>
            <li>パスワードの文字数や形式などは特に制限はありません。（半角英数字と記号のみ）</li>
          </ul>
        </div>
        <div class="space-y-2">
          <h4 class="font-bold mb-1">機能設定</h4>
          <ul class="list-disc pl-5 space-y-1">
            <li>動画の自動再生: 動画を自動で再生するかどうかを設定できます。</li>
            <li>いいねの重複カウントを制限: 続けていいねを押してもカウントされません。</li>
            <li>再生数の重複カウントを制限: 続けて再生してもカウントされません。</li>
          </ul>
        </div>
        <p class="text-sm md:text-base text-gray-500">注: ブラウザを一度終了すると制限が解除されます。</p>
      </div>
    </section>

    <section id="share" class="mb-12">
      <h3 class="font-bold mb-3">共有リンク</h3>
      <div class="space-y-2 video-meta-info text-base md:text-lg">
        <p>管理者は動画詳細ページの「共有リンク」ボタンから、共有リンクを発行できます。</p>
        <ul class="list-disc pl-5 space-y-1">
          <li>有効期限: 発行から24時間で自動失効します。</li>
          <li>ログイン不要: 受け取ったユーザーは認証なしで視聴可能です。</li>
          <li>再発行: 期限内の再発行は同じリンクを返し、期間を延長します。</li>
          <li>無効化: 途中での手動無効化機能はありません（取り扱いに注意）</li>
          <li>コピー/共有: URLは自動コピー。対応端末では共有シートを起動します。</li>
        </ul>
        <p class="text-sm md:text-base text-gray-500">注: パスワード保護が有効な場合のみの機能です。</p>
      </div>
    </section>

    <section id="disclaimer" class="mb-12">
      <h3 class="font-bold mb-3">免責事項</h3>
      <div class="space-y-2 video-meta-info text-base md:text-lg">
        <p>本アプリは、小規模なグループ内での簡易的な動画共有を目的としたシンプルなツールです。次の点をご確認のうえご利用ください。</p>
        <ul class="list-disc pl-5 space-y-1">
          <li>機能提供について: 常時の稼働やすべての機能の完璧な動作はお約束できません。</li>
          <li>セキュリティについて: 基本的な対策はありますが、高度な防御や厳格な運用は対象外です。</li>
          <li>機密情報の取り扱い: 個人情報や重要な社内情報など、機密性の高い内容のアップロードはお控えください。</li>
          <li>共有リンク: 共有リンクは24時間で失効しますが、URLが他の人に伝わると見られてしまう可能性があります。</li>
          <li>データについて: まれに動画や情報が消えたり壊れたりする可能性があります。必要に応じて各自でバックアップしてください。</li>
          <li>責任について: 本アプリの利用により生じた損害やトラブルについて、開発・運用側では責任を負えません。</li>
        </ul>
        <p class="text-sm md:text-base text-gray-500">なお、高い安全性や厳密な運用が必要な場合は、専用の商用サービスや別のプラットフォームのご利用をご検討ください。</p>
      </div>
    </section>

  </main>
  <?php include 'footer.php'; ?>
</body>

</html>