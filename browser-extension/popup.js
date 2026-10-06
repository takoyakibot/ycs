/**
 * 歌枠タイムスタンプ検出 - Popup Script
 * 埋め込みUIの表示/非表示・音量グラフの高さ設定・スキャン済み動画一覧の表示と管理
 */

const IS_GENERAL_EDITION = chrome.runtime.getManifest().x_edition === 'general';

const STORAGE_KEY_EMBEDDED_UI = 'showEmbeddedUI';
const STORAGE_KEY_GRAPH_BASE_HEIGHT = 'graphBaseHeight';
const STORAGE_KEY_GRAPH_HEIGHT_STEP = 'graphHeightStep';

// 以下「管理者版のみ」としたものについて:
// 一般版は rollup.config.mjs で IS_GENERAL_EDITION を true に固定してビルドし（popup-general.js）、
// 管理者向けの分岐・関数・定数を tree-shaking で成果物から除く。
// そのため管理者版でしか使わないものは、必ず `if (!IS_GENERAL_EDITION)` の内側か
// そこからしか参照されない関数・定数に置き、共通部分から参照しないこと。
// HTML側の管理者向け要素には data-admin-only を付ける（一般版の popup.html 生成時に除かれる）。
// 管理者版のみ: Google AI概要の非表示・チャット遅延送信・AI毒性チェック・YCS API設定
const STORAGE_KEY_HIDE_GOOGLE_AI = 'hideGoogleAI';
const STORAGE_KEY_CHAT_DELAY_ENABLED = 'chatDelayEnabled';
const STORAGE_KEY_CHAT_DELAY_SECONDS = 'chatDelaySeconds';
const STORAGE_KEY_TOXICITY_CHECK = 'toxicityCheckEnabled';
const STORAGE_KEY_CLAUDE_API_KEY = 'claudeApiKey';
const STORAGE_KEY_YCS_SERVER_URL = 'ycsServerUrl';
const STORAGE_KEY_YCS_API_TOKEN = 'ycsApiToken';

// 字幕データ等の送信先。ローカル開発時は設定で上書きする（管理者版のみ）
const DEFAULT_YCS_SERVER_URL = 'https://ycs.alpacasandbag.jp';

// 音量グラフの高さ（content.js側の既定値と揃えること）
const DEFAULT_GRAPH_BASE_HEIGHT = 60;
const DEFAULT_GRAPH_HEIGHT_STEP = 20;
const GRAPH_BASE_HEIGHT_RANGE = { min: 40, max: 400 };
const GRAPH_HEIGHT_STEP_RANGE = { min: 0, max: 100 };

// DOM要素
const elements = {
  showEmbeddedUI: document.getElementById('show-embedded-ui'),
  infoContainer: document.getElementById('info-container'),
  helpLink: document.getElementById('help-link'),
  scannedList: document.getElementById('scanned-list'),
  clearAllBtn: document.getElementById('clear-all-btn'),
  graphBaseHeight: document.getElementById('graph-base-height'),
  graphHeightStep: document.getElementById('graph-height-step'),
  graphHeightStatus: document.getElementById('graph-height-status')
};

// 管理者版のみのDOM要素（一般版の popup.html には存在しない）
const adminElements = IS_GENERAL_EDITION ? null : {
  hideGoogleAI: document.getElementById('hide-google-ai'),
  chatDelayEnabled: document.getElementById('chat-delay-enabled'),
  chatDelayOptions: document.getElementById('chat-delay-options'),
  chatDelaySeconds: document.getElementById('chat-delay-seconds'),
  chatDelayValue: document.getElementById('chat-delay-value'),
  toxicityCheckEnabled: document.getElementById('toxicity-check-enabled'),
  apiKeySection: document.getElementById('api-key-section'),
  claudeApiKey: document.getElementById('claude-api-key'),
  saveApiKey: document.getElementById('save-api-key'),
  apiKeyStatus: document.getElementById('api-key-status'),
  scanSection: document.getElementById('scan-section'),
  scanBtn: document.getElementById('scan-btn'),
  scanStatus: document.getElementById('scan-status'),
  ycsServerUrl: document.getElementById('ycs-server-url'),
  ycsApiToken: document.getElementById('ycs-api-token'),
  saveYcsSettings: document.getElementById('save-ycs-settings'),
  ycsSettingsStatus: document.getElementById('ycs-settings-status')
};

let isScanning = false;

/**
 * 初期化
 */
async function init() {
  // 現在のタブを取得
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  const isYouTube = tab?.url?.includes('youtube.com/watch');

  // YouTube埋め込みUI設定
  if (!isYouTube) {
    elements.showEmbeddedUI.disabled = true;
    elements.showEmbeddedUI.parentElement.style.opacity = '0.5';
  }

  // 保存された設定を読み込み
  const storageKeys = [
    STORAGE_KEY_EMBEDDED_UI,
    STORAGE_KEY_GRAPH_BASE_HEIGHT,
    STORAGE_KEY_GRAPH_HEIGHT_STEP
  ];
  if (!IS_GENERAL_EDITION) {
    storageKeys.push(
      STORAGE_KEY_HIDE_GOOGLE_AI,
      STORAGE_KEY_CHAT_DELAY_ENABLED,
      STORAGE_KEY_CHAT_DELAY_SECONDS,
      STORAGE_KEY_TOXICITY_CHECK,
      STORAGE_KEY_CLAUDE_API_KEY,
      STORAGE_KEY_YCS_SERVER_URL,
      STORAGE_KEY_YCS_API_TOKEN
    );
  }
  const result = await chrome.storage.local.get(storageKeys);
  const showUI = result[STORAGE_KEY_EMBEDDED_UI] !== false; // デフォルトはtrue

  elements.showEmbeddedUI.checked = showUI;

  // 音量グラフの高さ設定を読み込み
  elements.graphBaseHeight.value = clampNumber(
    result[STORAGE_KEY_GRAPH_BASE_HEIGHT], DEFAULT_GRAPH_BASE_HEIGHT, GRAPH_BASE_HEIGHT_RANGE);
  elements.graphHeightStep.value = clampNumber(
    result[STORAGE_KEY_GRAPH_HEIGHT_STEP], DEFAULT_GRAPH_HEIGHT_STEP, GRAPH_HEIGHT_STEP_RANGE);
  updateGraphHeightStatus();

  // イベントリスナーを設定
  elements.showEmbeddedUI.addEventListener('change', toggleEmbeddedUI);
  elements.graphBaseHeight.addEventListener('change', saveGraphHeightSettings);
  elements.graphHeightStep.addEventListener('change', saveGraphHeightSettings);
  elements.helpLink.addEventListener('click', showHelp);
  elements.clearAllBtn.addEventListener('click', clearAllScannedData);

  // YouTube埋め込みUIの初期状態をコンテンツスクリプトに通知
  if (isYouTube) {
    notifyYouTubeContentScript(showUI);
  }

  if (!IS_GENERAL_EDITION) {
    await initAdminSettings(tab, isYouTube, result);
  }

  // スキャン済み一覧を読み込み
  await loadScannedList();
}

/**
 * 管理者版のみの設定欄・スキャンボタンを初期化
 */
async function initAdminSettings(tab, isYouTube, result) {
  const isGoogle = tab?.url?.includes('google.com/search') || tab?.url?.includes('google.co.jp/search');
  const hideGoogleAI = result[STORAGE_KEY_HIDE_GOOGLE_AI] !== false; // デフォルトはtrue
  const chatDelayEnabled = result[STORAGE_KEY_CHAT_DELAY_ENABLED] === true; // デフォルトはfalse
  const chatDelaySeconds = result[STORAGE_KEY_CHAT_DELAY_SECONDS] || 10;
  const toxicityCheckEnabled = result[STORAGE_KEY_TOXICITY_CHECK] === true;
  const hasApiKey = !!result[STORAGE_KEY_CLAUDE_API_KEY];

  adminElements.hideGoogleAI.checked = hideGoogleAI;
  adminElements.chatDelayEnabled.checked = chatDelayEnabled;
  adminElements.chatDelaySeconds.value = chatDelaySeconds;
  adminElements.chatDelayValue.textContent = chatDelaySeconds + '秒';
  adminElements.chatDelayOptions.style.display = chatDelayEnabled ? 'block' : 'none';
  adminElements.toxicityCheckEnabled.checked = toxicityCheckEnabled;
  adminElements.apiKeySection.style.display = toxicityCheckEnabled ? 'block' : 'none';
  if (hasApiKey) {
    adminElements.claudeApiKey.placeholder = '設定済み';
    adminElements.apiKeyStatus.textContent = 'APIキー設定済み';
    adminElements.apiKeyStatus.style.color = '#2e7d32';
  }

  // YCS API設定を読み込み
  adminElements.ycsServerUrl.value = (result[STORAGE_KEY_YCS_SERVER_URL] || DEFAULT_YCS_SERVER_URL).replace(/\/+$/, '');
  if (result[STORAGE_KEY_YCS_API_TOKEN]) {
    adminElements.ycsApiToken.placeholder = '設定済み';
    adminElements.ycsSettingsStatus.textContent = 'APIトークン設定済み';
    adminElements.ycsSettingsStatus.style.color = '#2e7d32';
  }

  adminElements.hideGoogleAI.addEventListener('change', toggleHideGoogleAI);
  adminElements.chatDelayEnabled.addEventListener('change', toggleChatDelay);
  adminElements.chatDelaySeconds.addEventListener('input', changeChatDelaySeconds);
  adminElements.toxicityCheckEnabled.addEventListener('change', toggleToxicityCheck);
  adminElements.saveApiKey.addEventListener('click', saveClaudeApiKey);
  adminElements.saveYcsSettings.addEventListener('click', saveYcsSettings);

  if (isYouTube) {
    // tabCaptureスキャンセクションを表示
    adminElements.scanSection.style.display = 'block';
    adminElements.scanBtn.addEventListener('click', toggleScan);
    await updateScanButtonState(tab.id);
  }

  // Googleの場合は設定変更を通知
  if (isGoogle) {
    notifyGoogleContentScript(hideGoogleAI);
  }
}

/**
 * 埋め込みUI表示を切り替え
 */
async function toggleEmbeddedUI() {
  const show = elements.showEmbeddedUI.checked;

  // 設定を保存
  await chrome.storage.local.set({ [STORAGE_KEY_EMBEDDED_UI]: show });

  // コンテンツスクリプトに通知
  notifyYouTubeContentScript(show);
}

/**
 * Google AI概要の非表示を切り替え
 */
async function toggleHideGoogleAI() {
  const hide = adminElements.hideGoogleAI.checked;

  // 設定を保存
  await chrome.storage.local.set({ [STORAGE_KEY_HIDE_GOOGLE_AI]: hide });

  // Google検索ページに通知
  notifyGoogleContentScript(hide);
}

/**
 * チャット遅延送信を切り替え
 */
async function toggleChatDelay() {
  const enabled = adminElements.chatDelayEnabled.checked;
  await chrome.storage.local.set({ [STORAGE_KEY_CHAT_DELAY_ENABLED]: enabled });
  adminElements.chatDelayOptions.style.display = enabled ? 'block' : 'none';
}

/**
 * チャット遅延秒数を変更
 */
async function changeChatDelaySeconds() {
  const seconds = parseInt(adminElements.chatDelaySeconds.value, 10);
  adminElements.chatDelayValue.textContent = seconds + '秒';
  await chrome.storage.local.set({ [STORAGE_KEY_CHAT_DELAY_SECONDS]: seconds });
}

/**
 * AI毒性チェックを切り替え
 */
async function toggleToxicityCheck() {
  const enabled = adminElements.toxicityCheckEnabled.checked;
  await chrome.storage.local.set({ [STORAGE_KEY_TOXICITY_CHECK]: enabled });
  adminElements.apiKeySection.style.display = enabled ? 'block' : 'none';

  // APIキーが未設定の場合に警告
  if (enabled) {
    const result = await chrome.storage.local.get(STORAGE_KEY_CLAUDE_API_KEY);
    if (!result[STORAGE_KEY_CLAUDE_API_KEY]) {
      adminElements.apiKeyStatus.textContent = 'APIキーを入力してください';
      adminElements.apiKeyStatus.style.color = '#f57c00';
    }
  }
}

/**
 * Claude APIキーを保存
 */
async function saveClaudeApiKey() {
  const apiKey = adminElements.claudeApiKey.value.trim();
  if (!apiKey) {
    adminElements.apiKeyStatus.textContent = 'APIキーを入力してください';
    adminElements.apiKeyStatus.style.color = '#c62828';
    return;
  }

  if (!apiKey.startsWith('sk-ant-')) {
    adminElements.apiKeyStatus.textContent = 'APIキーはsk-ant-で始まる必要があります';
    adminElements.apiKeyStatus.style.color = '#c62828';
    return;
  }

  await chrome.storage.local.set({ [STORAGE_KEY_CLAUDE_API_KEY]: apiKey });
  adminElements.claudeApiKey.value = '';
  adminElements.claudeApiKey.placeholder = '設定済み';
  adminElements.apiKeyStatus.textContent = 'APIキーを保存しました';
  adminElements.apiKeyStatus.style.color = '#2e7d32';
}

/**
 * 数値を範囲内に丸める（不正値は既定値を返す）
 * @param {*} value - 対象の値
 * @param {number} defaultValue - 既定値
 * @param {{min: number, max: number}} range - 許容範囲
 * @returns {number}
 */
function clampNumber(value, defaultValue, range) {
  // 空欄・未設定は「既定値を使う」として扱う（Number('')が0になり最小値へ丸められるのを防ぐ）
  if (value === null || value === undefined || value === '') return defaultValue;
  const num = Number(value);
  if (!Number.isFinite(num)) return defaultValue;
  return Math.max(range.min, Math.min(range.max, Math.round(num)));
}

/**
 * 高さ設定の説明文を更新
 */
function updateGraphHeightStatus() {
  const base = Number(elements.graphBaseHeight.value);
  const step = Number(elements.graphHeightStep.value);
  // ズームは9段階（1x〜8x）
  const maxHeight = base + step * 8;
  elements.graphHeightStatus.textContent = `等倍 ${base}px 〜 最大ズーム ${maxHeight}px`;
}

/**
 * 音量グラフの高さ設定を保存（開いているYouTubeタブに即時反映される）
 */
async function saveGraphHeightSettings() {
  const base = clampNumber(elements.graphBaseHeight.value, DEFAULT_GRAPH_BASE_HEIGHT, GRAPH_BASE_HEIGHT_RANGE);
  const step = clampNumber(elements.graphHeightStep.value, DEFAULT_GRAPH_HEIGHT_STEP, GRAPH_HEIGHT_STEP_RANGE);

  // 丸めた結果を入力欄に反映
  elements.graphBaseHeight.value = base;
  elements.graphHeightStep.value = step;
  updateGraphHeightStatus();

  await chrome.storage.local.set({
    [STORAGE_KEY_GRAPH_BASE_HEIGHT]: base,
    [STORAGE_KEY_GRAPH_HEIGHT_STEP]: step
  });
}

/**
 * YCS API設定を保存
 */
async function saveYcsSettings() {
  // 末尾のスラッシュは除去する（APIパス連結時に「//」になるのを防ぐ）
  const serverUrl = (adminElements.ycsServerUrl.value.trim() || DEFAULT_YCS_SERVER_URL).replace(/\/+$/, '');
  const apiToken = adminElements.ycsApiToken.value.trim();

  const settings = { [STORAGE_KEY_YCS_SERVER_URL]: serverUrl };
  if (apiToken) {
    settings[STORAGE_KEY_YCS_API_TOKEN] = apiToken;
  }

  await chrome.storage.local.set(settings);
  adminElements.ycsServerUrl.value = serverUrl;

  if (apiToken) {
    adminElements.ycsApiToken.value = '';
    adminElements.ycsApiToken.placeholder = '設定済み';
  }
  adminElements.ycsSettingsStatus.textContent = '設定を保存しました';
  adminElements.ycsSettingsStatus.style.color = '#2e7d32';
}

/**
 * YouTubeコンテンツスクリプトに通知
 */
async function notifyYouTubeContentScript(show) {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id && tab.url?.includes('youtube.com/watch')) {
      await chrome.tabs.sendMessage(tab.id, {
        type: show ? 'SHOW_EMBEDDED_UI' : 'HIDE_EMBEDDED_UI'
      });
    }
  } catch (error) {
    console.log('Content script not ready:', error.message);
  }
}

/**
 * Googleコンテンツスクリプトに通知
 */
async function notifyGoogleContentScript(hide) {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id && (tab.url?.includes('google.com/search') || tab.url?.includes('google.co.jp/search'))) {
      await chrome.tabs.sendMessage(tab.id, {
        type: hide ? 'HIDE_GOOGLE_AI' : 'SHOW_GOOGLE_AI'
      });
    }
  } catch (error) {
    console.log('Content script not ready:', error.message);
  }
}

/**
 * スキャン済み動画一覧を取得
 */
async function getScannedVideosList() {
  const allData = await chrome.storage.local.get(null);
  const videos = [];

  for (const key in allData) {
    if (key.startsWith('volumeData_')) {
      const videoId = key.replace('volumeData_', '');
      const data = allData[key];
      videos.push({
        videoId,
        savedAt: data.savedAt,
        duration: data.duration
      });
    }
  }

  return videos.sort((a, b) => new Date(b.savedAt) - new Date(a.savedAt));
}

/**
 * スキャン済み一覧を読み込み・表示
 */
async function loadScannedList() {
  const videos = await getScannedVideosList();

  // ストレージ使用量を表示
  chrome.storage.local.getBytesInUse(null, (bytes) => {
    const usageEl = document.getElementById('storage-usage');
    if (usageEl) {
      const mb = (bytes / 1024 / 1024).toFixed(1);
      usageEl.textContent = `(${mb} MB / 10 MB)`;
    }
  });

  if (videos.length === 0) {
    elements.scannedList.innerHTML = '<div class="empty-message">スキャン済みの動画はありません</div>';
    elements.clearAllBtn.style.display = 'none';
    return;
  }

  elements.clearAllBtn.style.display = 'block';

  const html = videos.map(video => {
    const date = video.savedAt ? formatDate(video.savedAt) : '不明';
    return `
      <div class="scanned-item" data-video-id="${video.videoId}">
        <div class="scanned-info">
          <a href="#" class="scanned-video-id" data-video-id="${video.videoId}">${video.videoId}</a>
          <span class="scanned-date">${date}</span>
        </div>
        <div class="scanned-actions">
          <button class="btn-small btn-open" data-video-id="${video.videoId}">開く</button>
          <button class="btn-small btn-delete" data-video-id="${video.videoId}">削除</button>
        </div>
      </div>
    `;
  }).join('');

  elements.scannedList.innerHTML = html;

  // イベントリスナーを設定
  elements.scannedList.querySelectorAll('.scanned-video-id, .btn-open').forEach(el => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      openVideo(el.dataset.videoId);
    });
  });

  elements.scannedList.querySelectorAll('.btn-delete').forEach(el => {
    el.addEventListener('click', () => deleteScannedData(el.dataset.videoId));
  });
}

/**
 * 日付をフォーマット
 */
function formatDate(dateString) {
  try {
    const date = new Date(dateString);
    const month = (date.getMonth() + 1).toString().padStart(2, '0');
    const day = date.getDate().toString().padStart(2, '0');
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    return `${month}/${day} ${hours}:${minutes}`;
  } catch {
    return '不明';
  }
}

/**
 * 動画を開く
 */
function openVideo(videoId) {
  chrome.tabs.create({
    url: `https://www.youtube.com/watch?v=${videoId}`
  });
}

/**
 * スキャンデータを削除
 */
async function deleteScannedData(videoId) {
  await chrome.storage.local.remove(`volumeData_${videoId}`);
  await loadScannedList();
}

/**
 * 全てのスキャンデータをクリア
 */
async function clearAllScannedData() {
  if (!confirm('全てのスキャンデータを削除しますか？')) {
    return;
  }

  const allData = await chrome.storage.local.get(null);
  const keysToRemove = Object.keys(allData).filter(key => key.startsWith('volumeData_'));

  if (keysToRemove.length > 0) {
    await chrome.storage.local.remove(keysToRemove);
  }

  await loadScannedList();
}

/**
 * ヘルプを表示
 */
function showHelp(e) {
  e.preventDefault();
  if (IS_GENERAL_EDITION) {
    showInfo(`
      <strong>使い方:</strong><br>
      ・YouTube画面にUI: チェックでYouTube動画画面に音量検出UIを表示<br>
      ・スキャン: YouTube動画画面のYCSボタンからダイレクトスキャンを開始<br>
      ・楽曲推測: タイムスタンプマーカーをクリックして楽曲候補を表示<br>
      ・スキャン済み一覧: スキャン済みの動画を確認・開く・削除
    `);
  } else {
    showInfo(`
      <strong>使い方:</strong><br>
      ・スキャン開始: YouTube動画ページでこのボタンをクリック<br>
      ・YouTube画面にUI: チェックでYouTube動画画面に音量検出UIを表示<br>
      ・AI概要を非表示: チェックでGoogle検索のAI概要を非表示<br>
      ・チャット遅延送信: ライブチャットの送信を一定秒数保留し、その間にキャンセル可能<br>
      ・スキャン済み一覧: スキャン済みの動画を確認・開く・削除
    `);
  }
}

/**
 * 情報メッセージを表示
 */
function showInfo(message) {
  elements.infoContainer.innerHTML = `<div class="info-message">${message}</div>`;
}

/**
 * スキャンボタンの状態を更新
 */
async function updateScanButtonState(tabId) {
  try {
    const status = await chrome.tabs.sendMessage(tabId, { type: 'GET_SCAN_STATUS' });
    if (status.isComplete) {
      adminElements.scanBtn.textContent = '完了';
      adminElements.scanBtn.style.background = '#2e7d32';
      adminElements.scanStatus.textContent = 'スキャン完了済み';
    } else if (status.hasData && status.progress > 0) {
      adminElements.scanBtn.textContent = `再開 (${status.progress.toFixed(0)}%)`;
      adminElements.scanBtn.style.background = '#f57c00';
      adminElements.scanStatus.textContent = `${status.progress.toFixed(1)}%完了`;
    } else {
      adminElements.scanBtn.textContent = 'スキャン開始';
      adminElements.scanStatus.textContent = '';
    }
  } catch (error) {
    console.log('スキャン状態取得エラー:', error.message);
    adminElements.scanBtn.textContent = 'スキャン開始';
  }
}

/**
 * スキャンを開始/停止
 */
async function toggleScan() {
  try {
    const response = await chrome.runtime.sendMessage({ type: 'START_SCAN' });
    console.log('スキャン応答:', response);

    if (response.isScanning) {
      adminElements.scanBtn.textContent = '停止';
      adminElements.scanBtn.classList.add('scanning');
      adminElements.scanStatus.textContent = 'スキャン中...';
      isScanning = true;
    } else {
      adminElements.scanBtn.textContent = 'スキャン開始';
      adminElements.scanBtn.classList.remove('scanning');
      adminElements.scanStatus.textContent = '';
      isScanning = false;
      // 状態を再確認
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (tab?.id) {
        await updateScanButtonState(tab.id);
      }
    }
  } catch (error) {
    console.error('スキャンエラー:', error);
    adminElements.scanStatus.textContent = 'エラー: ' + error.message;
  }
}

// 初期化実行
init();
