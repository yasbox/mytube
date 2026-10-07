// 画面用 CSS（src/assets/css/tailwind.css）を作るための Tailwind CSS の設定
// PHP・JS のクラスを追加・変更したら `npm run build:css` で作り直し、できた CSS もコミットする
// （サーバーでは作らない。deploy/deploy.sh は作り直し忘れがあると反映を止める）
//
// 以前は CDN 版を使っていて head.php に独自の設定を書いていたが、CDN の読み込み前に書いていたため
// 実際には効いておらず、標準の設定で表示されていた。今の見た目はその標準の設定を前提に調整されているため、
// ブラウザ標準スタイルのリセット（preflight）は標準どおり有効のままにしている
/** @type {import('tailwindcss').Config} */
module.exports = {
  // ここに書いたファイルの中に出てくるクラス名の分だけ CSS を作る
  content: ['./src/**/*.php', './src/assets/js/**/*.js'],
  theme: {
    extend: {
      // 大きなモニター向けの幅（3xl:px-24 のように使う。標準の 2xl は 1536px まで）
      screens: {
        '3xl': '1920px',
        '4xl': '2560px',
        '5xl': '3200px',
      },
    },
  },
};
