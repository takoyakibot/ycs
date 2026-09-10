const elements = {
  lang: document.getElementById('lang'),
  mode: document.getElementById('mode'),
  partialOptions: document.getElementById('partial-options'),
  partialN: document.getElementById('partial-n'),
  partialNValue: document.getElementById('partial-n-value'),
  chunkInterval: document.getElementById('chunk-interval'),
  chunkIntervalValue: document.getElementById('chunk-interval-value'),
  context: document.getElementById('context'),
  videoLabel: document.getElementById('video-label'),
  status: document.getElementById('status'),
  openaiKey: document.getElementById('openai-key'),
  saveOpenaiKey: document.getElementById('save-openai-key'),
  openaiKeyStatus: document.getElementById('openai-key-status'),
  deeplKey: document.getElementById('deepl-key'),
  saveDeeplKey: document.getElementById('save-deepl-key'),
  deeplKeyStatus: document.getElementById('deepl-key-status'),
  log: document.getElementById('log'),
  emptyState: document.getElementById('empty-state'),
  clearBtn: document.getElementById('clear-btn'),
  resultsCount: document.getElementById('results-count'),
  settingsSection: document.getElementById('settings-section'),
  apikeySection: document.getElementById('apikey-section')
};

let resultCount = 0;
let currentVideoKey = null;

async function init() {
  const result = await chrome.storage.local.get(['translateSettings']);
  const saved = result.translateSettings || {};

  if (saved.lang) elements.lang.value = saved.lang;
  if (saved.mode) elements.mode.value = saved.mode;
  if (saved.partialN) elements.partialN.value = saved.partialN;
  if (saved.chunkInterval) elements.chunkInterval.value = saved.chunkInterval;
  updatePartialNLabel();
  updateChunkIntervalLabel();
  updatePartialVisibility();

  if (saved.openaiKey) {
    elements.openaiKey.placeholder = '設定済み';
    elements.openaiKeyStatus.textContent = 'APIキー設定済み';
    elements.openaiKeyStatus.style.color = '#2e7d32';
  }
  if (saved.deeplKey) {
    elements.deeplKey.placeholder = '設定済み';
    elements.deeplKeyStatus.textContent = 'APIキー設定済み';
    elements.deeplKeyStatus.style.color = '#2e7d32';
  }
  if (saved.openaiKey && saved.deeplKey) {
    elements.apikeySection.removeAttribute('open');
  } else {
    elements.apikeySection.setAttribute('open', '');
  }

  const savedResults = await chrome.runtime.sendMessage({ type: 'GET_RESULTS' });
  if (savedResults?.results?.length) {
    for (const r of savedResults.results) {
      appendLog(r.original, r.translated, r.elapsed, false);
    }
    scrollToBottom();
  }

  try {
    const captureStatus = await chrome.runtime.sendMessage({ type: 'GET_CAPTURE_STATUS' });
    if (captureStatus?.isCapturing) {
      elements.status.textContent = '音声を認識中...';
      elements.settingsSection.removeAttribute('open');
    }
  } catch {}

  await loadVideoContext();

  elements.lang.addEventListener('change', saveSettings);
  elements.mode.addEventListener('change', () => {
    updatePartialVisibility();
    saveSettings();
  });
  elements.partialN.addEventListener('input', () => {
    updatePartialNLabel();
    saveSettings();
  });
  elements.chunkInterval.addEventListener('input', () => {
    updateChunkIntervalLabel();
    saveSettings();
  });
  elements.context.addEventListener('change', saveVideoContextFromUI);
  elements.saveOpenaiKey.addEventListener('click', saveOpenaiKeyHandler);
  elements.saveDeeplKey.addEventListener('click', saveDeeplKeyHandler);
  elements.clearBtn.addEventListener('click', clearResults);

  chrome.tabs.onActivated.addListener(() => loadVideoContext());
  chrome.tabs.onUpdated.addListener((_tabId, changeInfo) => {
    if (changeInfo.url) loadVideoContext();
  });

  chrome.runtime.onMessage.addListener((message) => {
    if (message.type === 'TRANSLATION_RESULT') {
      appendLog(message.original, message.translated, message.elapsed);
    }
  });
}

function extractVideoKey(url) {
  try {
    const u = new URL(url);
    if (u.hostname.includes('youtube.com')) {
      const videoId = u.searchParams.get('v');
      if (videoId) return `yt:${videoId}`;
    }
    if (u.hostname.includes('youtu.be')) {
      const videoId = u.pathname.slice(1).split('/')[0];
      if (videoId) return `yt:${videoId}`;
    }
    return u.origin + u.pathname;
  } catch {
    return null;
  }
}

async function loadVideoContext() {
  let tab;
  try {
    const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true });
    tab = activeTab;
  } catch { return; }

  if (!tab?.url) {
    currentVideoKey = null;
    elements.videoLabel.textContent = '';
    elements.context.value = '';
    return;
  }

  const newKey = extractVideoKey(tab.url);
  if (newKey === currentVideoKey) return;

  if (currentVideoKey) {
    await saveVideoContextValue(currentVideoKey, elements.context.value.trim());
  }

  currentVideoKey = newKey;
  elements.videoLabel.textContent = tab.title || '';

  if (!currentVideoKey) {
    elements.context.value = '';
    return;
  }

  const result = await chrome.storage.local.get(['videoContexts']);
  const contexts = result.videoContexts || {};
  elements.context.value = contexts[currentVideoKey] || '';
}

async function saveVideoContextValue(key, text) {
  if (!key) return;
  const result = await chrome.storage.local.get(['videoContexts']);
  const contexts = result.videoContexts || {};
  if (text) {
    contexts[key] = text;
  } else {
    delete contexts[key];
  }
  await chrome.storage.local.set({ videoContexts: contexts });
}

async function saveVideoContextFromUI() {
  await saveVideoContextValue(currentVideoKey, elements.context.value.trim());
  chrome.runtime.sendMessage({
    type: 'UPDATE_SETTINGS',
    settings: { context: elements.context.value.trim() }
  }).catch(() => {});
}

function updatePartialVisibility() {
  elements.partialOptions.classList.toggle('visible', elements.mode.value === 'partial');
}

function updatePartialNLabel() {
  elements.partialNValue.textContent = `${elements.partialN.value}単語に1語`;
}

function updateChunkIntervalLabel() {
  elements.chunkIntervalValue.textContent = `${elements.chunkInterval.value}秒`;
}

async function saveSettings() {
  const current = await chrome.storage.local.get(['translateSettings']);
  const saved = current.translateSettings || {};
  const updated = {
    ...saved,
    lang: elements.lang.value,
    mode: elements.mode.value,
    partialN: parseInt(elements.partialN.value, 10),
    chunkInterval: parseInt(elements.chunkInterval.value, 10)
  };
  await chrome.storage.local.set({ translateSettings: updated });

  chrome.runtime.sendMessage({
    type: 'UPDATE_SETTINGS',
    settings: {
      lang: updated.lang,
      mode: updated.mode,
      partialN: updated.partialN,
      chunkInterval: updated.chunkInterval
    }
  }).catch(() => {});
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
}

function formatElapsed(seconds) {
  if (seconds == null) return null;
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function appendLog(original, translated, elapsed, autoScroll = true) {
  elements.emptyState.hidden = true;

  const shouldScroll = autoScroll &&
    (elements.log.scrollTop + elements.log.clientHeight >= elements.log.scrollHeight - 50);

  const elapsedStr = formatElapsed(elapsed);
  const timePrefix = elapsedStr ? `<span class="log-elapsed">${elapsedStr}</span> ` : '';

  const entry = document.createElement('div');
  entry.className = 'log-entry';
  entry.innerHTML =
    `<div class="log-original">${timePrefix}${escapeHtml(original)}</div>` +
    `<div class="log-translated">${escapeHtml(translated)}</div>`;
  elements.log.appendChild(entry);

  resultCount++;
  updateResultsCount();

  if (shouldScroll) {
    elements.log.scrollTop = elements.log.scrollHeight;
  }
}

function scrollToBottom() {
  elements.log.scrollTop = elements.log.scrollHeight;
}

function updateResultsCount() {
  elements.resultsCount.textContent = resultCount > 0 ? `(${resultCount})` : '';
}

async function clearResults() {
  const entries = elements.log.querySelectorAll('.log-entry');
  entries.forEach(e => e.remove());
  elements.emptyState.hidden = false;
  resultCount = 0;
  updateResultsCount();
  await chrome.storage.local.remove('translationResults');
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

init();
