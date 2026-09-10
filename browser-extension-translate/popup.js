const elements = {
  lang: document.getElementById('lang'),
  mode: document.getElementById('mode'),
  partialOptions: document.getElementById('partial-options'),
  partialN: document.getElementById('partial-n'),
  partialNValue: document.getElementById('partial-n-value'),
  startBtn: document.getElementById('start-btn'),
  status: document.getElementById('status'),
  openaiKey: document.getElementById('openai-key'),
  saveOpenaiKey: document.getElementById('save-openai-key'),
  openaiKeyStatus: document.getElementById('openai-key-status'),
  deeplKey: document.getElementById('deepl-key'),
  saveDeeplKey: document.getElementById('save-deepl-key'),
  deeplKeyStatus: document.getElementById('deepl-key-status'),
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

  let hasOpenaiKey = false;
  let hasDeeplKey = false;

  if (saved.openaiKey) {
    elements.openaiKey.placeholder = '設定済み';
    elements.openaiKeyStatus.textContent = 'APIキー設定済み';
    elements.openaiKeyStatus.style.color = '#2e7d32';
    hasOpenaiKey = true;
  }

  if (saved.deeplKey) {
    elements.deeplKey.placeholder = '設定済み';
    elements.deeplKeyStatus.textContent = 'APIキー設定済み';
    elements.deeplKeyStatus.style.color = '#2e7d32';
    hasDeeplKey = true;
  }

  updateStartButton(hasOpenaiKey, hasDeeplKey);

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
  elements.saveOpenaiKey.addEventListener('click', saveOpenaiKeyHandler);
  elements.saveDeeplKey.addEventListener('click', saveDeeplKeyHandler);
  document.getElementById('clear-log').addEventListener('click', async () => {
    await chrome.storage.local.remove('translationResults');
    elements.log.innerHTML = '';
  });

  const savedResults = await chrome.runtime.sendMessage({ type: 'GET_RESULTS' });
  if (savedResults?.results?.length) {
    for (const r of savedResults.results) {
      appendLog(r.original, r.translated, r.elapsed);
    }
  }

  chrome.runtime.onMessage.addListener((message) => {
    if (message.type === 'TRANSLATION_RESULT') {
      appendLog(message.original, message.translated, message.elapsed);
    }
  });
}

function updateStartButton(hasOpenaiKey, hasDeeplKey) {
  if (hasOpenaiKey && hasDeeplKey) {
    elements.startBtn.disabled = false;
    if (!isCapturing) {
      elements.status.textContent = '開始ボタンを押してください';
    }
  } else {
    elements.startBtn.disabled = true;
    const missing = [];
    if (!hasOpenaiKey) missing.push('OpenAI');
    if (!hasDeeplKey) missing.push('DeepL');
    elements.status.textContent = `${missing.join(' / ')} APIキーを設定してください`;
  }
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
    partialN: parseInt(elements.partialN.value, 10)
  };
}

async function getFullSettings() {
  const s = getSettings();
  const result = await chrome.storage.local.get(['translateSettings']);
  const saved = result.translateSettings || {};
  s.openaiKey = saved.openaiKey || '';
  s.deeplKey = saved.deeplKey || '';
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

  if (isCapturing) {
    chrome.runtime.sendMessage({
      type: 'UPDATE_SETTINGS',
      settings: { lang: updated.lang, mode: updated.mode, partialN: updated.partialN }
    });
  }
}

async function saveOpenaiKeyHandler() {
  const key = elements.openaiKey.value.trim();
  if (!key) {
    elements.openaiKeyStatus.textContent = 'キーを入力してください';
    elements.openaiKeyStatus.style.color = '#c62828';
    return;
  }

  const current = await chrome.storage.local.get(['translateSettings']);
  const saved = current.translateSettings || {};
  saved.openaiKey = key;
  await chrome.storage.local.set({ translateSettings: saved });

  elements.openaiKey.value = '';
  elements.openaiKey.placeholder = '設定済み';
  elements.openaiKeyStatus.textContent = '保存しました';
  elements.openaiKeyStatus.style.color = '#2e7d32';

  updateStartButton(true, !!saved.deeplKey);
}

async function saveDeeplKeyHandler() {
  const key = elements.deeplKey.value.trim();
  if (!key) {
    elements.deeplKeyStatus.textContent = 'キーを入力してください';
    elements.deeplKeyStatus.style.color = '#c62828';
    return;
  }

  const current = await chrome.storage.local.get(['translateSettings']);
  const saved = current.translateSettings || {};
  saved.deeplKey = key;
  await chrome.storage.local.set({ translateSettings: saved });

  elements.deeplKey.value = '';
  elements.deeplKey.placeholder = '設定済み';
  elements.deeplKeyStatus.textContent = '保存しました';
  elements.deeplKeyStatus.style.color = '#2e7d32';

  updateStartButton(!!saved.openaiKey, true);
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
      if (!fullSettings.openaiKey || !fullSettings.deeplKey) {
        elements.status.textContent = 'APIキーを設定してください';
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

function formatElapsed(seconds) {
  if (seconds == null) return '';
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function appendLog(original, translated, elapsed) {
  const entry = document.createElement('div');
  entry.className = 'log-entry';
  const ts = elapsed != null ? `<span class="log-time">${formatElapsed(elapsed)}</span> ` : '';
  entry.innerHTML = `<div class="log-original">${ts}${escapeHtml(original)}</div><div class="log-translated">${escapeHtml(translated)}</div>`;
  elements.log.appendChild(entry);
  elements.log.scrollTop = elements.log.scrollHeight;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

init();
