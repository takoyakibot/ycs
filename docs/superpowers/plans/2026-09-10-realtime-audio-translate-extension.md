# リアルタイム音声翻訳Chrome拡張 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 任意のブラウザタブの音声をキャプチャし、Web Speech APIで文字起こし→DeepL APIで日本語翻訳するChrome拡張を新規作成する

**Architecture:** MV3拡張。popup（UI）→ background（tabCapture管理・メッセージ中継）→ offscreen（音声処理・文字起こし・翻訳）の3層構成。既存の`browser-extension/`と同じパターンに従う。

**Tech Stack:** Chrome Extension Manifest V3, Web Speech API (SpeechRecognition), DeepL API Free, chrome.tabCapture, Offscreen Document

**Spec:** `docs/superpowers/specs/2026-09-10-realtime-audio-translate-extension-design.md`

## Global Constraints

- Chrome Extension Manifest V3
- 検証段階のためUI装飾・エラーハンドリングは最小限
- アイコンはプレースホルダー（空のPNG）
- 自動テストなし（手動検証）
- 既存の`browser-extension/`のコードパターンに準拠

## ファイル構成

```
browser-extension-translate/
├── manifest.json          — 拡張の定義（permissions, offscreen等）
├── background.js          — service worker: tabCapture管理、メッセージ中継
├── offscreen.html         — offscreen documentのHTML shell
├── offscreen.js           — 音声キャプチャ、SpeechRecognition、DeepL API呼び出し
├── popup.html             — UI: 言語選択、翻訳モード、APIキー入力、ログ表示
├── popup.js               — popupのロジック
└── icons/
    ├── icon16.png         — プレースホルダーアイコン
    ├── icon48.png
    └── icon128.png
```

---

### Task 1: プロジェクト骨格（manifest.json + offscreen.html + アイコン）

**Files:**
- Create: `browser-extension-translate/manifest.json`
- Create: `browser-extension-translate/offscreen.html`
- Create: `browser-extension-translate/icons/icon16.png`
- Create: `browser-extension-translate/icons/icon48.png`
- Create: `browser-extension-translate/icons/icon128.png`

**Interfaces:**
- Consumes: なし
- Produces: MV3拡張としてChromeに読み込み可能な最小構成

- [ ] **Step 1: manifest.jsonを作成**

```json
{
  "manifest_version": 3,
  "name": "Tab Audio Translator",
  "version": "0.1.0",
  "description": "タブの音声をリアルタイムで文字起こし・翻訳する",
  "permissions": ["tabCapture", "offscreen", "activeTab", "storage"],
  "host_permissions": ["https://api-free.deepl.com/*"],
  "action": {
    "default_popup": "popup.html",
    "default_icon": {
      "16": "icons/icon16.png",
      "48": "icons/icon48.png",
      "128": "icons/icon128.png"
    }
  },
  "background": {
    "service_worker": "background.js"
  },
  "icons": {
    "16": "icons/icon16.png",
    "48": "icons/icon48.png",
    "128": "icons/icon128.png"
  }
}
```

- [ ] **Step 2: offscreen.htmlを作成**

```html
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Offscreen Audio Translator</title>
</head>
<body>
  <script src="offscreen.js"></script>
</body>
</html>
```

- [ ] **Step 3: プレースホルダーアイコンを作成**

1x1ピクセルの透明PNGを3サイズ分作成する。以下のワンライナーで生成:

```bash
mkdir -p browser-extension-translate/icons
# 1x1 transparent PNG (最小の有効なPNG)
python3 -c "
import struct, zlib
def make_png(w, h):
    raw = b''
    for y in range(h):
        raw += b'\x00' + b'\x00\x00\x00\x00' * w
    def chunk(ctype, data):
        c = ctype + data
        return struct.pack('>I', len(data)) + c + struct.pack('>I', zlib.crc32(c) & 0xffffffff)
    ihdr = struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', ihdr) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')
for size in [16, 48, 128]:
    open(f'browser-extension-translate/icons/icon{size}.png', 'wb').write(make_png(size, size))
print('Icons created')
"
```

- [ ] **Step 4: 空のbackground.js, offscreen.js, popup.html, popup.jsを作成**

後続タスクで実装するため、空ファイルを配置してChromeに読み込めるようにする。

```bash
touch browser-extension-translate/background.js
touch browser-extension-translate/offscreen.js
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Tab Audio Translator</title></head><body><script src="popup.js"></script></body></html>' > browser-extension-translate/popup.html
touch browser-extension-translate/popup.js
```

- [ ] **Step 5: Chromeに読み込んでエラーがないことを手動確認**

`chrome://extensions` → デベロッパーモード → 「パッケージ化されていない拡張機能を読み込む」で `browser-extension-translate/` を指定。エラーが出ないことを確認。

- [ ] **Step 6: コミット**

```bash
git add browser-extension-translate/
git commit -m "feat: 音声翻訳Chrome拡張の骨格を作成"
```

---

### Task 2: background.js（tabCapture管理 + メッセージ中継）

**Files:**
- Create: `browser-extension-translate/background.js`

**Interfaces:**
- Consumes: なし
- Produces:
  - popup → background メッセージ: `{ type: 'START_CAPTURE' }` → offscreen documentを作成し、tabCapture streamIdを取得してoffscreenに転送。`sendResponse({ success: true })` を返す
  - popup → background メッセージ: `{ type: 'STOP_CAPTURE' }` → offscreenに停止指示を送り、offscreen documentを閉じる。`sendResponse({ success: true })` を返す
  - offscreen → background メッセージ: `{ type: 'TRANSLATION_RESULT', original: string, translated: string }` → popup/全リスナーに中継
  - popup → background メッセージ: `{ type: 'UPDATE_SETTINGS', lang: string, mode: string, partialN: number, deeplKey: string }` → offscreenに転送

- [ ] **Step 1: background.jsを実装**

```js
let isCapturing = false;

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'START_CAPTURE':
      startCapture(message.settings)
        .then(result => sendResponse(result))
        .catch(error => sendResponse({ success: false, error: error.message }));
      return true;

    case 'STOP_CAPTURE':
      stopCapture()
        .then(result => sendResponse(result))
        .catch(error => sendResponse({ success: false, error: error.message }));
      return true;

    case 'TRANSLATION_RESULT':
      // offscreenからの翻訳結果をそのまま中継（popupが受け取る）
      break;

    case 'UPDATE_SETTINGS':
      // offscreenに設定を転送
      chrome.runtime.sendMessage({
        type: 'UPDATE_SETTINGS_TO_OFFSCREEN',
        settings: message.settings
      }).catch(() => {});
      sendResponse({ success: true });
      return true;
  }
});

async function hasOffscreenDocument() {
  const contexts = await chrome.runtime.getContexts({
    contextTypes: ['OFFSCREEN_DOCUMENT'],
    documentUrls: [chrome.runtime.getURL('offscreen.html')]
  });
  return contexts.length > 0;
}

async function ensureOffscreenDocument() {
  if (await hasOffscreenDocument()) return;
  await chrome.offscreen.createDocument({
    url: 'offscreen.html',
    reasons: ['USER_MEDIA'],
    justification: 'Audio capture for speech recognition and translation'
  });
}

async function closeOffscreenDocument() {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument();
  }
}

async function startCapture(settings) {
  if (isCapturing) {
    return { success: false, error: '既にキャプチャ中です' };
  }

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id) {
    return { success: false, error: 'アクティブタブが見つかりません' };
  }

  await ensureOffscreenDocument();

  const streamId = await chrome.tabCapture.getMediaStreamId({ targetTabId: tab.id });
  if (!streamId) {
    await closeOffscreenDocument();
    return { success: false, error: 'Stream IDを取得できませんでした' };
  }

  const response = await chrome.runtime.sendMessage({
    type: 'START_RECOGNITION',
    streamId,
    settings
  });

  if (!response?.success) {
    await closeOffscreenDocument();
    return { success: false, error: response?.error || '音声認識の開始に失敗' };
  }

  isCapturing = true;
  return { success: true };
}

async function stopCapture() {
  await chrome.runtime.sendMessage({ type: 'STOP_RECOGNITION' }).catch(() => {});
  await closeOffscreenDocument();
  isCapturing = false;
  return { success: true };
}

// Service Worker起動時にクリーンアップ
(async () => {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument();
  }
  isCapturing = false;
})();
```

- [ ] **Step 2: Chromeに再読み込みしてservice workerが登録されることを確認**

- [ ] **Step 3: コミット**

```bash
git add browser-extension-translate/background.js
git commit -m "feat: background.js - tabCapture管理とメッセージ中継"
```

---

### Task 3: offscreen.js（音声キャプチャ + 文字起こし + 翻訳）

**Files:**
- Create: `browser-extension-translate/offscreen.js`

**Interfaces:**
- Consumes:
  - background → offscreen メッセージ: `{ type: 'START_RECOGNITION', streamId: string, settings: { lang: string, mode: string, partialN: number, deeplKey: string } }`
  - background → offscreen メッセージ: `{ type: 'STOP_RECOGNITION' }`
  - background → offscreen メッセージ: `{ type: 'UPDATE_SETTINGS_TO_OFFSCREEN', settings: { lang: string, mode: string, partialN: number, deeplKey: string } }`
- Produces:
  - offscreen → background メッセージ: `{ type: 'TRANSLATION_RESULT', original: string, translated: string }`

- [ ] **Step 1: offscreen.jsを実装 — 音声キャプチャとSpeechRecognition**

```js
let captureStream = null;
let recognition = null;
let settings = {
  lang: 'ko',
  mode: 'full',   // 'full' | 'partial'
  partialN: 3,
  deeplKey: ''
};

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'START_RECOGNITION':
      if (message.settings) Object.assign(settings, message.settings);
      startRecognition(message.streamId)
        .then(result => sendResponse(result))
        .catch(error => sendResponse({ success: false, error: error.message }));
      return true;

    case 'STOP_RECOGNITION':
      stopRecognition();
      sendResponse({ success: true });
      return true;

    case 'UPDATE_SETTINGS_TO_OFFSCREEN':
      if (message.settings) Object.assign(settings, message.settings);
      if (recognition) {
        recognition.lang = settings.lang === 'en' ? 'en-US' : 'ko';
      }
      sendResponse({ success: true });
      return true;
  }
});

async function startRecognition(streamId) {
  captureStream = await navigator.mediaDevices.getUserMedia({
    audio: {
      mandatory: {
        chromeMediaSource: 'tab',
        chromeMediaSourceId: streamId
      }
    }
  });

  if (!captureStream) {
    return { success: false, error: 'ストリームを取得できませんでした' };
  }

  // AudioContextに接続して音声をアクティブに保つ
  const audioContext = new AudioContext();
  const source = audioContext.createMediaStreamSource(captureStream);
  source.connect(audioContext.createAnalyser());

  recognition = new webkitSpeechRecognition();
  recognition.lang = settings.lang === 'en' ? 'en-US' : 'ko';
  recognition.continuous = true;
  recognition.interimResults = true;

  recognition.onresult = async (event) => {
    for (let i = event.resultIndex; i < event.results.length; i++) {
      if (event.results[i].isFinal) {
        const text = event.results[i][0].transcript.trim();
        if (!text) continue;
        await handleFinalText(text);
      }
    }
  };

  recognition.onerror = (event) => {
    console.error('SpeechRecognition error:', event.error);
    if (event.error === 'no-speech' || event.error === 'aborted') {
      // 自動再開
      try { recognition.start(); } catch (e) {}
    }
  };

  recognition.onend = () => {
    // continuous modeでも環境によって停止する場合がある — 自動再開
    if (captureStream) {
      try { recognition.start(); } catch (e) {}
    }
  };

  recognition.start();
  return { success: true };
}

function stopRecognition() {
  if (recognition) {
    recognition.onend = null; // 自動再開を防ぐ
    recognition.abort();
    recognition = null;
  }
  if (captureStream) {
    captureStream.getTracks().forEach(track => track.stop());
    captureStream = null;
  }
}

async function handleFinalText(text) {
  let translated;

  if (settings.mode === 'partial') {
    translated = await translatePartial(text, settings.partialN);
  } else {
    translated = await translateFull(text);
  }

  chrome.runtime.sendMessage({
    type: 'TRANSLATION_RESULT',
    original: text,
    translated: translated
  });
}

async function translateFull(text) {
  if (!settings.deeplKey) return '(DeepL APIキー未設定)';

  const sourceLang = settings.lang === 'en' ? 'EN' : 'KO';

  const response = await fetch('https://api-free.deepl.com/v2/translate', {
    method: 'POST',
    headers: {
      'Authorization': `DeepL-Auth-Key ${settings.deeplKey}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      text: [text],
      source_lang: sourceLang,
      target_lang: 'JA'
    })
  });

  if (!response.ok) {
    console.error('DeepL API error:', response.status);
    return `(翻訳エラー: ${response.status})`;
  }

  const data = await response.json();
  return data.translations?.[0]?.text || '(翻訳結果なし)';
}

async function translatePartial(text, n) {
  if (!settings.deeplKey) return '(DeepL APIキー未設定)';

  const words = text.split(/\s+/).filter(w => w.length > 0);
  if (words.length === 0) return text;

  // N単語ごとに1つの単語を翻訳対象として選ぶ
  const indicesToTranslate = [];
  for (let i = 0; i < words.length; i += n) {
    indicesToTranslate.push(i);
  }

  if (indicesToTranslate.length === 0) return text;

  // 翻訳対象の単語をまとめてDeepL APIに送る
  const wordsToTranslate = indicesToTranslate.map(i => words[i]);
  const sourceLang = settings.lang === 'en' ? 'EN' : 'KO';

  const response = await fetch('https://api-free.deepl.com/v2/translate', {
    method: 'POST',
    headers: {
      'Authorization': `DeepL-Auth-Key ${settings.deeplKey}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      text: wordsToTranslate,
      source_lang: sourceLang,
      target_lang: 'JA'
    })
  });

  if (!response.ok) {
    console.error('DeepL API error:', response.status);
    return text;
  }

  const data = await response.json();
  const translations = data.translations || [];

  // 翻訳結果を原文に差し込む
  const result = [...words];
  indicesToTranslate.forEach((wordIndex, transIndex) => {
    if (translations[transIndex]?.text) {
      result[wordIndex] = `[${translations[transIndex].text}]`;
    }
  });

  return result.join(' ');
}
```

- [ ] **Step 2: コミット**

```bash
git add browser-extension-translate/offscreen.js
git commit -m "feat: offscreen.js - 音声キャプチャ・文字起こし・翻訳処理"
```

---

### Task 4: popup.html + popup.js（操作UI + 翻訳ログ表示）

**Files:**
- Create: `browser-extension-translate/popup.html`
- Create: `browser-extension-translate/popup.js`

**Interfaces:**
- Consumes:
  - background からのメッセージ: `{ type: 'TRANSLATION_RESULT', original: string, translated: string }`
- Produces:
  - background へのメッセージ: `{ type: 'START_CAPTURE', settings: { lang, mode, partialN, deeplKey } }`
  - background へのメッセージ: `{ type: 'STOP_CAPTURE' }`
  - background へのメッセージ: `{ type: 'UPDATE_SETTINGS', settings: { lang, mode, partialN, deeplKey } }`

- [ ] **Step 1: popup.htmlを実装**

```html
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tab Audio Translator</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      width: 360px;
      font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
      font-size: 13px;
      color: #333;
      background: #fff;
    }

    .header {
      background: linear-gradient(135deg, #2196f3 0%, #1565c0 100%);
      color: white;
      padding: 10px 14px;
      font-size: 14px;
      font-weight: 600;
    }

    .controls {
      padding: 12px 14px;
      border-bottom: 1px solid #eee;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .row {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    label { font-size: 12px; color: #555; white-space: nowrap; }

    select, input[type="text"], input[type="password"] {
      flex: 1;
      font-size: 12px;
      padding: 4px 6px;
      border: 1px solid #ddd;
      border-radius: 4px;
    }

    input[type="range"] { flex: 1; }

    .btn {
      padding: 8px 16px;
      font-size: 13px;
      font-weight: 600;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      transition: opacity 0.2s;
    }

    .btn:hover { opacity: 0.9; }

    .btn-start {
      background: linear-gradient(135deg, #2196f3 0%, #1565c0 100%);
      color: white;
      width: 100%;
    }

    .btn-start.capturing {
      background: #e53935;
    }

    .btn-start:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    #partial-options { display: none; }
    #partial-options.visible { display: flex; }

    .log-area {
      height: 250px;
      overflow-y: auto;
      padding: 8px 14px;
      font-size: 12px;
      line-height: 1.6;
    }

    .log-entry {
      padding: 4px 0;
      border-bottom: 1px solid #f5f5f5;
      user-select: text;
    }

    .log-original {
      color: #888;
      font-size: 11px;
    }

    .log-translated {
      color: #1565c0;
    }

    .status {
      padding: 4px 14px;
      font-size: 11px;
      color: #888;
      text-align: center;
    }

    .api-key-section {
      padding: 0 14px 12px;
    }

    .api-key-row {
      display: flex;
      gap: 4px;
    }

    .btn-save {
      padding: 4px 10px;
      font-size: 11px;
      background: #2196f3;
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }

    .api-status {
      font-size: 10px;
      color: #888;
      margin-top: 2px;
    }
  </style>
</head>
<body>
  <div class="header">Tab Audio Translator</div>

  <div class="controls">
    <div class="row">
      <label for="lang">言語:</label>
      <select id="lang">
        <option value="ko">韓国語</option>
        <option value="en">英語</option>
      </select>
    </div>

    <div class="row">
      <label for="mode">翻訳:</label>
      <select id="mode">
        <option value="full">全文翻訳</option>
        <option value="partial">部分翻訳</option>
      </select>
    </div>

    <div class="row" id="partial-options">
      <label for="partial-n">間隔:</label>
      <input type="range" id="partial-n" min="2" max="8" value="3">
      <span id="partial-n-value">3単語に1語</span>
    </div>

    <button class="btn btn-start" id="start-btn" disabled>開始</button>
    <div class="status" id="status">DeepL APIキーを設定してください</div>
  </div>

  <div class="api-key-section">
    <label>DeepL API Free キー:</label>
    <div class="api-key-row">
      <input type="password" id="deepl-key" placeholder="xxxxxxxx-xxxx-...">
      <button class="btn-save" id="save-key">保存</button>
    </div>
    <div class="api-status" id="key-status"></div>
  </div>

  <div class="log-area" id="log"></div>

  <script src="popup.js"></script>
</body>
</html>
```

- [ ] **Step 2: popup.jsを実装**

```js
const elements = {
  lang: document.getElementById('lang'),
  mode: document.getElementById('mode'),
  partialOptions: document.getElementById('partial-options'),
  partialN: document.getElementById('partial-n'),
  partialNValue: document.getElementById('partial-n-value'),
  startBtn: document.getElementById('start-btn'),
  status: document.getElementById('status'),
  deeplKey: document.getElementById('deepl-key'),
  saveKey: document.getElementById('save-key'),
  keyStatus: document.getElementById('key-status'),
  log: document.getElementById('log')
};

let isCapturing = false;

async function init() {
  const result = await chrome.storage.local.get(['translateSettings']);
  const saved = result.translateSettings || {};

  if (saved.lang) elements.lang.value = saved.lang;
  if (saved.mode) elements.mode.value = saved.mode;
  if (saved.partialN) elements.partialN.value = saved.partialN;
  updatePartialNLabel();
  updatePartialVisibility();

  if (saved.deeplKey) {
    elements.deeplKey.placeholder = '設定済み';
    elements.keyStatus.textContent = 'APIキー設定済み';
    elements.keyStatus.style.color = '#2e7d32';
    elements.startBtn.disabled = false;
    elements.status.textContent = '開始ボタンを押してください';
  }

  elements.lang.addEventListener('change', saveSettings);
  elements.mode.addEventListener('change', () => {
    updatePartialVisibility();
    saveSettings();
  });
  elements.partialN.addEventListener('input', () => {
    updatePartialNLabel();
    saveSettings();
  });
  elements.startBtn.addEventListener('click', toggleCapture);
  elements.saveKey.addEventListener('click', saveDeeplKey);

  // 翻訳結果を受信
  chrome.runtime.onMessage.addListener((message) => {
    if (message.type === 'TRANSLATION_RESULT') {
      appendLog(message.original, message.translated);
    }
  });
}

function updatePartialVisibility() {
  elements.partialOptions.classList.toggle('visible', elements.mode.value === 'partial');
}

function updatePartialNLabel() {
  elements.partialNValue.textContent = `${elements.partialN.value}単語に1語`;
}

function getSettings() {
  return {
    lang: elements.lang.value,
    mode: elements.mode.value,
    partialN: parseInt(elements.partialN.value, 10),
    deeplKey: '' // storageから取得するためここでは空
  };
}

async function getFullSettings() {
  const s = getSettings();
  const result = await chrome.storage.local.get(['translateSettings']);
  s.deeplKey = result.translateSettings?.deeplKey || '';
  return s;
}

async function saveSettings() {
  const current = await chrome.storage.local.get(['translateSettings']);
  const saved = current.translateSettings || {};
  const updated = {
    ...saved,
    lang: elements.lang.value,
    mode: elements.mode.value,
    partialN: parseInt(elements.partialN.value, 10)
  };
  await chrome.storage.local.set({ translateSettings: updated });

  // キャプチャ中なら設定をoffscreenにも転送
  if (isCapturing) {
    chrome.runtime.sendMessage({
      type: 'UPDATE_SETTINGS',
      settings: { ...updated, deeplKey: updated.deeplKey || '' }
    });
  }
}

async function saveDeeplKey() {
  const key = elements.deeplKey.value.trim();
  if (!key) {
    elements.keyStatus.textContent = 'キーを入力してください';
    elements.keyStatus.style.color = '#c62828';
    return;
  }

  const current = await chrome.storage.local.get(['translateSettings']);
  const saved = current.translateSettings || {};
  saved.deeplKey = key;
  await chrome.storage.local.set({ translateSettings: saved });

  elements.deeplKey.value = '';
  elements.deeplKey.placeholder = '設定済み';
  elements.keyStatus.textContent = '保存しました';
  elements.keyStatus.style.color = '#2e7d32';
  elements.startBtn.disabled = false;
  elements.status.textContent = '開始ボタンを押してください';
}

async function toggleCapture() {
  if (isCapturing) {
    const result = await chrome.runtime.sendMessage({ type: 'STOP_CAPTURE' });
    if (result.success) {
      isCapturing = false;
      elements.startBtn.textContent = '開始';
      elements.startBtn.classList.remove('capturing');
      elements.status.textContent = '停止しました';
    }
  } else {
    const fullSettings = await getFullSettings();
    if (!fullSettings.deeplKey) {
      elements.status.textContent = 'DeepL APIキーを設定してください';
      return;
    }

    elements.startBtn.disabled = true;
    elements.status.textContent = '開始中...';

    const result = await chrome.runtime.sendMessage({
      type: 'START_CAPTURE',
      settings: fullSettings
    });

    elements.startBtn.disabled = false;

    if (result.success) {
      isCapturing = true;
      elements.startBtn.textContent = '停止';
      elements.startBtn.classList.add('capturing');
      elements.status.textContent = '音声を認識中...';
    } else {
      elements.status.textContent = `エラー: ${result.error}`;
    }
  }
}

function appendLog(original, translated) {
  const entry = document.createElement('div');
  entry.className = 'log-entry';
  entry.innerHTML = `<div class="log-original">${escapeHtml(original)}</div><div class="log-translated">${escapeHtml(translated)}</div>`;
  elements.log.appendChild(entry);
  elements.log.scrollTop = elements.log.scrollHeight;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

init();
```

- [ ] **Step 3: Chromeで拡張を再読み込みし、popupが表示されることを確認**

- [ ] **Step 4: コミット**

```bash
git add browser-extension-translate/popup.html browser-extension-translate/popup.js
git commit -m "feat: popup UI - 言語選択・翻訳モード・APIキー入力・ログ表示"
```

---

### Task 5: 結合テスト（手動検証）

**Files:**
- 変更なし（全ファイルの結合動作確認）

**Interfaces:**
- Consumes: Task 1〜4の全成果物
- Produces: 動作確認済みの拡張

- [ ] **Step 1: Chromeで拡張を再読み込み**

`chrome://extensions` → 更新ボタン

- [ ] **Step 2: DeepL APIキーを設定**

popupを開き、DeepL API Freeのキーを入力して保存

- [ ] **Step 3: 全文翻訳モードで韓国語動画をテスト**

1. 韓国語の動画またはライブ配信を開く
2. popupで言語を「韓国語」、翻訳を「全文翻訳」に設定
3. 「開始」ボタンを押す
4. 文字起こし結果と翻訳結果がログに表示されることを確認

- [ ] **Step 4: 部分翻訳モードでテスト**

1. 翻訳モードを「部分翻訳」に切り替え
2. スライダーで間隔を調整
3. 原文の一部の単語だけが日本語に置き換わった状態で表示されることを確認

- [ ] **Step 5: 英語動画でテスト**

1. 英語の動画に切り替え
2. 言語を「英語」に変更
3. 英語の文字起こしと翻訳が動作することを確認

- [ ] **Step 6: 問題があれば修正してコミット**

```bash
git add browser-extension-translate/
git commit -m "fix: 結合テストで発見した問題を修正"
```
