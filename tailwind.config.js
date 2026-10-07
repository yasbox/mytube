// 画面用 CSS（src/assets/css/tailwind.css）を作るための Tailwind CSS の設定
// PHP・JS のクラスを追加・変更したら `npm run build:css` で作り直し、できた CSS もコミットする
// （サーバーでは作らない。deploy/deploy.sh は作り直し忘れがあると反映を止める）
//
// 設定は Tailwind の標準のまま。以前は CDN 版を使っていて head.php に独自の設定
// （ブラウザ標準スタイルのリセットを使わない・3xl などの大画面用の幅・ヘッダー用の色）を書いていたが、
// CDN の読み込み前に書いていたため実際には効いておらず、標準の設定で表示されていた。
// 見た目を変えないよう、実際に効いていた標準の設定に合わせている
// （そのため 3xl: 4xl: のクラスは今も効かない。使うなら theme.extend.screens に追加する）
/** @type {import('tailwindcss').Config} */
module.exports = {
  // ここに書いたファイルの中に出てくるクラス名の分だけ CSS を作る
  content: ['./src/**/*.php', './src/assets/js/**/*.js'],
};
