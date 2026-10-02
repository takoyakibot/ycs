---
name: audit-songs
description: 本番の楽曲マスタ・紐付けを1バッチ分点検し、判定結果を判定テーブル（song_audits）に登録する。「楽曲マスタの点検をして」「紐付けの点検を回して」「点検を1回分やって」などで使用する。
argument-hint: [song|mapping] [--limit=N]
---

# 楽曲マスタ・紐付けの点検（1バッチ）

本番から未点検の候補を取得し、判定基準に沿って `ok` / `needs_fix` を付けて登録する。
登録先は `song_audits` のみ。**楽曲マスタやマッピングを直接変更してはならない**（適用は画面で人が行う）。

## 引数

- 第1引数: `song`（楽曲マスタ）または `mapping`（紐付け）。省略時は `song`
- `--limit=N`: 1バッチの件数。省略時は 30

## 接続情報

```bash
ssh -i ~/.ssh/alpacasandbag_app2_rsa -p 22 alpacasandbag@v2007.coreserver.jp
```

アプリパス: `~/domains/ycs.alpacasandbag.jp/public_html/`

以下では `APP='cd ~/domains/ycs.alpacasandbag.jp/public_html && php artisan'` を前提に書く。

## 手順

1. **候補を取得する**（JSON は scratchpad に保存する）

   ```bash
   ssh ... "$APP song-audit:export song --limit=30" > <scratchpad>/audit-export.json
   ```

   - `song_audits` が無いというエラーなら、本番に未デプロイ。点検は中止してユーザーに伝える
   - `[]` が返ったら点検対象は無い。そう報告して終わる

2. **判定する**: 各件を下の判定基準で見て、次の形の配列を `<scratchpad>/audit-result.json` に書く

   ```json
   [
     {
       "type": "song",
       "id": "<export の id>",
       "fingerprint": "<export の fingerprint をそのまま>",
       "verdict": "needs_fix",
       "reason": "曲名に（cover）が混入している",
       "suggestion": { "title": "夜に駆ける" }
     },
     { "type": "song", "id": "...", "fingerprint": "...", "verdict": "ok" }
   ]
   ```

   - `type` / `id` / `fingerprint` は export の値をそのまま使う（書き換えると登録されない）
   - `needs_fix` には `reason`（1000文字以内）が必須
   - `suggestion` は分かる範囲で付ける。使えるキーは `title` / `artist`（255文字以内、空文字不可）と、紐付けのみ `is_not_song: true`。紐付けの `title` / `artist` は「本来紐付くべき楽曲」の曲名・アーティストを書く
   - export の全件に判定を付ける（飛ばすと次回また出てくる）

3. **検証してから登録する**

   ```bash
   ssh ... "$APP song-audit:import --dry-run" < <scratchpad>/audit-result.json
   ssh ... "$APP song-audit:import" < <scratchpad>/audit-result.json
   ```

   - `--dry-run` でエラーが出たら JSON を直して再実行する
   - 「export 後に対象が更新されています」は、その間に人が直した行。判定し直さずに次回へ回してよい

4. **報告する**: 件数（ok / needs_fix / 除外）と、needs_fix の主な内容（曲名・理由・修正案）を短く一覧にする

## 判定基準

迷うものは `needs_fix` に倒して人の確認に回す。

### 楽曲マスタ（song）

- 曲名にカッコ類（`()（）[]【】「」『』`）で囲まれた付加情報（cover / 歌ってみた / 原曲キー / ショート / アカペラ 等）が混入していないか
  - 正式な曲名の一部（`Lemon (Remix)` 等）は問題なし。判断が付かなければ needs_fix
- 曲名に区切り文字（` / ` ` - ` `／`）やアーティスト名が混入していないか
- 伸ばし棒の位置のハイフン類（`-` `−` `－`）で曲名が断片化していないか（例: `チュ-リップ` から `チュ` が登録されている）。`timestamp_examples` に断片の直後・直前がスペースなしのハイフン類で続く元テキストがあれば needs_fix にし、正しい曲名を修正案にする
- アーティストが空、または曲名と入れ替わっていないか
- タグがアーティスト名と明らかに食い違っていないか
- `timestamp_examples`（実際のタイムスタンプの元テキスト）と曲名・アーティストが噛み合っているか

### 紐付け（mapping）

- `original_texts`（元テキスト）と紐付け先 `song` の曲名・アーティストが同じ曲を指しているか
  - 表記ゆれ（カナ/英字、大文字小文字、feat. の有無）は問題なし
  - 同名異曲（同じ曲名で別アーティスト）が疑われる場合は needs_fix
- 元テキストが曲名ではない（雑談・企画名・告知など）なら needs_fix、`suggestion: { "is_not_song": true }`

## 留意点

- 本番で実行してよいのは `song-audit:export` と `song-audit:import` のみ。他の artisan コマンドや SQL で楽曲・マッピングを書き換えない
- 1回の呼び出しで回すのは1バッチだけ。続けるかはユーザーに任せる
