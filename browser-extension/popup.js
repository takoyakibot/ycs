/**
 * 歌枠タイムスタンプ検出 - Popup Script
 */

let isScanning = false;
let currentTabId = null;

// DOM要素
const elements = {
  toggleGraphBtn: document.getElementById('toggle-graph-btn'),
  scanBtn: document.getElementById('scan-btn'),
  errorContainer: document.getElementById('error-container'),
  infoContainer: document.getElementById('info-container'),
  volumeThreshold: document.getElementById('volume-threshold'),
  quietThreshold: document.getElementById('quiet-threshold'),
  quietDuration: document.getElementById('quiet-duration'),
  cooldown: document.getElementById('cooldown'),
  // リストスキャン用
  videoIdList: document.getElementById('video-id-list'),
  listScanProgress: document.getElementById('list-scan-progress'),
  startListScanBtn: document.getElementById('start-list-scan-btn'),
  stopListScanBtn: document.getElementById('stop-list-scan-btn')
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
  elements.toggleGraphBtn.addEventListener('click', toggleVolumeGraph);
  elements.scanBtn.addEventListener('click', toggleScan);

  // 設定変更
  elements.volumeThreshold.addEventListener('change', updateConfig);
  elements.quietThreshold.addEventListener('change', updateConfig);
  elements.quietDuration.addEventListener('change', updateConfig);
  elements.cooldown.addEventListener('change', updateConfig);

  // リストスキャン
  elements.startListScanBtn.addEventListener('click', startListScan);
  elements.stopListScanBtn.addEventListener('click', stopListScan);
}

/**
 * ステータスを更新
 */
async function refreshStatus() {
  try {
    const response = await chrome.runtime.sendMessage({ type: 'GET_STATUS' });

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
  // スキャンボタンの状態
  if (isScanning) {
    elements.scanBtn.classList.add('btn-scanning');
    elements.scanBtn.textContent = 'スキャン停止';
  } else {
    elements.scanBtn.classList.remove('btn-scanning');
    elements.scanBtn.textContent = '高速スキャン';
  }
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

/**
 * リストスキャンを開始
 */
async function startListScan() {
  const text = elements.videoIdList.value.trim();
  if (!text) {
    showError('videoIdリストを入力してください');
    return;
  }

  // videoIdリストをパース（1行1ID、空行・空白を除外）
  const videoIds = text
    .split('\n')
    .map(line => line.trim())
    .filter(id => id && /^[a-zA-Z0-9_-]{11}$/.test(id));

  if (videoIds.length === 0) {
    showError('有効なvideoIdがありません（11文字の英数字）');
    return;
  }

  try {
    // リストスキャン状態を保存
    await chrome.storage.local.set({
      listScanVideoIds: videoIds,
      listScanCurrentIndex: 0,
      listScanActive: true
    });

    // UI更新
    updateListScanUI(0, videoIds.length, true);

    // 最初の動画に移動
    const firstVideoId = videoIds[0];
    await chrome.tabs.update(currentTabId, {
      url: `https://www.youtube.com/watch?v=${firstVideoId}`
    });

    showInfo(`リストスキャン開始: ${videoIds.length}件の動画を処理します`);
  } catch (error) {
    console.error('リストスキャン開始エラー:', error);
    showError('リストスキャンの開始に失敗しました');
  }
}

/**
 * リストスキャンを停止
 */
async function stopListScan() {
  try {
    await chrome.storage.local.set({
      listScanActive: false
    });

    // スキャン中の場合は停止
    await chrome.runtime.sendMessage({ type: 'STOP_SCAN' });

    updateListScanUI(0, 0, false);
    showInfo('リストスキャンを停止しました');
  } catch (error) {
    console.error('リストスキャン停止エラー:', error);
  }
}

/**
 * リストスキャンUIを更新
 */
function updateListScanUI(current, total, isActive) {
  elements.listScanProgress.textContent = `${current} / ${total}`;

  if (isActive) {
    elements.startListScanBtn.style.display = 'none';
    elements.stopListScanBtn.style.display = 'block';
    elements.stopListScanBtn.classList.add('btn-scanning');
  } else {
    elements.startListScanBtn.style.display = 'block';
    elements.stopListScanBtn.style.display = 'none';
    elements.stopListScanBtn.classList.remove('btn-scanning');
  }
}

/**
 * リストスキャン状態を復元
 */
async function restoreListScanState() {
  try {
    const result = await chrome.storage.local.get([
      'listScanVideoIds',
      'listScanCurrentIndex',
      'listScanActive'
    ]);

    if (result.listScanVideoIds && result.listScanVideoIds.length > 0) {
      // IDリストをテキストエリアに復元
      elements.videoIdList.value = result.listScanVideoIds.join('\n');
    }

    if (result.listScanActive && result.listScanVideoIds) {
      const total = result.listScanVideoIds.length;
      const current = result.listScanCurrentIndex || 0;
      updateListScanUI(current, total, true);
    }
  } catch (error) {
    console.error('リストスキャン状態復元エラー:', error);
  }
}

// 初期化実行
init();
restoreListScanState();
