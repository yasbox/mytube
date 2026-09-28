# デプロイ

1 つのコードベースから、複数のインスタンス（サイト）へ同じコードを配布します。
インスタンスごとの違いは `.env` と `data/`（サイト名・ロゴ・パスワード等）だけで、コードは共通です。

## 仕組み

```
GitHub（このリポジトリ）
   │ git fetch
   ▼
サーバー: <作業ディレクトリ>/            ← インスタンスごとの git clone
            ├─ .git/                     （公開されない）
            └─ src/  ◀── ドキュメントルートからシンボリックリンク
                 ├─ .env, data/, videos/, thumbnails/   ← 実行時データ（.gitignore 対象）
                 └─ *.php, assets/ ...                  ← デプロイで更新されるコード
```

`deploy.sh` は SSH でサーバーに入り、作業ディレクトリを指定したコミットへ `git checkout` します。
実行時データは git の管理外なので、デプロイで上書き・削除されません。

## 準備

1. Git Bash と、`~/.ssh/config` にサーバーの `Host` 設定（鍵認証）を用意する
2. `deploy/instances.example.conf` を `deploy/instances.conf` にコピーして編集する

## 使い方

```bash
# 反映内容の確認だけ（作業ツリーは変更しない）
deploy/deploy.sh -n all

# デプロイ（GitHub の origin/master を反映。インスタンスごとに確認あり）
deploy/deploy.sh cocotube
deploy/deploy.sh all

# 特定のコミットやブランチを反映（GitHub へ push 済みのもの）
deploy/deploy.sh -r <コミット> mytube

# 直前のデプロイ前の状態に戻す（もう一度実行すると元に戻る）
deploy/deploy.sh --rollback cocotube
```

反映前に次を表示・確認します。

- 追加されるコミットと、変更されるファイル
- 変更される PHP ファイルの構文チェック（サーバーの CLI php を使用）
- サーバー上で直接変更されたファイルがないか（あれば中止）

反映後、`instances.conf` に URL があれば、ログイン画面の表示と `.env` 等が公開されていないことを確認します。

デプロイ履歴はサーバーの `<作業ディレクトリ>/.git/mytube-deploy.log` に残ります。

## 注意

- **サーバー上でコードを直接編集しないでください。** 次のデプロイが中止されます。
  修正はこのリポジトリで行い、push してからデプロイします。
- デプロイできるのは GitHub に push 済みのコミットだけです（サーバーが GitHub から取得するため）。
- 旧リポジトリ（cocotube）を origin にしているインスタンスは、初回だけ `--switch-origin` を付けて実行します。
  付け替え前の状態は `refs/mytube-deploy/before-switch` に保存されます。
- コミット 9dd986f（`.htaccess` の修正）より前へロールバックすると、`.env` 等が再び公開されます。
  反映後の確認で警告が出た場合は、すぐに最新版へデプロイし直してください。

## 新しいインスタンスを追加する

```bash
# サーバー上で
git clone git@github.com:yasbox/mytube.git <作業ディレクトリ>
cp <作業ディレクトリ>/src/env.example <作業ディレクトリ>/src/.env   # 編集する
ln -s <作業ディレクトリ>/src <ドキュメントルート>                  # 既存のドキュメントルートは退避してから
```

その後、`deploy/instances.conf` に 1 行追加します。
