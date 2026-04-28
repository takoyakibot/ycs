# DESIGN.md — 歌枠履歴er:D

> このファイルはAIエージェントが日本語UIを正確に生成するための基礎指針です。
> デザインの方向性（トーン・世界観）は意図的に規定せず、日本語タイポグラフィとUI基礎のみを定めています。

---

## 1. Typography Rules

### 1.1 和文フォント

- **ゴシック体（優先）**: Zen Maru Gothic（丸ゴシック、Google Fonts）
- **フォールバック**: "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif

### 1.2 欧文フォント

- **ディスプレイ・数値**: Outfit（Google Fonts）
- **本文**: Figtree（既存、Tailwind既定）
- **等幅**: "SFMono-Regular", "Consolas", "Menlo", monospace

### 1.3 font-family 指定

```css
/* 和文本文・UI */
font-family: "Zen Maru Gothic", "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;

/* ディスプレイ（見出し・ヒーロー） */
font-family: "Outfit", "Zen Maru Gothic", sans-serif;

/* 等幅（タイムスタンプ・コード） */
font-family: "Outfit", "SFMono-Regular", "Consolas", "Menlo", monospace;

/* Tailwind既定（管理画面等） */
font-family: "Figtree", sans-serif;
```

**フォールバックの考え方**:
- 和文フォントを先に指定し、日本語の表示品質を優先
- 欧文フォントを先に置くのはディスプレイ用途のみ（Outfit等）
- 最後に必ず generic family を指定

### 1.4 文字サイズ・行間・字間の基準

| 用途 | Size | Weight | Line Height | Letter Spacing |
|------|------|--------|-------------|----------------|
| ヒーロータイトル | 1.875rem〜2.25rem | 700 | 1.3 | -0.02em |
| 見出し（h1〜h2） | 1.25rem〜1.5rem | 700 | 1.4 | 0em |
| 小見出し（h3〜h4） | 0.925rem〜1.1rem | 600 | 1.4 | 0em |
| 本文 | 0.875rem〜0.9rem | 400 | 1.7 | 0.02em |
| キャプション | 0.75rem〜0.8rem | 400 | 1.5 | 0.02em |
| 最小テキスト | 0.7rem | 400 | 1.4 | 0.01em |

### 1.5 行間・字間ガイドライン

- 日本語本文は `line-height: 1.5` **以上**を必ず確保する（1.7が推奨）
- `letter-spacing` は全角文字の場合 `0.02em〜0.04em` で可読性が向上する
- 見出しは `letter-spacing: 0` またはわずかなマイナスで引き締める
- 欧文混じりテキストでは `letter-spacing` が欧文にも影響する点に注意

### 1.6 禁則処理・改行ルール

```css
word-break: break-all;
overflow-wrap: break-word;
line-break: strict;
```

**禁則対象**:
- 行頭禁止: `）」』】〕〉》」】、。，．・：；？！`
- 行末禁止: `（「『【〔〈《「【`

### 1.7 OpenType 機能

```css
/* 見出し・ナビゲーション向け */
font-feature-settings: "palt" 1;

/* 欧文カーニング */
font-feature-settings: "kern" 1;
```

- `palt`（プロポーショナル字詰め）は見出しやボタンに有効。本文には適用しない
- `kern` は和欧混植時に有効

---

## 2. Responsive Behavior

### ブレークポイント

Tailwind CSSの既定に従う:

| Name | Width | 説明 |
|------|-------|------|
| Mobile | < 640px | モバイルファースト |
| sm | >= 640px | 小型タブレット〜 |
| lg | >= 1024px | デスクトップ |

### タッチターゲット

- 最小サイズ: 44px x 44px（WCAG基準）
- モバイルのボタンは `py-2 px-4` 以上を確保

### フォントサイズの調整

- モバイルでは本文 14〜16px
- 見出しはデスクトップの 75〜85% 程度に縮小
- `sm:text-*` でブレークポイントごとに調整

---

## 3. Dark Mode

- Tailwind `darkMode` 未指定 → `media` 戦略（`prefers-color-scheme`）
- カスタムCSSは `@media (prefers-color-scheme: dark)` で記述
- Bladeテンプレート内では `dark:` Tailwindバリアントを使用可

### ダークモードの色の指針

- 背景に純粋な `#000000` を使わない（`#111827` 〜 `#1f2937` を使用）
- テキストに純粋な `#ffffff` を使わない（`#f3f4f6` 〜 `#e5e7eb` を使用）
- コントラスト比 WCAG AA（4.5:1）以上を確保

---

## 4. Accessibility

- `prefers-reduced-motion: reduce` でアニメーションを無効化する
- キーボードフォーカスには `:focus-visible` で視覚的インジケーターを提供する
- `aria-label`, `role`, `aria-pressed` 等の属性を適切に付与する
- カスタムフォーカスリングでブラウザデフォルトを上書きする場合、代替の視覚表示を必ず設ける

---

## 5. Do's and Don'ts

### Do

- フォントは必ずフォールバックチェーンを指定する
- 日本語本文の `line-height` は 1.5 以上にする
- 色のコントラスト比は WCAG AA 以上を確保する
- `prefers-reduced-motion` でアニメーションを制御する
- ダークモードを `@media (prefers-color-scheme: dark)` で対応する

### Don't

- `font-family` に和文フォント1つだけを指定しない（環境依存になる）
- 日本語本文に `line-height: 1.2` 以下を使わない
- テキストの色に純粋な `#000000` を使わない
- `outline: none` だけでフォーカス表示を消さない（代替表示を設ける）
- 全角・半角スペースを混在させない
