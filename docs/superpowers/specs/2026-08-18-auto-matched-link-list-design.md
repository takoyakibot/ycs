# 自動判定の紐付け内容を一覧で確認する画面

## 概要

TS分解画面の「自動判定を一括紐付け」で何が楽曲マスタに紐付いたのかを、一覧で確認できる画面を追加する。

## 背景・目的

TS分解画面の「自動判定を一括紐付け」ボタンは、実行後に「N件紐付けました」という件数だけを返す。何と何が紐付いたのかを確認する手段がないため、誤った紐付けが混ざっていても気づけない。

特にアーティスト名が空の楽曲マスタが作られる問題（Issue #639）はこの経路でも起こるため、紐付け結果を見て問題を見つけられる状態にしておきたい。

紐付けが誤っていた場合の修正はタイムスタンプ正規化画面で行えるので、本画面は確認専用とする。

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

既存の TS分解画面（`decompose.js`）や報告一覧（`reports.js`）は「API + JS」で作られているが、本画面は読み取り専用で、絞り込みもクエリパラメータで足りるため JS を持たせない。

JS を書かないことで、画面の振る舞いを Feature テストで完全に検証できる。フロントエンドJSのユニットテスト基盤がない（Issue #650）現状では、テスト可能な形で作れることを優先する。

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

アーティスト名が空の行（紐付け済みなら `songs.artist`、未紐付けなら `derived_artist` が空）は警告として強調表示する。

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
- 管理者以外のアクセスが弾かれること（`EnsureUserIsAdmin` ミドルウェアの挙動に合わせる）
- ページングが機能すること（51件で2ページになる）

## 未解決の論点

なし。
