# リアルタイム音声翻訳Chrome拡張 設計書

## 概要

任意のブラウザタブの音声をリアルタイムでキャプチャし、文字起こし→日本語翻訳するChrome拡張。
既存の`browser-extension/`とは独立したプロジェクトとして`browser-extension-translate/`に配置する。

## 動機

Chromeの自動字幕機能は言語判定が甘く、韓国語配信で日本語に空耳して文字起こしされる問題がある。
また、韓国語のまま文字起こしされてもコピーや翻訳ができない。
本拡張では言語を明示的に指定することでこの問題を回避し、翻訳結果をコピー可能なテキストとして提供する。

## 対象言語

- 韓国語（ko）→ 日本語
- 英語（en）→ 日本語

## 技術構成

- Chrome Extension Manifest V3
- Web Speech API（SpeechRecognition）— 文字起こし
- DeepL API Free — 翻訳
- chrome.tabCapture + Offscreen Document — 音声キャプチャ

## アーキテクチャ

```
popup.html (操作UI: 言語選択・開始/停止・結果表示)
    ↓↑ chrome.runtime.sendMessage
background.js (service worker)
    ↓ chrome.tabCapture.getMediaStreamId → offscreen へ転送
offscreen.html/js
    ├─ navigator.mediaDevices.getUserMedia(streamId) → MediaStream取得
    ├─ SpeechRecognition(lang指定) → 文字起こしテキスト
    ├─ DeepL API Free → 日本語翻訳
    └─ chrome.runtime.sendMessage → background → popup へ結果返却
```

## コンポーネント詳細

### manifest.json

```json
{
  "manifest_version": 3,
  "name": "Tab Audio Translator",
  "version": "0.1.0",
  "permissions": ["tabCapture", "offscreen", "activeTab", "storage"],
  "host_permissions": ["https://api-free.deepl.com/*"],
  "action": { "default_popup": "popup.html" },
  "background": { "service_worker": "background.js" }
}
```

### popup.html / popup.js

- 言語選択ドロップダウン（韓国語 / 英語）
- 翻訳モード選択（全文翻訳 / 部分翻訳）
- 部分翻訳の割合スライダー（N単語に1語を翻訳。例: 3なら3単語ごとに1語）
- DeepL APIキー入力欄（初回のみ。`chrome.storage.local`に保存）
- 開始/停止ボタン
- 翻訳結果のテキストログ表示エリア（スクロール可能、テキスト選択・コピー可能）

### background.js

- popupからの開始/停止メッセージを受信
- `chrome.tabCapture.getMediaStreamId()`でアクティブタブの音声ストリームIDを取得
- offscreen documentの作成・管理
- offscreen→popupへの翻訳結果メッセージ中継

### offscreen.html / offscreen.js

- backgroundから受け取ったstreamIdで`navigator.mediaDevices.getUserMedia()`を呼び音声取得
- `SpeechRecognition`インスタンスを作成、`lang`プロパティに選択言語を設定
  - 韓国語: `'ko'`
  - 英語: `'en-US'`
- `continuous = true`, `interimResults = true`で継続的に認識
- `onresult`イベントで確定テキスト（`isFinal === true`）を取得
- 翻訳モードに応じた処理:
  - **全文翻訳**: 確定テキスト全体をDeepL APIに送信し日本語翻訳を取得
  - **部分翻訳**: 確定テキストをスペースで単語分割し、N単語ごとに1単語をDeepL APIで個別翻訳。翻訳された単語を原文中に差し込んで混合テキストを生成
    - 韓国語: スペース区切りが単語単位にほぼ対応するためそのまま利用
    - 英語: スペース区切りで分割
- 元テキスト＋翻訳テキストをbackgroundへ送信

### DeepL API連携

- エンドポイント: `https://api-free.deepl.com/v2/translate`
- パラメータ: `text`, `source_lang`（KO/EN）, `target_lang`（JA）
- 認証: `Authorization: DeepL-Auth-Key {key}`
- APIキーは`chrome.storage.local`から取得

## ファイル構成

```
browser-extension-translate/
├── manifest.json
├── popup.html
├── popup.js
├── background.js
├── offscreen.html
├── offscreen.js
└── icons/
    ├── icon16.png
    ├── icon48.png
    └── icon128.png
```

## 制約・注意事項

- Web Speech APIはChrome系ブラウザでのみ動作（Googleのサーバーに音声が送られる）
- DeepL API Freeは月50万文字の制限あり
- SpeechRecognitionはoffscreen document内で動作させる（service workerではDOM APIが使えないため）
- 検証段階のためエラーハンドリング・UI装飾は最小限
- アイコンはプレースホルダーで可

## テスト方法

手動確認：韓国語・英語の動画やライブ配信で実際に動作させて検証する。
