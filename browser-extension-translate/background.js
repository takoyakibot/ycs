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

    case 'GET_CAPTURE_STATUS':
      sendResponse({ isCapturing });
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

// インストール・更新時のみクリーンアップ（Service Worker再起動時には実行しない）
chrome.runtime.onInstalled.addListener(async () => {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument();
  }
  isCapturing = false;
});
