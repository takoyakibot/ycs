#!/bin/bash
#
# 一般版ブラウザ拡張のビルド成果物（dist/general/）を点検し、
# ストア申請・プライバシーポリシーと突き合わせるための事実を列挙する。
#
# 使い方: inspect-general.sh [リポジトリルート]
#   事前に browser-extension/build.sh を実行しておくこと。
#
# 出力は「事実の一覧」であり、良し悪しの判定は SKILL.md の手順で行う。
# ただし明確な不整合（権限なしでの chrome API 利用、管理者専用機能の混入）は [NG] で示す。

set -u
ROOT="${1:-$(git rev-parse --show-toplevel)}"
EXT="$ROOT/browser-extension"
DIST="$EXT/dist/general"
MANIFEST="$DIST/manifest.json"

if [ ! -f "$MANIFEST" ]; then
  echo "[NG] $MANIFEST がありません。先に browser-extension/build.sh を実行してください"
  exit 1
fi

JS_FILES=$(find "$DIST" -name '*.js' | sort)
NG=0

echo "## manifest"
node -e '
const m = require(process.argv[1]);
console.log("name:", m.name);
console.log("version:", m.version);
console.log("description:", m.description);
console.log("permissions:", (m.permissions || []).join(", ") || "(なし)");
console.log("host_permissions:", (m.host_permissions || []).join(", ") || "(なし)");
console.log("content_scripts.matches:", (m.content_scripts || []).flatMap(c => c.matches).join(", "));
console.log("web_accessible_resources:", (m.web_accessible_resources || []).flatMap(w => w.resources).join(", "));
' "$MANIFEST"

# 古い成果物での点検を防ぐため、ソースより dist が古ければ警告する
NEWEST_SRC=$(find "$EXT/src" "$EXT"/*.js "$EXT"/*.json "$EXT"/*.html -newer "$MANIFEST" 2>/dev/null | grep -v node_modules | head -1)
if [ -n "$NEWEST_SRC" ]; then
  echo "[NG] dist/general がソースより古い（例: ${NEWEST_SRC#$ROOT/}）。build.sh を再実行してください"
  NG=1
fi

echo
echo "## chrome.* API の利用状況（一般版成果物）"
PERMS=$(node -e 'console.log((require(process.argv[1]).permissions||[]).join(" "))' "$MANIFEST")
# 権限が必要な名前空間と、manifest に必要な権限名の対応
# （runtime / storage.local 以外の i18n などは権限不要なのでここでは扱わない）
for ns in $(grep -ohE 'chrome\.[a-zA-Z]+' $JS_FILES | sort -u | sed 's/chrome\.//'); do
  count=$(grep -ohE "chrome\.$ns\b" $JS_FILES | wc -l | tr -d ' ')
  files=$(grep -lE "chrome\.$ns\b" $JS_FILES | xargs -n1 basename | tr '\n' ' ')
  case "$ns" in
    runtime|tabs|action|i18n|windows|extension) need="" ;;   # tabs は query/sendMessage/create なら権限不要（url を読む場合は下で別途確認）
    *) need="$ns" ;;
  esac
  if [ -n "$need" ] && ! echo " $PERMS " | grep -q " $need "; then
    echo "[NG] chrome.$ns x$count ($files) — manifest に \"$need\" 権限がない。到達しないコードなら除去、必要なら権限追加"
    NG=1
  else
    echo "[OK] chrome.$ns x$count ($files)"
  fi
done
for p in $PERMS; do
  case "$p" in
    activeTab)
      # API 呼び出しを伴わない権限なのでコードからは要否を判定できない
      echo "[要確認] activeTab — host_permissions で足りるなら不要。ポップアップ等から host_permissions 外のタブを操作するときだけ必要"
      continue ;;
  esac
  if ! grep -qE "chrome\.$p\b" $JS_FILES; then
    echo "[NG] 権限 \"$p\" を宣言しているがコード上で使っていない（審査で却下理由になる）"
    NG=1
  fi
done
# tab.url / tab.title を読むには対象タブが host_permissions（または activeTab）の範囲内である必要がある
TAB_URL_READS=$(grep -nE "\btabs?\??\.(url|title|pendingUrl)\b" "$DIST/background.js" "$DIST/popup.js" 2>/dev/null | sed "s|$DIST/||" | cut -c1-140)
if [ -n "$TAB_URL_READS" ]; then
  echo "-- タブの url / title を読んでいる箇所（対象タブが host_permissions の範囲内か確認。popup.js の YouTube 判定は既知で問題なし）"
  echo "$TAB_URL_READS" | sed 's/^/  /'
fi

echo
echo "## host_permissions の要否"
# MV3 ではコンテンツスクリプトからの fetch はページのオリジン扱い（CORS はサーバー側の設定次第）で、
# host_permissions が効くのは background / popup / offscreen などの拡張コンテキストからの通信と、
# コンテンツスクリプトの注入・tabs の url 参照だけ
for hp in $(node -e 'console.log((require(process.argv[1]).host_permissions||[]).join(" "))' "$MANIFEST"); do
  host=$(echo "$hp" | sed -E 's|^[a-z*]+://([^/]+)/.*|\1|')
  in_cs=$(node -e 'const m=require(process.argv[1]);console.log((m.content_scripts||[]).some(c=>c.matches.some(x=>x.includes(process.argv[2])))?"yes":"")' "$MANIFEST" "$host")
  # 拡張コンテキストからこのホストへ通信しているか（URL 文字列の有無ではなく fetch/XHR の行で見る）
  ext_fetch=$(grep -lE "(fetch|open)\(.*$host" "$DIST/background.js" "$DIST/popup.js" 2>/dev/null | xargs -n1 basename 2>/dev/null | tr '\n' ' ')
  if [ -n "$in_cs" ] || [ -n "$ext_fetch" ]; then
    echo "[OK] ${hp}（content_scripts の対象: ${in_cs:-no} / 拡張コンテキストからの通信: ${ext_fetch:-なし}）"
  elif [ -n "$TAB_URL_READS" ]; then
    echo "[要確認] ${hp} — 通信も注入もない。上の「タブの url を読んでいる箇所」でこのホストのタブを判定しているなら必要、そうでなければ不要"
  else
    echo "[要確認] ${hp} — 拡張コンテキストからの通信もコンテンツスクリプトの注入もない。コンテンツスクリプトからの通信だけなら不要（サーバーの CORS で許可する）"
  fi
done

echo
echo "## 外部への通信先（URL・APIパス）"
grep -ohE "https?://[a-zA-Z0-9.-]+(/[a-zA-Z0-9_./-]*)?" $JS_FILES | sort | uniq -c | sort -rn
echo "-- 本体サーバーのAPIパス"
grep -ohE "/api/[a-zA-Z0-9/_-]+" $JS_FILES | sort | uniq -c
echo "-- YouTube 内部API"
grep -ohE "youtubei/v1/[a-zA-Z0-9_/]+|/api/timedtext" $JS_FILES | sort | uniq -c
echo "-- fetch / XHR / sendBeacon の呼び出し箇所（URL が変数の箇所は前後のコードで送信先を確認する）"
grep -nE "\bfetch\(|XMLHttpRequest|sendBeacon\(" $JS_FILES | sed "s|$DIST/||" | cut -c1-160

echo
echo "## ポリシーに書くべきブラウザ内の保存・読み取り"
for pat in "indexedDB" "localStorage" "sessionStorage" "navigator\.clipboard\.readText" "navigator\.clipboard\.writeText" "document\.cookie"; do
  files=$(grep -lE "$pat" $JS_FILES | xargs -n1 basename 2>/dev/null | tr '\n' ' ')
  [ -n "$files" ] && echo "  $pat: $files"
done
echo "  ↑ コンテンツスクリプトの indexedDB / localStorage は拡張ではなくページ（www.youtube.com）のオリジンに保存され、アンインストールしても消えない"

echo
echo "## 管理者専用機能の混入チェック"
# 一般版に入ってはいけないもの。管理者向けのコード・UI・文字列はビルド時に成果物から除く方針
# （実行時に隠すだけでは ZIP の中身として審査で見えるため）。
# チャットリプレイの取得・ローカル保存・自動検出での利用は一般版に含める方針（2026-10 オーナー判断）なので対象外
check_ng() {
  local label="$1" pattern="$2"
  shift 2
  local files=("$@")
  local hit
  hit=$(grep -lE -- "$pattern" "${files[@]}" 2>/dev/null | xargs -n1 basename 2>/dev/null | tr '\n' ' ')
  if [ -n "$hit" ]; then
    echo "[NG] ${label}: $hit"
    grep -nE -- "$pattern" "${files[@]}" 2>/dev/null | sed "s|$DIST/||" | cut -c1-140 | head -5 | sed 's/^/  /'
    NG=1
  else
    echo "[OK] ${label}: なし"
  fi
}
# shellcheck disable=SC2206
JS_LIST=($JS_FILES)
HTML_LIST=($(find "$DIST" -name '*.html' | sort))
check_ng "管理画面API (api/manage)" "api/manage/" "${JS_LIST[@]}"
check_ng "トークン必須の書き込みAPI (api/extension)" "api/extension/" "${JS_LIST[@]}"
check_ng "APIトークンの読み込み・送信 (ycsApiToken / Authorization)" "ycsApiToken|Authorization" "${JS_LIST[@]}"
check_ng "Claude API・APIキー (anthropic / sk-ant- / claudeApiKey)" "anthropic|sk-ant-|claudeApiKey" "${JS_LIST[@]}" "${HTML_LIST[@]}"
check_ng "localhost への通信" "https?://(localhost|127\.0\.0\.1)" "${JS_LIST[@]}"
check_ng "ハイライト検出・リストスキャン・字幕一括取得" "highlights|scan-targets|subtitle-targets|listScan|subtitleScan" "${JS_LIST[@]}"
check_ng "tabCapture / offscreen 連携のメッセージ" "START_SCAN|STOP_SCAN|UPDATE_VIDEO_TIME|_FROM_OFFSCREEN|SCAN_PERMISSION_ERROR|CHECK_TOXICITY" "${JS_LIST[@]}"
check_ng "管理者向けUI要素 (data-admin-only)" "data-admin-only" "${JS_LIST[@]}" "${HTML_LIST[@]}"
check_ng "管理者向け設定欄 (トークン・サーバーURL・チャット遅延・Google AI)" "ycs-api-token|ycs-server-url|chat-delay|hide-google-ai|toxicity" "${HTML_LIST[@]}" "${JS_LIST[@]}"
# エディションの実行時判定が残っている＝ビルド時に畳み込まれず、管理者向けの分岐が成果物に残っている
check_ng "エディションの実行時判定 (x_edition / state.edition / IS_GENERAL_EDITION)" "x_edition|\.edition\b|IS_GENERAL_EDITION" "${JS_LIST[@]}"
grep -qE "localhost|127\.0\.0\.1" "$MANIFEST" && { echo "[NG] manifest に localhost 系の記述がある"; NG=1; }

echo
echo "## 成果物に含まれるファイルの要否"
# manifest・popup.html から参照されないファイルは一般版で使わないので入れない（icons/icon.svg など）
UNUSED=$(node -e '
const fs = require("fs"), path = require("path");
const dist = process.argv[1];
const m = JSON.parse(fs.readFileSync(path.join(dist, "manifest.json"), "utf8"));
const used = new Set(["manifest.json"]);
const add = p => p && used.add(p.replace(/^\//, ""));
Object.values(m.icons || {}).forEach(add);
Object.values((m.action || {}).default_icon || {}).forEach(add);
add((m.action || {}).default_popup);
add((m.background || {}).service_worker);
(m.content_scripts || []).forEach(c => (c.js || []).concat(c.css || []).forEach(add));
(m.web_accessible_resources || []).forEach(w => (w.resources || []).forEach(add));
for (const page of [...used].filter(p => p.endsWith(".html"))) {
  const html = fs.readFileSync(path.join(dist, page), "utf8");
  for (const [, ref] of html.matchAll(/(?:src|href)="([^"#:]+)"/g)) add(path.posix.join(path.posix.dirname(page), ref));
}
const walk = d => fs.readdirSync(d, { withFileTypes: true }).flatMap(e => e.isDirectory() ? walk(path.join(d, e.name)) : [path.relative(dist, path.join(d, e.name))]);
console.log(walk(dist).filter(f => !used.has(f)).join("\n"));
' "$DIST")
if [ -n "$UNUSED" ]; then
  echo "[NG] manifest・popup.html から参照されないファイルがある（build.sh で一般版に入れないようにする）:"
  echo "$UNUSED" | sed 's/^/  /'
  NG=1
else
  echo "[OK] すべて manifest・popup.html から参照されている"
fi

echo
echo "## 成果物サイズ"
du -sh "$DIST" | awk '{print $1}'
find "$DIST" -type f | sed "s|$DIST/||" | sort

echo
if [ $NG -eq 0 ]; then
  echo "結果: NG なし（[要確認] は個別に判断）"
else
  echo "結果: NG あり"
fi
exit $NG
