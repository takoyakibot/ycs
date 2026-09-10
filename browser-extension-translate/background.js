let isCapturing = false;
let captureTabId = null;

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
      saveTranslationResult(message).catch(() => {});
      break;

    case 'GET_RESULTS':
      chrome.storage.local.get(['translationResults']).then(data => {
        sendResponse({ results: data.translationResults || [] });
      });
      return true;

    case 'UPDATE_SETTINGS':
      chrome.runtime.sendMessage({
        type: 'UPDATE_SETTINGS_TO_OFFSCREEN',
        settings: message.settings
      }).catch(() => {});
      sendResponse({ success: true });
      return true;

    case 'GET_CAPTURE_STATUS':
      hasOffscreenDocument().then(hasDoc => {
        const capturing = isCapturing && hasDoc;
        if (isCapturing && !hasDoc) {
          isCapturing = false;
          captureTabId = null;
        }
        sendResponse({ isCapturing: capturing });
      });
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

  let streamId;
  try {
    streamId = await chrome.tabCapture.getMediaStreamId({ targetTabId: tab.id });
  } catch (error) {
    if (error.message?.includes('active stream')) {
      await stopCapture();
      await ensureOffscreenDocument();
      streamId = await chrome.tabCapture.getMediaStreamId({ targetTabId: tab.id });
    } else {
      await closeOffscreenDocument();
      throw error;
    }
  }

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
  captureTabId = tab.id;
  return { success: true };
}

async function stopCapture() {
  await chrome.runtime.sendMessage({ type: 'STOP_RECOGNITION' }).catch(() => {});
  await closeOffscreenDocument();
  isCapturing = false;
  captureTabId = null;
  return { success: true };
}

const MAX_RESULTS = 500;

async function saveTranslationResult(message) {
  const data = await chrome.storage.local.get(['translationResults']);
  const results = data.translationResults || [];
  results.push({
    original: message.original,
    translated: message.translated,
    elapsed: message.elapsed ?? null,
    timestamp: Date.now()
  });
  if (results.length > MAX_RESULTS) {
    results.splice(0, results.length - MAX_RESULTS);
  }
  await chrome.storage.local.set({ translationResults: results });
}

// インストール・更新時のみクリーンアップ（Service Worker再起動時には実行しない）
chrome.runtime.onInstalled.addListener(async () => {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument();
  }
  isCapturing = false;
});
