# 自動判定の紐付け内容を一覧で確認する画面

## 概要

TS分解画面の「自動判定を一括紐付け」で何が楽曲マスタに紐付いたのかを、一覧で確認できる画面を追加する。

## 背景・目的

TS分解画面の「自動判定を一括紐付け」ボタンは、実行後に「N件紐付けました」という件数だけを返す。何と何が紐付いたのかを確認する手段がないため、誤った紐付けが混ざっていても気づけない。

特にアーティスト名が空の楽曲マスタが作られる問題（Issue #639）はこの経路でも起こるため、紐付け結果を見て問題を見つけられる状態にしておきたい。

紐付けが誤っていた場合の修正はタイムスタンプ正規化画面で行えるので、本画面は確認専用とする。

ただし本画面の紐付け状態は `timestamp_decompositions.song_id` を根拠にしており、正規化画面の解除・付け替え（`SongMappingService::unlinkTimestamp()` / `linkTimestamp()`）は `timestamp_song_mappings` しか更新しない。したがって正規化画面で直した内容は本画面に反映されず、状態列・曲名・アーティスト・絞り込みは古い紐付けを表示し続ける。自動で追随する経路は存在しない。

判定元を `timestamp_song_mappings` 側に付け替えることはしない。`bulkLinkAutoMatched()` の対象条件は `status = auto_matched` かつ `song_id IS NULL` かつ `derived_title IS NOT NULL` の3つで、`song_id` を根拠にしている限り一覧の「未紐付け」は一括紐付けの対象集合を**包含する**（§3 の目的）。厳密には一致しない — `derived_title` が空の行は「未紐付け」と表示されるが一括紐付けは拾わない（`linkToSong()` も早期 return する）。この行が滞留する問題は Issue #670 で扱う。

mappings 基準に変えるとこの包含関係すら崩れる。正規化画面で紐付けた行（`SongMappingService::linkTimestamp()` は `timestamp_song_mappings` だけを作る）は `song_id` が NULL のままなので、mappings 基準では「紐付け済み」と表示されるのに一括紐付けは拾ってしまう。つまり**予告に出ない紐付けが起きる**（実測: 一括紐付けの対象集合から mappings 基準の未紐付けを引くと、この行が残る）。

なお `song_id` はあるがマッピングが無い行は、mappings 基準では「未紐付け」に出るのに一括紐付けは拾わない（`song_id` が非NULL のため）。これは包含関係を保ったまま差を広げる方向で、上の「包含が崩れる」とは別のケース。乖離の解消は Issue #660 で扱う。

## スコープ

### 対象

- 自動判定されたレコードの一覧ページの新規追加
- TS分解画面の統計「自動: N件」から一覧ページへの導線
- 一覧の絞り込み（状態・アーティスト名が空）

### 対象外

- 一覧画面上での紐付けの修正・解除（タイムスタンプ正規化画面で行う）
- 正規化画面への検索状態の引き継ぎ（正規化画面は検索文字列をURLで受け取れないため、元テキストのコピーボタンで代替する）
- 一括紐付けの実行履歴の記録（実行単位で追う要求は現時点でない）

## 設計

### 1. 実装方式

Blade によるサーバーサイドレンダリングとする。

既存の TS分解画面（`decompose.js`）や報告一覧（`reports.js`）は「API + JS」で作られているが、本画面は読み取り専用で、絞り込みもクエリパラメータで足りるため、独立した JS ファイルは追加せず画面のロジックは Blade 側に置く。

例外は元テキストのコピー処理で、これは Blade 内に最小限のインライン JS として書く。この分だけ、一覧の表示・絞り込み・ページングは Feature テストで完全に検証できるが、コピー処理の分岐（clipboard 非対応時・失敗時・タイマー管理）は自動テストの対象外になる。フロントエンドJSのユニットテスト基盤がない（Issue #650）現状ではやむを得ない範囲とし、基盤が整い次第テストを追加する。

### 2. ルート

`routes/web.php` の `auth` + `admin` グループ内、`/songs/decompose` の隣に追加する。

```php
Route::get('/songs/decompose/linked', [TimestampDecompositionController::class, 'linked'])
    ->name('songs.decompose.linked');
```

`/songs/decompose/{id}` 形式の既存ルートはない（`skip` / `undo` は `api/` 配下）ため、パスの衝突は起きない。

### 3. 一覧の対象

`status = auto_matched` のレコードを、**紐付け済み（`song_id` あり）・未紐付け（`song_id` なし）の両方**を対象にする。

紐付け済みだけに絞ると、TS分解画面の統計「自動: N件」と一覧の件数が食い違って混乱する。両方を載せて状態列で区別することで数字が一致し、さらに一括紐付けを実行する前に「これから何が紐付くか」を確認できるようになる。

### 4. 表示項目

| 列 | 内容 |
| --- | --- |
| 元テキスト | `original_text`。コピーボタンを併置する |
| 紐付け先 | 紐付け済みなら `songs.title` / `songs.artist`。未紐付けなら判定結果の `derived_title` / `derived_artist` |
| 確信度 | `confidence` を百分率で表示 |
| 状態 | 「紐付け済み」または「未紐付け」 |
| 日時 | `updated_at` |

曲名またはアーティスト名が空の行（紐付け済みなら `songs.title` / `songs.artist`、未紐付けなら `derived_title` / `derived_artist` が空）は警告として強調表示する。文言は `⚠ 曲名なし` と `⚠ 未設定` に分ける（同じ文言にするとどちらが欠けているのか画面から判別できない）。

### 5. 絞り込み

クエリパラメータ `filter` で切り替える。

| 値 | 対象 |
| --- | --- |
| （なし） | すべて |
| `linked` | 紐付け済み |
| `unlinked` | 未紐付け |
| `empty_artist` | アーティスト名が空 |

不正な値が渡された場合は「すべて」として扱う。

並び順は `updated_at` の降順。1ページ 50 件でページングする。

### 6. Service へのメソッド追加

`TimestampDecompositionService` に一覧取得メソッドを追加する。Controller にクエリを書かず、既存の責務分担（Controller は入出力、Service がデータ取得）に合わせる。

```php
public function getAutoMatchedList(?string $filter = null, int $perPage = 50): LengthAwarePaginator
```

`TimestampDecomposition::song()` リレーションは既に定義されているので、これを eager load して N+1 を避ける。

`empty_artist` の判定は、紐付け済みなら結合した `songs.artist`、未紐付けなら `derived_artist` を見る必要があるため、`song_id` の有無で条件を分けた `where` グループで組む。

### 7. 導線

TS分解画面（`resources/views/songs/decompose.blade.php`）の統計表示にある「自動: N件」をこのページへのリンクにする。一覧ページからは TS分解画面へ戻るリンクを置く。

## テスト

`tests/Feature/` に追加する。

- 対象レコードだけが一覧に出ること（`pending` / `selected` / `skipped` は出ない）
- 紐付け済みは `songs` の値、未紐付けは `derived_*` の値が表示されること
- 各絞り込み（`linked` / `unlinked` / `empty_artist`）が正しく効くこと
- 不正な `filter` 値が「すべて」として扱われること
- アーティスト名が空の行が警告として表示されること
- 曲名が空の行が警告として表示されること（アーティストだけが空のときは曲名側の警告を出さないこと）
- 未認証のアクセスがログイン画面にリダイレクトされること
- ページングが機能すること（51件で2ページになる）

`EnsureUserIsAdmin` は「登録ユーザーは全て管理者として扱う」実装なので、認証済みユーザーの権限差による 403 は発生しない。未認証時のリダイレクトのみを確認する。

## 未解決の論点

なし。
