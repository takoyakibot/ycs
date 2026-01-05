/**
 * 歌枠タイムスタンプ検出 - Popup Script
 */

let isCapturing = false;
let isScanning = false;
let currentTabId = null;

// DOM要素
const elements = {
  statusDot: document.getElementById('status-dot'),
  statusText: document.getElementById('status-text'),
  count: document.getElementById('count'),
  toggleBtn: document.getElementById('toggle-btn'),
  btnIcon: document.getElementById('btn-icon'),
  btnText: document.getElementById('btn-text'),
  timestampsList: document.getElementById('timestamps-list'),
  copyBtn: document.getElementById('copy-btn'),
  clearBtn: document.getElementById('clear-btn'),
  toggleOverlayBtn: document.getElementById('toggle-overlay-btn'),
  toggleGraphBtn: document.getElementById('toggle-graph-btn'),
  scanBtn: document.getElementById('scan-btn'),
  errorContainer: document.getElementById('error-container'),
  infoContainer: document.getElementById('info-container'),
  volumeThreshold: document.getElementById('volume-threshold'),
  quietThreshold: document.getElementById('quiet-threshold'),
  quietDuration: document.getElementById('quiet-duration'),
  cooldown: document.getElementById('cooldown')
};

/**
 * 初期化
 */
async function init() {
  // 現在のタブを取得
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  currentTabId = tab?.id;

  // YouTubeかチェック
  if (!tab?.url?.includes('youtube.com/watch')) {
    showInfo('YouTubeの動画ページで使用してください');
    elements.toggleBtn.disabled = true;
    return;
  }

  // コンテンツスクリプトが読み込まれているか確認
  const contentScriptReady = await checkContentScript();
  if (!contentScriptReady) {
    showInfo('ページを再読み込みしてください（拡張機能の更新後に必要です）');
  }

  // 現在の状態を取得
  await refreshStatus();

  // イベントリスナーを設定
  setupEventListeners();
}

/**
 * コンテンツスクリプトが読み込まれているか確認
 */
async function checkContentScript() {
  try {
    const response = await chrome.tabs.sendMessage(currentTabId, { type: 'PING' });
    return response === 'PONG';
  } catch {
    return false;
  }
}

/**
 * イベントリスナーを設定
 */
function setupEventListeners() {
  elements.toggleBtn.addEventListener('click', toggleCapture);
  elements.copyBtn.addEventListener('click', copyTimestamps);
  elements.clearBtn.addEventListener('click', clearTimestamps);
  elements.toggleOverlayBtn.addEventListener('click', toggleOverlay);
  elements.toggleGraphBtn.addEventListener('click', toggleVolumeGraph);
  elements.scanBtn.addEventListener('click', toggleScan);

  // 設定変更
  elements.volumeThreshold.addEventListener('change', updateConfig);
  elements.quietThreshold.addEventListener('change', updateConfig);
  elements.quietDuration.addEventListener('change', updateConfig);
  elements.cooldown.addEventListener('change', updateConfig);
}

/**
 * ステータスを更新
 */
async function refreshStatus() {
  try {
    const response = await chrome.runtime.sendMessage({ type: 'GET_STATUS' });

    isCapturing = response.isCapturing;
    isScanning = response.isScanning;
    updateUI(response);

    // 設定値を反映
    if (response.config) {
      elements.volumeThreshold.value = response.config.volumeThreshold;
      elements.quietThreshold.value = response.config.quietThreshold;
      elements.quietDuration.value = response.config.quietMinDuration;
      elements.cooldown.value = response.config.cooldown;
    }
  } catch (error) {
    console.error('ステータス取得エラー:', error);
  }
}

/**
 * UIを更新
 */
function updateUI(data) {
  // ステータス表示
  if (isCapturing) {
    elements.statusDot.classList.add('active');
    elements.statusText.textContent = '検出中';
    elements.btnIcon.textContent = '■';
    elements.btnText.textContent = '検出停止';
    elements.toggleBtn.classList.remove('btn-start');
    elements.toggleBtn.classList.add('btn-stop');
  } else {
    elements.statusDot.classList.remove('active');
    elements.statusText.textContent = '停止中';
    elements.btnIcon.textContent = '▶';
    elements.btnText.textContent = '検出開始';
    elements.toggleBtn.classList.remove('btn-stop');
    elements.toggleBtn.classList.add('btn-start');
  }

  // スキャンボタンの状態
  if (isScanning) {
    elements.scanBtn.classList.add('btn-scanning');
    elements.scanBtn.textContent = 'スキャン停止';
  } else {
    elements.scanBtn.classList.remove('btn-scanning');
    elements.scanBtn.textContent = '高速スキャン';
  }

  // タイムスタンプ一覧
  renderTimestamps(data.timestamps || []);
}

/**
 * タイムスタンプ一覧を描画
 */
function renderTimestamps(timestamps) {
  elements.count.textContent = timestamps.length;

  if (timestamps.length === 0) {
    elements.timestampsList.innerHTML = '<div class="empty-state">まだ検出されていません</div>';
    return;
  }

  elements.timestampsList.innerHTML = timestamps.map((ts, index) => `
    <div class="timestamp-item" data-time="${ts.time}">
      <span class="timestamp-time">${ts.formattedTime}</span>
      <span class="timestamp-label">楽曲開始候補 #${index + 1}</span>
    </div>
  `).join('');

  // クリックイベントを設定
  elements.timestampsList.querySelectorAll('.timestamp-item').forEach(item => {
    item.addEventListener('click', () => {
      const time = parseFloat(item.dataset.time);
      seekToTime(time);
    });
  });
}

/**
 * コンテンツスクリプトにメッセージを送信（エラーを無視）
 */
async function sendToContentScript(message) {
  try {
    return await chrome.tabs.sendMessage(currentTabId, message);
  } catch (error) {
    // コンテンツスクリプトが読み込まれていない場合は無視
    console.log('Content script not ready:', error.message);
    return null;
  }
}

/**
 * キャプチャ開始/停止を切り替え
 */
async function toggleCapture() {
  try {
    if (isCapturing) {
      await chrome.runtime.sendMessage({ type: 'STOP_CAPTURE' });
      await sendToContentScript({ type: 'CAPTURE_STOPPED' });
    } else {
      const response = await chrome.runtime.sendMessage({
        type: 'START_CAPTURE',
        tabId: currentTabId
      });

      if (!response.success) {
        showError(response.error || 'キャプチャを開始できませんでした');
        return;
      }

      await sendToContentScript({ type: 'CAPTURE_STARTED' });
    }

    await refreshStatus();
  } catch (error) {
    console.error('切り替えエラー:', error);
    showError('操作に失敗しました: ' + error.message);
  }
}

/**
 * 指定時刻にシーク
 */
async function seekToTime(time) {
  await sendToContentScript({ type: 'SEEK_TO_TIME', time });
}

/**
 * タイムスタンプをコピー
 */
async function copyTimestamps() {
  try {
    const response = await chrome.runtime.sendMessage({ type: 'GET_TIMESTAMPS' });
    const timestamps = response.timestamps || [];

    if (timestamps.length === 0) {
      return;
    }

    const text = timestamps.map(ts => ts.formattedTime).join('\n');
    await navigator.clipboard.writeText(text);

    elements.copyBtn.textContent = 'コピーしました!';
    setTimeout(() => {
      elements.copyBtn.textContent = 'コピー';
    }, 1500);
  } catch (error) {
    console.error('コピーエラー:', error);
  }
}

/**
 * タイムスタンプをクリア
 */
async function clearTimestamps() {
  await chrome.runtime.sendMessage({ type: 'CLEAR_TIMESTAMPS' });
  await refreshStatus();
}

/**
 * オーバーレイ表示を切り替え
 */
async function toggleOverlay() {
  await sendToContentScript({ type: 'TOGGLE_OVERLAY' });
}

/**
 * 設定を更新
 */
async function updateConfig() {
  const config = {
    volumeThreshold: parseFloat(elements.volumeThreshold.value),
    quietThreshold: parseFloat(elements.quietThreshold.value),
    quietMinDuration: parseFloat(elements.quietDuration.value),
    cooldown: parseFloat(elements.cooldown.value)
  };

  await chrome.runtime.sendMessage({ type: 'UPDATE_CONFIG', config });
}

/**
 * エラーメッセージを表示
 */
function showError(message) {
  elements.errorContainer.innerHTML = `<div class="error-message">${message}</div>`;
  setTimeout(() => {
    elements.errorContainer.innerHTML = '';
  }, 5000);
}

/**
 * 情報メッセージを表示
 */
function showInfo(message) {
  elements.infoContainer.innerHTML = `<div class="info-message">${message}</div>`;
}

/**
 * 音量グラフ表示を切り替え
 */
async function toggleVolumeGraph() {
  try {
    await chrome.runtime.sendMessage({ type: 'TOGGLE_VOLUME_GRAPH' });
  } catch (error) {
    console.error('グラフ切り替えエラー:', error);
  }
}

/**
 * 高速スキャンを切り替え
 */
async function toggleScan() {
  try {
    await chrome.runtime.sendMessage({ type: 'START_SCAN' });
    await refreshStatus();
  } catch (error) {
    console.error('スキャン切り替えエラー:', error);
    showError('スキャンに失敗しました: ' + error.message);
  }
}

// 初期化実行
init();
