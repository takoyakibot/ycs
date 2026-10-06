---
name: prepare-extension-release
description: 一般版ブラウザ拡張（歌枠タイムスタンプ検出）の Chrome Web Store 申請準備を行う。一般版をビルドして権限・通信先・管理者専用機能の混入を点検し、ストア掲載情報・申請フォーム記入内容・プライバシーポリシーを実装に合わせて更新し、アップロード用ZIPを作る。初回公開でもバージョンアップでも使う。「一般版を公開したい」「ストアに出す準備」「拡張の新バージョンを申請」「ストア申請用の資料を整えて」「一般版のリリース」などで使用する。一般版のコードを変更した後にストアへ反映したい場合も、明示されていなくてもこのスキルを使う。
argument-hint: "[--version <x.y.z>]"
---

# 一般版ブラウザ拡張のストア申請準備

一般版（`browser-extension/manifest.general.json` → `dist/general/`）を Chrome Web Store に出す・更新するための準備を、**実装の事実から**行う。

ストア審査で却下される典型は「説明文と実際の機能が違う」「宣言した権限を使っていない／使っている権限を宣言していない」「申告したデータ収集と実際の通信が違う」の3つで、どれも実装と資料のずれから起きる。このスキルの目的は、そのずれを毎回機械的に潰すこと。資料は記憶や過去の文面からではなく、点検スクリプトの出力とコードから書く。

## 管理する資料

| ファイル | 役割 |
|---|---|
| `browser-extension/manifest.general.json` | 名前・バージョン・権限の正 |
| `browser-extension/store-listing.md` | ストア掲載情報と、デベロッパーダッシュボードの申請フォームに貼る内容（単一用途・権限の理由・データ使用の申告）。ダッシュボードの入力欄と1対1で対応させる |
| `resources/lang/ja/extensionPrivacyPolicy.md` | `/extension/privacy` で公開されるプライバシーポリシー。ストア登録時にこのURLを入力する |
| `browser-extension/CHANGELOG.general.md` | 一般版のバージョンごとの変更点（ユーザー向けの言葉で書く）。無ければ作る |

## 方針（オーナー判断済み）

- 配布形態は **限定公開（unlisted）** で Store 登録し、サイトから誘導する。一般公開への切り替えは別判断
- チャットリプレイ取得（`youtubei/v1/live_chat/get_live_chat_replay`）と `page-bridge.js` は一般版に含める。その代わりプライバシーポリシーで取得と保存先を明記する
- 一般版は認証不要。公開API（`api/public/*`）だけを使う。トークン必須の書き込みAPI（`api/extension/*`）の呼び出しと `ycsApiToken` の読み込みは一般版の成果物に含めない（一般版はトークンを設定する手段がなく、出す理由がない）
- チャット遅延・Google AI非表示は一般版に含めない（単一用途ポリシーのため）
- 名前とアイコンに「YouTube」を含めない（商標）。説明文で「YouTube動画の〜」のように対象として言及するのは可。提携・公認と読める書き方はしない

方針を変えたい様子がユーザーにあれば、資料を直す前に確認する。

## ビルドの仕組み（NG を直すときに必要）

管理者向けのコード・UI・文字列は、実行時に隠すのではなく**ビルド時に一般版の成果物から物理的に除く**（ZIP の中身は審査で見られるため）。設定はすべて `browser-extension/rollup.config.mjs`。

- `content-general.js`: `src/content/index-general.js` を rollup でバンドル
  - 管理者専用モジュールは `generalStubs` でスタブ（または一般版専用モジュール）に差し替える
  - 共有モジュール内の管理者向け分岐は `src/content/edition.js` の `IS_GENERAL_EDITION` 定数で書く。一般版ビルドでは `generalStubs` で `edition-general.js`（`true`）に差し替わるので、`if (!IS_GENERAL_EDITION) { ... }` の中身は tree-shaking で消える。`state` などの実行時の値でエディションを判定すると成果物に残るので使わない
  - トークン必須の `api/extension/*` 呼び出し、`ycsApiToken` の読み込み、tabCapture / offscreen とのメッセージはこの方法で一般版から除いている
- `background-general.js` / `popup-general.js`: `background.js` / `popup.js` の `IS_GENERAL_EDITION` 判定（`chrome.runtime.getManifest().x_edition`）を `fixGeneralEdition` で `true` に置き換えてバンドルする。一般版に入れたくない処理は `if (!IS_GENERAL_EDITION)` の中か、そこからしか呼ばれない関数・定数に置く（共通部分から参照すると残る）
- `popup-general.html`: `popup.html` から `data-admin-only` の付いた要素（`<style data-admin-only>` を含む）と HTML コメントを除き、見出しを一般版 manifest の `name` にしたもの。管理者向けの要素には必ず `data-admin-only` を付ける。一般版 `popup.js` が `getElementById` で参照する要素が消えているとビルドがエラーになる
- 一般版の tree-shaking は `tryCatchDeoptimization: false`（`GENERAL_TREESHAKE`）。既定のままだと try ブロック内の管理者向け分岐が抜け殻として残り、呼び出し先の関数も成果物に残るため
- `page-bridge.js` はビルドを通らずそのままコピーされる。アイコンは manifest が参照する PNG だけを入れる（`icons/icon.svg` は入れない）
- `content.js` / `content-general.js` / `background-general.js` / `popup-general.js` / `popup-general.html` は git で管理している成果物なので、ビルド後は `git status` で差分を確認し、ソースと一緒にコミットする

## 手順

ソースの修正を伴うため、CLAUDE.md のワークフロー（developの最新化 → ブランチ作成 → 修正 → レビュー → PR …）に従う。ブランチ名は例えば `release/extension-general-v<version>`。

### 1. 前回リリースからの差分を把握する

```bash
git tag -l 'extension-general-v*' --sort=-v:refname | head -1   # 前回公開したバージョン
git log --oneline <前回タグ>..HEAD -- browser-extension/
```

- タグが無ければ初回公開として扱う
- 差分のうち一般版に効くもの（`src/content/` の共有モジュール、`index-general.js`、`background.js`、`popup.*`、`page-bridge.js`、`manifest.general.json`）を拾い、CHANGELOG と説明文の更新要否を判断する

### 2. ビルドして点検する

```bash
(cd browser-extension && bash build.sh)
.claude/skills/prepare-extension-release/scripts/inspect-general.sh
```

出力の読み方:

- **[NG] 権限なしの chrome API**：一般版で到達しないコードなら成果物から除く（スタブ化、または一般版専用のエントリを分ける）。必要な機能なら権限を追加し、store-listing.md に理由を書く。単に権限を足して解決しないこと。権限は審査で一番見られる
- **[NG] 宣言しているが使っていない権限**：manifest から外す
- **[NG] 管理者専用機能の混入**：管理者向けの API・トークン・UI 要素・エディションの実行時判定が成果物に残っている。上の「ビルドの仕組み」の方法でビルド時に除く。Claude API や localhost 宛ての通信、使っていない入力欄が成果物に文字列として残るだけでも、審査で通信先や未使用機能として質問されることがある
- **[NG] 参照されないファイル**：manifest・popup.html から参照されないファイルは一般版で使わないので、`build.sh` で `dist/general/` に入れないようにする
- **外部への通信先**：この一覧がプライバシーポリシーとデータ使用の申告の根拠になる。一覧にある通信先はすべて、資料のどこかで説明されていなければならない

- **host_permissions の要否**：MV3 ではコンテンツスクリプトからの `fetch` はページのオリジン扱いになり、host_permissions は効かない（通るかどうかはサーバーの CORS 次第。`config/cors.php` で `https://www.youtube.com` を許可済み）。拡張コンテキストから使わない host は外す
- **ブラウザ内の保存・読み取り**：IndexedDB・クリップボードなどはポリシーに書く。コンテンツスクリプトの IndexedDB は `www.youtube.com` のオリジンに保存され、拡張をアンインストールしても消えないので、「データの削除」の説明に影響する

NG を直したら、ビルドと点検をやり直して NG が消えるまで繰り返す。権限を外した場合は、ブラウザでの動作確認が必要なことを最後の報告に入れる（コードの静的な点検だけでは、権限不足による実行時エラーは見つからない）。

### 2.5 サーバー側の扱いを確認する

プライバシーポリシーには、送ったデータがサーバーでどう扱われるかも書く。一般版が呼ぶ公開API（`routes/api.php` の `public/*`）について、コントローラを読んで次を確認する。

- DB に保存するか（公開照合APIは保存しない設計）
- ログに何が残るか（アクセスログ、本文を書き出していないか）
- 回数制限（`RouteServiceProvider` の `public-api-daily` など）

### 3. 機能の棚卸し

点検結果とコード（`src/content/index-general.js` から辿れるモジュール、生成された `popup-general.html`）から、一般版でユーザーができることを列挙する。説明文の「主な機能」はこの棚卸しと一致させる。管理者版にしかない機能（トークン設定、サーバー保存、リストスキャン、ハイライト検出など）を書かないこと。

### 4. バージョンを決める

- 初回は `manifest.general.json` の現在値のままでよい
- ストアのタイトルと概要は manifest の `name` と `description` がそのまま使われ、ダッシュボードでは編集できない。掲載名や概要を変えたいときは manifest を直す。`name` に付いている「（一般版）」はストア利用者には意味が通じにくいので、初回公開時は公開名をユーザーに確認する
- 更新時は前回タグより必ず上げる（Store は同じかより低いバージョンのアップロードを拒否する）。バグ修正のみならパッチ、機能追加ならマイナー。`--version` 指定があればそれに従う
- `manifest.general.json` の `version` を書き換え、`CHANGELOG.general.md` に項目を追加する

### 5. 資料を更新する

`store-listing.md` は以下の構成を保つ（ダッシュボードの入力欄の順）。

1. **ストア掲載情報**：タイトル（45文字以内）と概要（132文字以内）は manifest から入ることの注記、説明、カテゴリ、言語、ホームページURL、サポートURL
2. **プライバシーへの取り組み**
   - 単一用途の説明
   - 権限ごとの使用理由（manifest の `permissions` と `host_permissions` の全項目について1つずつ）
   - リモートコードの使用：いいえ（`page-bridge.js` は同梱ファイルであってリモートコードではない）
   - データ使用の申告：ダッシュボードのデータ種別（ウェブサイトのコンテンツ、ユーザーのアクティビティ など）ごとに、収集する／しない と理由。判断基準は次のとおり
     - 拡張の外（本体サーバー）へ送るものは「収集する」。字幕テキストや入力中の曲名など、ページ上の内容や利用者が入力した文字列は「ウェブサイトのコンテンツ」に入れる
     - ブラウザ内にだけ保存して外へ送らないもの（音量データ、チャットリプレイ、設定）は「収集しない」でよい。ただしプライバシーポリシーには書く
     - 情報を取りに行くだけの YouTube への要求（字幕・チャットの取得）は、利用者のデータを第三者へ渡すものではないので申告の対象外。ポリシーには書く
   - 3つの遵守事項の宣言（データを販売しない、単一用途と無関係な目的に使わない、信用力判断に使わない）
3. **配布**：公開設定（限定公開）、対象地域
4. **スクリーンショット**：必要な枚数（1280x800 または 640x400、1〜5枚）と撮るべき画面の指示

文字数制限は数えて確認する（`node -e "console.log([...'文字列'].length)"`）。

プライバシーポリシーは点検結果の「外部への通信先」「ブラウザ内の保存・読み取り」と手順2.5の確認結果に照らし、送信するもの・ローカルに保存するもの・送らないものを書く。本文を変えた場合は施行日も更新する。ストアの申告とポリシーで食い違いがあると却下理由になるので、最後に両者を突き合わせる。ポリシー内のリンク（お問い合わせ、サイトの利用規約など）は `routes/web.php` に実在するか確認する。

### 6. ZIP を作る

```bash
cd browser-extension/dist/general && zip -rq ../ycs-extension-general-<version>.zip . -x '.*' && cd -
unzip -l browser-extension/dist/ycs-extension-general-<version>.zip
```

`manifest.json` が ZIP のルートにあることを確認する（フォルダごと固めると審査に出せない）。ZIP は `dist/` 内なので git には入らない。

### 7. PR と、ユーザーへの引き継ぎ

資料とコードの修正を PR にする。PR 本文に点検結果の要約（NG の解消内容、通信先一覧）を書く。

コミット時の pre-commit フック（PHP テストとフロントのビルド）は、拡張だけの変更でも `--no-verify` で飛ばさずに通す。プライバシーポリシーは Laravel 側のファイルなので、テストを通しておく意味がある。

プライバシーポリシーを変えた場合、ストアに入力する `/extension/privacy` に反映されるのは本番デプロイ後になる。申請は **PR のマージと本番デプロイの後** に行う。

申請はアカウント操作なのでユーザーが行う。最後に以下を報告する:

- 権限を外した場合は、`dist/general/` を読み込んで行う動作確認の手順（何が動けば OK か）
- ZIP のパス
- ダッシュボードで貼る場所と、`store-listing.md` の該当セクションの対応
- 今回用意が必要なスクリーンショット（初回、または画面が変わった場合）
- 審査通過後に打つタグのコマンド: `git tag extension-general-v<version> <マージコミット> && git push origin extension-general-v<version>`

初回公開の場合は、デベロッパー登録（$5、非トレーダー申告）が済んでいるかも確認項目に入れる。
