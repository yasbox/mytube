# MyTube

シンプルにセルフホストできる動画共有アプリです。管理パネルからアップロード・公開/非公開・削除・変換を行えます。

## 主な機能

- 視聴・再生、いいね、一覧ソート（新着/人気/再生数/いいね。人気は「再生数＋いいね数×2」、同じ値なら新しい順。トップページと管理画面で同じ順番）
- 管理パネル（公開/非公開、削除、統計）＋アップロード専用ページ
- 大容量チャンクアップロード、サムネイル自動生成、メタ情報保存
- 動画変換（MP4 変換の開始・進捗確認・停止が可能）
- 共有リンク（24時間有効のワンタイムパスワード付き）

## 動作環境

- PHP 8.2 + Apache、FFmpeg
- Docker / Docker Compose（推奨）

## 使い方（Docker）

1) 環境変数を用意
```bash
cp src/env.example src/.env
# ADMIN_PASSWORD は必ず設定する（例）
# ADMIN_PASSWORD=your_admin_password
```
`ADMIN_PASSWORD` が未設定（または以前の既定値 `admin123` のまま）だと、管理者としてログインできません。

2) 起動
```bash
docker compose up -d
```

3) アクセス
- 閲覧: `http://localhost:8080/`
- 管理パネル: `http://localhost:8080/admin.php`
- アップロード: `http://localhost:8080/upload.php`
- 設定: `http://localhost:8080/settings.php`

管理パネルのログインには `.env` の `ADMIN_PASSWORD` を使用します。
（`ADMIN_PASSWORD` を環境変数で設定している場合、UIからの変更はできません）

## 設定

- 読み込み優先度（下ほど強い）
  1. `src/config/default.php`
  2. `src/data/settings.json`（初回自動生成／管理画面から更新）
  3. `.env`（環境変数）
- よく使う項目
  - アプリ共通: `APP_NAME`, `DEBUG_MODE`
  - 認証: `ADMIN_PASSWORD`（環境変数で設定している場合はUIから変更不可）
  - アップロード: `UPLOAD_MAX_SIZE`（バイト）, `CHUNK_SIZE`（バイト）
  - FFmpeg: `FFMPEG_PATH`
  - 変換（任意）: `CONVERT_TO_MP4`, `VIDEO_CODEC`, `VIDEO_PRESET`, `VIDEO_CRF`, `AUDIO_CODEC`, `AUDIO_BITRATE`, `WEB_OPTIMIZE`
- パスワード保護や閲覧用ユーザーパスワードは管理画面の「設定」から切り替えできます。

### 共有リンクについて
- 管理者ログイン時、動画ページの「共有リンク」から発行できます（サイトが「パスワードで保護」ONのときに表示）
- 24時間有効のワンタイムパスワード付きリンク（`share` クエリで認証）。受け取った人はログインなしでその動画だけを閲覧できます
- 「共有」ボタンは通常の動画 URL をコピーするだけで、パスワード保護 ON のサイトでは閲覧にログインが必要です

### 動画ファイルの保護
- `videos/`・`thumbnails/` へのアクセスは `media.php` が閲覧権限を確認し、許可した場合のみ期限付きの専用 URL（`media/…`、6〜12時間有効）へリダイレクトします。ファイル自体は Web サーバーが静的配信するため、シーク再生にも対応します
- パスワード保護 ON のときは、ログイン済みか、共有リンクでその動画を開いた場合のみ取得できます。非公開の動画は管理者のみです
- 専用 URL の有効期間は `security.media_url_ttl`（秒、既定 6 時間）で変更できます

## 複数サイトへの反映

- サーバー上の各サイトへのデプロイ手順は [`deploy/README.md`](deploy/README.md) を参照してください

## 保存場所（デフォルト）

- 動画: `src/videos/`
- サムネイル: `src/thumbnails/`
- 動画メタデータ: `src/videos/<basename>.json`
- アプリ設定: `src/data/settings.json`

## トラブルシュート（簡易）

- アップロード/変換に失敗: FFmpeg のパスと `UPLOAD_MAX_SIZE` を確認
- ログ確認: `docker compose logs web`

## ライセンス

MIT License
