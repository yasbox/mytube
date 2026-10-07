#!/usr/bin/env bash
# MyTube デプロイスクリプト
#
# 各インスタンスのサーバー上の git 作業ディレクトリを、指定したコミットへ切り替える。
# .env / data / videos / thumbnails 等の実行時データは .gitignore 対象のため影響を受けない。
# 使い方は deploy/README.md を参照。
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel)
CONFIG="$SCRIPT_DIR/instances.conf"
REF=origin/master
MODE=deploy
ASSUME_YES=false
SWITCH_ORIGIN=false

usage() {
  cat <<'EOF'
使い方: deploy/deploy.sh [オプション] <インスタンス名>... | all

  -n, --dry-run        反映内容の確認のみ（作業ツリーは変更しない）
  -r, --ref <ref>      デプロイするコミット（既定: origin/master）
      --rollback       直前のデプロイ前の状態に戻す（もう一度実行すると元に戻る）
      --switch-origin  サーバーの origin をこのリポジトリへ付け替える（旧リポジトリからの移行時のみ）
  -y, --yes            確認を省略
  -c, --config <file>  設定ファイル（既定: deploy/instances.conf）
  -h, --help           このヘルプ
EOF
}

die() { echo "エラー: $*" >&2; exit 1; }
warn() { echo "警告: $*" >&2; }

TARGETS=()
while [ $# -gt 0 ]; do
  case "$1" in
    -n|--dry-run) MODE=dry-run ;;
    --rollback) MODE=rollback ;;
    --switch-origin) SWITCH_ORIGIN=true ;;
    -r|--ref) [ $# -ge 2 ] || die "$1 には値が必要です"; REF=$2; shift ;;
    -c|--config) [ $# -ge 2 ] || die "$1 には値が必要です"; CONFIG=$2; shift ;;
    -y|--yes) ASSUME_YES=true ;;
    -h|--help) usage; exit 0 ;;
    -*) die "不明なオプション: $1" ;;
    *) TARGETS+=("$1") ;;
  esac
  shift
done
[ ${#TARGETS[@]} -gt 0 ] || { usage; exit 1; }

# ---- 設定ファイル: <名前> <SSHホスト> <サーバー上の作業ディレクトリ> [確認用URL]
[ -f "$CONFIG" ] || die "設定ファイルがありません: $CONFIG（deploy/instances.example.conf をコピーして作成）"
declare -A HOSTS APPS URLS
ALL=()
while read -r name host app url _; do
  case "$name" in ''|'#'*) continue ;; esac
  [[ $name =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "インスタンス名が不正です: $name"
  [[ $host =~ ^[A-Za-z0-9._@-]+$ ]] || die "$name: SSHホストが不正です: $host"
  [[ $app =~ ^/[A-Za-z0-9._/-]+$ ]] || die "$name: 作業ディレクトリは英数字と ._/- のみの絶対パスで指定してください: $app"
  [[ -z ${url:-} || $url =~ ^https?://[^[:space:]]+$ ]] || die "$name: URLが不正です: $url"
  HOSTS[$name]=$host
  APPS[$name]=$app
  URLS[$name]=${url%/}
  ALL+=("$name")
done < <(tr -d '\r' < "$CONFIG")

if [ "${TARGETS[*]}" = all ]; then
  TARGETS=("${ALL[@]}")
fi
for t in "${TARGETS[@]}"; do
  [ -n "${HOSTS[$t]:-}" ] || die "設定ファイルにないインスタンスです: $t"
done

# ---- デプロイ対象のコミットとリポジトリ名（例: yasbox/mytube）
REPO_SLUG=$(git -C "$REPO_ROOT" remote get-url origin | sed -E 's#^.*[:/]([^/:]+/[^/:]+)$#\1#; s#\.git$##')
[[ $REPO_SLUG =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || die "origin からリポジトリ名を判別できません: $REPO_SLUG"

COMMIT=-
if [ "$MODE" != rollback ]; then
  case "$REF" in origin/*) git -C "$REPO_ROOT" fetch --quiet origin ;; esac
  COMMIT=$(git -C "$REPO_ROOT" rev-parse --verify --quiet "$REF^{commit}") || die "コミットが見つかりません: $REF"
  echo "デプロイするコミット: $(git -C "$REPO_ROOT" log -1 --format='%h %s' "$COMMIT")（$REF）"
fi

# ---- 画面用 CSS（Tailwind）の作り直し忘れがないか
# サーバーでは CSS を作らず、リポジトリの src/assets/css/tailwind.css をそのまま使うため、
# PHP・JS のクラスを変えたのに作り直していないと、新しいクラスの見た目が反映されない
check_css() {
  git -C "$REPO_ROOT" cat-file -e "$COMMIT:tailwind.config.js" 2>/dev/null || return 0   # CSS ファイル化より前のコミット
  local cli="$REPO_ROOT/node_modules/tailwindcss/lib/cli.js" tmp ok=true
  if [ ! -f "$cli" ] || ! command -v node >/dev/null 2>&1; then
    warn "画面用 CSS が最新かを確認できませんでした（node と、リポジトリでの npm install が必要です）"
    return 0
  fi
  tmp=$(mktemp -d)
  git -C "$REPO_ROOT" archive "$COMMIT" src tailwind tailwind.config.js | tar -x -C "$tmp"
  if ! (cd "$tmp" && node "$cli" -c tailwind.config.js -i tailwind/input.css -o built.css >/dev/null 2>&1) \
    || ! cmp -s <(tr -d '\r' < "$tmp/built.css") <(tr -d '\r' < "$tmp/src/assets/css/tailwind.css"); then
    ok=false
  fi
  rm -rf "$tmp"
  $ok || die "src/assets/css/tailwind.css が PHP・JS のクラスと合っていません。npm run build:css で作り直し、コミット・push してから実行してください"
  echo "画面用 CSS: 最新です"
}
[ "$MODE" = rollback ] || check_css

# ---- サーバー側で実行するスクリプト（引数: モード 名前 作業ディレクトリ コミット リポジトリ名 origin付け替え可否）
IFS= read -r -d '' REMOTE_SCRIPT <<'REMOTE' || true
set -euo pipefail
mode=$1 name=$2 app=$3 commit=$4 repo=$5 switch=$6

fail() { echo "エラー[$name]: $*" >&2; exit 1; }
short() { git rev-parse --short "$1"; }
subject() { git log -1 --format=%s "$1"; }

cd "$app" 2>/dev/null || fail "ディレクトリがありません: $app"
[ -d .git ] || fail "git の作業ディレクトリではありません: $app"
[ -f src/video_converter.php ] || fail "MyTube のディレクトリではないようです: $app"
log=.git/mytube-deploy.log   # デプロイ履歴（作業ツリーの外なので公開されない）
cur=$(git rev-parse HEAD)
branch=$(git symbolic-ref --short -q HEAD || echo detached)
show=false
case "$mode" in preview|rollback-preview) show=true ;; esac

need_switch=false
fetch_url=$(git remote get-url origin 2>/dev/null || true)
case "$mode" in
  preview|apply)
    case "$fetch_url" in
      *[:/]"$repo"|*[:/]"$repo".git) ;;
      *)
        [ "$switch" = true ] || fail "origin が $repo ではありません（$fetch_url）。旧リポジトリからの移行は --switch-origin を付けて実行してください"
        need_switch=true
        old_url=$fetch_url
        fetch_url=$(printf '%s' "$old_url" | sed -E "s#[^/:]+/[^/:]+\$#$repo.git#")
        echo "origin の付け替え: $old_url → $fetch_url"
        ;;
    esac
    if $need_switch; then
      # origin はまだ変えず、一時的な参照名で取得する（確認後に参照だけ削除）
      git fetch --quiet "$fetch_url" '+refs/heads/*:refs/mytube-deploy/preview/*'
    else
      git fetch --quiet origin
    fi
    found=true
    git cat-file -e "$commit^{commit}" 2>/dev/null || found=false
    git for-each-ref --format='%(refname)' refs/mytube-deploy/preview/ | while IFS= read -r r; do
      git update-ref -d "$r"
    done
    $found || fail "コミット ${commit:0:7} をサーバーで取得できません（GitHub へ push 済みか確認してください）"
    ;;
  rollback-preview|rollback-apply)
    [ -s "$log" ] || fail "デプロイ履歴がないため戻せません（$app/$log）"
    # 履歴の形式: 日付 時刻 種別 変更前コミット 変更後コミット
    read -r _ _ _ commit last_to < <(tail -n 1 "$log")
    [ "$last_to" = "$cur" ] || echo "注意: 現在のコミット $(short "$cur") が履歴の最終状態 ${last_to:0:7} と異なります"
    git cat-file -e "$commit^{commit}" 2>/dev/null || fail "戻し先のコミット ${commit:0:7} がありません"
    ;;
  *) fail "不明なモード: $mode" ;;
esac

if $show; then
  echo "現在: $(short "$cur") $(subject "$cur")  [ブランチ: $branch]"
  echo "反映: $(short "$commit") $(subject "$commit")"
fi

# 追跡ファイルがサーバー上で直接変更されていないか（反映先と同じ内容なら引き継ぐ）
carry=""
blocked=""
while IFS= read -r f; do
  [ -n "$f" ] || continue
  if git diff --quiet "$commit" -- "$f"; then carry="$carry $f"; else blocked="$blocked $f"; fi
done < <(git diff --name-only HEAD)
[ -z "$blocked" ] || fail "サーバー上で直接変更されたファイルがあります:$blocked（内容を確認し、リポジトリへ取り込むか破棄してから再実行してください）"

if [ "$cur" = "$commit" ] && ! $need_switch; then
  echo "変更なし（既に $(short "$commit") です）"
  case "$mode" in apply|rollback-apply) exit 0 ;; esac
  exit 3   # 呼び出し側で反映をスキップする合図
fi

if $show; then
  if git merge-base --is-ancestor "$cur" "$commit" 2>/dev/null; then
    echo "--- 追加されるコミット ---"
    git log --format='  %h %ad %s' --date=short "$cur..$commit" | head -n 30
  elif git merge-base --is-ancestor "$commit" "$cur" 2>/dev/null; then
    echo "--- 取り消されるコミット（巻き戻し） ---"
    git log --format='  %h %ad %s' --date=short "$commit..$cur" | head -n 30
  else
    echo "（履歴がつながっていないため、ファイルの差分のみ表示します）"
  fi
  echo "--- 変更されるファイル ---"
  git diff --stat=100 "$cur" "$commit" -- src | sed 's/^/  /'
  [ -z "$carry" ] || echo "サーバー上の変更（反映先と同じ内容のため引き継ぎ）:$carry"
fi

# 変更される PHP ファイルの構文チェック
if command -v php >/dev/null 2>&1; then
  n=0
  errs=0
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    n=$((n + 1))
    if ! out=$(git show "$commit:$f" | php -l 2>&1); then
      echo "  $f: $out" >&2
      errs=$((errs + 1))
    fi
  done < <(git diff --name-only --diff-filter=ACMR "$cur" "$commit" -- '*.php')
  [ "$errs" -eq 0 ] || fail "PHP の構文エラーが $errs 件あります"
  if $show; then
    echo "PHP 構文チェック: OK（変更 $n ファイル / CLI php $(php -r 'echo PHP_VERSION;')）"
  fi
fi

case "$mode" in apply|rollback-apply) ;; *) exit 0 ;; esac

if $need_switch; then
  git update-ref refs/mytube-deploy/before-switch "$cur"   # 付け替え前の状態を保存
  git remote set-url origin "$fetch_url"
  git fetch --quiet --prune origin
fi
for f in $carry; do
  git checkout -q "$commit" -- "$f"
done
git checkout -q -B master "$commit"
if git rev-parse -q --verify refs/remotes/origin/master >/dev/null; then
  git branch -q --set-upstream-to=origin/master master
fi
kind=deploy
[ "$mode" = rollback-apply ] && kind=rollback
echo "$(date '+%F %T') $kind $cur $commit" >> "$log"
echo "完了: $(short "$cur") → $(short "$commit")"
REMOTE

remote() { # remote <ホスト> <コマンド文字列>（標準入力はそのまま渡す）
  if [ -n "${DEPLOY_REMOTE_CMD:-}" ]; then
    # テスト用: 例) DEPLOY_REMOTE_CMD="docker exec -i コンテナ名"
    $DEPLOY_REMOTE_CMD bash -c "$2"
  else
    ssh -o BatchMode=yes "$1" "$2"
  fi
}

run_remote() { # run_remote <ホスト> <モード> <名前> <作業ディレクトリ>
  printf '%s\n' "$REMOTE_SCRIPT" | tr -d '\r' \
    | remote "$1" "bash -s -- $2 $3 $4 $COMMIT $REPO_SLUG $SWITCH_ORIGIN"
}

confirm() {
  $ASSUME_YES && return 0
  [ -t 0 ] || die "確認が必要です。非対話で実行する場合は -y を付けてください"
  local ans
  read -r -p "$1 [y/N] " ans
  [[ $ans =~ ^[yY]([eE][sS])?$ ]]
}

# 反映後の確認: ログイン画面が表示でき、秘密ファイルの中身が返らないこと
smoke_test() {
  local url=$1 code body p ok=true
  code=$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$url/login.php" || true)
  if [ "$code" = 200 ]; then
    echo "確認: $url/login.php → 200"
  else
    warn "$url/login.php が ${code:-応答なし} を返しました。表示を確認してください"
    ok=false
  fi
  for p in .env data/settings.json; do
    body=$(curl -s -m 20 "$url/$p" || true)
    if printf '%s' "$body" | grep -qE 'ADMIN_PASSWORD=|"user_password"'; then
      warn "$url/$p の中身が公開されています"
      ok=false
    fi
  done
  $ok && echo "確認: 秘密ファイル（.env, data/settings.json）は公開されていません"
  $ok
}

FAILED=()
for name in "${TARGETS[@]}"; do
  host=${HOSTS[$name]}
  app=${APPS[$name]}
  url=${URLS[$name]}
  echo
  echo "=== $name ($host:$app)"
  case "$MODE" in
    dry-run)
      rc=0
      run_remote "$host" preview "$name" "$app" || rc=$?
      [ "$rc" -eq 0 ] || [ "$rc" -eq 3 ] || FAILED+=("$name")
      ;;
    deploy|rollback)
      pre=preview
      apply=apply
      if [ "$MODE" = rollback ]; then pre=rollback-preview; apply=rollback-apply; fi
      rc=0
      run_remote "$host" "$pre" "$name" "$app" || rc=$?
      [ "$rc" -eq 3 ] && continue
      if [ "$rc" -ne 0 ]; then
        FAILED+=("$name")
        continue
      fi
      if ! confirm "$name に反映しますか？"; then
        echo "スキップしました"
        continue
      fi
      if run_remote "$host" "$apply" "$name" "$app"; then
        if [ -n "$url" ]; then smoke_test "$url" || FAILED+=("$name(確認)"); fi
      else
        FAILED+=("$name")
      fi
      ;;
  esac
done

echo
if [ ${#FAILED[@]} -gt 0 ]; then
  echo "失敗: ${FAILED[*]}" >&2
  exit 1
fi
echo "すべて完了しました"
