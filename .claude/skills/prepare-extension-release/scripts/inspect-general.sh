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
    runtime|tabs|action|i18n) need="" ;;   # tabs は query/sendMessage 程度なら権限不要
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
if grep -qE "\.url\b" "$DIST/background.js" "$DIST/popup.js" 2>/dev/null && grep -qE "chrome\.tabs" "$DIST/background.js" "$DIST/popup.js" 2>/dev/null; then
  echo "[要確認] chrome.tabs でタブの url を読んでいる — host_permissions（または activeTab）の範囲内のタブであること"
fi

echo
echo "## host_permissions の要否"
# MV3 ではコンテンツスクリプトからの fetch はページのオリジン扱い（CORS はサーバー側の設定次第）で、
# host_permissions が効くのは background / popup / offscreen などの拡張コンテキストからの通信と、
# コンテンツスクリプトの注入・tabs の url 参照だけ
for hp in $(node -e 'console.log((require(process.argv[1]).host_permissions||[]).join(" "))' "$MANIFEST"); do
  host=$(echo "$hp" | sed -E 's|^[a-z*]+://([^/]+)/.*|\1|')
  ext_ctx=$(grep -lF "$host" "$DIST/background.js" "$DIST/popup.js" "$DIST/popup.html" 2>/dev/null | xargs -n1 basename 2>/dev/null | tr '\n' ' ')
  in_cs=$(node -e 'const m=require(process.argv[1]);console.log((m.content_scripts||[]).some(c=>c.matches.some(x=>x.includes(process.argv[2])))?"yes":"")' "$MANIFEST" "$host")
  if [ -n "$ext_ctx" ] || [ -n "$in_cs" ]; then
    echo "[OK] ${hp}（拡張コンテキストでの参照: ${ext_ctx:-なし} / content_scripts の対象: ${in_cs:-no}）"
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
# 一般版に入ってはいけないもの。チャットリプレイ取得は一般版に含める方針（2026-10 オーナー判断）なので対象外
check_absent() {
  local label="$1" pattern="$2"
  local hit
  hit=$(grep -lE "$pattern" $JS_FILES | xargs -n1 basename 2>/dev/null | tr '\n' ' ')
  if [ -n "$hit" ]; then
    echo "[要確認] ${label}: $hit"
  else
    echo "[OK] ${label}: なし"
  fi
}
check_absent "管理画面API (api/manage)" "api/manage/"
check_absent "Claude API 直接呼び出し" "api\.anthropic\.com"
check_absent "localhost への通信" "https?://(localhost|127\.0\.0\.1)"
check_absent "ハイライト検出API" "api/extension/highlights"
check_absent "リストスキャン系API" "scan-targets|subtitle-targets"
grep -qE "localhost|127\.0\.0\.1" "$MANIFEST" && { echo "[NG] manifest に localhost 系の記述がある"; NG=1; }

echo
echo "## トークン必須の書き込みAPI（一般版ではトークン未設定で実行されないこと）"
grep -nE "fetch\([^)]*api/extension/" $JS_FILES | sed "s|$ROOT/||" | while IFS= read -r line; do
  echo "  $line"
done
echo "  ↑ 各呼び出しの直前で ycsApiToken の有無を判定していることをコードで確認する"

echo
echo "## popup.html に残る管理者向け要素（実行時に data-admin-only で消えるが、ZIP の中身としては審査で見える）"
grep -nE "data-admin-only|sk-ant-|api-token|server-url" "$DIST/popup.html" | cut -c1-140

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
