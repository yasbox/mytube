# MyTube

シンプルにセルフホストできる動画共有アプリです。管理パネルからアップロード・公開/非公開・削除・変換を行えます。

## 主な機能

- 視聴・再生、いいね、一覧ソート（新着/人気/再生数/いいね）
- 管理パネル（アップロード、公開/非公開、削除、統計）
- 大容量チャンクアップロード、サムネイル自動生成、メタ情報保存
- 共有リンク（ワンタイムパスワード付き共有も対応）

## 動作環境

- PHP 8.2 + Apache、FFmpeg
- Docker / Docker Compose（推奨）

## 使い方（Docker）

1) 環境変数を用意
```bash
cp src/env.example src/.env
# 必要に応じて編集（例）
# ADMIN_PASSWORD=your_admin_password
```

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

## 設定

- 読み込み優先度（下ほど強い）
  1. `src/config/default.php`
  2. `src/data/settings.json`（初回自動生成／管理画面から更新）
  3. `.env`（環境変数）
- よく使う項目
  - `APP_NAME`, `DEBUG_MODE`
  - `ADMIN_PASSWORD`
  - `UPLOAD_MAX_SIZE`（バイト）, `CHUNK_SIZE`（バイト）
  - `FFMPEG_PATH`
- パスワード保護やユーザーパスワードは管理画面の「設定」から切り替えできます。

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
