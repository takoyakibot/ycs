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

  const captureStatus = await chrome.runtime.sendMessage({ type: 'GET_CAPTURE_STATUS' });
  if (captureStatus?.isCapturing) {
    isCapturing = true;
    elements.startBtn.textContent = '停止';
    elements.startBtn.classList.add('capturing');
    elements.status.textContent = '音声を認識中...';
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
  try {
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
  } catch (error) {
    elements.startBtn.disabled = false;
    elements.status.textContent = `エラー: ${error.message}`;
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
