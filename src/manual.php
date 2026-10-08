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
$pageCss = 'admin';
$isAdminPage = true;
$maxUploadMb = (int)Config::get('features.upload.max_size_mb', 500);
?>
<!DOCTYPE html>
<html lang="ja">
<?php include 'head.php'; ?>
<body class="min-h-screen flex flex-col">
  <?php include 'header.php'; ?>

  <main class="studio">
    <?php $adminTab = 'manual'; $adminTitle = '使い方'; include 'admin_nav.php'; ?>

    <div class="manual">
      <p class="manual-lead">管理者向けの操作ガイドです。各画面でできることを簡単にまとめています。</p>

      <div class="manual-cards">
        <a href="#watch" class="manual-card">
          <div class="manual-card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><circle cx="12" cy="12" r="9"/></svg>動画を見る</div>
          <p>検索・続きから再生・次の動画の自動再生</p>
        </a>
        <a href="#upload" class="manual-card">
          <div class="manual-card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 5v14m7-7H5"/></svg>アップロード</div>
          <p>PC やスマホから動画をアップロード</p>
        </a>
        <a href="#admin" class="manual-card">
          <div class="manual-card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>動画の管理</div>
          <p>編集・公開/非公開・変換・削除</p>
        </a>
        <a href="#settings" class="manual-card">
          <div class="manual-card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>設定</div>
          <p>サイト名・ロゴ・パスワード保護など</p>
        </a>
        <a href="#share" class="manual-card">
          <div class="manual-card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>共有リンク</div>
          <p>パスワード保護中でも24時間だけ見られるリンク。途中で無効にもできます</p>
        </a>
        <a href="#disclaimer" class="manual-card">
          <div class="manual-card__title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>ご利用にあたって</div>
          <p>このアプリの位置づけと注意点</p>
        </a>
      </div>

      <h2 id="watch">動画を見る</h2>
      <ul>
        <li>検索: 上の検索欄から、タイトルと説明に含まれる言葉で動画を探せます。</li>
        <li>並べ替え: トップページの「新しい順・人気順・再生数順・いいね数順」で並べ替えられます。人気順は「再生数＋いいね数×2」の順です。</li>
        <li>続きから再生: 途中まで見た動画は、次に開くと続きから再生します。一覧のサムネイルの赤いバーは、どこまで見たかを表します。</li>
        <li>次の動画: 動画ページの右側（スマホでは下）に、トップページの並び順で次の動画が並びます。「自動再生」がオンなら、見終わると次の動画へ進みます。</li>
      </ul>
      <p class="manual-note">続きから再生の位置と自動再生のオン・オフは、使っているブラウザごとに記録されます（PC とスマホでは共有されません）。</p>

      <h2 id="upload">アップロード</h2>
      <ol>
        <li>「アップロード」タブで、動画ファイルを選ぶか、画面にドラッグ＆ドロップします。</li>
        <li>タイトルと説明（どちらも任意）を入力して「アップロード」を押します。</li>
        <li>対応形式は MP4（推奨）, WebM, OGG, AVI, MOV, MKV, FLV です。最大のファイルサイズは <?= number_format($maxUploadMb) ?>MB です。</li>
      </ol>

      <h2 id="admin">動画の管理</h2>
      <p>「動画」タブで、統計（動画数・再生数など）の確認と、動画ごとの操作ができます。</p>
      <ul>
        <li>サムネイルかタイトルをクリック: 動画ページを開きます（非公開の動画も管理者は見られます）。</li>
        <li>公開 / 非公開: ボタンで切り替えます。非公開の動画は一覧に出ず、管理者以外は見られません。</li>
        <li>編集（鉛筆のボタン）: タイトル・説明・サムネイルを変更できます。サムネイルは画像をクリックして差し替えるか、「動画から作り直す」で動画から自動で作ったものに戻せます。</li>
        <li>変換（矢印のボタン）: MP4 以外の動画を MP4 に変換します（MP4 は作り直し）。長い動画は時間がかかり、失敗することもあります。サーバーに負担がかかるため、必要なときだけ使ってください。</li>
        <li>削除（ごみ箱のボタン）: 動画を削除します。</li>
      </ul>
      <p class="manual-note">削除は取り消せません。慎重に実行してください。</p>

      <h2 id="settings">設定</h2>
      <h3>サイト</h3>
      <ul>
        <li>サイト名・サイトの説明・ロゴ・最初のテーマ（ライト / ダーク）を変更できます。</li>
      </ul>
      <h3>セキュリティ</h3>
      <ul>
        <li>パスワードで保護する: オンにすると、見るときにパスワードが必要になります。</li>
        <li>閲覧用のパスワード: 見る人に伝えるパスワードです（半角英数字と記号）。</li>
      </ul>
      <h3>再生とカウント</h3>
      <ul>
        <li>動画の自動再生: 動画ページを開いたら自動で再生を始めます。</li>
        <li>再生数: ページを開いて再生が始まったときに1回数えます（一時停止やシークでは増えません）。「重複カウントを制限」をオンにすると、同じ人がブラウザのタブを閉じるまでは1回だけ数えます。</li>
        <li>いいね: 「重複カウントを制限」をオンにすると、同じ人がタブを閉じるまでは1回だけ数えます。</li>
      </ul>

      <h2 id="share">共有リンク</h2>
      <p>パスワード保護がオンのとき、管理者は動画ページの「共有リンク」ボタンから、ログインせずに見られるリンクを発行できます。</p>
      <ul>
        <li>有効期限: 発行から24時間で使えなくなります。</li>
        <li>見られる範囲: その動画だけです（一覧や次の動画は表示されません）。</li>
        <li>発行し直し: 期限内に発行し直すと同じリンクになります（期限は延びません）。無効にしたあとに発行すると、新しいリンクになります。</li>
        <li>確認・無効化: 「動画」タブの「共有中のリンク」に、今使えるリンクと残り時間が出ます。「無効にする」を押すと、期限前でもすぐに使えなくなります（使えるリンクがないときは表示されません）。</li>
        <li>リンクは自動でコピーされ、対応している端末では共有画面が開きます。</li>
      </ul>

      <h2 id="disclaimer">ご利用にあたって</h2>
      <p>このアプリは、小規模なグループ内で手軽に動画を共有するためのシンプルなツールです。次の点をご確認のうえご利用ください。</p>
      <ul>
        <li>常に動いていることや、すべての機能が完璧に動くことはお約束できません。</li>
        <li>基本的な安全対策はしていますが、高度な防御や厳格な運用は対象外です。</li>
        <li>個人情報や重要な情報など、機密性の高い内容のアップロードはお控えください。</li>
        <li>共有リンクは24時間で使えなくなりますが、URL が他の人に伝わると見られてしまう可能性があります。心配なときは「共有中のリンク」から無効にしてください。</li>
        <li>まれに動画や情報が消えたり壊れたりする可能性があります。大切な動画は手元にも保存してください。</li>
        <li>利用により生じた損害やトラブルについて、開発・運用側では責任を負えません。</li>
      </ul>
    </div>
  </main>
<?php include 'footer.php'; ?>
</body>
</html>
