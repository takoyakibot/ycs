/**
 * 歌枠タイムスタンプ検出 - Content Script
 *
 * YouTubeページに注入され、動画の再生時刻を取得してbackground.jsに送信する
 * 音量ダイナミクスグラフを表示してシークバーとして機能させる
 */

let videoElement = null;
let timeUpdateInterval = null;
let volumeGraphContainer = null;
let volumeCanvas = null;
let volumeCtx = null;
let volumeData = []; // 音量データを蓄積
let videoDuration = 0;
let isGraphVisible = false;

// 再生リスト自動スキャン用
let isAutoScanMode = false;
let autoScanStopRequested = false;

// リストスキャン用（videoIdリストからの連続スキャン）
let isListScanMode = false;
let listScanButtonContainer = null;
let listScanAutoClickTimer = null;
let listScanCountdownInterval = null;
const LIST_SCAN_AUTO_CLICK_DELAY = 3000; // 3秒後に自動クリック

// 検出されたタイムスタンプ候補
let detectedTimestamps = [];


// 直接音声解析用（tabCaptureを使わない方式）
let audioContext = null;
let analyserNode = null;
let gainNode = null; // 音量制御用
let mediaElementSource = null;
let isScanning = false;
let scanInterval = null;
const SAMPLING_INTERVAL_SEC = 2; // サンプリング間隔（秒）
const LEGACY_GRAPH_RESOLUTION = 500; // 旧形式との互換用

// タイムスタンプエディタ: マーカー管理
let tsMarkers = []; // { id, time, text }
let selectedMarkerId = null;
let nextMarkerId = 1;
const MARKER_SNAP_THRESHOLD_SEC = 3; // マーカー選択の判定距離（秒）
const MARKER_SNAP_THRESHOLD_PX = 8; // マーカー選択の判定距離（ピクセル）。長時間動画では秒基準が1px未満になるため併用
let videoListenerTarget = null; // イベント登録済みのvideo要素（SPA遷移での重複登録防止）

// タイムスタンプエディタ: Undo/Redo履歴（変更前スナップショットのスタック）
let tsHistoryUndo = [];
let tsHistoryRedo = [];
const TS_HISTORY_LIMIT = 50; // 履歴の最大保持数
const TS_HISTORY_COALESCE_MS = 1500; // 同種の連続操作を1履歴にまとめる時間窓
let lastHistoryTag = null;
let lastHistoryTime = 0;
let tsZeroPad = false; // タイムスタンプのゼロ埋め設定
let tsEditorMode = 'marker'; // 'marker': マーカー追加, 'seek': シーク

/**
 * 動画の長さに応じたグラフ解像度を計算
 * @param {number} duration - 動画の長さ（秒）
 * @returns {number} データポイント数
 */
function calcGraphResolution(duration) {
  if (!duration || duration <= 0) return LEGACY_GRAPH_RESOLUTION;
  return Math.ceil(duration / SAMPLING_INTERVAL_SEC);
}
let originalPlaybackRate = 1;
let audioInitialized = false; // 音声解析が初期化済みか

// ズーム関連
const ZOOM_LEVELS = [1, 1.5, 2, 3, 4, 5, 6, 7, 8];
// グラフ高さの既定値（ポップアップの設定で上書きされる。popup.js側の既定値と揃えること）
const DEFAULT_GRAPH_BASE_HEIGHT_PX = 60; // ズーム1xでのグラフ高さ
const DEFAULT_GRAPH_HEIGHT_STEP_PX = 20; // ズーム1段階ごとの高さ増分
let graphBaseHeightPx = DEFAULT_GRAPH_BASE_HEIGHT_PX;
let graphHeightStepPx = DEFAULT_GRAPH_HEIGHT_STEP_PX;
// 許容範囲（popup.js側と揃えること）。canvasに直接適用する値のため、
// ストレージの内容を信頼せずここでも必ずクランプする
const GRAPH_BASE_HEIGHT_RANGE = { min: 40, max: 400 };
const GRAPH_HEIGHT_STEP_RANGE = { min: 0, max: 100 };
let zoomIndex = 0; // ZOOM_LEVELSのインデックス
let lastSaveTime = 0; // 最後に音量データを保存した時刻
const SAVE_INTERVAL = 3000; // 保存間隔（ミリ秒）

// 音量表示モード（false: 絶対値, true: 相対値）
let isRelativeVolumeMode = false;

// 埋め込みUI設定
let embeddedUIVisible = true; // デフォルトは表示
let embeddedTriggerButton = null;
let listScanPanel = null;
let listScanPanelVisible = false;
let currentListScanVideoIds = [];

// チャット検索用
let chatSearchPanel = null;
let chatSearchPanelVisible = false;
let chatSearchDB = null;
const CHAT_DB_NAME = 'YCSChatDB';
const CHAT_DB_VERSION = 1;
const CHAT_STORE_NAME = 'chats';
const CHAT_MAX_AGE_DAYS = 30; // 30日以上前のデータは削除

// ハイライト検出結果保存用
let highlightDB = null;
const HIGHLIGHT_DB_NAME = 'YCSHighlightDB';
const HIGHLIGHT_DB_VERSION = 1;
const HIGHLIGHT_STORE_NAME = 'highlights';
const HIGHLIGHT_MAX_AGE_DAYS = 90; // 90日以上前のデータは削除

// 字幕取得用
let subtitlePanel = null;
let subtitlePanelVisible = false;

// ハイライト検出用
let highlightPanel = null;
let highlightPanelVisible = false;

// 字幕サーバー送信用（重複送信防止キャッシュ）
const subtitleSentCache = new Set();

// YCS APIトークン（chrome.storageから読み込み）
let ycsApiToken = null;
let ycsServerUrl = null;
// 字幕データ等の送信先。ローカル開発時はポップアップの設定で上書きする
const DEFAULT_YCS_SERVER_URL = 'https://ycs.alpacasandbag.jp';

/**
 * グラフ高さ設定の値を許容範囲に丸める
 * @param {*} value - ストレージから読んだ値
 * @param {number} defaultValue - 既定値
 * @param {{min: number, max: number}} range - 許容範囲
 * @returns {number}
 */
function clampGraphHeightValue(value, defaultValue, range) {
  if (value === null || value === undefined || value === '') return defaultValue;
  const num = Number(value);
  if (!Number.isFinite(num)) return defaultValue;
  return Math.max(range.min, Math.min(range.max, Math.round(num)));
}

/**
 * グラフ高さ設定を読み込む（ポップアップで変更可能）
 */
async function loadGraphHeightSettings() {
  try {
    const result = await chrome.storage.local.get(['graphBaseHeight', 'graphHeightStep']);
    graphBaseHeightPx = clampGraphHeightValue(
      result.graphBaseHeight, DEFAULT_GRAPH_BASE_HEIGHT_PX, GRAPH_BASE_HEIGHT_RANGE);
    graphHeightStepPx = clampGraphHeightValue(
      result.graphHeightStep, DEFAULT_GRAPH_HEIGHT_STEP_PX, GRAPH_HEIGHT_STEP_RANGE);
  } catch (error) {
    console.warn('[YCS] グラフ高さ設定の読み込みエラー:', error);
  }
}

/**
 * 埋め込みUI設定を読み込む
 */
async function loadEmbeddedUISettings() {
  try {
    const result = await chrome.storage.local.get('showEmbeddedUI');
    embeddedUIVisible = result.showEmbeddedUI !== false; // デフォルトはtrue
    return embeddedUIVisible;
  } catch (error) {
    console.error('埋め込みUI設定読み込みエラー:', error);
    return true;
  }
}

/**
 * 埋め込みトリガーボタンを作成
 */
function createEmbeddedTriggerButton() {
  if (embeddedTriggerButton) return;

  // ボタンコンテナを作成
  const buttonContainer = document.createElement('div');
  buttonContainer.id = 'ycs-button-container';
  buttonContainer.innerHTML = `
    <style>
      #ycs-button-container {
        position: fixed;
        bottom: 80px;
        right: 16px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 8px;
      }
      #ycs-button-container.hidden {
        display: none !important;
      }
      .ycs-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: 12px;
        font-weight: bold;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .ycs-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 16px rgba(0,0,0,0.4);
      }
      .ycs-btn.active {
        background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
      }
      #ycs-list-btn {
        font-size: 16px;
      }
      #ycs-chat-btn {
        font-size: 14px;
      }
      #ycs-subtitle-btn {
        font-size: 14px;
      }
      #ycs-highlight-btn {
        font-size: 14px;
      }
    </style>
    <button class="ycs-btn" id="ycs-trigger-btn" title="タイムスタンプ検出グラフを表示/非表示">YCS</button>
    <button class="ycs-btn" id="ycs-list-btn" title="リストスキャンパネルを開く">☰</button>
    <button class="ycs-btn" id="ycs-chat-btn" title="チャット検索パネルを開く">💬</button>
    <button class="ycs-btn" id="ycs-subtitle-btn" title="字幕取得パネルを開く">📝</button>
    <button class="ycs-btn" id="ycs-highlight-btn" title="ハイライト検出パネルを開く">✨</button>
  `;

  document.body.appendChild(buttonContainer);
  embeddedTriggerButton = buttonContainer;

  // YCSボタンのイベント
  buttonContainer.querySelector('#ycs-trigger-btn').addEventListener('click', () => {
    toggleEmbeddedUI();
  });

  // リストボタンのイベント
  buttonContainer.querySelector('#ycs-list-btn').addEventListener('click', () => {
    toggleListScanPanel();
  });

  // チャット検索ボタンのイベント
  buttonContainer.querySelector('#ycs-chat-btn').addEventListener('click', () => {
    toggleChatSearchPanel();
  });

  // 字幕取得ボタンのイベント
  buttonContainer.querySelector('#ycs-subtitle-btn').addEventListener('click', () => {
    toggleSubtitlePanel();
  });

  // ハイライト検出ボタンのイベント
  buttonContainer.querySelector('#ycs-highlight-btn').addEventListener('click', () => {
    toggleHighlightPanel();
  });

  updateTriggerButtonState();
}

/**
 * 埋め込みUIの表示/非表示をトグル
 */
function toggleEmbeddedUI() {
  if (volumeGraphContainer) {
    const isVisible = volumeGraphContainer.classList.contains('visible');
    if (isVisible) {
      hideVolumeGraphPanel();
    } else {
      volumeGraphContainer.classList.add('visible');
      isGraphVisible = true;
      updateVideoDuration();
      resizeCanvas();
    }
    updateTriggerButtonState();
  }
}

/**
 * トリガーボタンの状態を更新
 */
function updateTriggerButtonState() {
  if (!embeddedTriggerButton) return;

  const ycsBtn = embeddedTriggerButton.querySelector('#ycs-trigger-btn');
  if (ycsBtn) {
    if (isGraphVisible) {
      ycsBtn.classList.add('active');
    } else {
      ycsBtn.classList.remove('active');
    }
  }

  const listBtn = embeddedTriggerButton.querySelector('#ycs-list-btn');
  if (listBtn) {
    if (listScanPanelVisible) {
      listBtn.classList.add('active');
    } else {
      listBtn.classList.remove('active');
    }
  }

  const chatBtn = embeddedTriggerButton.querySelector('#ycs-chat-btn');
  if (chatBtn) {
    chatBtn.classList.toggle('active', chatSearchPanelVisible);
  }

  const subtitleBtn = embeddedTriggerButton.querySelector('#ycs-subtitle-btn');
  if (subtitleBtn) {
    subtitleBtn.classList.toggle('active', subtitlePanelVisible);
  }

  const highlightBtn = embeddedTriggerButton.querySelector('#ycs-highlight-btn');
  if (highlightBtn) {
    highlightBtn.classList.toggle('active', highlightPanelVisible);
  }
}

/**
 * 埋め込みUIを表示
 */
function showEmbeddedUI() {
  embeddedUIVisible = true;
  if (embeddedTriggerButton) {
    embeddedTriggerButton.classList.remove('hidden');
  }
}

/**
 * 音量グラフパネルを非表示にする共通処理
 * ペースト変換ポップアップのリスナー残留を防ぐため、非表示化は必ずここを経由すること
 */
function hideVolumeGraphPanel() {
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  if (volumeGraphContainer) {
    volumeGraphContainer.classList.remove('visible');
  }
  isGraphVisible = false;
}

/**
 * 埋め込みUIを非表示
 */
function hideEmbeddedUI() {
  embeddedUIVisible = false;
  if (embeddedTriggerButton) {
    embeddedTriggerButton.classList.add('hidden');
  }
  // グラフも非表示
  hideVolumeGraphPanel();
}

/**
 * リストスキャンパネルを作成
 */
function createListScanPanel() {
  if (listScanPanel) return;

  listScanPanel = document.createElement('div');
  listScanPanel.id = 'ycs-list-scan-panel';
  listScanPanel.innerHTML = `
    <style>
      #ycs-list-scan-panel {
        position: fixed;
        bottom: 140px;
        right: 16px;
        z-index: 9998;
        width: 320px;
        max-height: 450px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-list-scan-panel.visible {
        display: flex !important;
      }
      .lsp-header {
        padding: 8px 16px 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        flex-direction: column;
      }
      .lsp-header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
      }
      .lsp-header-title {
        font-weight: 600;
        font-size: 14px;
      }
      .lsp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .lsp-close-btn:hover {
        background: rgba(255,255,255,0.3);
      }
      .lsp-tabs {
        display: flex;
        gap: 0;
      }
      .lsp-tab {
        flex: 1;
        padding: 8px 12px;
        border: none;
        background: rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.7);
        font-size: 12px;
        cursor: pointer;
        border-radius: 8px 8px 0 0;
        transition: all 0.2s;
      }
      .lsp-tab:hover {
        background: rgba(255,255,255,0.15);
      }
      .lsp-tab.active {
        background: rgba(20, 20, 20, 0.95);
        color: #fff;
        font-weight: 500;
      }
      .lsp-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow-y: auto;
        max-height: 350px;
      }
      .lsp-tab-content {
        display: none;
      }
      .lsp-tab-content.active {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }
      .lsp-input-section {
        display: flex;
        flex-direction: column;
        gap: 8px;
      }
      .lsp-textarea {
        width: 100%;
        height: 60px;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 8px;
        font-size: 11px;
        font-family: monospace;
        resize: vertical;
        box-sizing: border-box;
      }
      .lsp-textarea::placeholder {
        color: #888;
      }
      .lsp-btn-row {
        display: flex;
        gap: 8px;
      }
      .lsp-btn {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }
      .lsp-btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
      }
      .lsp-btn-primary:hover {
        filter: brightness(1.1);
      }
      .lsp-btn-secondary {
        background: #444;
        color: white;
      }
      .lsp-btn-secondary:hover {
        background: #555;
      }
      .lsp-btn-danger {
        background: #c62828;
        color: white;
      }
      .lsp-btn-danger:hover {
        background: #d32f2f;
      }
      .lsp-progress {
        font-size: 12px;
        color: #aaa;
        text-align: center;
        padding: 4px;
      }
      .lsp-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
      }
      .lsp-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        background: #2a2a2a;
        border-radius: 6px;
        font-size: 11px;
      }
      .lsp-item.current {
        background: #3a3a6a;
        border: 1px solid #667eea;
      }
      .lsp-item-index {
        color: #888;
        width: 20px;
      }
      .lsp-item-id {
        font-family: monospace;
        flex: 1;
        color: #ddd;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .lsp-item-status {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
      }
      .lsp-status-icon {
        width: 16px;
        text-align: center;
      }
      .lsp-status-icon.pending { color: #888; }
      .lsp-status-icon.scanning { color: #ff9800; animation: pulse 1s infinite; }
      .lsp-status-icon.completed { color: #4caf50; }
      .lsp-status-icon.partial { color: #ffeb3b; }
      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
      }
      .lsp-progress-bar {
        width: 40px;
        height: 4px;
        background: #444;
        border-radius: 2px;
        overflow: hidden;
      }
      .lsp-progress-fill {
        height: 100%;
        background: #4caf50;
        transition: width 0.3s;
      }
      .lsp-open-btn {
        background: #333;
        border: none;
        color: #aaa;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 10px;
        cursor: pointer;
      }
      .lsp-open-btn:hover {
        background: #444;
        color: #fff;
      }
      .lsp-delete-btn {
        background: transparent;
        border: none;
        color: #888;
        padding: 4px 6px;
        border-radius: 4px;
        font-size: 10px;
        cursor: pointer;
      }
      .lsp-delete-btn:hover {
        background: #c62828;
        color: #fff;
      }
      .lsp-empty {
        text-align: center;
        color: #888;
        padding: 20px;
        font-size: 12px;
      }
      .lsp-scanned-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        background: #2a2a2a;
        border-radius: 6px;
        font-size: 11px;
      }
      .lsp-scanned-item .lsp-item-id {
        font-family: monospace;
        flex: 1;
        color: #ddd;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .lsp-scanned-item .lsp-item-date {
        color: #888;
        font-size: 10px;
      }
      .lsp-scanned-item .lsp-item-actions {
        display: flex;
        gap: 4px;
      }
      .lsp-scanned-count {
        font-size: 12px;
        color: #aaa;
        text-align: center;
        padding: 4px;
      }
    </style>
    <div class="lsp-header">
      <div class="lsp-header-top">
        <span class="lsp-header-title">スキャン管理</span>
        <button class="lsp-close-btn" id="lsp-close-btn">×</button>
      </div>
      <div class="lsp-tabs">
        <button class="lsp-tab active" data-tab="list-scan">リストスキャン</button>
        <button class="lsp-tab" data-tab="scanned-list">スキャン済み一覧</button>
        <button class="lsp-tab" data-tab="subtitle-scan">字幕取得</button>
      </div>
    </div>
    <div class="lsp-content">
      <!-- リストスキャン タブ -->
      <div class="lsp-tab-content active" id="tab-list-scan">
        <div class="lsp-input-section">
          <textarea class="lsp-textarea" id="lsp-video-ids" placeholder="videoIdを1行に1つずつ入力（11文字の英数字）"></textarea>
          <div class="lsp-btn-row">
            <button class="lsp-btn lsp-btn-primary" id="lsp-load-btn">読み込み</button>
            <button class="lsp-btn lsp-btn-secondary" id="lsp-clear-btn">クリア</button>
          </div>
        </div>
        <div class="lsp-progress" id="lsp-progress-info">0 / 0</div>
        <div class="lsp-list" id="lsp-video-list">
          <div class="lsp-empty">VideoIDを入力して読み込みボタンをクリック</div>
        </div>
        <div class="lsp-btn-row">
          <button class="lsp-btn lsp-btn-primary" id="lsp-start-btn" disabled>▶ スキャン開始</button>
          <button class="lsp-btn lsp-btn-danger" id="lsp-stop-btn" style="display:none;">■ 停止</button>
        </div>
      </div>
      <!-- スキャン済み一覧 タブ -->
      <div class="lsp-tab-content" id="tab-scanned-list">
        <div class="lsp-scanned-count" id="lsp-scanned-count">0 件のスキャン済み動画</div>
        <div class="lsp-list" id="lsp-scanned-video-list">
          <div class="lsp-empty">スキャン済みの動画がありません</div>
        </div>
        <div class="lsp-btn-row">
          <button class="lsp-btn lsp-btn-danger" id="lsp-clear-all-btn">全てクリア</button>
        </div>
      </div>
      <!-- 字幕一括取得 タブ -->
      <div class="lsp-tab-content" id="tab-subtitle-scan">
        <div class="lsp-progress" id="ssp-status">タイムスタンプあり＆字幕未取得のアーカイブを順次取得します</div>
        <div class="lsp-list" id="ssp-video-list">
          <div class="lsp-empty">「対象を読み込み」をクリック</div>
        </div>
        <div class="lsp-btn-row">
          <button class="lsp-btn lsp-btn-secondary" id="ssp-load-btn">対象を読み込み</button>
          <button class="lsp-btn lsp-btn-primary" id="ssp-start-btn" disabled>▶ 開始</button>
          <button class="lsp-btn lsp-btn-danger" id="ssp-stop-btn" style="display:none;">■ 停止</button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(listScanPanel);

  // イベントリスナー設定
  listScanPanel.querySelector('#lsp-close-btn').addEventListener('click', hideListScanPanel);
  listScanPanel.querySelector('#lsp-load-btn').addEventListener('click', loadVideoIdList);
  listScanPanel.querySelector('#lsp-clear-btn').addEventListener('click', clearVideoIdList);
  listScanPanel.querySelector('#lsp-start-btn').addEventListener('click', startListScanFromPanel);
  listScanPanel.querySelector('#lsp-stop-btn').addEventListener('click', stopListScanFromPanel);
  listScanPanel.querySelector('#lsp-clear-all-btn').addEventListener('click', clearAllScannedVideos);

  // 字幕一括取得タブ
  listScanPanel.querySelector('#ssp-load-btn').addEventListener('click', loadSubtitleScanTargets);
  listScanPanel.querySelector('#ssp-start-btn').addEventListener('click', startSubtitleScan);
  listScanPanel.querySelector('#ssp-stop-btn').addEventListener('click', stopSubtitleScan);
  restoreSubtitleScanPanelState();

  // タブ切り替えイベント
  listScanPanel.querySelectorAll('.lsp-tab').forEach(tab => {
    tab.addEventListener('click', () => switchTab(tab.dataset.tab));
  });
}

/**
 * リストスキャンパネルを表示/非表示トグル
 */
function toggleListScanPanel() {
  if (listScanPanelVisible) {
    hideListScanPanel();
  } else {
    showListScanPanel();
  }
}

/**
 * リストスキャンパネルを表示
 */
function showListScanPanel() {
  if (!listScanPanel) {
    createListScanPanel();
  }
  listScanPanel.classList.add('visible');
  listScanPanelVisible = true;
  updateTriggerButtonState();

  // 既存のリストスキャン状態を復元
  restoreListScanState();
}

/**
 * リストスキャンパネルを非表示
 */
function hideListScanPanel() {
  if (listScanPanel) {
    listScanPanel.classList.remove('visible');
  }
  listScanPanelVisible = false;
  updateTriggerButtonState();
}

/**
 * videoIdリストを読み込み
 */
async function loadVideoIdList() {
  const textarea = listScanPanel.querySelector('#lsp-video-ids');
  const text = textarea.value.trim();

  if (!text) {
    return;
  }

  // videoIdをパース（1行1ID、空行・空白を除外）
  const videoIds = text
    .split('\n')
    .map(line => line.trim())
    .filter(id => id && /^[a-zA-Z0-9_-]{11}$/.test(id));

  if (videoIds.length === 0) {
    return;
  }

  currentListScanVideoIds = videoIds;

  // ストレージに保存
  await chrome.storage.local.set({
    listScanVideoIds: videoIds,
    listScanCurrentIndex: 0
  });

  // UIを更新
  await renderVideoList();
}

/**
 * videoIdリストをクリア
 */
async function clearVideoIdList() {
  currentListScanVideoIds = [];
  listScanPanel.querySelector('#lsp-video-ids').value = '';
  listScanPanel.querySelector('#lsp-video-list').innerHTML = '<div class="lsp-empty">VideoIDを入力して読み込みボタンをクリック</div>';
  listScanPanel.querySelector('#lsp-progress-info').textContent = '0 / 0';
  listScanPanel.querySelector('#lsp-start-btn').disabled = true;

  await chrome.storage.local.remove(['listScanVideoIds', 'listScanCurrentIndex', 'listScanActive']);
}

/**
 * 動画リストを描画
 */
async function renderVideoList() {
  const listContainer = listScanPanel.querySelector('#lsp-video-list');
  const startBtn = listScanPanel.querySelector('#lsp-start-btn');
  const progressInfo = listScanPanel.querySelector('#lsp-progress-info');

  if (currentListScanVideoIds.length === 0) {
    listContainer.innerHTML = '<div class="lsp-empty">VideoIDを入力して読み込みボタンをクリック</div>';
    startBtn.disabled = true;
    return;
  }

  // 各動画のスキャン状況を取得
  const statuses = await Promise.all(
    currentListScanVideoIds.map(id => getVideoScanStatus(id))
  );

  // 完了数をカウント
  const completedCount = statuses.filter(s => s.status === 'completed').length;
  progressInfo.textContent = `${completedCount} / ${currentListScanVideoIds.length}`;

  // 現在の動画ID
  const currentVideoId = getVideoId();

  // リストを描画
  listContainer.innerHTML = currentListScanVideoIds.map((videoId, index) => {
    const status = statuses[index];
    const isCurrent = videoId === currentVideoId;

    const statusIcon = {
      'not_scanned': '○',
      'scanning': '●',
      'completed': '✓',
      'partial': '△'
    }[status.status] || '○';

    const statusClass = status.status;

    return `
      <div class="lsp-item ${isCurrent ? 'current' : ''}" data-video-id="${videoId}">
        <span class="lsp-item-index">${index + 1}.</span>
        <span class="lsp-item-id">${videoId}</span>
        <div class="lsp-item-status">
          <span class="lsp-status-icon ${statusClass}">${statusIcon}</span>
          <div class="lsp-progress-bar">
            <div class="lsp-progress-fill" style="width: ${status.progress}%"></div>
          </div>
          <span>${status.progress}%</span>
        </div>
        <button class="lsp-open-btn" data-video-id="${videoId}">開く</button>
      </div>
    `;
  }).join('');

  // [開く]ボタンのイベントリスナー
  listContainer.querySelectorAll('.lsp-open-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const videoId = e.target.dataset.videoId;
      window.location.href = `https://www.youtube.com/watch?v=${videoId}`;
    });
  });

  startBtn.disabled = false;
}

/**
 * 動画のスキャン状況を取得
 */
async function getVideoScanStatus(videoId) {
  const key = `volumeData_${videoId}`;
  const result = await chrome.storage.local.get(key);

  if (!result[key] || !result[key].data) {
    return { status: 'not_scanned', progress: 0 };
  }

  const data = result[key].data;
  const filledCount = data.filter(v => v > 0).length;
  const progress = Math.round((filledCount / data.length) * 100);

  if (progress >= 95) {
    return { status: 'completed', progress: 100 };
  } else if (progress > 0) {
    return { status: 'partial', progress };
  }

  return { status: 'not_scanned', progress: 0 };
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
      currentListScanVideoIds = result.listScanVideoIds;
      listScanPanel.querySelector('#lsp-video-ids').value = result.listScanVideoIds.join('\n');
      await renderVideoList();

      // スキャン中の場合はUIを更新
      if (result.listScanActive) {
        listScanPanel.querySelector('#lsp-start-btn').style.display = 'none';
        listScanPanel.querySelector('#lsp-stop-btn').style.display = 'block';
      }
    }
  } catch (error) {
    console.error('リストスキャン状態復元エラー:', error);
  }
}

/**
 * パネルからリストスキャンを開始
 */
async function startListScanFromPanel() {
  if (currentListScanVideoIds.length === 0) return;

  // スキャン済みでない最初の動画を見つける
  let startIndex = 0;
  for (let i = 0; i < currentListScanVideoIds.length; i++) {
    const status = await getVideoScanStatus(currentListScanVideoIds[i]);
    if (status.status !== 'completed') {
      startIndex = i;
      break;
    }
    if (i === currentListScanVideoIds.length - 1) {
      // 全て完了済み
      console.log('リストスキャン: 全ての動画がスキャン済みです');
      return;
    }
  }

  // ストレージに状態を保存
  await chrome.storage.local.set({
    listScanVideoIds: currentListScanVideoIds,
    listScanCurrentIndex: startIndex,
    listScanActive: true
  });

  // UIを更新
  listScanPanel.querySelector('#lsp-start-btn').style.display = 'none';
  listScanPanel.querySelector('#lsp-stop-btn').style.display = 'block';

  // 対象動画に移動
  const targetVideoId = currentListScanVideoIds[startIndex];
  const currentVideoId = getVideoId();

  if (currentVideoId !== targetVideoId) {
    window.location.href = `https://www.youtube.com/watch?v=${targetVideoId}`;
  } else {
    // 現在の動画がターゲットなら、スキャンボタンを表示
    isListScanMode = true;
    showListScanButton(startIndex, currentListScanVideoIds.length);
  }
}

/**
 * パネルからリストスキャンを停止
 */
async function stopListScanFromPanel() {
  isListScanMode = false;
  hideListScanButton();

  await chrome.storage.local.set({ listScanActive: false });

  // UIを更新
  if (listScanPanel) {
    listScanPanel.querySelector('#lsp-start-btn').style.display = 'block';
    listScanPanel.querySelector('#lsp-stop-btn').style.display = 'none';
  }

  // スキャン中の場合は停止
  chrome.runtime.sendMessage({ type: 'STOP_SCAN' });
}

// ==========================================
// 字幕一括取得スキャン（#598）
// タイムスタンプあり＆字幕未取得のアーカイブを順次遷移して字幕を送信する。
// 状態は音量リストスキャン（listScan*）と独立したキーで持つ
// ==========================================

let subtitleScanTargets = [];

/**
 * 自タブのIDをbackground経由で取得する
 */
function getOwnTabId() {
  return new Promise(resolve => {
    try {
      chrome.runtime.sendMessage({ type: 'GET_TAB_ID' }, res => {
        resolve(res?.tabId ?? null);
      });
    } catch (e) {
      resolve(null);
    }
  });
}

function setSubtitleScanStatus(text) {
  const el = listScanPanel?.querySelector('#ssp-status');
  if (el) el.textContent = text;
}

/**
 * サーバーから字幕未取得のアーカイブ一覧を読み込む
 */
async function loadSubtitleScanTargets() {
  if (!ycsApiToken) {
    await loadYcsApiSettings();
  }
  if (!ycsApiToken) {
    setSubtitleScanStatus('APIトークンが未設定です。プロフィール画面で発行し、拡張の設定に登録してください');
    return;
  }

  setSubtitleScanStatus('対象を読み込んでいます…');

  try {
    const response = await fetch(`${ycsServerUrl}/api/extension/subtitle-targets`, {
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${ycsApiToken}`,
      },
    });
    if (!response.ok) {
      throw new Error(`一覧の取得に失敗しました (${response.status})`);
    }

    const data = await response.json();
    subtitleScanTargets = data.targets || [];

    const listEl = listScanPanel?.querySelector('#ssp-video-list');
    if (listEl) {
      listEl.innerHTML = subtitleScanTargets.length === 0
        ? '<div class="lsp-empty">字幕未取得のアーカイブはありません</div>'
        : subtitleScanTargets.map((t, i) => `
            <div class="lsp-item">
              <span class="lsp-item-index">${i + 1}.</span>
              <span class="lsp-item-id" title="${escapeHtml(t.video_id)}">${escapeHtml(t.title || t.video_id)}</span>
            </div>
          `).join('');
    }
    setSubtitleScanStatus(`対象: ${subtitleScanTargets.length}件`);

    const startBtn = listScanPanel?.querySelector('#ssp-start-btn');
    if (startBtn) startBtn.disabled = subtitleScanTargets.length === 0;
  } catch (error) {
    console.error('[YCS] 字幕取得対象の読み込みエラー:', error);
    setSubtitleScanStatus('エラー: ' + error.message);
  }
}

/**
 * 字幕一括取得スキャンを開始する
 */
async function startSubtitleScan() {
  if (subtitleScanTargets.length === 0) return;

  // 音量リストスキャンと同時に走ると遷移を取り合うため開始を拒否する
  const { listScanActive } = await chrome.storage.local.get(['listScanActive']);
  if (listScanActive) {
    setSubtitleScanStatus('音量のリストスキャン実行中は開始できません。先に停止してください');
    return;
  }

  const tabId = await getOwnTabId();
  const videoIds = subtitleScanTargets.map(t => t.video_id);

  await chrome.storage.local.set({
    subtitleScanVideoIds: videoIds,
    subtitleScanIndex: 0,
    subtitleScanActive: true,
    subtitleScanTabId: tabId,
    subtitleScanResults: { sent: 0, skipped: 0, failed: 0 },
  });

  updateSubtitleScanButtons(true);

  const firstVideoId = videoIds[0];
  if (getVideoId() === firstVideoId) {
    checkAndStartSubtitleScan();
  } else {
    window.location.href = `https://www.youtube.com/watch?v=${firstVideoId}`;
  }
}

/**
 * 字幕一括取得スキャンを停止する
 */
async function stopSubtitleScan() {
  await chrome.storage.local.set({ subtitleScanActive: false });
  updateSubtitleScanButtons(false);
  setSubtitleScanStatus('停止しました');
}

function updateSubtitleScanButtons(running) {
  if (!listScanPanel) return;
  listScanPanel.querySelector('#ssp-start-btn').style.display = running ? 'none' : 'block';
  listScanPanel.querySelector('#ssp-stop-btn').style.display = running ? 'block' : 'none';
}

/**
 * パネル生成時に実行中の字幕スキャン状態をUIへ反映する
 */
async function restoreSubtitleScanPanelState() {
  try {
    const result = await chrome.storage.local.get([
      'subtitleScanActive', 'subtitleScanVideoIds', 'subtitleScanIndex',
    ]);
    if (result.subtitleScanActive && result.subtitleScanVideoIds) {
      updateSubtitleScanButtons(true);
      setSubtitleScanStatus(`字幕取得中… ${(result.subtitleScanIndex || 0) + 1}/${result.subtitleScanVideoIds.length}`);
    }
  } catch (e) { /* 復元失敗は無視 */ }
}

/**
 * ページ読み込み時に字幕スキャンの続きを実行する
 * 実行タブ以外・想定外の動画では何もしない（#580と同種の暴走を防ぐ）
 */
async function checkAndStartSubtitleScan() {
  try {
    const result = await chrome.storage.local.get([
      'subtitleScanVideoIds', 'subtitleScanIndex', 'subtitleScanActive', 'subtitleScanTabId',
    ]);

    if (!result.subtitleScanActive || !result.subtitleScanVideoIds) return;

    // 実行タブのガード
    const tabId = await getOwnTabId();
    if (result.subtitleScanTabId != null && tabId !== result.subtitleScanTabId) return;

    const videoIds = result.subtitleScanVideoIds;
    const index = result.subtitleScanIndex || 0;
    const videoId = getVideoId();
    if (videoId !== videoIds[index]) return;

    console.log(`[YCS] 字幕スキャン: ${index + 1}/${videoIds.length} を処理します`);
    setSubtitleScanStatus(`字幕取得中… ${index + 1}/${videoIds.length}`);
    updateSubtitleScanButtons(true);

    // プレイヤーの準備を待ってから処理（CCボタン経由の取得に必要）
    waitForPlayerAndProcessSubtitle(videoId);
  } catch (error) {
    console.error('[YCS] 字幕スキャンチェックエラー:', error);
  }
}

function waitForPlayerAndProcessSubtitle(videoId) {
  let attempt = 0;
  const check = () => {
    if (videoElement && videoElement.readyState >= 2) {
      processSubtitleScanVideo(videoId);
    } else if (attempt >= 30) {
      // 30秒待っても準備できない動画（限定公開・削除等）はスキップ
      console.warn('[YCS] 字幕スキャン: プレイヤーが準備できないためスキップします', videoId);
      recordSubtitleScanResult('skipped').then(proceedToNextSubtitleScanVideo);
    } else {
      attempt++;
      setTimeout(check, 1000);
    }
  };
  // ページ読み込み直後の切り替わりを待つ
  setTimeout(check, 1500);
}

/**
 * 現在の動画の字幕を取得してサーバーへ送信し、次へ進む
 */
async function processSubtitleScanVideo(videoId) {
  try {
    const tracks = await getCaptionTracksFromPage();
    if (!tracks || tracks.length === 0) {
      console.log('[YCS] 字幕スキャン: 字幕がないためスキップ', videoId);
      await recordSubtitleScanResult('skipped');
    } else {
      const track = pickPreferredCaptionTrack(tracks);
      const segments = await fetchTimedText(videoId, track.languageCode);
      if (!segments || segments.length === 0) {
        await recordSubtitleScanResult('skipped');
      } else {
        await postSubtitlesToServer(videoId, track.languageCode, track.kind === 'asr' ? 'asr' : '', segments);
        await recordSubtitleScanResult('sent');
      }
    }
  } catch (error) {
    console.warn('[YCS] 字幕スキャン: 取得・送信に失敗', videoId, error.message);
    await recordSubtitleScanResult('failed');
  }

  await proceedToNextSubtitleScanVideo();
}

async function recordSubtitleScanResult(kind) {
  const result = await chrome.storage.local.get(['subtitleScanResults']);
  const counts = result.subtitleScanResults || { sent: 0, skipped: 0, failed: 0 };
  counts[kind] = (counts[kind] || 0) + 1;
  await chrome.storage.local.set({ subtitleScanResults: counts });
}

/**
 * 次の動画へ遷移する。最後まで進んだらまとめを表示して終了
 */
async function proceedToNextSubtitleScanVideo() {
  const result = await chrome.storage.local.get([
    'subtitleScanVideoIds', 'subtitleScanIndex', 'subtitleScanActive', 'subtitleScanTabId', 'subtitleScanResults',
  ]);

  // 停止済みなら何もしない（処理中に停止ボタンが押されたケース）
  if (!result.subtitleScanActive) return;

  // 実行タブのガード（遷移直前にも確認する）
  const tabId = await getOwnTabId();
  if (result.subtitleScanTabId != null && tabId !== result.subtitleScanTabId) return;

  const videoIds = result.subtitleScanVideoIds || [];
  const nextIndex = (result.subtitleScanIndex || 0) + 1;

  if (nextIndex >= videoIds.length) {
    const counts = result.subtitleScanResults || {};
    const summary = `字幕一括取得が完了しました（送信 ${counts.sent || 0}件 / スキップ ${counts.skipped || 0}件 / 失敗 ${counts.failed || 0}件）`;
    console.log('[YCS] ' + summary);
    await chrome.storage.local.set({ subtitleScanActive: false });
    updateSubtitleScanButtons(false);
    setSubtitleScanStatus(summary);
    return;
  }

  await chrome.storage.local.set({ subtitleScanIndex: nextIndex });

  // 連続アクセスを避けるため少し待ってから遷移する（待機中に停止されたら遷移しない）
  setTimeout(async () => {
    const { subtitleScanActive } = await chrome.storage.local.get(['subtitleScanActive']);
    if (!subtitleScanActive) return;
    window.location.href = `https://www.youtube.com/watch?v=${videoIds[nextIndex]}`;
  }, 2000);
}

/**
 * タブを切り替え
 */
function switchTab(tabId) {
  if (!listScanPanel) return;

  // タブボタンの状態を更新
  listScanPanel.querySelectorAll('.lsp-tab').forEach(tab => {
    tab.classList.toggle('active', tab.dataset.tab === tabId);
  });

  // タブコンテンツの表示を切り替え
  listScanPanel.querySelector('#tab-list-scan').classList.toggle('active', tabId === 'list-scan');
  listScanPanel.querySelector('#tab-scanned-list').classList.toggle('active', tabId === 'scanned-list');

  // スキャン済み一覧タブに切り替えた場合はリストを更新
  if (tabId === 'scanned-list') {
    loadScannedVideosList();
  }
}

/**
 * スキャン済み動画一覧を読み込み
 */
async function loadScannedVideosList() {
  try {
    const allData = await chrome.storage.local.get(null);
    const videos = [];

    for (const key in allData) {
      if (key.startsWith('volumeData_')) {
        const videoId = key.replace('volumeData_', '');
        const data = allData[key];

        if (!data || !data.data) continue;

        const filledCount = data.data.filter(v => v > 0).length;
        const progress = Math.round((filledCount / data.data.length) * 100);

        videos.push({
          videoId,
          savedAt: data.savedAt || null,
          duration: data.duration || 0,
          progress: progress >= 95 ? 100 : progress
        });
      }
    }

    // 日付でソート（新しい順）
    videos.sort((a, b) => {
      if (!a.savedAt) return 1;
      if (!b.savedAt) return -1;
      return new Date(b.savedAt) - new Date(a.savedAt);
    });

    renderScannedVideosList(videos);
  } catch (error) {
    console.error('スキャン済み動画一覧取得エラー:', error);
  }
}

/**
 * スキャン済み動画一覧を描画
 */
function renderScannedVideosList(videos) {
  if (!listScanPanel) return;

  const listContainer = listScanPanel.querySelector('#lsp-scanned-video-list');
  const countEl = listScanPanel.querySelector('#lsp-scanned-count');
  const clearAllBtn = listScanPanel.querySelector('#lsp-clear-all-btn');

  countEl.textContent = `${videos.length} 件のスキャン済み動画`;

  if (videos.length === 0) {
    listContainer.innerHTML = '<div class="lsp-empty">スキャン済みの動画がありません</div>';
    clearAllBtn.disabled = true;
    return;
  }

  clearAllBtn.disabled = false;

  const html = videos.map(video => {
    const dateStr = video.savedAt
      ? new Date(video.savedAt).toLocaleDateString('ja-JP', { month: 'numeric', day: 'numeric' })
      : '-';

    return `
      <div class="lsp-scanned-item" data-video-id="${video.videoId}">
        <span class="lsp-item-id">${video.videoId}</span>
        <span class="lsp-item-date">${dateStr}</span>
        <span class="lsp-item-status">${video.progress}%</span>
        <div class="lsp-item-actions">
          <button class="lsp-open-btn" data-action="open" data-video-id="${video.videoId}">開く</button>
          <button class="lsp-delete-btn" data-action="delete" data-video-id="${video.videoId}">×</button>
        </div>
      </div>
    `;
  }).join('');

  listContainer.innerHTML = html;

  // イベントリスナーを設定
  listContainer.querySelectorAll('[data-action="open"]').forEach(btn => {
    btn.addEventListener('click', () => openYouTubeVideo(btn.dataset.videoId));
  });

  listContainer.querySelectorAll('[data-action="delete"]').forEach(btn => {
    btn.addEventListener('click', () => deleteScannedVideo(btn.dataset.videoId));
  });
}

/**
 * YouTube動画を開く
 */
function openYouTubeVideo(videoId) {
  window.open(`https://www.youtube.com/watch?v=${videoId}`, '_blank');
}

/**
 * スキャン済み動画データを削除
 */
async function deleteScannedVideo(videoId) {
  const key = `volumeData_${videoId}`;
  await chrome.storage.local.remove(key);

  // リストを更新
  loadScannedVideosList();
}

/**
 * 全てのスキャン済み動画データを削除
 */
async function clearAllScannedVideos() {
  if (!confirm('全てのスキャン済みデータを削除しますか？')) {
    return;
  }

  try {
    const allData = await chrome.storage.local.get(null);
    const keysToRemove = [];

    for (const key in allData) {
      if (key.startsWith('volumeData_')) {
        keysToRemove.push(key);
      }
    }

    if (keysToRemove.length > 0) {
      await chrome.storage.local.remove(keysToRemove);
    }

    // リストを更新
    loadScannedVideosList();
  } catch (error) {
    console.error('全データ削除エラー:', error);
  }
}

// ==========================================
// チャット検索機能
// ==========================================

/**
 * チャット検索パネルを表示/非表示トグル
 */
function toggleChatSearchPanel() {
  if (chatSearchPanelVisible) {
    hideChatSearchPanel();
  } else {
    showChatSearchPanel();
  }
}

/**
 * チャット検索パネルを表示
 */
async function showChatSearchPanel() {
  // 同一位置の他パネルと排他
  if (subtitlePanelVisible) {
    hideSubtitlePanel();
  }
  if (highlightPanelVisible) {
    hideHighlightPanel();
  }

  if (!chatSearchPanel) {
    createChatSearchPanel();
  }
  chatSearchPanel.classList.add('visible');
  chatSearchPanelVisible = true;
  updateTriggerButtonState();

  // IndexedDBを初期化
  await initChatDB();

  // 既存のチャットデータがあるか確認
  const videoId = getVideoId();
  if (videoId) {
    await loadChatDataForVideo(videoId);
  }
}

/**
 * チャット検索パネルを非表示
 */
function hideChatSearchPanel() {
  if (chatSearchPanel) {
    chatSearchPanel.classList.remove('visible');
  }
  chatSearchPanelVisible = false;
  updateTriggerButtonState();
}

/**
 * チャット検索パネルを作成
 */
function createChatSearchPanel() {
  if (chatSearchPanel) return;

  chatSearchPanel = document.createElement('div');
  chatSearchPanel.id = 'ycs-chat-search-panel';
  chatSearchPanel.innerHTML = `
    <style>
      #ycs-chat-search-panel {
        position: fixed;
        bottom: 140px;
        right: 70px;
        z-index: 9997;
        width: 360px;
        max-height: 500px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-chat-search-panel.visible {
        display: flex !important;
      }
      .csp-header {
        padding: 12px 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .csp-header-title {
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .csp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .csp-close-btn:hover {
        background: rgba(255,255,255,0.3);
      }
      .csp-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow-y: auto;
        max-height: 400px;
      }
      .csp-search-row {
        display: flex;
        gap: 8px;
      }
      .csp-search-input {
        flex: 1;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 8px 12px;
        font-size: 13px;
      }
      .csp-search-input::placeholder {
        color: #888;
      }
      .csp-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }
      .csp-btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
      }
      .csp-btn-primary:hover {
        filter: brightness(1.1);
      }
      .csp-btn-secondary {
        background: #444;
        color: white;
      }
      .csp-btn-secondary:hover {
        background: #555;
      }
      .csp-filters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }
      .csp-filter-btn {
        padding: 4px 12px;
        border: 1px solid #444;
        border-radius: 16px;
        background: transparent;
        color: #aaa;
        font-size: 11px;
        cursor: pointer;
        transition: all 0.2s;
      }
      .csp-filter-btn:hover {
        border-color: #666;
        color: #fff;
      }
      .csp-filter-btn.active {
        background: #667eea;
        border-color: #667eea;
        color: #fff;
      }
      .csp-status {
        font-size: 12px;
        color: #888;
        text-align: center;
        padding: 4px;
      }
      .csp-status.loading {
        color: #ff9800;
      }
      .csp-results {
        display: flex;
        flex-direction: column;
        gap: 4px;
        max-height: 280px;
        overflow-y: auto;
      }
      .csp-result-item {
        display: flex;
        gap: 8px;
        padding: 8px;
        background: #2a2a2a;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.2s;
      }
      .csp-result-item:hover {
        background: #3a3a3a;
      }
      .csp-result-time {
        color: #667eea;
        font-family: monospace;
        font-size: 11px;
        flex-shrink: 0;
        width: 60px;
      }
      .csp-result-message {
        flex: 1;
        font-size: 12px;
        color: #ddd;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .csp-result-badge {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        flex-shrink: 0;
      }
      .csp-badge-superchat {
        background: #ff6b6b;
        color: white;
      }
      .csp-empty {
        text-align: center;
        color: #888;
        padding: 20px;
        font-size: 12px;
      }
      .csp-actions {
        display: flex;
        gap: 8px;
        padding-top: 8px;
        border-top: 1px solid #333;
      }
    </style>
    <div class="csp-header">
      <span class="csp-header-title">💬 チャット検索</span>
      <button class="csp-close-btn" id="csp-close-btn">×</button>
    </div>
    <div class="csp-content">
      <div class="csp-search-row">
        <input type="text" class="csp-search-input" id="csp-search-input" placeholder="検索ワードを入力...">
        <button class="csp-btn csp-btn-primary" id="csp-search-btn">検索</button>
      </div>
      <div class="csp-filters">
        <button class="csp-filter-btn active" data-filter="all">全て</button>
        <button class="csp-filter-btn" data-filter="superchat">スパチャ</button>
      </div>
      <div class="csp-status" id="csp-status">チャットを読み込み中...</div>
      <div class="csp-results" id="csp-results">
        <div class="csp-empty">検索結果がここに表示されます</div>
      </div>
      <div class="csp-actions">
        <button class="csp-btn csp-btn-secondary" id="csp-fetch-btn">チャット取得</button>
        <button class="csp-btn csp-btn-secondary" id="csp-clear-btn">データ削除</button>
      </div>
    </div>
  `;

  document.body.appendChild(chatSearchPanel);

  // イベントリスナー設定
  chatSearchPanel.querySelector('#csp-close-btn').addEventListener('click', hideChatSearchPanel);
  chatSearchPanel.querySelector('#csp-search-btn').addEventListener('click', searchChats);
  chatSearchPanel.querySelector('#csp-search-input').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') searchChats();
  });
  chatSearchPanel.querySelector('#csp-fetch-btn').addEventListener('click', fetchChatData);
  chatSearchPanel.querySelector('#csp-clear-btn').addEventListener('click', clearChatDataForVideo);

  // フィルターボタンのイベント
  chatSearchPanel.querySelectorAll('.csp-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      chatSearchPanel.querySelectorAll('.csp-filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      searchChats();
    });
  });
}

/**
 * IndexedDBを初期化
 */
function initChatDB() {
  return new Promise((resolve, reject) => {
    if (chatSearchDB) {
      resolve(chatSearchDB);
      return;
    }

    const request = indexedDB.open(CHAT_DB_NAME, CHAT_DB_VERSION);

    request.onerror = () => reject(request.error);

    request.onsuccess = () => {
      chatSearchDB = request.result;
      resolve(chatSearchDB);
    };

    request.onupgradeneeded = (event) => {
      const db = event.target.result;

      if (!db.objectStoreNames.contains(CHAT_STORE_NAME)) {
        const store = db.createObjectStore(CHAT_STORE_NAME, { keyPath: 'id' });
        store.createIndex('videoId', 'videoId', { unique: false });
        store.createIndex('timestamp', 'timestamp', { unique: false });
        store.createIndex('savedAt', 'savedAt', { unique: false });
      }
    };
  });
}

/**
 * 動画のチャットデータを読み込み
 */
async function loadChatDataForVideo(videoId) {
  const statusEl = chatSearchPanel?.querySelector('#csp-status');

  try {
    await initChatDB();

    const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readonly');
    const store = transaction.objectStore(CHAT_STORE_NAME);
    const index = store.index('videoId');
    const request = index.getAll(videoId);

    return new Promise((resolve) => {
      request.onsuccess = () => {
        const chats = request.result || [];
        if (statusEl) {
          statusEl.textContent = chats.length > 0
            ? `${chats.length}件のチャットを読み込み済み`
            : 'チャットデータがありません。「チャット取得」ボタンで取得してください';
          statusEl.classList.remove('loading');
        }
        resolve(chats);
      };
      request.onerror = () => {
        if (statusEl) {
          statusEl.textContent = 'チャット読み込みエラー';
        }
        resolve([]);
      };
    });
  } catch (error) {
    console.error('チャット読み込みエラー:', error);
    if (statusEl) {
      statusEl.textContent = 'チャット読み込みエラー';
    }
    return [];
  }
}

/**
 * InnerTube APIからチャットリプレイを取得
 */
async function fetchChatData() {
  const videoId = getVideoId();
  if (!videoId) return;

  const statusEl = chatSearchPanel?.querySelector('#csp-status');
  if (statusEl) {
    statusEl.textContent = 'チャットを取得中...';
    statusEl.classList.add('loading');
  }

  try {
    // ytInitialDataからcontinuationトークンを取得
    const continuation = await getChatContinuation();
    if (!continuation) {
      if (statusEl) {
        statusEl.textContent = 'チャットリプレイが見つかりません（チャットが無い動画、またはチャットリプレイが無効な動画です）';
        statusEl.classList.remove('loading');
      }
      return;
    }

    // チャットを取得
    const chats = await fetchAllChatReplays(continuation);

    // IndexedDBに保存
    await saveChatsToDB(videoId, chats);

    if (statusEl) {
      statusEl.textContent = `${chats.length}件のチャットを取得しました`;
      statusEl.classList.remove('loading');
    }
  } catch (error) {
    console.error('チャット取得エラー:', error);
    if (statusEl) {
      statusEl.textContent = 'チャット取得エラー: ' + error.message;
      statusEl.classList.remove('loading');
    }
  }
}

/**
 * page-bridge.js経由でチャットリプレイのcontinuationトークンを取得
 * Content ScriptからはページコンテキストのytInitialDataに直接アクセスできないため、
 * page-bridge.jsにメッセージを送って取得する
 */
async function getChatContinuation() {
  // page-bridge.jsが注入されていることを保証
  await ensurePageBridge();

  return new Promise((resolve) => {
    const timeout = setTimeout(() => {
      window.removeEventListener('message', handler);
      console.warn('[YCS] チャットcontinuation取得タイムアウト');
      resolve(null);
    }, 5000);

    function handler(event) {
      if (event.source !== window) return;
      if (event.data?.type === 'YCS_CHAT_CONTINUATION_RESPONSE') {
        window.removeEventListener('message', handler);
        clearTimeout(timeout);
        resolve(event.data.continuation || null);
      }
    }

    window.addEventListener('message', handler);
    window.postMessage({ type: 'YCS_GET_CHAT_CONTINUATION' }, '*');
  });
}

/**
 * 全チャットリプレイを取得
 */
async function fetchAllChatReplays(initialContinuation) {
  const chats = [];
  let continuation = initialContinuation;
  let iterations = 0;
  const maxIterations = 100; // 安全のため上限を設定

  while (continuation && iterations < maxIterations) {
    iterations++;

    const statusEl = chatSearchPanel?.querySelector('#csp-status');
    if (statusEl) {
      statusEl.textContent = `チャットを取得中... (${chats.length}件)`;
    }

    const response = await fetchChatReplayPage(continuation);
    if (!response) break;

    const newChats = parseChatResponse(response);
    chats.push(...newChats);

    // 次のcontinuationを取得
    continuation = getNextContinuation(response);

    // レート制限対策
    await new Promise(resolve => setTimeout(resolve, 100));
  }

  return chats;
}

/**
 * チャットリプレイの1ページを取得
 */
async function fetchChatReplayPage(continuation) {
  try {
    const response = await fetch('https://www.youtube.com/youtubei/v1/live_chat/get_live_chat_replay?key=AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        context: {
          client: {
            clientName: 'WEB',
            clientVersion: '2.20231219.04.00'
          }
        },
        continuation: continuation
      })
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  } catch (error) {
    console.error('チャットページ取得エラー:', error);
    return null;
  }
}

/**
 * チャットレスポンスをパース
 */
function parseChatResponse(response) {
  const chats = [];

  try {
    const actions = response?.continuationContents?.liveChatContinuation?.actions || [];

    for (const action of actions) {
      const replayAction = action?.replayChatItemAction;
      if (!replayAction) continue;

      const chatActions = replayAction?.actions || [];
      for (const chatAction of chatActions) {
        const item = chatAction?.addChatItemAction?.item;
        if (!item) continue;

        const chat = parseChatItem(item, replayAction.videoOffsetTimeMsec);
        if (chat) {
          chats.push(chat);
        }
      }
    }
  } catch (error) {
    console.error('チャットパースエラー:', error);
  }

  return chats;
}

/**
 * 個別チャットアイテムをパース
 */
function parseChatItem(item, offsetMsec) {
  try {
    // 通常メッセージ
    if (item.liveChatTextMessageRenderer) {
      const renderer = item.liveChatTextMessageRenderer;
      return {
        type: 'normal',
        message: getMessageText(renderer.message),
        timestamp: parseInt(offsetMsec) || 0,
        isSuperchat: false
      };
    }

    // スーパーチャット
    if (item.liveChatPaidMessageRenderer) {
      const renderer = item.liveChatPaidMessageRenderer;
      return {
        type: 'superchat',
        message: getMessageText(renderer.message),
        timestamp: parseInt(offsetMsec) || 0,
        amount: renderer.purchaseAmountText?.simpleText || '',
        isSuperchat: true
      };
    }

    return null;
  } catch (error) {
    return null;
  }
}

/**
 * メッセージテキストを取得
 */
function getMessageText(message) {
  if (!message) return '';

  const runs = message.runs || [];
  return runs.map(run => {
    if (run.text) return run.text;
    if (run.emoji) return run.emoji.shortcuts?.[0] || '';
    return '';
  }).join('');
}

/**
 * 次のcontinuationを取得
 */
function getNextContinuation(response) {
  try {
    const continuations = response?.continuationContents?.liveChatContinuation?.continuations || [];
    for (const cont of continuations) {
      if (cont?.liveChatReplayContinuationData?.continuation) {
        return cont.liveChatReplayContinuationData.continuation;
      }
    }
    return null;
  } catch (error) {
    return null;
  }
}

/**
 * 指定したvideoIdのチャットをIndexedDBから削除
 */
async function deleteChatsByVideoId(videoId) {
  await initChatDB();

  const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
  const store = transaction.objectStore(CHAT_STORE_NAME);
  const index = store.index('videoId');

  return new Promise((resolve, reject) => {
    const request = index.openCursor(IDBKeyRange.only(videoId));

    request.onsuccess = (event) => {
      const cursor = event.target.result;
      if (cursor) {
        cursor.delete();
        cursor.continue();
      }
    };

    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error);
  });
}

/**
 * チャットをIndexedDBに保存
 */
async function saveChatsToDB(videoId, chats) {
  if (chats.length === 0) return;

  await initChatDB();

  // 既存データを削除して重複を防ぐ
  await deleteChatsByVideoId(videoId);

  const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
  const store = transaction.objectStore(CHAT_STORE_NAME);

  const savedAt = new Date().toISOString();

  for (let i = 0; i < chats.length; i++) {
    const chat = chats[i];
    const record = {
      id: `${videoId}_${chat.timestamp}_${i}`,
      videoId,
      ...chat,
      savedAt
    };
    store.put(record);
  }

  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error);
  });
}

/**
 * チャットを検索
 */
async function searchChats() {
  const videoId = getVideoId();
  if (!videoId) return;

  const searchInput = chatSearchPanel?.querySelector('#csp-search-input');
  const resultsEl = chatSearchPanel?.querySelector('#csp-results');
  const activeFilter = chatSearchPanel?.querySelector('.csp-filter-btn.active')?.dataset.filter || 'all';

  const query = searchInput?.value?.trim().toLowerCase() || '';

  // チャットを取得
  const chats = await loadChatDataForVideo(videoId);

  // フィルタリング
  let filtered = chats;

  if (activeFilter === 'superchat') {
    filtered = filtered.filter(c => c.isSuperchat);
  }

  // 検索
  if (query) {
    filtered = filtered.filter(c =>
      c.message?.toLowerCase().includes(query)
    );
  }

  // 時刻でソート
  filtered.sort((a, b) => a.timestamp - b.timestamp);

  // 結果を表示
  renderChatResults(filtered);
}

/**
 * チャット検索結果を表示
 */
function renderChatResults(chats) {
  const resultsEl = chatSearchPanel?.querySelector('#csp-results');
  if (!resultsEl) return;

  if (chats.length === 0) {
    resultsEl.innerHTML = '<div class="csp-empty">検索結果がありません</div>';
    return;
  }

  const html = chats.slice(0, 200).map(chat => {
    const timeStr = formatTimestampMsec(chat.timestamp);
    const badge = chat.isSuperchat
      ? `<span class="csp-result-badge csp-badge-superchat">${chat.amount || 'SC'}</span>`
      : '';

    return `
      <div class="csp-result-item" data-timestamp="${chat.timestamp}">
        <span class="csp-result-time">${timeStr}</span>
        <span class="csp-result-message">${escapeHtml(chat.message)}</span>
        ${badge}
      </div>
    `;
  }).join('');

  resultsEl.innerHTML = html;

  // クリックでシーク
  resultsEl.querySelectorAll('.csp-result-item').forEach(item => {
    item.addEventListener('click', () => {
      const timestamp = parseInt(item.dataset.timestamp);
      if (videoElement && !isNaN(timestamp)) {
        videoElement.currentTime = timestamp / 1000;
      }
    });
  });
}

/**
 * ミリ秒値をタイムスタンプ表記にフォーマット（チャットのoffsetMs用）
 * NOTE: 5573行に同名で別実装(秒用、vdg/マーカー用)があったため、
 * 名前を formatTimestampMsec に変更して衝突を解消した。
 * 後勝ち定義により旧名 formatTimestamp はチャット用には機能していなかった
 * （表示時刻が実際と全く異なる不具合の原因）。
 */
function formatTimestampMsec(msec) {
  const totalSeconds = Math.floor(msec / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

/**
 * HTMLエスケープ
 */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text || '';
  return div.innerHTML;
}



/**
 * 動画のチャットデータを削除
 */
async function clearChatDataForVideo() {
  const videoId = getVideoId();
  if (!videoId) return;

  if (!confirm('この動画のチャットデータを削除しますか？')) {
    return;
  }

  try {
    await initChatDB();

    const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
    const store = transaction.objectStore(CHAT_STORE_NAME);
    const index = store.index('videoId');
    const request = index.openCursor(IDBKeyRange.only(videoId));

    request.onsuccess = () => {
      const cursor = request.result;
      if (cursor) {
        cursor.delete();
        cursor.continue();
      }
    };

    await new Promise((resolve, reject) => {
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
    });

    const statusEl = chatSearchPanel?.querySelector('#csp-status');
    if (statusEl) {
      statusEl.textContent = 'チャットデータを削除しました';
    }

    const resultsEl = chatSearchPanel?.querySelector('#csp-results');
    if (resultsEl) {
      resultsEl.innerHTML = '<div class="csp-empty">検索結果がここに表示されます</div>';
    }
  } catch (error) {
    console.error('チャット削除エラー:', error);
  }
}

/**
 * 古いチャットデータをクリーンアップ
 */
async function cleanupOldChatData() {
  try {
    await initChatDB();

    const cutoffDate = new Date();
    cutoffDate.setDate(cutoffDate.getDate() - CHAT_MAX_AGE_DAYS);
    const cutoffStr = cutoffDate.toISOString();

    const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
    const store = transaction.objectStore(CHAT_STORE_NAME);
    const index = store.index('savedAt');
    const range = IDBKeyRange.upperBound(cutoffStr);

    const request = index.openCursor(range);
    let deletedCount = 0;

    request.onsuccess = () => {
      const cursor = request.result;
      if (cursor) {
        cursor.delete();
        deletedCount++;
        cursor.continue();
      }
    };

    await new Promise((resolve) => {
      transaction.oncomplete = () => {
        if (deletedCount > 0) {
          console.log(`[YCS] ${deletedCount}件の古いチャットデータを削除しました`);
        }
        resolve();
      };
    });
  } catch (error) {
    console.error('チャットクリーンアップエラー:', error);
  }
}

// 起動時に古いデータをクリーンアップ
setTimeout(cleanupOldChatData, 5000);
setTimeout(cleanupOldHighlightData, 6000);

// ==========================================
// 字幕取得機能
// ==========================================

/**
 * 字幕パネルを表示/非表示トグル
 */
function toggleSubtitlePanel() {
  if (subtitlePanelVisible) {
    hideSubtitlePanel();
  } else {
    showSubtitlePanel();
  }
}

/**
 * 字幕パネルを表示
 */
async function showSubtitlePanel() {
  // 同一位置の他パネルと排他
  if (chatSearchPanelVisible) {
    hideChatSearchPanel();
  }
  if (highlightPanelVisible) {
    hideHighlightPanel();
  }

  if (!subtitlePanel) {
    createSubtitlePanel();
  }
  subtitlePanel.classList.add('visible');
  subtitlePanelVisible = true;
  updateTriggerButtonState();

  // 字幕トラックを自動取得
  const videoId = getVideoId();
  if (videoId) {
    await fetchSubtitleTracks(videoId);
  }
}

/**
 * 字幕パネルを非表示
 */
function hideSubtitlePanel() {
  if (subtitlePanel) {
    subtitlePanel.classList.remove('visible');
  }
  subtitlePanelVisible = false;
  updateTriggerButtonState();
}

/**
 * 字幕パネルを作成
 */
function createSubtitlePanel() {
  if (subtitlePanel) return;

  subtitlePanel = document.createElement('div');
  subtitlePanel.id = 'ycs-subtitle-panel';
  subtitlePanel.innerHTML = `
    <style>
      #ycs-subtitle-panel {
        position: fixed;
        bottom: 140px;
        right: 70px;
        z-index: 9997;
        width: 400px;
        max-height: 550px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-subtitle-panel.visible {
        display: flex !important;
      }
      .stp-header {
        padding: 12px 16px;
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .stp-header-title {
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .stp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .stp-close-btn:hover {
        background: rgba(255,255,255,0.3);
      }
      .stp-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        overflow-y: auto;
        max-height: 460px;
      }
      .stp-controls {
        display: flex;
        gap: 8px;
        align-items: center;
      }
      .stp-lang-select {
        flex: 1;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 6px 10px;
        font-size: 12px;
      }
      .stp-btn {
        padding: 6px 14px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }
      .stp-btn-primary {
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
        color: white;
      }
      .stp-btn-primary:hover {
        filter: brightness(1.1);
      }
      .stp-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
      .stp-search-row {
        display: flex;
        gap: 8px;
      }
      .stp-search-input {
        flex: 1;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 6px 10px;
        font-size: 12px;
      }
      .stp-search-input::placeholder {
        color: #888;
      }
      .stp-status {
        font-size: 12px;
        color: #888;
        text-align: center;
        padding: 4px;
      }
      .stp-status.loading {
        color: #a78bfa;
      }
      .stp-results {
        display: flex;
        flex-direction: column;
        gap: 2px;
        max-height: 340px;
        overflow-y: auto;
      }
      .stp-result-item {
        display: flex;
        gap: 8px;
        padding: 6px 8px;
        background: #2a2a2a;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.2s;
        align-items: flex-start;
      }
      .stp-result-item:hover {
        background: #3a3a3a;
      }
      .stp-result-time {
        color: #a78bfa;
        font-family: monospace;
        font-size: 11px;
        flex-shrink: 0;
        width: 58px;
        text-align: right;
      }
      .stp-result-text {
        flex: 1;
        font-size: 12px;
        color: #ddd;
        line-height: 1.4;
      }
      .stp-empty {
        text-align: center;
        color: #888;
        padding: 20px;
        font-size: 12px;
      }
      .stp-load-more {
        text-align: center;
        color: #aaa;
        padding: 8px;
        font-size: 12px;
        cursor: pointer;
        border-top: 1px solid #444;
        margin-top: 4px;
      }
      .stp-load-more:hover {
        color: #fff;
        background: #444;
      }
    </style>
    <div class="stp-header">
      <span class="stp-header-title">📝 字幕取得</span>
      <button class="stp-close-btn" id="stp-close-btn">×</button>
    </div>
    <div class="stp-content">
      <div class="stp-controls">
        <select class="stp-lang-select" id="stp-lang-select">
          <option value="">字幕トラックを読み込み中...</option>
        </select>
        <button class="stp-btn stp-btn-primary" id="stp-fetch-btn" disabled>取得</button>
      </div>
      <div class="stp-search-row">
        <input type="text" class="stp-search-input" id="stp-search-input" placeholder="字幕内を検索...">
      </div>
      <div class="stp-status" id="stp-status">字幕トラックを読み込み中...</div>
      <div class="stp-results" id="stp-results">
        <div class="stp-empty">字幕がここに表示されます</div>
      </div>
    </div>
  `;

  document.body.appendChild(subtitlePanel);

  // イベントリスナー設定
  subtitlePanel.querySelector('#stp-close-btn').addEventListener('click', hideSubtitlePanel);
  subtitlePanel.querySelector('#stp-fetch-btn').addEventListener('click', () => {
    const videoId = getVideoId();
    if (videoId) fetchSubtitleContent(videoId);
  });
  subtitlePanel.querySelector('#stp-search-input').addEventListener('input', filterSubtitleResults);
}

// 現在表示中の字幕データ（フィルタ用に保持）
let currentSubtitles = [];
let currentCaptionTracks = [];

/**
 * ページコンテキストのytInitialPlayerResponseから字幕トラックを取得
 * content scriptはページのJS名前空間にアクセスできないため、
 * page-bridge.jsをページに注入してwindow.postMessageで通信する。
 */
let pageBridgeReady = null;
function ensurePageBridge() {
  if (pageBridgeReady) return pageBridgeReady;
  pageBridgeReady = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = chrome.runtime.getURL('page-bridge.js');
    script.onload = () => { script.remove(); resolve(); };
    script.onerror = () => {
      script.remove();
      // 失敗時はキャッシュをクリアして次回呼び出しで再試行可能にする
      pageBridgeReady = null;
      reject(new Error('page-bridge.jsのロードに失敗しました'));
    };
    document.documentElement.appendChild(script);
  });
  return pageBridgeReady;
}

async function getCaptionTracksFromPage() {
  await ensurePageBridge();

  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      window.removeEventListener('message', handler);
      reject(new Error('字幕データの取得がタイムアウトしました'));
    }, 5000);

    function handler(event) {
      if (event.source !== window || event.data?.type !== 'YCS_CAPTION_TRACKS_RESPONSE') return;
      window.removeEventListener('message', handler);
      clearTimeout(timeout);

      if (event.data.playabilityStatus !== 'OK') {
        reject(new Error('動画を取得できません。動画が非公開・削除済み、または年齢制限がある可能性があります'));
        return;
      }
      resolve(event.data.tracks);
    }

    window.addEventListener('message', handler);
    window.postMessage({ type: 'YCS_GET_CAPTION_TRACKS' }, '*');
  });
}

// ブラウザ拡張はYouTubeページ上のcontent scriptとして動作するため、
// ページ埋め込みデータから字幕トラックを取得する設計としている。
// サーバーサイドのSubtitle APIは管理画面からの利用を想定。
async function fetchSubtitleTracks(videoId) {
  const statusEl = subtitlePanel?.querySelector('#stp-status');
  const selectEl = subtitlePanel?.querySelector('#stp-lang-select');
  const fetchBtn = subtitlePanel?.querySelector('#stp-fetch-btn');

  if (statusEl) {
    statusEl.textContent = '字幕トラックを読み込み中...';
    statusEl.classList.add('loading');
  }

  try {
    // ページコンテキストのytInitialPlayerResponseから字幕トラックデータを取得
    const captionTracks = await getCaptionTracksFromPage();
    currentCaptionTracks = captionTracks;

    if (selectEl) {
      if (captionTracks.length === 0) {
        selectEl.innerHTML = '<option value="">字幕がありません</option>';
        if (fetchBtn) fetchBtn.disabled = true;
        if (statusEl) {
          statusEl.textContent = 'この動画には字幕がありません';
          statusEl.classList.remove('loading');
        }
        return;
      }

      selectEl.innerHTML = '';
      let jaOption = null;
      captionTracks.forEach(track => {
        const option = document.createElement('option');
        option.value = track.languageCode || '';
        option.dataset.lang = track.languageCode || '';
        option.textContent = (track.name || track.languageCode || '') + (track.kind === 'asr' ? ' (自動生成)' : '');
        selectEl.appendChild(option);
        if (track.languageCode === 'ja' && !jaOption) jaOption = option;
      });

      // 日本語を優先選択
      if (jaOption) jaOption.selected = true;

      if (fetchBtn) fetchBtn.disabled = false;
    }

    if (statusEl) {
      statusEl.textContent = `${captionTracks.length}件の字幕トラックが見つかりました`;
      statusEl.classList.remove('loading');
    }

    // トラックが見つかったら自動で字幕を取得
    await fetchSubtitleContent(videoId);
  } catch (error) {
    console.error('字幕トラック取得エラー:', error);
    if (statusEl) {
      statusEl.textContent = 'エラー: ' + error.message;
      statusEl.classList.remove('loading');
    }
    if (selectEl) {
      selectEl.innerHTML = '<option value="">取得エラー</option>';
    }
    if (fetchBtn) fetchBtn.disabled = true;
  }
}

/**
 * 選択された字幕トラックの内容を取得
 * timedtext APIのbaseUrlを使用してJSON形式で字幕を取得する。
 * baseUrlが利用できない場合はget_transcript APIにフォールバック。
 */
async function fetchSubtitleContent(videoId) {
  const statusEl = subtitlePanel?.querySelector('#stp-status');
  const fetchBtn = subtitlePanel?.querySelector('#stp-fetch-btn');
  const selectEl = subtitlePanel?.querySelector('#stp-lang-select');

  if (!videoId) return;

  if (fetchBtn) fetchBtn.disabled = true;
  if (statusEl) {
    statusEl.textContent = '字幕を取得中...';
    statusEl.classList.add('loading');
  }

  try {
    const selectedLang = selectEl?.value || 'ja';

    // InnerTube player APIで最新のbaseUrlを取得してtimedtextをfetch
    currentSubtitles = await fetchTimedText(videoId, selectedLang);

    if (statusEl) {
      statusEl.textContent = `${currentSubtitles.length}件の字幕を取得しました`;
      statusEl.classList.remove('loading');
    }

    renderSubtitleResults(currentSubtitles);

    // サーバーに自動送信
    sendSubtitlesToServer(videoId, selectedLang, currentSubtitles);
  } catch (error) {
    console.error('字幕取得エラー:', error);
    if (statusEl) {
      statusEl.textContent = 'エラー: ' + error.message;
      statusEl.classList.remove('loading');
    }
  } finally {
    if (fetchBtn) fetchBtn.disabled = false;
  }
}

/**
 * YCS API設定をchrome.storageから読み込み
 */
async function loadYcsApiSettings() {
  try {
    const result = await chrome.storage.local.get(['ycsApiToken', 'ycsServerUrl']);
    ycsApiToken = result.ycsApiToken || null;
    // 末尾のスラッシュは除去する（APIパス連結時に「//」になるのを防ぐ）
    ycsServerUrl = (result.ycsServerUrl || DEFAULT_YCS_SERVER_URL).replace(/\/+$/, '');
  } catch (error) {
    console.warn('[YCS] API設定読み込みエラー:', error);
  }
}

/**
 * 字幕データをYCSサーバーに自動送信
 * 失敗時はconsole.warnのみ（字幕表示自体は影響させない）
 */
async function sendSubtitlesToServer(videoId, lang, subtitles) {
  // 選択中のトラックからkindを判定
  const selectEl = subtitlePanel?.querySelector('#stp-lang-select');
  const selectedOption = selectEl?.selectedOptions?.[0];
  const selectedLang = selectedOption?.dataset?.lang || lang;
  const selectedTrack = currentCaptionTracks.find(t => t.languageCode === selectedLang);
  const kind = selectedTrack?.kind === 'asr' ? 'asr' : '';

  try {
    await postSubtitlesToServer(videoId, selectedLang, kind, subtitles);
  } catch (error) {
    console.warn('[YCS] 字幕データ送信エラー:', error.message);
  }
}

/**
 * 字幕データ送信の共通処理（言語・kindを明示して送信）
 * 失敗時はthrowする（自動送信側はcatchして握りつぶし、候補表示側はエラー表示に使う）
 */
async function postSubtitlesToServer(videoId, languageCode, kind, subtitles) {
  if (!videoId || !subtitles || subtitles.length === 0) return;

  // API設定が未読み込みなら読み込む
  if (!ycsApiToken) {
    await loadYcsApiSettings();
  }
  if (!ycsApiToken) {
    // 自動送信側はcatchでwarnになり従来と同じ扱い。スキャン・候補表示側は失敗として扱える
    throw new Error('APIトークンが未設定です。プロフィール画面でトークンを発行し、拡張の設定に登録してください');
  }

  // 重複送信防止
  const cacheKey = `${videoId}_${languageCode}_${kind}`;
  if (subtitleSentCache.has(cacheKey)) return;

  const response = await fetch(`${ycsServerUrl}/api/manage/archives/subtitles/store`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${ycsApiToken}`,
    },
    body: JSON.stringify({
      video_id: videoId,
      language_code: languageCode,
      kind: kind,
      subtitles: subtitles.map(s => ({
        start: s.start,
        duration: s.duration,
        text: s.text,
      })),
    }),
  });

  if (!response.ok) {
    console.warn(`[YCS] 字幕データ送信失敗: ${response.status}`);
    throw new Error(`字幕データの送信に失敗しました (${response.status})`);
  }

  subtitleSentCache.add(cacheKey);
  const data = await response.json();
  console.log(`[YCS] 字幕データ送信成功: ${videoId} (${data.segment_count}セグメント, FP: ${data.fingerprints_generated}件)`);
}

/**
 * InnerTube player API経由で最新の字幕を取得（page-bridge.js経由）
 * 毎回InnerTube APIを呼ぶことでbaseUrlの署名期限切れを回避する。
 */
function fetchTimedText(videoId, lang) {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      window.removeEventListener('message', handler);
      reject(new Error('字幕の取得がタイムアウトしました'));
    }, 15000);

    function handler(event) {
      if (event.source !== window || event.data?.type !== 'YCS_TIMEDTEXT_RESPONSE') return;
      window.removeEventListener('message', handler);
      clearTimeout(timeout);
      if (event.data.error) {
        reject(new Error(event.data.error));
      } else {
        resolve(event.data.segments);
      }
    }

    window.addEventListener('message', handler);
    window.postMessage({ type: 'YCS_FETCH_TIMEDTEXT', videoId, lang }, '*');
  });
}

/**
 * 字幕検索フィルター
 */
function filterSubtitleResults() {
  const query = subtitlePanel?.querySelector('#stp-search-input')?.value?.trim().toLowerCase() || '';
  if (!query) {
    renderSubtitleResults(currentSubtitles);
    return;
  }
  const filtered = currentSubtitles.filter(sub => sub.text.toLowerCase().includes(query));
  renderSubtitleResults(filtered);
}

/**
 * 字幕結果を描画
 */
function renderSubtitleResults(subtitles) {
  const resultsEl = subtitlePanel?.querySelector('#stp-results');
  if (!resultsEl) return;

  if (subtitles.length === 0) {
    resultsEl.innerHTML = '<div class="stp-empty">字幕がありません</div>';
    return;
  }

  const CHUNK_SIZE = 500;
  resultsEl.innerHTML = '';
  resultsEl._subtitles = subtitles;
  resultsEl._rendered = 0;

  appendSubtitleChunk(resultsEl, CHUNK_SIZE);
}

/**
 * 字幕アイテムを指定件数分追加描画
 */
function appendSubtitleChunk(resultsEl, chunkSize) {
  const subtitles = resultsEl._subtitles;
  const start = resultsEl._rendered;
  const end = Math.min(start + chunkSize, subtitles.length);

  const fragment = document.createDocumentFragment();
  for (let i = start; i < end; i++) {
    const sub = subtitles[i];
    const sec = Math.floor(sub.start);
    const item = document.createElement('div');
    item.className = 'stp-result-item';
    item.dataset.time = sec;
    item.innerHTML = `<span class="stp-result-time">${formatSubtitleTime(sec)}</span><span class="stp-result-text">${escapeHtml(sub.text)}</span>`;
    item.addEventListener('click', () => {
      if (videoElement && !isNaN(sec)) {
        videoElement.currentTime = sec;
      }
    });
    fragment.appendChild(item);
  }
  resultsEl._rendered = end;

  // 既存の「もっと表示」ボタンがあれば削除
  const oldBtn = resultsEl.querySelector('.stp-load-more');
  if (oldBtn) oldBtn.remove();

  resultsEl.appendChild(fragment);

  // まだ残りがあれば「もっと表示」ボタンを追加
  if (end < subtitles.length) {
    const remaining = subtitles.length - end;
    const btn = document.createElement('div');
    btn.className = 'stp-load-more';
    btn.textContent = `もっと表示（残り ${remaining} 件）`;
    btn.addEventListener('click', () => {
      appendSubtitleChunk(resultsEl, chunkSize);
    });
    resultsEl.appendChild(btn);
  }
}

/**
 * 秒数をH:MM:SS形式にフォーマット
 */
function formatSubtitleTime(totalSeconds) {
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = totalSeconds % 60;
  if (h > 0) {
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }
  return `${m}:${s.toString().padStart(2, '0')}`;
}

/* =========================================================================
 * ハイライト検出パネル
 *
 * 音量・字幕・コメントの3シグナルをサーバーAPI
 * (POST /api/extension/highlights/detect) に送信し、AIがラベル付けした
 * 候補をリスト表示する。各候補クリックでその時刻にシークできる。
 * =======================================================================*/

function toggleHighlightPanel() {
  if (highlightPanelVisible) {
    hideHighlightPanel();
  } else {
    showHighlightPanel();
  }
}

function showHighlightPanel() {
  if (chatSearchPanelVisible) hideChatSearchPanel();
  if (subtitlePanelVisible) hideSubtitlePanel();

  if (!highlightPanel) {
    createHighlightPanel();
  }
  highlightPanel.classList.add('visible');
  highlightPanelVisible = true;
  updateTriggerButtonState();
  updateHighlightDataStatus();
  // 保存済みのハイライト検出結果を自動復元
  restoreSavedHighlightResult();
}

/**
 * IndexedDBから保存済みのハイライト検出結果を読み込んで表示
 * 既に結果が表示中の場合は上書きしない（再検出ボタン前提）
 */
async function restoreSavedHighlightResult() {
  const videoId = getVideoId();
  if (!videoId) return;
  const resultsEl = highlightPanel?.querySelector('#hlp-results');
  if (!resultsEl) return;
  // 既に結果カードが表示されている場合はスキップ（再検出後の状態を維持）
  if (resultsEl.querySelector('.hlp-result-item')) return;

  const saved = await loadHighlightResult(videoId);
  if (saved && Array.isArray(saved.candidates) && saved.candidates.length > 0) {
    renderHighlightResults(saved.candidates);
    const savedDate = new Date(saved.savedAt).toLocaleString('ja-JP');
    setHighlightStatus(`保存済みの検出結果を表示中（${savedDate}）`, false);
  }
}

function hideHighlightPanel() {
  if (highlightPanel) {
    highlightPanel.classList.remove('visible');
  }
  hideHighlightSubtitleTooltip();
  highlightPanelVisible = false;
  updateTriggerButtonState();
}

function createHighlightPanel() {
  if (highlightPanel) return;

  highlightPanel = document.createElement('div');
  highlightPanel.id = 'ycs-highlight-panel';
  highlightPanel.innerHTML = `
    <style>
      #ycs-highlight-panel {
        position: fixed;
        bottom: 140px;
        right: 70px;
        z-index: 9997;
        width: 420px;
        max-height: 600px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-highlight-panel.visible {
        display: flex !important;
      }
      .hlp-header {
        padding: 12px 16px;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .hlp-header-title {
        font-weight: 600;
        font-size: 14px;
      }
      .hlp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
      }
      .hlp-close-btn:hover { background: rgba(255,255,255,0.35); }
      .hlp-body {
        padding: 12px 16px;
        overflow-y: auto;
        flex: 1;
      }
      .hlp-data-status {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 12px;
        font-size: 12px;
        background: rgba(255,255,255,0.05);
        padding: 8px 12px;
        border-radius: 6px;
        margin-bottom: 12px;
      }
      .hlp-data-status .label { color: #aaa; }
      .hlp-data-status .ok { color: #4ade80; }
      .hlp-data-status .ng { color: #f87171; }
      .hlp-detect-btn {
        width: 100%;
        padding: 10px;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        margin-bottom: 12px;
      }
      .hlp-detect-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
      .hlp-detect-btn:not(:disabled):hover { filter: brightness(1.1); }
      .hlp-status {
        font-size: 12px;
        color: #aaa;
        min-height: 16px;
        margin-bottom: 8px;
      }
      .hlp-status.error { color: #f87171; }
      .hlp-status.loading::after {
        content: '...';
        animation: hlp-blink 1s infinite;
      }
      @keyframes hlp-blink {
        0%, 50% { opacity: 1; }
        51%, 100% { opacity: 0.3; }
      }
      .hlp-results {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .hlp-result-item {
        background: rgba(255,255,255,0.06);
        border-radius: 6px;
        padding: 8px 10px;
        cursor: pointer;
        border-left: 3px solid #f59e0b;
        transition: background 0.15s;
      }
      .hlp-result-item:hover { background: rgba(255,255,255,0.12); }
      .hlp-result-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
      }
      .hlp-result-time {
        color: #fbbf24;
        font-family: monospace;
        font-weight: 600;
      }
      .hlp-result-type {
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 4px;
        background: rgba(255,255,255,0.1);
        color: #ddd;
      }
      .hlp-result-confidence {
        font-size: 11px;
        color: #a3e635;
      }
      .hlp-result-label {
        font-size: 13px;
        color: #fff;
        margin-bottom: 3px;
        word-break: break-word;
      }
      .hlp-result-reason {
        font-size: 11px;
        color: #aaa;
        word-break: break-word;
      }
      .hlp-empty {
        color: #888;
        text-align: center;
        padding: 20px 0;
        font-size: 12px;
      }
      #ycs-highlight-subtitle-tooltip {
        position: fixed;
        z-index: 9998;
        max-width: 360px;
        max-height: 320px;
        overflow-y: auto;
        background: rgba(15, 15, 15, 0.96);
        border: 1px solid #444;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.5);
        padding: 8px 10px;
        font-size: 11px;
        color: #ddd;
        display: none;
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
      }
      #ycs-highlight-subtitle-tooltip.visible { display: block; }
      .hlp-tooltip-title {
        font-size: 11px;
        color: #aaa;
        margin-bottom: 6px;
        padding-bottom: 4px;
        border-bottom: 1px solid #333;
      }
      .hlp-tooltip-line {
        display: flex;
        gap: 6px;
        padding: 2px 0;
        line-height: 1.4;
      }
      .hlp-tooltip-line.in-range {
        color: #fff;
        font-weight: 600;
      }
      .hlp-tooltip-time {
        flex-shrink: 0;
        color: #4fc3f7;
        font-family: monospace;
        min-width: 44px;
      }
      .hlp-tooltip-text {
        word-break: break-word;
      }
      .hlp-tooltip-empty {
        color: #777;
        font-style: italic;
        padding: 4px 0;
      }
    </style>
    <div class="hlp-header">
      <span class="hlp-header-title">✨ ハイライト検出 (AI)</span>
      <button class="hlp-close-btn" title="閉じる">×</button>
    </div>
    <div class="hlp-body">
      <div class="hlp-data-status" id="hlp-data-status">
        <span class="label">音量:</span><span id="hlp-status-volumes" class="ng">未取得</span>
        <span class="label">字幕:</span><span id="hlp-status-subtitles" class="ng">未取得</span>
        <span class="label">コメント:</span><span id="hlp-status-chats" class="ng">未取得</span>
        <span class="label">動画長:</span><span id="hlp-status-duration" class="ng">不明</span>
      </div>
      <button class="hlp-detect-btn" id="hlp-detect-btn">AIに送信してハイライトを検出</button>
      <div class="hlp-status" id="hlp-status"></div>
      <div class="hlp-results" id="hlp-results">
        <div class="hlp-empty">まだ検出していません</div>
      </div>
    </div>
  `;
  document.body.appendChild(highlightPanel);

  highlightPanel.querySelector('.hlp-close-btn').addEventListener('click', () => {
    hideHighlightPanel();
  });
  highlightPanel.querySelector('#hlp-detect-btn').addEventListener('click', () => {
    detectHighlights();
  });
}

/**
 * パネル上部のデータ状況表示を更新
 */
function updateHighlightDataStatus() {
  if (!highlightPanel) return;
  // state: 'ok' | 'ng' | 'neutral'
  const setStatus = (id, state, text) => {
    const el = highlightPanel.querySelector(`#${id}`);
    if (!el) return;
    el.textContent = text;
    el.className = state === 'ok' ? 'ok' : state === 'ng' ? 'ng' : '';
  };

  setStatus(
    'hlp-status-volumes',
    volumeData.length > 0 ? 'ok' : 'ng',
    volumeData.length > 0 ? `${volumeData.length}サンプル` : '未取得（スキャン実行が必要）'
  );
  setStatus(
    'hlp-status-subtitles',
    currentSubtitles.length > 0 ? 'ok' : 'ng',
    currentSubtitles.length > 0 ? `${currentSubtitles.length}件` : '未取得（字幕パネルで取得）'
  );
  // コメント件数をIndexedDBから非同期で取得して表示
  // loadChatDataForVideo()はチャット検索パネルのステータスを副作用で更新するため、
  // ここでは件数のカウントのみを行う専用処理を使う
  (async () => {
    try {
      const videoId = getVideoId();
      if (!videoId) {
        setStatus('hlp-status-chats', 'ng', '動画ID不明');
        return;
      }
      await initChatDB();
      const count = await new Promise((resolve, reject) => {
        const tx = chatSearchDB.transaction([CHAT_STORE_NAME], 'readonly');
        const req = tx.objectStore(CHAT_STORE_NAME).index('videoId').count(videoId);
        req.onsuccess = () => resolve(req.result || 0);
        req.onerror = () => reject(req.error);
      });
      if (count > 0) {
        setStatus('hlp-status-chats', 'ok', `${count}件（取得済み）`);
      } else {
        setStatus('hlp-status-chats', 'ng', '未取得（💬ボタンで取得）');
      }
    } catch (e) {
      setStatus('hlp-status-chats', 'ng', '確認失敗');
    }
  })();
  setStatus(
    'hlp-status-duration',
    videoDuration > 0 ? 'ok' : 'ng',
    videoDuration > 0 ? `${Math.round(videoDuration)}秒` : '不明'
  );

  // 検出ボタンの活性化条件: 動画長 > 0 かつ 音量データ or 字幕 or（コメントは取得時に確認）どれかある
  const detectBtn = highlightPanel.querySelector('#hlp-detect-btn');
  if (detectBtn) {
    detectBtn.disabled = !(videoDuration > 0);
  }
}

/**
 * ハイライト検出APIを呼び出す
 */
async function detectHighlights() {
  const statusEl = highlightPanel?.querySelector('#hlp-status');
  const detectBtn = highlightPanel?.querySelector('#hlp-detect-btn');
  const resultsEl = highlightPanel?.querySelector('#hlp-results');

  if (!statusEl || !detectBtn || !resultsEl) return;
  // ダブルクリック・連打による多重実行を防止
  if (detectBtn.disabled) return;
  detectBtn.disabled = true;

  try {
    const videoId = getVideoId();
    if (!videoId) {
      setHighlightStatus('動画IDが取得できません', true);
      return;
    }
    if (!videoDuration || videoDuration <= 0) {
      setHighlightStatus('動画長が取得できていません。動画を少し再生してください', true);
      return;
    }

    // API設定を読み込み
    if (!ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!ycsApiToken) {
      setHighlightStatus('APIトークンが未設定です。拡張ポップアップで設定してください', true);
      return;
    }

    setHighlightStatus('データを収集中', false, true);

    // チャットデータをIndexedDBから取得
    let rawChats = [];
    try {
      await initChatDB();
      rawChats = await loadChatDataForVideo(videoId);
    } catch (e) {
      console.warn('[YCS Highlight] チャットDB読込失敗:', e);
    }
    const chats = (rawChats || []).map(c => ({
      offsetMs: c.timestamp,
      message: c.message,
      isSuperchat: !!c.isSuperchat,
    })).filter(c => Number.isFinite(c.offsetMs) && typeof c.message === 'string' && c.message.length > 0);

    const subtitlesPayload = (currentSubtitles || []).map(s => ({
      start: Number(s.start) || 0,
      // サーバーバリデーション (max:60) に合わせてクランプ
      duration: Math.min(60, Math.max(0, Number(s.duration) || 0)),
      text: String(s.text ?? ''),
    })).filter(s => s.text.length > 0);

    const volumesPayload = (volumeData || []).map(v => {
      if (typeof v === 'number') return v;
      // 万一オブジェクトで保存されていた場合のフォールバック
      return Number.isFinite(v?.value) ? v.value : 0;
    });

    if (volumesPayload.length === 0 && subtitlesPayload.length === 0 && chats.length === 0) {
      setHighlightStatus('音量・字幕・コメントのいずれも未取得です', true);
      return;
    }

    setHighlightStatus(
      `送信中 (音量${volumesPayload.length} / 字幕${subtitlesPayload.length} / コメント${chats.length})`,
      false,
      true
    );

    const response = await fetch(`${ycsServerUrl}/api/extension/highlights/detect`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${ycsApiToken}`,
      },
      body: JSON.stringify({
        video_id: videoId,
        duration: videoDuration,
        volumes: volumesPayload,
        subtitles: subtitlesPayload,
        chats: chats,
      }),
    });

    if (!response.ok) {
      let errorMsg = `HTTP ${response.status}`;
      try {
        const errBody = await response.json();
        if (errBody?.message) errorMsg += `: ${errBody.message}`;
      } catch (_) { /* ignore */ }
      throw new Error(errorMsg);
    }

    const data = await response.json();
    const candidates = data?.candidates || [];
    renderHighlightResults(candidates);
    setHighlightStatus(`${candidates.length}件の候補を検出しました`, false);
    // 自動保存（次回パネルを開いた時に自動復元される）
    saveHighlightResult(videoId, candidates);
  } catch (error) {
    console.error('[YCS Highlight] 検出エラー:', error);
    setHighlightStatus(`検出に失敗しました: ${error.message}`, true);
  } finally {
    detectBtn.disabled = false;
  }
}

function setHighlightStatus(text, isError, isLoading) {
  const statusEl = highlightPanel?.querySelector('#hlp-status');
  if (!statusEl) return;
  statusEl.textContent = text;
  statusEl.classList.toggle('error', !!isError);
  statusEl.classList.toggle('loading', !!isLoading);
}

// ツールチップ消失の遅延タイマー（カード→ツールチップへの移動時の取りこぼし防止）
let highlightTooltipHideTimer = null;

/**
 * ハイライト候補ホバー時の字幕ツールチップを取得（無ければ作成）
 * ツールチップ自体にもmouseenter/mouseleaveを付けて、スクロールバー操作中に
 * 消えないようにする
 */
function getOrCreateHighlightTooltip() {
  let tooltip = document.getElementById('ycs-highlight-subtitle-tooltip');
  if (!tooltip) {
    tooltip = document.createElement('div');
    tooltip.id = 'ycs-highlight-subtitle-tooltip';
    document.body.appendChild(tooltip);
    // ツールチップ上にマウスがある間は非表示タイマーをキャンセル
    tooltip.addEventListener('mouseenter', () => {
      if (highlightTooltipHideTimer) {
        clearTimeout(highlightTooltipHideTimer);
        highlightTooltipHideTimer = null;
      }
    });
    // ツールチップから離れたら非表示
    tooltip.addEventListener('mouseleave', () => {
      hideHighlightSubtitleTooltip();
    });
  }
  return tooltip;
}

/**
 * 指定時刻範囲（前後30秒の余白付き）に含まれる字幕を抽出
 * @param {number} startSec - 区間の開始秒
 * @param {number} endSec - 区間の終了秒
 * @param {number} marginSec - 前後の余白秒
 * @returns {Array<{start:number, duration:number, text:string, inRange:boolean}>}
 */
function getSubtitlesAround(startSec, endSec, marginSec = 30) {
  if (!Array.isArray(currentSubtitles) || currentSubtitles.length === 0) return [];
  const fromSec = Math.max(0, startSec - marginSec);
  const toSec = endSec + marginSec;
  const result = [];
  for (const sub of currentSubtitles) {
    const subStart = Number(sub.start) || 0;
    const subEnd = subStart + (Number(sub.duration) || 0);
    // 字幕区間が表示範囲と重なるか
    if (subEnd >= fromSec && subStart <= toSec) {
      const inRange = subStart <= endSec && subEnd >= startSec;
      result.push({
        start: subStart,
        duration: Number(sub.duration) || 0,
        text: String(sub.text || ''),
        inRange,
      });
    }
  }
  return result;
}

/**
 * ハイライト候補にホバーした時のツールチップ表示
 */
function showHighlightSubtitleTooltip(candidate, anchorEl) {
  const tooltip = getOrCreateHighlightTooltip();
  // 表示要求が来たら消失タイマーはキャンセル
  if (highlightTooltipHideTimer) {
    clearTimeout(highlightTooltipHideTimer);
    highlightTooltipHideTimer = null;
  }
  const startSec = Number(candidate.time) || 0;
  const endSec = Number.isFinite(candidate.end_time) ? Number(candidate.end_time) : startSec;

  if (!Array.isArray(currentSubtitles) || currentSubtitles.length === 0) {
    tooltip.innerHTML = `
      <div class="hlp-tooltip-title">前後30秒の字幕</div>
      <div class="hlp-tooltip-empty">字幕が未取得です（📝ボタンで取得してください）</div>
    `;
  } else {
    const subs = getSubtitlesAround(startSec, endSec, 30);
    if (subs.length === 0) {
      tooltip.innerHTML = `
        <div class="hlp-tooltip-title">前後30秒の字幕</div>
        <div class="hlp-tooltip-empty">この区間に字幕はありません</div>
      `;
    } else {
      const lines = subs.map(s => {
        const timeStr = formatSubtitleTime(Math.floor(s.start));
        return `<div class="hlp-tooltip-line ${s.inRange ? 'in-range' : ''}">
          <span class="hlp-tooltip-time">${timeStr}</span>
          <span class="hlp-tooltip-text">${escapeHtml(s.text)}</span>
        </div>`;
      }).join('');
      tooltip.innerHTML = `
        <div class="hlp-tooltip-title">字幕（区間±30秒、太字は区間内）</div>
        ${lines}
      `;
    }
  }

  // 位置決め: カードの右側に配置、右が画面外ならカードの左側に
  const rect = anchorEl.getBoundingClientRect();
  const tooltipWidth = 360; // max-widthと一致
  const margin = 8;
  let left = rect.right + margin;
  if (left + tooltipWidth > window.innerWidth) {
    left = Math.max(8, rect.left - tooltipWidth - margin);
  }
  tooltip.style.left = `${left}px`;
  // 縦位置決定のために実高さが必要だが、ユーザーに位置決定途中が見えないよう
  // 一時的に visibility: hidden にしてレイアウトのみ走らせる
  tooltip.style.visibility = 'hidden';
  tooltip.style.top = '0px';
  tooltip.classList.add('visible');
  const tooltipHeight = tooltip.offsetHeight;
  const top = Math.min(
    Math.max(8, window.innerHeight - tooltipHeight - 8),
    Math.max(8, rect.top)
  );
  tooltip.style.top = `${top}px`;
  tooltip.style.visibility = '';
}

function hideHighlightSubtitleTooltip() {
  if (highlightTooltipHideTimer) {
    clearTimeout(highlightTooltipHideTimer);
    highlightTooltipHideTimer = null;
  }
  const tooltip = document.getElementById('ycs-highlight-subtitle-tooltip');
  if (tooltip) tooltip.classList.remove('visible');
}

/**
 * カードから離れた時に少し待ってからツールチップを非表示にする
 * （ツールチップ上にマウスが移動した場合は mouseenter でキャンセルされる）
 */
function scheduleHideHighlightSubtitleTooltip() {
  if (highlightTooltipHideTimer) {
    clearTimeout(highlightTooltipHideTimer);
  }
  highlightTooltipHideTimer = setTimeout(() => {
    hideHighlightSubtitleTooltip();
  }, 200);
}

/**
 * ハイライト保存用IndexedDBを初期化
 * keyPath: 'videoId' なので、同じ動画で再検出すると自動的に上書き保存される
 */
function initHighlightDB() {
  return new Promise((resolve, reject) => {
    if (highlightDB) {
      resolve(highlightDB);
      return;
    }
    const request = indexedDB.open(HIGHLIGHT_DB_NAME, HIGHLIGHT_DB_VERSION);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      highlightDB = request.result;
      resolve(highlightDB);
    };
    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains(HIGHLIGHT_STORE_NAME)) {
        const store = db.createObjectStore(HIGHLIGHT_STORE_NAME, { keyPath: 'videoId' });
        store.createIndex('savedAt', 'savedAt', { unique: false });
      }
    };
  });
}

/**
 * ハイライト検出結果をIndexedDBに保存（最新1件で上書き）
 */
async function saveHighlightResult(videoId, candidates) {
  if (!videoId || !Array.isArray(candidates) || candidates.length === 0) return;
  try {
    await initHighlightDB();
    const tx = highlightDB.transaction([HIGHLIGHT_STORE_NAME], 'readwrite');
    const store = tx.objectStore(HIGHLIGHT_STORE_NAME);
    store.put({
      videoId,
      candidates,
      savedAt: Date.now(),
    });
  } catch (e) {
    console.warn('[YCS Highlight] 保存に失敗:', e);
  }
}

/**
 * 古いハイライト検出結果を削除（HIGHLIGHT_MAX_AGE_DAYS日以上前のレコード）
 * 起動時に1回だけ実行される
 */
async function cleanupOldHighlightData() {
  try {
    await initHighlightDB();
    const cutoffMs = Date.now() - HIGHLIGHT_MAX_AGE_DAYS * 24 * 60 * 60 * 1000;
    const tx = highlightDB.transaction([HIGHLIGHT_STORE_NAME], 'readwrite');
    const store = tx.objectStore(HIGHLIGHT_STORE_NAME);
    const index = store.index('savedAt');
    const range = IDBKeyRange.upperBound(cutoffMs);
    const request = index.openCursor(range);
    let deletedCount = 0;
    request.onsuccess = () => {
      const cursor = request.result;
      if (cursor) {
        cursor.delete();
        deletedCount++;
        cursor.continue();
      }
    };
    await new Promise((resolve) => {
      tx.oncomplete = () => {
        if (deletedCount > 0) {
          console.log(`[YCS] ${deletedCount}件の古いハイライト結果を削除しました`);
        }
        resolve();
      };
    });
  } catch (e) {
    console.error('[YCS Highlight] クリーンアップエラー:', e);
  }
}

/**
 * IndexedDBから指定videoIdのハイライト検出結果を取得
 * @returns {Promise<{videoId, candidates, savedAt}|null>}
 */
async function loadHighlightResult(videoId) {
  if (!videoId) return null;
  try {
    await initHighlightDB();
    return await new Promise((resolve, reject) => {
      const tx = highlightDB.transaction([HIGHLIGHT_STORE_NAME], 'readonly');
      const req = tx.objectStore(HIGHLIGHT_STORE_NAME).get(videoId);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  } catch (e) {
    console.warn('[YCS Highlight] 読込に失敗:', e);
    return null;
  }
}

function renderHighlightResults(candidates) {
  const resultsEl = highlightPanel?.querySelector('#hlp-results');
  if (!resultsEl) return;
  resultsEl.innerHTML = '';

  if (!candidates || candidates.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'hlp-empty';
    empty.textContent = '候補が見つかりませんでした';
    resultsEl.appendChild(empty);
    return;
  }

  // 時刻順（既にサーバー側でソート済みのはずだが念のため）
  const sorted = [...candidates].sort((a, b) => (a.time || 0) - (b.time || 0));

  const fragment = document.createDocumentFragment();
  for (const c of sorted) {
    const item = document.createElement('div');
    item.className = 'hlp-result-item';

    const head = document.createElement('div');
    head.className = 'hlp-result-head';

    const time = document.createElement('span');
    time.className = 'hlp-result-time';
    time.textContent = formatSubtitleTime(Math.floor(c.time || 0));

    const type = document.createElement('span');
    type.className = 'hlp-result-type';
    type.textContent = c.type || 'other';

    const conf = document.createElement('span');
    conf.className = 'hlp-result-confidence';
    const confValue = Number.isFinite(c.confidence) ? c.confidence : 0;
    conf.textContent = `score ${(confValue * 100).toFixed(0)}%`;

    head.appendChild(time);
    head.appendChild(type);
    head.appendChild(conf);

    const label = document.createElement('div');
    label.className = 'hlp-result-label';
    label.textContent = c.label || '(ラベルなし)';

    const reason = document.createElement('div');
    reason.className = 'hlp-result-reason';
    reason.textContent = c.reason || '';

    item.appendChild(head);
    item.appendChild(label);
    if (reason.textContent) {
      item.appendChild(reason);
    }

    item.addEventListener('click', () => {
      if (videoElement && Number.isFinite(c.time)) {
        videoElement.currentTime = c.time;
      }
    });

    // ホバー時に区間前後の字幕をツールチップ表示
    // mouseleave時は遅延付きで非表示にし、その間にツールチップ上に移動すれば消えない
    item.addEventListener('mouseenter', () => {
      showHighlightSubtitleTooltip(c, item);
    });
    item.addEventListener('mouseleave', () => {
      scheduleHideHighlightSubtitleTooltip();
    });

    fragment.appendChild(item);
  }
  resultsEl.appendChild(fragment);
}

/**
 * 現在の動画IDを取得
 */
function getVideoId() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get('v');
}

/**
 * ストレージの容量を確認し、上限に近い場合は古いデータを削除
 * @returns {Promise<void>}
 */
async function ensureStorageCapacity() {
  const STORAGE_LIMIT = 10 * 1024 * 1024; // 10MB
  const THRESHOLD = 0.8; // 80%で削除開始

  return new Promise((resolve) => {
    chrome.storage.local.getBytesInUse(null, (bytesInUse) => {
      if (bytesInUse < STORAGE_LIMIT * THRESHOLD) {
        resolve();
        return;
      }

      console.warn(`ストレージ使用量が上限の${Math.round(bytesInUse / STORAGE_LIMIT * 100)}%に達しています。古いデータを削除します。`);

      chrome.storage.local.get(null, (allData) => {
        const volumeEntries = [];
        for (const key in allData) {
          if (key.startsWith('volumeData_') && allData[key]?.savedAt) {
            volumeEntries.push({ key, savedAt: allData[key].savedAt });
          }
        }

        // 古い順にソート
        volumeEntries.sort((a, b) => a.savedAt - b.savedAt);

        // 古いものから1/4を削除
        const deleteCount = Math.max(1, Math.floor(volumeEntries.length / 4));
        const keysToDelete = volumeEntries.slice(0, deleteCount).map(e => e.key);

        if (keysToDelete.length > 0) {
          chrome.storage.local.remove(keysToDelete, () => {
            console.log(`ストレージ容量確保のため${keysToDelete.length}件の古い波形データを削除しました`);
            resolve();
          });
        } else {
          resolve();
        }
      });
    });
  });
}

/**
 * 音量データをストレージに保存
 */
async function saveVolumeData() {
  const videoId = getVideoId();
  if (!videoId || volumeData.length === 0) return;

  // 容量チェック・古いデータの自動削除
  await ensureStorageCapacity();

  const storageKey = `volumeData_${videoId}`;
  const dataToSave = {
    version: 2, // v2: 等間隔サンプリング（v1/未指定: 固定500ポイント）
    samplingInterval: SAMPLING_INTERVAL_SEC,
    data: volumeData,
    duration: videoDuration,
    timestamps: detectedTimestamps,
    savedAt: Date.now()
  };

  chrome.storage.local.set({ [storageKey]: dataToSave }, () => {
    if (chrome.runtime.lastError) {
      console.error(`音量データの保存に失敗しました: ${chrome.runtime.lastError.message}`);
      return;
    }
    console.log(`音量データを保存しました: ${videoId} (${volumeData.length}サンプル, ${detectedTimestamps.length}候補)`);
  });
}

/**
 * 保存された音量データを読み込み
 */
function loadVolumeData() {
  const videoId = getVideoId();
  if (!videoId) return;

  const storageKey = `volumeData_${videoId}`;
  chrome.storage.local.get(storageKey, (result) => {
    const saved = result[storageKey];
    if (saved && saved.data && saved.data.length > 0) {
      volumeData = saved.data;
      if (saved.duration) {
        videoDuration = saved.duration;
      }
      // タイムスタンプも読み込み
      if (saved.timestamps && saved.timestamps.length > 0) {
        detectedTimestamps = saved.timestamps;
      }
      console.log(`音量データを読み込みました: ${videoId} (${volumeData.length}サンプル, ${detectedTimestamps.length}候補)`);
      drawVolumeGraph();

      // 進捗を計算してUIを更新
      getScanStatus().then(status => {
        updateProgress(status.progress);
        updateScanButtonState(status);
      });
    }
  });
}

/**
 * スキャンボタンの状態を更新
 */
function updateScanButtonState(status) {
  if (!volumeGraphContainer) return;
  const scanBtn = volumeGraphContainer.querySelector('#vdg-scan-btn');
  if (!scanBtn) return;

  if (status.isComplete) {
    scanBtn.textContent = '完了';
    scanBtn.title = 'スキャン完了済み（クリアボタンでリセット可能）';
    scanBtn.style.background = '#2e7d32'; // 緑
  } else if (status.hasData && status.progress > 0) {
    scanBtn.textContent = `再開 (${status.progress.toFixed(0)}%)`;
    scanBtn.title = `${status.progress.toFixed(1)}%完了 - クリックで続きをスキャン`;
    scanBtn.style.background = '#f57c00'; // オレンジ
  } else {
    scanBtn.textContent = 'スキャン';
    scanBtn.title = '動画全体をスキャンしてグラフを生成';
    scanBtn.style.background = '#333';
  }
}

/**
 * 権限エラーメッセージを表示
 */
function showPermissionError() {
  if (!volumeGraphContainer) return;

  // 既にメッセージがあれば何もしない
  if (volumeGraphContainer.querySelector('.vdg-permission-error')) return;

  const message = document.createElement('div');
  message.className = 'vdg-permission-error';
  message.innerHTML = `
    <div style="
      background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
      border: 1px solid #ffb74d;
      border-radius: 8px;
      padding: 12px 16px;
      margin: 8px 0;
      font-size: 13px;
      color: #e65100;
      display: flex;
      align-items: center;
      gap: 10px;
    ">
      <span style="font-size: 18px;">⚠️</span>
      <div>
        <div style="font-weight: 600; margin-bottom: 4px;">スキャンを開始できません</div>
        <div style="font-size: 12px; color: #f57c00;">
          拡張機能アイコンをクリックしてポップアップを開き、<br>
          「スキャン開始」ボタンからスキャンしてください。
        </div>
      </div>
    </div>
  `;

  // グラフコンテナの先頭に挿入
  const graphContainer = volumeGraphContainer.querySelector('.vdg-canvas-container');
  if (graphContainer) {
    graphContainer.parentNode.insertBefore(message, graphContainer);
  } else {
    volumeGraphContainer.appendChild(message);
  }
}

/**
 * 権限エラーメッセージを非表示
 */
function hidePermissionError() {
  if (!volumeGraphContainer) return;
  const errorMsg = volumeGraphContainer.querySelector('.vdg-permission-error');
  if (errorMsg) {
    errorMsg.remove();
  }
}

/**
 * 保存された音量データを削除
 */
function deleteVolumeData() {
  const videoId = getVideoId();
  if (!videoId) return;

  const storageKey = `volumeData_${videoId}`;
  chrome.storage.local.remove(storageKey, () => {
    console.log(`音量データを削除しました: ${videoId}`);
  });
}

// タイムスタンプ検出パラメータ（デフォルト値）
let detectParams = {
  volumeThreshold: 0.15,    // 音量のしきい値（0-1）
  quietThreshold: 0.05,     // 静かな状態と判定する音量
  quietMinDuration: 1.0,    // 静かな状態が続く最小時間（秒）
  cooldown: 3.0             // 連続検出を防ぐクールダウン（秒）
};

/**
 * 保存された音量データからタイムスタンプを再検出
 * 注意: グラフ用データはRMS*5で正規化されているため、元のスケールに戻して検出
 */
function redetectTimestamps() {
  if (!volumeData || volumeData.length === 0 || !videoDuration) {
    console.log('再検出: 音量データがありません');
    return;
  }

  // 既存のタイムスタンプをクリア
  detectedTimestamps = [];

  const secondsPerSample = videoDuration / volumeData.length;
  let quietDuration = 0;
  let lastDetectionTime = -detectParams.cooldown;

  // グラフ用データはRMS*5で正規化されているので、元のスケールに戻す
  const NORMALIZATION_FACTOR = 5;

  console.log('再検出開始', {
    params: detectParams,
    dataLength: volumeData.length,
    duration: videoDuration,
    secondsPerSample,
    normalizationFactor: NORMALIZATION_FACTOR
  });

  for (let i = 0; i < volumeData.length; i++) {
    // 正規化を解除して元のRMSスケールに戻す
    const volume = volumeData[i] / NORMALIZATION_FACTOR;
    const currentTime = i * secondsPerSample;

    // 静かな状態をトラッキング
    if (volume < detectParams.quietThreshold) {
      quietDuration += secondsPerSample;
    } else {
      // 静かな状態から急に大きくなった場合
      if (quietDuration >= detectParams.quietMinDuration &&
          volume > detectParams.volumeThreshold &&
          (currentTime - lastDetectionTime) > detectParams.cooldown) {

        const timestamp = {
          time: currentTime,
          formattedTime: formatTime(currentTime),
          volume: volume,
          detectedAt: new Date().toISOString()
        };

        detectedTimestamps.push(timestamp);
        lastDetectionTime = currentTime;

        console.log('タイムスタンプ検出:', timestamp.formattedTime, 'volume:', volume.toFixed(4));
      }
      quietDuration = 0;
    }
  }

  console.log(`再検出完了: ${detectedTimestamps.length}件のタイムスタンプを検出`);

  // グラフを再描画（マーカー更新のため）
  drawVolumeGraph();

  // データを保存
  saveVolumeData();
}

/**
 * 秒数を MM:SS または HH:MM:SS 形式に変換
 */
function formatTime(seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);

  if (h > 0) {
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }
  return `${m}:${s.toString().padStart(2, '0')}`;
}

/**
 * 再生リストIDを取得
 */
function getPlaylistId() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get('list');
}

/**
 * 再生リスト内かどうかを判定
 */
function isInPlaylist() {
  return !!getPlaylistId();
}

/**
 * 再生リストの情報を取得
 */
function getPlaylistInfo() {
  const playlistPanel = document.querySelector('ytd-playlist-panel-renderer');
  if (!playlistPanel) return null;

  const items = playlistPanel.querySelectorAll('ytd-playlist-panel-video-renderer');
  const currentIndex = Array.from(items).findIndex(item =>
    item.hasAttribute('selected') || item.querySelector('[selected]')
  );

  return {
    total: items.length,
    currentIndex: currentIndex >= 0 ? currentIndex : 0
  };
}

/**
 * 次の動画へ移動
 */
function goToNextVideo() {
  const nextButton = document.querySelector('.ytp-next-button');
  if (nextButton) {
    nextButton.click();
    return true;
  }
  return false;
}

/**
 * 現在の動画が既にスキャン済みかチェック
 */
function isCurrentVideoScanned() {
  return new Promise((resolve) => {
    const videoId = getVideoId();
    if (!videoId) {
      resolve(false);
      return;
    }
    const storageKey = `volumeData_${videoId}`;
    chrome.storage.local.get(storageKey, (result) => {
      resolve(!!result[storageKey]);
    });
  });
}

/**
 * スキャン状況を取得
 * @returns {Promise<{hasData: boolean, progress: number, resumeTime: number, isComplete: boolean}>}
 */
function getScanStatus() {
  return new Promise((resolve) => {
    const videoId = getVideoId();
    if (!videoId) {
      resolve({ hasData: false, progress: 0, resumeTime: 0, isComplete: false });
      return;
    }

    const storageKey = `volumeData_${videoId}`;
    chrome.storage.local.get(storageKey, (result) => {
      const saved = result[storageKey];
      if (!saved || !saved.data || saved.data.length === 0) {
        resolve({ hasData: false, progress: 0, resumeTime: 0, isComplete: false });
        return;
      }

      const data = saved.data;
      const duration = saved.duration || 0;
      const resolution = data.length;

      // 進捗を計算（データがある部分の割合）
      let lastFilledIndex = -1;
      for (let i = data.length - 1; i >= 0; i--) {
        if (data[i] > 0) {
          lastFilledIndex = i;
          break;
        }
      }

      const progress = lastFilledIndex >= 0
        ? ((lastFilledIndex + 1) / resolution) * 100
        : 0;

      // 95%以上なら完了とみなす
      const isComplete = progress >= 95;

      // 再開時刻を計算
      const resumeTime = duration > 0 && lastFilledIndex >= 0
        ? (lastFilledIndex / resolution) * duration
        : 0;

      resolve({
        hasData: true,
        progress: progress,
        resumeTime: resumeTime,
        isComplete: isComplete,
        data: data,
        duration: duration
      });
    });
  });
}

/**
 * 現在のページがYouTubeの動画視聴ページかどうか判定
 */
function isWatchPage() {
  return location.pathname === '/watch';
}

/**
 * watchページ用のUI初期化
 * SPA遷移でwatchページに来た場合にも呼ばれる
 */
function initWatchPageUI() {
  // 動画要素を取得
  findVideoElement();

  // 音量グラフを作成
  createVolumeGraph();

  // 埋め込みトリガーボタンを作成
  createEmbeddedTriggerButton();

  // 埋め込みUI設定に従ってボタンを表示/非表示
  if (embeddedUIVisible) {
    showEmbeddedUI();
  } else {
    hideEmbeddedUI();
  }

  // 音量グラフを挿入（SPA遷移時はDOMが変わるため再挿入）
  insertVolumeGraph();

  // リストスキャンモードをチェック
  checkAndStartListScan();

  // 字幕一括取得スキャンをチェック
  checkAndStartSubtitleScan();
}

/**
 * watchページから離れる際のUI非表示処理
 */
function hideWatchPageUI() {
  if (embeddedTriggerButton) {
    embeddedTriggerButton.classList.add('hidden');
  }
  hideVolumeGraphPanel();

  // スキャン中なら停止
  if (isScanning) {
    chrome.runtime.sendMessage({ type: 'STOP_SCAN' });
  }

}

// 初期化
async function init() {
  // メッセージリスナーを最初に設定（他の処理でエラーが出ても通信可能にする）
  chrome.runtime.onMessage.addListener(handleMessage);

  // ストレージ変更リスナー（2窓リアルタイム同期用）
  chrome.storage.onChanged.addListener(handleStorageChange);

  try {
    // 埋め込みUI設定・グラフ高さ設定を読み込み（UI構築前に反映する）
    await loadEmbeddedUISettings();
    await loadGraphHeightSettings();

    // watchページの場合のみUI初期化
    if (isWatchPage()) {
      initWatchPageUI();
    }

    // YouTube SPAナビゲーション対応（全ページで監視）
    observePageChanges();
  } catch (error) {
    console.error('Content script初期化エラー:', error);
  }
}

/**
 * ストレージ変更を監視（別タブでのスキャン結果をリアルタイム反映）
 */
function handleStorageChange(changes, areaName) {
  if (areaName !== 'local') return;

  // グラフ高さ設定の変更を即時反映
  if (changes.graphBaseHeight || changes.graphHeightStep) {
    if (changes.graphBaseHeight) {
      graphBaseHeightPx = clampGraphHeightValue(
        changes.graphBaseHeight.newValue, DEFAULT_GRAPH_BASE_HEIGHT_PX, GRAPH_BASE_HEIGHT_RANGE);
    }
    if (changes.graphHeightStep) {
      graphHeightStepPx = clampGraphHeightValue(
        changes.graphHeightStep.newValue, DEFAULT_GRAPH_HEIGHT_STEP_PX, GRAPH_HEIGHT_STEP_RANGE);
    }
    if (volumeGraphContainer) {
      resizeCanvas();
    }
  }

  // 埋め込みUI設定の変更を監視
  if (changes.showEmbeddedUI) {
    const showUI = changes.showEmbeddedUI.newValue !== false;
    if (showUI) {
      showEmbeddedUI();
    } else {
      hideEmbeddedUI();
    }
  }

  const videoId = getVideoId();
  if (!videoId) return;

  const storageKey = `volumeData_${videoId}`;

  // 現在の動画のデータが更新された場合
  if (changes[storageKey]) {
    const newData = changes[storageKey].newValue;
    if (newData && newData.data && newData.data.length > 0) {
      // 自分自身のスキャン中は無視（自分の保存による更新）
      if (isScanning) return;

      console.log(`他タブからの音量データを受信: ${videoId}`);

      // データを更新
      volumeData = newData.data;
      if (newData.duration) {
        videoDuration = newData.duration;
      }

      // タイムスタンプも更新
      if (newData.timestamps) {
        detectedTimestamps = newData.timestamps;
      }

      // グラフを再描画
      drawVolumeGraph();

      // 進捗表示を更新
      const progress = volumeGraphContainer?.querySelector('#vdg-progress');
      if (progress && videoDuration > 0) {
        const coverage = volumeData.length > 0
          ? (volumeData.filter(v => v > 0).length / volumeData.length) * 100
          : 0;
        progress.textContent = `分析 ${Math.round(coverage)}%`;
      }
    }
  }
}

/**
 * リストスキャン続行ボタンを表示
 */
function showListScanButton(currentIndex, totalCount) {
  // 既存のボタンがあれば更新のみ
  if (listScanButtonContainer) {
    const info = listScanButtonContainer.querySelector('.list-scan-info');
    if (info) {
      info.textContent = `動画 ${currentIndex + 1} / ${totalCount}`;
    }
    listScanButtonContainer.style.display = 'flex';
    return;
  }

  // コンテナを作成
  listScanButtonContainer = document.createElement('div');
  listScanButtonContainer.id = 'list-scan-button-container';
  listScanButtonContainer.innerHTML = `
    <style>
      #list-scan-button-container {
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 9999;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        display: flex;
        flex-direction: column;
        gap: 12px;
        font-family: 'Roboto', sans-serif;
        animation: slideIn 0.3s ease-out;
      }
      @keyframes slideIn {
        from { transform: translateX(100px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      #list-scan-button-container .list-scan-title {
        color: white;
        font-size: 14px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      #list-scan-button-container .list-scan-info {
        color: rgba(255,255,255,0.9);
        font-size: 13px;
      }
      #list-scan-button-container .list-scan-btn {
        background: white;
        color: #667eea;
        border: none;
        border-radius: 8px;
        padding: 12px 24px;
        font-size: 14px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.2s;
      }
      #list-scan-button-container .list-scan-btn:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      }
      #list-scan-button-container .list-scan-btn:active {
        transform: scale(0.98);
      }
      #list-scan-button-container .list-scan-btn.scanning {
        background: #ff5722;
        color: white;
      }
      #list-scan-button-container .list-scan-cancel {
        background: transparent;
        color: rgba(255,255,255,0.8);
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 6px;
        padding: 8px 16px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
      }
      #list-scan-button-container .list-scan-cancel:hover {
        background: rgba(255,255,255,0.1);
        color: white;
      }
      #list-scan-button-container .list-scan-countdown {
        color: #ffeb3b;
        font-size: 12px;
        text-align: center;
        animation: pulse 1s infinite;
      }
      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
      }
    </style>
    <div class="list-scan-title">
      📋 リストスキャン
    </div>
    <div class="list-scan-info">動画 ${currentIndex + 1} / ${totalCount}</div>
    <div class="list-scan-countdown" id="list-scan-countdown">3秒後に自動開始...</div>
    <button class="list-scan-btn" id="list-scan-start-btn">▶ スキャン開始</button>
    <button class="list-scan-cancel" id="list-scan-cancel-btn">キャンセル</button>
  `;

  document.body.appendChild(listScanButtonContainer);

  // イベントリスナー
  const startBtn = listScanButtonContainer.querySelector('#list-scan-start-btn');
  const cancelBtn = listScanButtonContainer.querySelector('#list-scan-cancel-btn');
  const countdown = listScanButtonContainer.querySelector('#list-scan-countdown');

  const startScan = () => {
    // タイマーをクリア
    clearAutoClickTimer();
    if (countdown) countdown.style.display = 'none';
    startBtn.classList.add('scanning');
    startBtn.textContent = '⏳ スキャン中...';
    chrome.runtime.sendMessage({ type: 'START_SCAN' });
  };

  startBtn.addEventListener('click', startScan);

  cancelBtn.addEventListener('click', async () => {
    clearAutoClickTimer();
    isListScanMode = false;
    await chrome.storage.local.set({ listScanActive: false });
    hideListScanButton();
    console.log('リストスキャン: キャンセルされました');
  });

  // 自動クリックタイマーを開始
  startAutoClickTimer(startScan, countdown);
}

/**
 * リストスキャン続行ボタンを非表示
 */
function hideListScanButton() {
  clearAutoClickTimer();
  if (listScanButtonContainer) {
    listScanButtonContainer.style.display = 'none';
  }
}

/**
 * 自動クリックタイマーを開始
 */
function startAutoClickTimer(callback, countdownElement) {
  clearAutoClickTimer();

  let remaining = LIST_SCAN_AUTO_CLICK_DELAY / 1000;

  // カウントダウン表示を更新
  const updateCountdown = () => {
    if (countdownElement) {
      countdownElement.textContent = `${remaining}秒後に自動開始...`;
    }
  };

  updateCountdown();

  // 1秒ごとにカウントダウン
  listScanCountdownInterval = setInterval(() => {
    remaining--;
    if (remaining > 0) {
      updateCountdown();
    } else {
      clearInterval(listScanCountdownInterval);
      listScanCountdownInterval = null;
    }
  }, 1000);

  // 指定時間後に自動クリック
  listScanAutoClickTimer = setTimeout(() => {
    clearInterval(listScanCountdownInterval);
    listScanCountdownInterval = null;
    console.log('リストスキャン: 自動クリック実行');
    callback();
  }, LIST_SCAN_AUTO_CLICK_DELAY);
}

/**
 * 自動クリックタイマーをクリア
 */
function clearAutoClickTimer() {
  if (listScanAutoClickTimer) {
    clearTimeout(listScanAutoClickTimer);
    listScanAutoClickTimer = null;
  }
  if (listScanCountdownInterval) {
    clearInterval(listScanCountdownInterval);
    listScanCountdownInterval = null;
  }
}

/**
 * リストスキャンボタンの状態を更新
 */
function updateListScanButtonState(scanning) {
  if (!listScanButtonContainer) return;
  const btn = listScanButtonContainer.querySelector('#list-scan-start-btn');
  if (!btn) return;

  if (scanning) {
    btn.classList.add('scanning');
    btn.textContent = '⏳ スキャン中...';
  } else {
    btn.classList.remove('scanning');
    btn.textContent = '▶ スキャン開始';
  }
}

/**
 * リストスキャンモードをチェックしてボタンを表示
 */
async function checkAndStartListScan() {
  try {
    const result = await chrome.storage.local.get([
      'listScanVideoIds',
      'listScanCurrentIndex',
      'listScanActive'
    ]);

    if (!result.listScanActive || !result.listScanVideoIds) {
      return;
    }

    const videoIds = result.listScanVideoIds;
    const currentIndex = result.listScanCurrentIndex || 0;
    const currentVideoId = getVideoId();

    // 現在の動画がリストに含まれているか確認
    const expectedVideoId = videoIds[currentIndex];
    if (currentVideoId !== expectedVideoId) {
      console.log('リストスキャン: 想定外のvideoIdです', { expected: expectedVideoId, actual: currentVideoId });
      return;
    }

    console.log(`リストスキャン: 動画 ${currentIndex + 1}/${videoIds.length} の準備中`);
    isListScanMode = true;

    // 動画の読み込みを待ってからボタンを表示
    waitForVideoAndShowButton(currentIndex, videoIds.length);
  } catch (error) {
    console.error('リストスキャンチェックエラー:', error);
  }
}

/**
 * 動画の読み込みを待ってリストスキャンボタンを表示
 */
function waitForVideoAndShowButton(currentIndex, totalCount) {
  const checkVideo = () => {
    if (videoElement && videoElement.readyState >= 2 && videoDuration > 0) {
      // 既にスキャン済みかチェック
      isCurrentVideoScanned().then(scanned => {
        if (scanned) {
          console.log('リストスキャン: 既にスキャン済み、次の動画へ');
          proceedToNextListScanVideo();
        } else {
          console.log('リストスキャン: ボタンを表示');
          // ボタンを表示（ユーザーのクリックでスキャン開始）
          showListScanButton(currentIndex, totalCount);
        }
      });
    } else {
      setTimeout(checkVideo, 1000);
    }
  };

  // 少し待ってからチェック開始（ページ読み込み完了を待つ）
  setTimeout(checkVideo, 2000);
}

/**
 * リストスキャンの次の動画へ移動
 */
async function proceedToNextListScanVideo() {
  if (!isListScanMode) {
    return;
  }

  try {
    const result = await chrome.storage.local.get([
      'listScanVideoIds',
      'listScanCurrentIndex'
    ]);

    const videoIds = result.listScanVideoIds || [];
    const currentIndex = result.listScanCurrentIndex || 0;
    const nextIndex = currentIndex + 1;

    if (nextIndex >= videoIds.length) {
      // 全て完了
      console.log('リストスキャン完了: すべての動画をスキャンしました');
      isListScanMode = false;
      hideListScanButton();
      await chrome.storage.local.set({
        listScanActive: false
      });
      // 完了メッセージをポップアップに通知
      chrome.runtime.sendMessage({ type: 'LIST_SCAN_COMPLETE' });
      return;
    }

    // インデックスを更新
    await chrome.storage.local.set({
      listScanCurrentIndex: nextIndex
    });

    // 次の動画へ移動
    const nextVideoId = videoIds[nextIndex];
    console.log(`リストスキャン: 次の動画へ移動 (${nextIndex + 1}/${videoIds.length}): ${nextVideoId}`);

    // 少し待ってから移動（安定性向上のため）
    setTimeout(() => {
      window.location.href = `https://www.youtube.com/watch?v=${nextVideoId}`;
    }, 1500);
  } catch (error) {
    console.error('リストスキャン次の動画移動エラー:', error);
    isListScanMode = false;
  }
}

/**
 * YouTube動画要素を見つける
 */
function findVideoElement() {
  const checkVideo = () => {
    videoElement = document.querySelector('video.html5-main-video');
    if (videoElement) {
      console.log('動画要素を検出しました');
      startTimeSync();
    } else {
      setTimeout(checkVideo, 1000);
    }
  };
  checkVideo();
}

/**
 * 再生時刻の同期を開始
 */
function startTimeSync() {
  if (timeUpdateInterval) {
    clearInterval(timeUpdateInterval);
  }

  // video要素へのイベント登録
  // YouTubeのSPA遷移では同じvideo要素が使い回されるため、startTimeSyncが
  // 再実行されても同一要素へ重複登録しないようにする（リスナーの積み上がり防止）
  if (videoListenerTarget !== videoElement) {
    videoListenerTarget = videoElement;

    // 動画のメタデータ読み込み時に長さを取得
    videoElement.addEventListener('loadedmetadata', () => {
      updateVideoDuration();
    });

    // 停止中のシークでも赤い再生位置ラインを追従させる
    // （通常の更新は再生中のみのインターバル処理のため、シーク完了時にも更新する）
    videoElement.addEventListener('seeked', () => {
      if (isGraphVisible) {
        updateTimeMarker();
      }
    });
  }

  // すでに読み込み済みの場合
  if (videoElement.duration) {
    updateVideoDuration();
  }

  timeUpdateInterval = setInterval(() => {
    if (videoElement && !videoElement.paused) {
      chrome.runtime.sendMessage({
        type: 'UPDATE_VIDEO_TIME',
        time: videoElement.currentTime
      });
      // 音量グラフの再生位置マーカーを更新
      if (isGraphVisible) {
        updateTimeMarker();
      }
    }
  }, 100);
}

/**
 * 音量グラフUIを作成
 */
function createVolumeGraph() {
  if (volumeGraphContainer) return;

  volumeGraphContainer = document.createElement('div');
  volumeGraphContainer.id = 'volume-dynamics-graph';
  volumeGraphContainer.innerHTML = `
    <style>
      #volume-dynamics-graph {
        position: relative !important;
        width: 100% !important;
        min-height: 60px !important;
        height: auto !important;
        background: rgba(0, 0, 0, 0.85) !important;
        border-radius: 4px !important;
        margin-top: 8px !important;
        margin-bottom: 8px !important;
        display: none !important;
        flex-direction: column !important;
        cursor: pointer !important;
        user-select: none !important;
        z-index: 1 !important;
        box-sizing: border-box !important;
      }

      #volume-dynamics-graph.visible {
        display: flex !important;
      }

      .vdg-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        font-size: 11px;
        color: #aaa;
        background: rgba(0, 0, 0, 0.5);
        border-radius: 4px 4px 0 0;
      }

      .vdg-title {
        font-weight: 500;
      }

      .vdg-controls {
        display: flex;
        gap: 8px;
        align-items: center;
      }

      .vdg-playlist-info {
        font-size: 10px;
        color: #4fc3f7;
        margin-right: 4px;
      }

      .vdg-btn {
        background: #333;
        border: none;
        color: #fff;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 10px;
        cursor: pointer;
        transition: background 0.2s;
      }

      .vdg-btn:hover {
        background: #555;
      }

      .vdg-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
      }

      .vdg-btn:disabled:hover {
        background: #333;
      }

      .vdg-btn.scanning {
        background: #c62828;
      }

      .vdg-btn.auto-scanning {
        background: #1565c0;
      }

      .vdg-btn.hidden {
        display: none;
      }

      .vdg-canvas-container {
        /* flex:1（=flex-basis:0%）にすると縦方向のflexアイテムではheightより
           flex-basisが優先され、高さ指定が一切効かなくなるためflex伸縮させない */
        flex: 0 0 auto;
        position: relative;
        /* 初期高さ。以降はresizeCanvas()がズームレベルに応じてインラインstyleで制御する
           （高さを未確定のままにすると、canvasの高さ属性やスクロールバーの出現が
             コンテナ高さの測定値に跳ね返り、ズームのたびに高さが増え続ける） */
        height: 60px;
        min-height: 40px;
        overflow-x: auto;
        overflow-y: hidden;
      }

      .vdg-canvas-container::-webkit-scrollbar {
        height: 6px;
      }

      .vdg-canvas-container::-webkit-scrollbar-track {
        background: #1a1a1a;
        border-radius: 3px;
      }

      .vdg-canvas-container::-webkit-scrollbar-thumb {
        background: #444;
        border-radius: 3px;
      }

      .vdg-canvas-container::-webkit-scrollbar-thumb:hover {
        background: #555;
      }

      .vdg-canvas-wrapper {
        position: relative;
        height: 100%;
        min-width: 100%;
      }

      #volume-canvas {
        height: 100%;
        display: block;
      }

      .vdg-time-marker {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #ff5722;
        pointer-events: none;
        z-index: 10;
      }

      .vdg-time-labels {
        display: flex;
        justify-content: space-between;
        padding: 2px 8px;
        font-size: 9px;
        color: #666;
        font-family: monospace;
      }

      .vdg-hover-time {
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0, 0, 0, 0.9);
        color: #fff;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-family: monospace;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s;
        z-index: 20;
      }

      #volume-dynamics-graph:hover .vdg-hover-time {
        opacity: 1;
      }

      .vdg-progress {
        font-size: 10px;
        color: #4fc3f7;
      }

      .vdg-zoom-info {
        font-size: 10px;
        color: #888;
        margin-left: 4px;
      }

      .vdg-volume-mode {
        font-size: 9px;
        padding: 2px 6px;
        margin-left: 4px;
        background: #333;
        border: 1px solid #555;
        border-radius: 3px;
        color: #aaa;
        cursor: pointer;
        transition: all 0.2s;
      }

      .vdg-volume-mode:hover {
        background: #444;
        color: #fff;
      }

      .vdg-volume-mode.active {
        background: #1b5e20;
        border-color: #4caf50;
        color: #4caf50;
      }

      /* タイムスタンプエディタ */
      .vdg-ts-editor {
        border-top: 1px solid #333;
        margin-top: 4px;
      }

      .vdg-ts-editor-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        font-size: 11px;
        color: #888;
      }

      .vdg-ts-mode-toggle {
        display: flex;
        border-radius: 4px;
        overflow: hidden;
        border: 1px solid #444;
      }

      .vdg-mode-btn {
        padding: 2px 8px;
        font-size: 10px;
        background: #222;
        color: #888;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
      }

      .vdg-mode-btn:hover {
        background: #333;
        color: #ccc;
      }

      .vdg-mode-btn.active {
        background: #1565c0;
        color: #fff;
      }

      .vdg-ts-editor-actions {
        display: flex;
        gap: 4px;
      }

      .vdg-btn-copy {
        background: #1565c0 !important;
      }

      .vdg-btn-copy:hover {
        background: #1976d2 !important;
      }

      .vdg-btn-clear-markers {
        background: #555 !important;
      }

      .vdg-btn-clear-markers:hover {
        background: #666 !important;
      }

      .vdg-ts-list {
        max-height: 200px;
        overflow-y: auto;
        /* 一覧の上下端に達してもYouTubeページ側へスクロールを伝播させない */
        overscroll-behavior-y: contain;
        padding: 0 4px;
      }

      .vdg-ts-empty {
        text-align: center;
        color: #555;
        font-size: 11px;
        padding: 12px 0;
      }

      .vdg-ts-row {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 3px 4px;
        border-radius: 3px;
        cursor: pointer;
        transition: background 0.15s;
      }

      .vdg-ts-row:hover {
        background: #2a2a2a;
      }

      .vdg-ts-row.selected {
        background: #1b3a1b;
        outline: 1px solid #4caf50;
      }

      .vdg-ts-time {
        font-family: monospace;
        font-size: 12px;
        color: #4fc3f7;
        flex-shrink: 0;
        min-width: 50px;
      }

      .vdg-ts-text-input {
        flex: 1;
        background: transparent;
        border: none;
        border-bottom: 1px solid #333;
        color: #ddd;
        font-size: 12px;
        padding: 2px 4px;
        outline: none;
        min-width: 0;
      }

      .vdg-ts-text-input:focus {
        border-bottom-color: #4fc3f7;
      }

      .vdg-ts-text-input::placeholder {
        color: #555;
      }

      .vdg-ts-offset-btn {
        background: #333;
        border: 1px solid #555;
        color: #ccc;
        font-size: 10px;
        padding: 1px 4px;
        border-radius: 3px;
        cursor: pointer;
        flex-shrink: 0;
        line-height: 1.2;
      }

      .vdg-ts-offset-btn:hover {
        background: #444;
        color: #fff;
      }

      .vdg-ts-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        border-top: 1px solid #2a2a2a;
      }

      .vdg-ts-help {
        font-size: 10px;
        color: #999;
      }

      .vdg-paste-popup {
        position: absolute;
        background: #222;
        border: 1px solid #555;
        border-radius: 4px;
        z-index: 100;
        min-width: 180px;
        max-width: 320px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        font-size: 11px;
      }

      .vdg-paste-popup-title {
        padding: 4px 8px;
        color: #888;
        font-size: 10px;
        border-bottom: 1px solid #444;
      }

      .vdg-paste-popup-item {
        padding: 5px 8px;
        color: #eee;
        cursor: pointer;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .vdg-paste-popup-item:hover {
        background: #444;
      }

      .vdg-paste-popup-item.raw {
        color: #999;
        border-top: 1px solid #444;
      }

      .vdg-ts-suggest-btn {
        background: #2a3a4a;
        border: 1px solid #456;
        color: #9cf;
        font-size: 10px;
        padding: 1px 4px;
        border-radius: 3px;
        cursor: pointer;
        flex-shrink: 0;
        line-height: 1.2;
      }

      .vdg-ts-suggest-btn:hover {
        background: #345;
        color: #cef;
      }

      .vdg-paste-popup-item .similarity {
        color: #8a8;
        font-size: 10px;
        margin-left: 6px;
      }

      .vdg-paste-popup-item .artist {
        color: #999;
        font-size: 10px;
        margin-left: 6px;
      }

      .vdg-paste-popup-item.message {
        color: #999;
        cursor: default;
      }

      .vdg-ts-format-toggle {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        color: #666;
        cursor: pointer;
      }

      .vdg-ts-format-toggle input {
        width: 12px;
        height: 12px;
        cursor: pointer;
      }

    </style>
    <div class="vdg-header">
      <span class="vdg-title">音量ダイナミクス</span>
      <div class="vdg-controls">
        <span class="vdg-playlist-info" id="vdg-playlist-info"></span>
        <span class="vdg-progress" id="vdg-progress" title="音量分析の完了率">分析 0%</span>
        <span class="vdg-zoom-info" id="vdg-zoom-info" title="グラフの表示倍率（Ctrl+ホイールで変更）">倍率 1x</span>
        <button class="vdg-volume-mode" id="vdg-volume-mode-btn" title="固定スケールでの絶対値表示中（クリックで相対表示に切替）">絶対</button>
        <button class="vdg-btn" id="vdg-scan-btn" title="動画全体をスキャンしてグラフを生成">スキャン</button>
        <button class="vdg-btn" id="vdg-auto-scan-btn" title="再生リスト内の動画を順番にスキャン">自動</button>
      </div>
    </div>
    <div class="vdg-canvas-container" id="vdg-canvas-container">
      <div class="vdg-canvas-wrapper" id="vdg-canvas-wrapper">
        <canvas id="volume-canvas"></canvas>
        <div class="vdg-time-marker" id="vdg-time-marker"></div>
      </div>
      <div class="vdg-hover-time" id="vdg-hover-time">0:00</div>
    </div>
    <div class="vdg-time-labels">
      <span id="vdg-start-time">0:00</span>
      <span id="vdg-end-time">--:--</span>
    </div>
    <div class="vdg-ts-editor" id="vdg-ts-editor">
      <div class="vdg-ts-editor-header">
        <div class="vdg-ts-mode-toggle">
          <button class="vdg-mode-btn active" id="vdg-mode-marker" title="クリックでマーカーを追加">+ マーカー</button>
          <button class="vdg-mode-btn" id="vdg-mode-seek" title="クリックで再生位置を移動">シーク</button>
        </div>
        <div class="vdg-ts-editor-actions">
          <button class="vdg-btn" id="vdg-ts-undo-btn" title="元に戻す (Ctrl+Z)" disabled>↶ 戻る</button>
          <button class="vdg-btn" id="vdg-ts-redo-btn" title="やり直す (Ctrl+Y)" disabled>↷ 進む</button>
          <button class="vdg-btn vdg-btn-copy" id="vdg-ts-copy-btn" title="テキストとしてコピー">コピー</button>
          <button class="vdg-btn vdg-btn-clear-markers" id="vdg-ts-clear-btn" title="すべてのマーカーを削除">クリア</button>
        </div>
      </div>
      <div class="vdg-ts-list" id="vdg-ts-list">
        <div class="vdg-ts-empty">波形グラフをクリックしてタイムスタンプを追加</div>
      </div>
      <div class="vdg-ts-footer">
        <div class="vdg-ts-help">
          クリック: マーカー追加(付近は選択/ドラッグで移動) | Enter: 曲名入力/入力終了 | Del: 削除 | Esc: 入力終了・選択解除 | ←→: 1秒移動(2度押し5秒) | ↑↓: マーカー移動(入力中は行頭/行末へ) | Space: 再生/停止 | J/L: 再生を10秒戻す/進める | Ctrl+Z/Y: 操作を戻す/やり直す | Ctrl+ホイール: 拡大/縮小
        </div>
        <label class="vdg-ts-format-toggle">
          <input type="checkbox" id="vdg-ts-zeropad">
          <span>ゼロ埋め (00:03:45)</span>
        </label>
      </div>
    </div>
  `;

  // Canvas設定（コンテナ内から取得）
  volumeCanvas = volumeGraphContainer.querySelector('#volume-canvas');
  if (volumeCanvas) {
    volumeCtx = volumeCanvas.getContext('2d');
  }

  // イベントリスナー
  setupVolumeGraphEvents();

  // YouTubeプレーヤーの下に挿入
  insertVolumeGraph();

  // 保存済みの音量データを読み込み
  loadVolumeData();
}

/**
 * 音量グラフをYouTubeプレーヤーの下に挿入
 */
function insertVolumeGraph() {
  const insertGraph = () => {
    // #below（動画の下のコンテンツセクション）の先頭に挿入
    // #player-containerはabsolute positionのため、そこに挿入すると動画に重なる
    const belowContainer = document.querySelector('ytd-watch-flexy #below');
    if (belowContainer && !document.getElementById('volume-dynamics-graph')) {
      belowContainer.insertBefore(volumeGraphContainer, belowContainer.firstChild);
      resizeCanvas();
      return true;
    }
    return false;
  };

  if (!insertGraph()) {
    const observer = new MutationObserver((mutations, obs) => {
      if (insertGraph()) {
        obs.disconnect();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
    setTimeout(() => observer.disconnect(), 10000);
  }
}

/**
 * Canvasをリサイズ
 */
function resizeCanvas() {
  if (!volumeCanvas || !volumeGraphContainer) return;

  const canvasContainer = volumeGraphContainer.querySelector('#vdg-canvas-container');
  const canvasWrapper = volumeGraphContainer.querySelector('#vdg-canvas-wrapper');
  if (!canvasContainer || !canvasWrapper) return;

  const containerRect = canvasContainer.getBoundingClientRect();
  const baseWidth = containerRect.width;
  const zoomedWidth = baseWidth * getZoomLevel();
  // 高さはズームレベルから決定論的に算出する（拡大で高く、縮小で元に戻る）
  // ※測定値から算出すると、canvasの高さ属性やスクロールバー分が測定に跳ね返って
  //   高さが増え続けるフィードバックループになるため、必ず計算値を使うこと
  canvasContainer.style.height = `${graphBaseHeightPx + zoomIndex * graphHeightStepPx}px`;
  // 描画領域は水平スクロールバーを除いた実表示高さ
  const height = canvasContainer.clientHeight;

  // ラッパーとCanvasの幅・高さを設定
  // （高さもCSSで明示し、canvasの高さ属性がレイアウトへ影響しないようにする）
  canvasWrapper.style.width = `${zoomedWidth}px`;
  volumeCanvas.style.width = `${zoomedWidth}px`;
  volumeCanvas.style.height = `${height}px`;

  // Canvas解像度を設定
  volumeCanvas.width = zoomedWidth * window.devicePixelRatio;
  volumeCanvas.height = height * window.devicePixelRatio;

  // コンテキストをリセット
  volumeCtx = volumeCanvas.getContext('2d');
  volumeCtx.scale(window.devicePixelRatio, window.devicePixelRatio);

  drawVolumeGraph();
}

/**
 * 現在のズームレベルを取得
 */
function getZoomLevel() {
  return ZOOM_LEVELS[zoomIndex];
}

/**
 * ズームレベルを設定（delta: 1でズームイン、-1でズームアウト）
 */
function changeZoomLevel(delta) {
  const oldZoom = getZoomLevel();
  const newIndex = Math.max(0, Math.min(ZOOM_LEVELS.length - 1, zoomIndex + delta));

  if (newIndex === zoomIndex) return;
  zoomIndex = newIndex;

  const newZoom = getZoomLevel();

  // ズーム情報を更新
  const zoomInfo = volumeGraphContainer?.querySelector('#vdg-zoom-info');
  if (zoomInfo) {
    zoomInfo.textContent = `倍率 ${newZoom}x`;
  }

  // スクロール位置を維持するための計算
  const canvasContainer = volumeGraphContainer?.querySelector('#vdg-canvas-container');
  if (canvasContainer) {
    const containerRect = canvasContainer.getBoundingClientRect();
    const scrollRatio = (canvasContainer.scrollLeft + containerRect.width / 2) / (containerRect.width * oldZoom);

    resizeCanvas();

    // スクロール位置を調整（中心を維持）
    const newScrollLeft = scrollRatio * containerRect.width * newZoom - containerRect.width / 2;
    canvasContainer.scrollLeft = Math.max(0, newScrollLeft);
  } else {
    resizeCanvas();
  }
}

/**
 * マーカースナップ判定の閾値（秒）を計算
 * 長時間動画では秒基準の閾値が1ピクセル未満になり実質機能しないため、
 * ピクセル基準の閾値と比較して大きい方を採用する
 * @param {number} totalWidth - ズームを考慮したグラフの全体幅（ピクセル）
 * @returns {number} 閾値（秒）
 */
function getMarkerSnapThresholdSec(totalWidth) {
  if (!videoDuration || !totalWidth) return MARKER_SNAP_THRESHOLD_SEC;
  const pxPerSec = totalWidth / videoDuration;
  return Math.max(MARKER_SNAP_THRESHOLD_SEC, MARKER_SNAP_THRESHOLD_PX / pxPerSec);
}

/**
 * 音量グラフのイベント設定
 */
function setupVolumeGraphEvents() {
  const container = volumeGraphContainer.querySelector('.vdg-canvas-container');
  const hoverTime = volumeGraphContainer.querySelector('#vdg-hover-time');
  const scanBtn = volumeGraphContainer.querySelector('#vdg-scan-btn');
  const autoScanBtn = volumeGraphContainer.querySelector('#vdg-auto-scan-btn');
  const playlistInfo = volumeGraphContainer.querySelector('#vdg-playlist-info');

  console.log('setupVolumeGraphEvents: ', {
    container: !!container,
    hoverTime: !!hoverTime,
    scanBtn: !!scanBtn,
    autoScanBtn: !!autoScanBtn
  });

  // 再生リスト情報を更新
  updatePlaylistUI();

  // クリックでシーク（ズーム対応）
  if (container) {
    const canvasWrapper = container.querySelector('#vdg-canvas-wrapper');

    // マーカードラッグの状態
    let draggingMarkerId = null; // ドラッグ中のマーカーID
    let dragMoved = false; // ドラッグ判定（一定距離動いたか）
    let dragStartClientX = 0;
    let dragStartSnapshot = null; // ドラッグ開始時点の状態（Undo用）
    let suppressClickAfterDrag = false; // ドラッグ直後のclickを無視するためのフラグ
    const DRAG_START_THRESHOLD_PX = 3; // これ以上動いたらクリックではなくドラッグとみなす

    // スクロール位置とズームを考慮して、マウス位置から動画内の時刻を計算
    const getTimeFromMouseEvent = (e) => {
      const containerRect = container.getBoundingClientRect();
      const x = e.clientX - containerRect.left + container.scrollLeft;
      const totalWidth = containerRect.width * getZoomLevel();
      const ratio = Math.max(0, Math.min(1, x / totalWidth));
      return ratio * videoDuration;
    };

    // マーカーモード: 既存マーカーの上でmousedownしたらドラッグ開始
    container.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      if (tsEditorMode !== 'marker') return;
      if (!videoElement || !videoDuration) return;

      const containerRect = container.getBoundingClientRect();
      const totalWidth = containerRect.width * getZoomLevel();
      const time = getTimeFromMouseEvent(e);
      const snapSec = getMarkerSnapThresholdSec(totalWidth);
      const nearMarker = tsMarkers.find(m => Math.abs(m.time - time) < snapSec);
      if (!nearMarker) return;

      draggingMarkerId = nearMarker.id;
      dragMoved = false;
      dragStartClientX = e.clientX;
      // 実際にドラッグ（移動）が確定した場合のみ履歴に積むため、開始時点の状態を控えておく
      dragStartSnapshot = snapshotMarkers();
      // 曲名編集中なら入力状態を解除する（preventDefaultで自動blurが起きないため）
      blurMarkerTextInput();
      selectedMarkerId = nearMarker.id;
      updateTimestampList();
      drawVolumeGraph();
      // ドラッグ中のテキスト選択を防止
      e.preventDefault();
    });

    // ドラッグ中の移動（グラフ外に出ても追従できるようdocumentで監視）
    document.addEventListener('mousemove', (e) => {
      if (draggingMarkerId === null) return;
      if (!dragMoved && Math.abs(e.clientX - dragStartClientX) < DRAG_START_THRESHOLD_PX) return;
      dragMoved = true;

      const marker = tsMarkers.find(m => m.id === draggingMarkerId);
      if (!marker || !videoDuration) return;

      marker.time = Math.floor(getTimeFromMouseEvent(e));
      container.style.cursor = 'grabbing';
      // 一覧の更新はドラッグ確定時にまとめて行う（毎フレームのDOM再生成を避ける）
      drawVolumeGraph();
    });

    // ドラッグ確定
    document.addEventListener('mouseup', (e) => {
      if (e.button !== 0) return;
      if (draggingMarkerId === null) return;
      const marker = tsMarkers.find(m => m.id === draggingMarkerId);
      draggingMarkerId = null;
      container.style.cursor = 'grab';
      if (!dragMoved || !marker) {
        dragStartSnapshot = null;
        return;
      }

      // ドラッグ直後に発火するclickでマーカー追加/選択が走らないようにする
      // （グラフ外でmouseupした場合はclick自体が発火しないため、タイマーで確実にリセットする）
      suppressClickAfterDrag = true;
      setTimeout(() => { suppressClickAfterDrag = false; }, 0);
      if (dragStartSnapshot) {
        pushMarkerHistory(null, dragStartSnapshot);
        dragStartSnapshot = null;
      }
      tsMarkers.sort((a, b) => a.time - b.time);
      if (videoElement) {
        videoElement.currentTime = marker.time;
        updateTimeMarker();
      }
      updateTimestampList();
      drawVolumeGraph();
      saveMarkersToStorage();
    });

    container.addEventListener('click', (e) => {
      if (!videoElement || !videoDuration) return;
      // ドラッグ操作の終端で発火したclickは無視（リセットはmouseup側のタイマーで行う）
      if (suppressClickAfterDrag) return;

      // スクロール位置とズームを考慮した座標計算
      const containerRect = container.getBoundingClientRect();
      const scrollLeft = container.scrollLeft;
      const x = e.clientX - containerRect.left + scrollLeft;
      const totalWidth = containerRect.width * getZoomLevel();
      const ratio = x / totalWidth;
      const clickTime = ratio * videoDuration;

      if (tsEditorMode === 'seek') {
        // シークモード: 再生位置を移動するだけ
        videoElement.currentTime = clickTime;
      } else {
        // マーカーモード: 既存マーカーの近くは選択、それ以外は追加
        const snapSec = getMarkerSnapThresholdSec(totalWidth);
        const nearMarker = tsMarkers.find(m => Math.abs(m.time - clickTime) < snapSec);
        if (nearMarker) {
          selectedMarkerId = nearMarker.id;
          videoElement.currentTime = nearMarker.time;
          updateTimestampList();
        } else {
          pushMarkerHistory();
          const marker = { id: nextMarkerId++, time: Math.floor(clickTime), text: '' };
          tsMarkers.push(marker);
          tsMarkers.sort((a, b) => a.time - b.time);
          selectedMarkerId = marker.id;
          videoElement.currentTime = marker.time;
          updateTimestampList();
          saveMarkersToStorage();
        }
      }
      // 停止中でも赤い再生位置ラインを即時更新
      updateTimeMarker();
      drawVolumeGraph();
    });

    // ホバーで時間表示（ズーム対応）
    container.addEventListener('mousemove', (e) => {
      if (!videoDuration) return;

      const containerRect = container.getBoundingClientRect();
      const scrollLeft = container.scrollLeft;
      const x = e.clientX - containerRect.left + scrollLeft;
      const totalWidth = containerRect.width * getZoomLevel();
      const ratio = Math.max(0, Math.min(1, x / totalWidth));
      const time = ratio * videoDuration;

      // カーソル形状: マーカーモードで既存マーカーの近くは「選択/ドラッグ可能」を示すgrab、
      // それ以外は「追加」を示す十字。シークモードはポインタ
      // ドラッグ中はgrabbing（documentのmousemove側で設定）を維持する
      if (draggingMarkerId !== null) {
        // 何もしない
      } else if (tsEditorMode === 'marker') {
        const snapSec = getMarkerSnapThresholdSec(totalWidth);
        const isNearMarker = tsMarkers.some(m => Math.abs(m.time - time) < snapSec);
        container.style.cursor = isNearMarker ? 'grab' : 'crosshair';
      } else {
        container.style.cursor = 'pointer';
      }

      if (hoverTime) {
        hoverTime.textContent = formatTimeDisplay(time);
        // ホバー位置はスクロール位置を引いた画面上の位置
        hoverTime.style.left = `${e.clientX - containerRect.left}px`;
      }
    });

    // グラフ外のクリックで選択を解除する
    // （選択がない間はDelete/Space等のショートカットが発動しないため、誤削除を防げる）
    // stopPropagationするボタン・メニューのクリックでも確実に届くようキャプチャフェーズで監視
    document.addEventListener('click', (e) => {
      // マーカーをグラフ外までドラッグして離した際のclickでは解除しない
      if (suppressClickAfterDrag) return;
      if (selectedMarkerId === null) return;
      if (e.target instanceof Element && volumeGraphContainer && volumeGraphContainer.contains(e.target)) return;
      deselectMarker();
    }, true);

    // マウスホイールイベント（ホイールのみ: 横スクロール）
    // Ctrl+ホイールのズームはパネル全体で受けるため、ここでは処理せず親へ伝播させる
    container.addEventListener('wheel', (e) => {
      if (e.ctrlKey) return;
      e.preventDefault();
      container.scrollLeft += e.deltaY;
    }, { passive: false });
  }

  // Ctrl + ホイール: ズーム（グラフ上に限らずパネルのどこでも反応させる）
  volumeGraphContainer.addEventListener('wheel', (e) => {
    if (!e.ctrlKey) return; // 通常のホイールは一覧のスクロール等に任せる
    e.preventDefault(); // ブラウザのページ拡大を抑止
    changeZoomLevel(e.deltaY > 0 ? -1 : 1);
  }, { passive: false });

  // 音量表示モード切り替えボタン
  const volumeModeBtn = volumeGraphContainer.querySelector('#vdg-volume-mode-btn');
  if (volumeModeBtn) {
    volumeModeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      isRelativeVolumeMode = !isRelativeVolumeMode;
      volumeModeBtn.classList.toggle('active', isRelativeVolumeMode);
      volumeModeBtn.textContent = isRelativeVolumeMode ? '相対' : '絶対';
      volumeModeBtn.title = isRelativeVolumeMode
        ? 'アーカイブ内の最大音量を100%とした相対表示中'
        : '固定スケールでの絶対値表示中';
      drawVolumeGraph();
    });
  }

  // スキャンボタン（tabCapture方式、常にミュート）
  if (scanBtn) {
    scanBtn.addEventListener('click', async (e) => {
      e.stopPropagation();
      console.log('スキャンボタンがクリックされました');
      try {
        const response = await chrome.runtime.sendMessage({ type: 'START_SCAN' });
        console.log('START_SCAN応答:', response);
      } catch (error) {
        console.error('START_SCANエラー:', error);
      }
    });
  }



  // 自動スキャンボタン（再生リスト用）
  if (autoScanBtn) {
    autoScanBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (isAutoScanMode) {
        stopAutoScan();
      } else {
        startAutoScan();
      }
    });
  }

  // ウィンドウリサイズ
  window.addEventListener('resize', () => {
    resizeCanvas();
  });

  // タイムスタンプエディタ: 戻す/やり直すボタン
  const tsUndoBtn = volumeGraphContainer.querySelector('#vdg-ts-undo-btn');
  const tsRedoBtn = volumeGraphContainer.querySelector('#vdg-ts-redo-btn');
  if (tsUndoBtn) {
    tsUndoBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      undoMarkers();
    });
  }
  if (tsRedoBtn) {
    tsRedoBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      redoMarkers();
    });
  }

  // タイムスタンプエディタ: コピーボタン
  const tsCopyBtn = volumeGraphContainer.querySelector('#vdg-ts-copy-btn');
  if (tsCopyBtn) {
    tsCopyBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      copyTimestamps();
    });
  }

  // タイムスタンプエディタ: クリアボタン
  const tsClearBtn = volumeGraphContainer.querySelector('#vdg-ts-clear-btn');
  if (tsClearBtn) {
    tsClearBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (tsMarkers.length === 0) return;
      if (!confirm(`${tsMarkers.length}件のマーカーをすべて削除しますか？`)) return;
      pushMarkerHistory();
      tsMarkers = [];
      selectedMarkerId = null;
      updateTimestampList();
      drawVolumeGraph();
      saveMarkersToStorage();
    });
  }

  // タイムスタンプエディタ: モード切替
  const modeMarkerBtn = volumeGraphContainer.querySelector('#vdg-mode-marker');
  const modeSeekBtn = volumeGraphContainer.querySelector('#vdg-mode-seek');
  if (modeMarkerBtn && modeSeekBtn) {
    const updateModeUI = () => {
      modeMarkerBtn.classList.toggle('active', tsEditorMode === 'marker');
      modeSeekBtn.classList.toggle('active', tsEditorMode === 'seek');
    };
    modeMarkerBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      tsEditorMode = 'marker';
      updateModeUI();
    });
    modeSeekBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      tsEditorMode = 'seek';
      updateModeUI();
    });
  }

  // タイムスタンプエディタ: 保存済みマーカーを復元
  loadMarkersFromStorage();

  // タイムスタンプエディタ: ゼロ埋め設定
  const zeroPadCheckbox = volumeGraphContainer.querySelector('#vdg-ts-zeropad');
  if (zeroPadCheckbox) {
    // 保存された設定を復元
    chrome.storage.local.get('tsZeroPad', (result) => {
      tsZeroPad = result.tsZeroPad === true;
      zeroPadCheckbox.checked = tsZeroPad;
    });
    zeroPadCheckbox.addEventListener('change', () => {
      tsZeroPad = zeroPadCheckbox.checked;
      chrome.storage.local.set({ tsZeroPad });
      updateTimestampList();
    });
  }

  // タイムスタンプエディタ: キーボード操作
  // YouTube本体のショートカット（Space: 再生/停止、←→: シーク等）と二重に発火すると
  // 打ち消し合うため、キャプチャフェーズで先に処理して stopImmediatePropagation で止める
  let lastArrowTime = 0;
  document.addEventListener('keydown', (e) => {
    const isTextInput = e.target.classList.contains('vdg-ts-text-input');
    // テキスト入力中は基本的にスキップ（入力を妨げない）
    if (isTextInput && !['Delete', 'ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(e.key)) return;
    // IME変換中は確定(Enter)・取消(Esc)・候補選択(↑↓)をIME側の処理に委ねる
    // （奪うと変換中の文字が失われる。keyCode 229は変換開始時のフォールバック判定）
    if (isTextInput && (e.isComposing || e.keyCode === 229)) return;
    // 検索ボックスやコメント欄など、エディタ以外の編集可能要素への入力は妨げない
    if (!isTextInput && isEditableTarget(e.target)) return;
    // 設定メニューやボタン等、YouTube本体のUI部品にフォーカスがある間は本体の操作を優先
    if (!isTsEditorKeyScope()) return;
    if (!isGraphVisible) return;

    // Undo/Redo（マーカー未選択でも有効。テキスト入力中は先頭の早期returnにより入力欄側のUndoを優先）
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'z') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (e.shiftKey) {
        redoMarkers();
      } else {
        undoMarkers();
      }
      return;
    }
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'y') {
      e.preventDefault();
      e.stopImmediatePropagation();
      redoMarkers();
      return;
    }

    // 曲名入力中のEnter/Escは入力状態を終了して選択状態に戻す
    // （入力内容は1文字ごとに保存済みなので、確定/取消の区別はせず単に抜ける。
    //   編集前に戻したい場合はCtrl+Zを使う）
    if (isTextInput && (e.key === 'Enter' || e.key === 'Escape')) {
      // ペースト変換ポップアップが開いている間は、ポップアップ側のキー処理を優先する
      // （Esc=元テキストのまま挿入。閉じてから改めて押せば入力状態を抜けられる）
      if (lyricsPastePopup) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      blurMarkerTextInput();
      return;
    }

    // 曲名入力中の↑↓はカーソルを文字列の先頭/末尾へ移動する
    // （入力状態を抜けるのはEnter/Escの役割。IME変換中は上のガードで候補選択が優先される）
    if (isTextInput && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      e.preventDefault();
      e.stopImmediatePropagation();
      // ポップアップが開いていれば閉じる（キー操作では挿入しないのがポップアップ側の挙動と同じ）
      closeLyricsPastePopup();
      closeSongCandidatePopup();
      const pos = e.key === 'ArrowUp' ? 0 : e.target.value.length;
      e.target.setSelectionRange(pos, pos);
      return;
    }

    // マーカー未選択なら何もしない
    if (selectedMarkerId === null) return;

    const now = Date.now();

    if (e.key === 'Delete' && !isTextInput) {
      e.preventDefault();
      e.stopImmediatePropagation();
      deleteSelectedMarker();
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      e.stopImmediatePropagation();
      // 上下キーで前後のマーカーに移動（入力中は上の分岐でカーソル移動として扱われる）
      const currentIndex = tsMarkers.findIndex(m => m.id === selectedMarkerId);
      if (currentIndex < 0) return;
      const nextIndex = e.key === 'ArrowUp'
        ? Math.max(0, currentIndex - 1)
        : Math.min(tsMarkers.length - 1, currentIndex + 1);
      if (nextIndex !== currentIndex) {
        selectedMarkerId = tsMarkers[nextIndex].id;
        if (videoElement) {
          videoElement.currentTime = tsMarkers[nextIndex].time;
          updateTimeMarker();
        }
        updateTimestampList();
        drawVolumeGraph();
      }
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      e.stopImmediatePropagation();
      const direction = e.key === 'ArrowLeft' ? -1 : 1;
      // 200ms以内の連続押下で5秒移動
      const delta = (now - lastArrowTime < 200) ? 5 : 1;
      lastArrowTime = now;
      moveSelectedMarker(direction * delta);
    } else if (e.key === 'Enter' && !isTextInput) {
      // 選択中マーカーの曲名入力欄にフォーカス（カーソルは末尾）
      e.preventDefault();
      e.stopImmediatePropagation();
      const input = volumeGraphContainer?.querySelector(`.vdg-ts-text-input[data-marker-id="${selectedMarkerId}"]`);
      if (input) {
        input.focus({ preventScroll: true });
        const len = input.value.length;
        input.setSelectionRange(len, len);
        scrollSelectedRowIntoView();
      }
    } else if (e.key === 'Escape') {
      // 選択解除のみ行い、YouTube側のEsc処理（メニューを閉じる等）は妨げない
      // （入力欄非フォーカスで曲名候補ポップアップだけ開いている場合もここで閉じる）
      closeSongCandidatePopup();
      deselectMarker();
    } else if (e.key === ' ') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (videoElement) {
        // マーカー選択中でも再生位置は動かさず、現在位置から再生/停止する
        if (videoElement.paused) {
          videoElement.play();
        } else {
          videoElement.pause();
        }
      }
    }
  }, true);

  // Spaceのkeyupも止める（フォーカス中の要素がSpaceで反応して再生状態が再度トグルされるのを防ぐ）
  document.addEventListener('keyup', (e) => {
    if (e.key !== ' ') return;
    if (isEditableTarget(e.target)) return;
    if (!isTsEditorKeyScope()) return;
    if (!isGraphVisible || selectedMarkerId === null) return;
    e.preventDefault();
    e.stopImmediatePropagation();
  }, true);
}

/**
 * キー入力を妨げてはいけない編集可能要素かどうかを判定
 * @param {EventTarget} target - イベントターゲット
 * @returns {boolean}
 */
function isEditableTarget(target) {
  if (!(target instanceof Element)) return false;
  if (target.isContentEditable) return true;
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * タイムスタンプエディタのキーボードショートカットを処理してよいフォーカス状態か判定
 * 背景（body）・グラフ内・プレイヤー本体へのフォーカスのみ対象とし、
 * 設定メニューや各種ボタンなどYouTube本体のUI部品にフォーカスがある場合は本体の操作を優先する
 * @returns {boolean}
 */
function isTsEditorKeyScope() {
  const active = document.activeElement;
  if (!active || active === document.body || active === document.documentElement) return true;
  if (volumeGraphContainer && volumeGraphContainer.contains(active)) return true;
  if (active === videoElement) return true;
  // プレイヤーのルート要素（#movie_player）自体は対象。内部のボタン・メニューは除外
  if (active.id === 'movie_player') return true;
  return false;
}

/**
 * 再生リストUIを更新
 */
function updatePlaylistUI() {
  if (!volumeGraphContainer) return;

  const autoScanBtn = volumeGraphContainer.querySelector('#vdg-auto-scan-btn');
  const playlistInfo = volumeGraphContainer.querySelector('#vdg-playlist-info');

  if (!isInPlaylist()) {
    // 再生リスト外では自動スキャンボタンを非表示
    if (autoScanBtn) autoScanBtn.classList.add('hidden');
    if (playlistInfo) playlistInfo.textContent = '';
    return;
  }

  // 再生リスト内では自動スキャンボタンを表示
  if (autoScanBtn) autoScanBtn.classList.remove('hidden');

  // 再生リスト情報を表示
  const info = getPlaylistInfo();
  if (info && playlistInfo) {
    playlistInfo.textContent = `${info.currentIndex + 1}/${info.total}`;
  }
}

/**
 * 自動スキャンを開始
 */
async function startAutoScan() {
  if (!isInPlaylist()) {
    console.log('自動スキャン: 再生リスト外では使用できません');
    return;
  }

  isAutoScanMode = true;
  autoScanStopRequested = false;

  const autoScanBtn = volumeGraphContainer?.querySelector('#vdg-auto-scan-btn');
  if (autoScanBtn) {
    autoScanBtn.classList.add('auto-scanning');
    autoScanBtn.textContent = '停止';
  }

  console.log('自動スキャン開始');

  // 現在の動画がスキャン済みかチェック
  const alreadyScanned = await isCurrentVideoScanned();
  if (alreadyScanned) {
    console.log('現在の動画はスキャン済み、次の動画へ移動');
    proceedToNextVideoOrFinish();
  } else {
    // スキャンを開始（tabCapture方式、常にミュート）
    chrome.runtime.sendMessage({ type: 'START_SCAN' });
  }
}

/**
 * 自動スキャンを停止
 */
function stopAutoScan() {
  isAutoScanMode = false;
  autoScanStopRequested = true;

  const autoScanBtn = volumeGraphContainer?.querySelector('#vdg-auto-scan-btn');
  if (autoScanBtn) {
    autoScanBtn.classList.remove('auto-scanning');
    autoScanBtn.textContent = '自動';
  }

  // 進行中のスキャンも停止（tabCapture方式）
  chrome.runtime.sendMessage({ type: 'STOP_SCAN' });

  console.log('自動スキャン停止');
}

/**
 * 次の動画へ移動するか、自動スキャンを完了
 */
function proceedToNextVideoOrFinish() {
  if (!isAutoScanMode || autoScanStopRequested) {
    return;
  }

  const info = getPlaylistInfo();
  if (!info) {
    stopAutoScan();
    return;
  }

  // 最後の動画かどうかチェック
  if (info.currentIndex >= info.total - 1) {
    console.log('自動スキャン完了: 再生リストの最後に到達');
    stopAutoScan();
    return;
  }

  console.log(`次の動画へ移動 (${info.currentIndex + 1}/${info.total})`);

  // 少し待ってから次の動画へ移動
  setTimeout(() => {
    if (isAutoScanMode && !autoScanStopRequested) {
      goToNextVideo();
    }
  }, 1500);
}

/**
 * 音声解析を初期化（Video要素から直接解析）
 */
function initAudioAnalysis() {
  if (!videoElement) {
    console.error('Video要素が見つかりません');
    return false;
  }

  // 既に初期化済みならスキップ
  if (audioInitialized && mediaElementSource) {
    return true;
  }

  try {
    // 既存のAudioContextがあれば再利用
    if (!audioContext) {
      audioContext = new (window.AudioContext || window.webkitAudioContext)();
    }

    // MediaElementSourceは一度だけ作成可能
    if (!mediaElementSource) {
      mediaElementSource = audioContext.createMediaElementSource(videoElement);

      // AnalyserNodeを作成
      analyserNode = audioContext.createAnalyser();
      analyserNode.fftSize = 2048;
      analyserNode.smoothingTimeConstant = 0.8;

      // GainNodeを作成（音量制御用）
      gainNode = audioContext.createGain();
      gainNode.gain.value = 1; // デフォルトは音声ON

      // Video → Analyser → Gain → 出力 の接続
      mediaElementSource.connect(analyserNode);
      analyserNode.connect(gainNode);
      gainNode.connect(audioContext.destination);

      audioInitialized = true;
      console.log('音声解析を初期化しました（Video要素から直接解析）');
    }

    return true;
  } catch (error) {
    console.error('音声解析の初期化に失敗:', error);
    return false;
  }
}

/**
 * 音量を設定（ミュート切り替え用）
 */
function setAudioGain(muted) {
  if (gainNode) {
    gainNode.gain.value = muted ? 0 : 1;
    console.log('音声ゲイン設定:', muted ? 'ミュート' : '音声ON');
  }
}

/**
 * スキャンを開始（Video要素から直接音声解析）
 */
async function startDirectScan() {
  if (isScanning) {
    stopDirectScan();
    return;
  }

  if (!videoElement) {
    console.error('Video要素が見つかりません');
    return;
  }

  // 音声解析を初期化
  if (!initAudioAnalysis()) {
    console.error('音声解析の初期化に失敗しました');
    return;
  }

  // AudioContextがsuspendedの場合は再開
  if (audioContext.state === 'suspended') {
    await audioContext.resume();
  }

  // 動画情報を取得
  updateVideoDuration();
  if (!videoDuration) {
    console.error('動画の長さを取得できません');
    return;
  }

  isScanning = true;
  const resolution = calcGraphResolution(videoDuration);
  volumeData = new Array(resolution).fill(0);

  // 現在の状態を保存
  originalPlaybackRate = videoElement.playbackRate;

  // ミュート設定を適用（GainNodeで制御）
  setAudioGain(scanMuted);

  // 高速再生に設定
  videoElement.playbackRate = 4;
  videoElement.currentTime = 0;
  videoElement.play();

  // UIを更新
  updateScanButtonUI(true);
  if (volumeGraphContainer) {
    volumeGraphContainer.classList.add('visible');
    isGraphVisible = true;
  }

  console.log('スキャン開始（直接音声解析）', { muted: scanMuted });

  // 音量データの収集を開始
  const dataArray = new Float32Array(analyserNode.fftSize);

  scanInterval = setInterval(() => {
    if (!isScanning || !analyserNode) {
      stopDirectScan();
      return;
    }

    // 音声データを取得
    analyserNode.getFloatTimeDomainData(dataArray);

    // RMS（二乗平均平方根）で音量を計算
    let sum = 0;
    for (let i = 0; i < dataArray.length; i++) {
      sum += dataArray[i] * dataArray[i];
    }
    const rms = Math.sqrt(sum / dataArray.length);

    // 正規化（0-1の範囲に）
    const normalizedVolume = Math.min(1, rms * 5);

    // データポイントのインデックスを計算
    const currentResolution = volumeData.length;
    const index = Math.floor((videoElement.currentTime / videoDuration) * currentResolution);

    if (index >= 0 && index < currentResolution) {
      if (normalizedVolume > volumeData[index]) {
        volumeData[index] = normalizedVolume;
      }
    }

    // 進捗を更新
    const progress = (volumeData.filter(v => v > 0).length / currentResolution) * 100;
    updateProgress(progress);
    drawVolumeGraph();

    // 動画の終わりに近づいたらスキャン完了
    if (videoElement.currentTime >= videoDuration - 1) {
      stopDirectScan();
    }
  }, 50); // 50msごとにサンプリング
}

/**
 * スキャンを停止
 */
function stopDirectScan() {
  if (!isScanning) return;

  isScanning = false;

  if (scanInterval) {
    clearInterval(scanInterval);
    scanInterval = null;
  }

  // 再生状態を元に戻す
  if (videoElement) {
    videoElement.playbackRate = originalPlaybackRate;
    videoElement.pause();
    videoElement.currentTime = 0;
  }

  // 音声を元に戻す（音声ON）
  setAudioGain(false);

  // UIを更新
  updateScanButtonUI(false);

  // スキャン完了時に結果を保存
  if (volumeData.length > 0 && volumeData.some(v => v > 0)) {
    saveVolumeData();
  }

  console.log('スキャン停止');

  // 自動スキャンモードの場合は次の動画へ
  if (isAutoScanMode && !autoScanStopRequested) {
    proceedToNextVideoOrFinish();
  }
}

/**
 * スキャンボタンのUIを更新
 */
function updateScanButtonUI(scanning) {
  if (!volumeGraphContainer) return;
  const scanBtn = volumeGraphContainer.querySelector('#vdg-scan-btn');
  if (scanBtn) {
    if (scanning) {
      scanBtn.classList.add('scanning');
      scanBtn.textContent = '停止';
    } else {
      scanBtn.classList.remove('scanning');
      scanBtn.textContent = 'スキャン';
    }
  }
}

/**
 * 音量グラフを描画
 */
function drawVolumeGraph() {
  if (!volumeCtx || !volumeCanvas) return;

  const width = volumeCanvas.width / window.devicePixelRatio;
  const height = volumeCanvas.height / window.devicePixelRatio;

  // クリア
  volumeCtx.clearRect(0, 0, width, height);

  if (volumeData.length === 0) {
    // データなし
    volumeCtx.fillStyle = '#333';
    volumeCtx.fillRect(0, 0, width, height);
    volumeCtx.fillStyle = '#666';
    volumeCtx.font = '11px sans-serif';
    volumeCtx.textAlign = 'center';
    volumeCtx.fillText('再生またはスキャンで音量データを収集', width / 2, height / 2 + 4);
    return;
  }

  // 背景
  volumeCtx.fillStyle = '#1a1a1a';
  volumeCtx.fillRect(0, 0, width, height);

  // 相対表示モードの場合、最大値を取得
  let maxVolume = 1;
  if (isRelativeVolumeMode) {
    maxVolume = Math.max(...volumeData.filter(v => v > 0)) || 1;
  }

  // グラデーション
  const gradient = volumeCtx.createLinearGradient(0, height, 0, 0);
  gradient.addColorStop(0, '#1b5e20');
  gradient.addColorStop(0.5, '#4caf50');
  gradient.addColorStop(0.8, '#ffeb3b');
  gradient.addColorStop(1, '#f44336');

  // 波形描画
  volumeCtx.fillStyle = gradient;
  volumeCtx.beginPath();
  volumeCtx.moveTo(0, height);

  const barWidth = width / volumeData.length;
  for (let i = 0; i < volumeData.length; i++) {
    // 相対表示モードの場合は最大値で正規化
    const normalizedValue = isRelativeVolumeMode ? volumeData[i] / maxVolume : volumeData[i];
    const barHeight = normalizedValue * height;
    const x = i * barWidth;
    volumeCtx.lineTo(x, height - barHeight);
  }

  volumeCtx.lineTo(width, height);
  volumeCtx.closePath();
  volumeCtx.fill();

  // 中心線
  volumeCtx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
  volumeCtx.lineWidth = 1;
  volumeCtx.beginPath();
  volumeCtx.moveTo(0, height / 2);
  volumeCtx.lineTo(width, height / 2);
  volumeCtx.stroke();

  // タイムスタンプマーカーを描画
  drawTimestampMarkers(width, height);
}

/**
 * タイムスタンプマーカーを描画
 */
function drawTimestampMarkers(width, height) {
  if (!volumeCtx || !videoDuration) return;

  // 自動検出マーカー（旧機能、破線オレンジ）
  if (detectedTimestamps.length > 0) {
    volumeCtx.strokeStyle = '#ff5722';
    volumeCtx.lineWidth = 1;
    volumeCtx.setLineDash([4, 2]);

    for (const ts of detectedTimestamps) {
      const x = (ts.time / videoDuration) * width;
      volumeCtx.beginPath();
      volumeCtx.moveTo(x, 0);
      volumeCtx.lineTo(x, height);
      volumeCtx.stroke();
    }

    volumeCtx.setLineDash([]);
  }

  // 手動マーカー（タイムスタンプエディタ）
  for (const marker of tsMarkers) {
    const x = (marker.time / videoDuration) * width;
    const isSelected = marker.id === selectedMarkerId;

    // 縦線
    volumeCtx.strokeStyle = isSelected ? '#4caf50' : '#ffd54f';
    volumeCtx.lineWidth = isSelected ? 2.5 : 1.5;
    volumeCtx.setLineDash([]);
    volumeCtx.beginPath();
    volumeCtx.moveTo(x, 0);
    volumeCtx.lineTo(x, height);
    volumeCtx.stroke();

    // 三角マーカー（上部）
    volumeCtx.fillStyle = isSelected ? '#4caf50' : '#ffd54f';
    volumeCtx.beginPath();
    volumeCtx.moveTo(x, 0);
    volumeCtx.lineTo(x - 5, 10);
    volumeCtx.lineTo(x + 5, 10);
    volumeCtx.closePath();
    volumeCtx.fill();
  }
}

/**
 * タイムスタンプ一覧UIを更新
 */
function updateTimestampList() {
  const listEl = volumeGraphContainer?.querySelector('#vdg-ts-list');
  if (!listEl) return;

  // リスト再構築でペースト変換ポップアップの対象入力欄が破棄されるため閉じる
  closeLyricsPastePopup();
  closeSongCandidatePopup();

  if (tsMarkers.length === 0) {
    listEl.innerHTML = '<div class="vdg-ts-empty">波形グラフをクリックしてタイムスタンプを追加</div>';
    return;
  }

  listEl.innerHTML = tsMarkers.map(marker => `
    <div class="vdg-ts-row ${marker.id === selectedMarkerId ? 'selected' : ''}" data-marker-id="${marker.id}">
      <button type="button" class="vdg-ts-offset-btn" data-marker-id="${marker.id}" data-delta="-1" title="-1秒" tabindex="-1">-1s</button>
      <span class="vdg-ts-time">${formatTimestamp(marker.time)}</span>
      <button type="button" class="vdg-ts-offset-btn" data-marker-id="${marker.id}" data-delta="1" title="+1秒" tabindex="-1">+1s</button>
      <input type="text" class="vdg-ts-text-input" value="${escapeHtml(marker.text)}" placeholder="曲名を入力..." data-marker-id="${marker.id}">
      <button type="button" class="vdg-ts-suggest-btn" data-marker-id="${marker.id}" title="字幕から曲名候補を表示" tabindex="-1">候補</button>
    </div>
  `).join('');

  // イベントリスナー
  listEl.querySelectorAll('.vdg-ts-offset-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(btn.dataset.markerId);
      const delta = parseInt(btn.dataset.delta);
      selectedMarkerId = id;
      moveSelectedMarker(delta);
    });
  });

  listEl.querySelectorAll('.vdg-ts-suggest-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(btn.dataset.markerId);
      selectedMarkerId = id;
      const marker = tsMarkers.find(m => m.id === id);
      if (marker) {
        showSongCandidates(marker);
      }
    });
  });

  listEl.querySelectorAll('.vdg-ts-row').forEach(row => {
    row.addEventListener('click', (e) => {
      if (e.target.classList.contains('vdg-ts-text-input') || e.target.classList.contains('vdg-ts-offset-btn') || e.target.classList.contains('vdg-ts-suggest-btn')) return;
      const id = parseInt(row.dataset.markerId);
      selectedMarkerId = id;
      const marker = tsMarkers.find(m => m.id === id);
      if (marker && videoElement) {
        videoElement.currentTime = marker.time;
        updateTimeMarker();
      }
      updateTimestampList();
      drawVolumeGraph();
    });
  });

  listEl.querySelectorAll('.vdg-ts-text-input').forEach(input => {
    input.addEventListener('input', (e) => {
      const id = parseInt(input.dataset.markerId);
      const marker = tsMarkers.find(m => m.id === id);
      if (marker) {
        // タイピングは1履歴にまとめる（marker.text更新前に積むので編集前の曲名が復元される）
        pushMarkerHistory(`text:${id}`);
        marker.text = e.target.value;
        saveMarkersToStorage();
      }
    });
    input.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return; // 右クリック等はコンテキストメニュー操作を妨げない
      // 未編集状態のシングルクリックは選択のみ（ダブルクリックで入力状態にする）
      if (document.activeElement === input) return; // 編集中は通常のカーソル操作
      e.preventDefault(); // フォーカス取得（入力状態化）を抑止
      // 別の入力欄を編集中だった場合は入力状態を解除する
      blurMarkerTextInput();
      const id = parseInt(input.dataset.markerId);
      selectedMarkerId = id;
      const marker = tsMarkers.find(m => m.id === id);
      if (marker && videoElement) {
        videoElement.currentTime = marker.time;
        updateTimeMarker();
      }
      // リストを再構築するとこの入力欄が破棄されてダブルクリック判定が壊れるため、
      // ハイライトのみ更新する
      updateTimestampListSelection();
      drawVolumeGraph();
    });
    input.addEventListener('dblclick', (e) => {
      e.preventDefault();
      input.focus({ preventScroll: true });
      const len = input.value.length;
      input.setSelectionRange(len, len);
      scrollSelectedRowIntoView();
    });
    input.addEventListener('paste', (e) => {
      const pasted = e.clipboardData?.getData('text/plain');
      if (!pasted) return;
      // 「アーティスト名 曲名 歌詞 ...」形式なら変換候補を表示（通常のテキストはそのままペースト）
      const candidates = buildLyricsSplitCandidates(pasted);
      if (!candidates) return;
      e.preventDefault();
      showLyricsPastePopup(input, candidates, pasted.trim());
    });
    input.addEventListener('focus', () => {
      const id = parseInt(input.dataset.markerId);
      if (selectedMarkerId === id) return;
      selectedMarkerId = id;
      // ここでリスト全体をinnerHTML再生成するとフォーカス中の入力欄が破棄されて
      // 曲名が入力できなくなるため、選択ハイライトのみ更新する
      updateTimestampListSelection();
      drawVolumeGraph();
    });
  });

  // 選択中の行・マーカーが表示範囲外ならスクロールして表示（追加・選択・ドラッグ確定などの再構築時）
  scrollSelectedRowIntoView();
  scrollGraphToSelectedMarker();
}

/**
 * マーカー状態のスナップショットを作成
 */
function snapshotMarkers() {
  return {
    markers: tsMarkers.map(m => ({ ...m })),
    selectedId: selectedMarkerId,
    nextId: nextMarkerId,
  };
}

/**
 * 変更前のマーカー状態をUndo履歴に積む。マーカーを変更する処理の直前に呼ぶこと
 * @param {string|null} tag - 操作種別。同じtagの操作が短時間に連続した場合は1履歴にまとめる
 *                            （←→連打での移動や曲名のタイピングを1回のUndoで戻せるようにするため）。
 *                            nullはまとめない
 * @param {object|null} snapshot - 積むスナップショット。省略時は現在の状態
 */
function pushMarkerHistory(tag = null, snapshot = null) {
  const now = Date.now();
  if (tag !== null && tag === lastHistoryTag && now - lastHistoryTime < TS_HISTORY_COALESCE_MS) {
    lastHistoryTime = now;
    return;
  }
  lastHistoryTag = tag;
  lastHistoryTime = now;
  tsHistoryUndo.push(snapshot || snapshotMarkers());
  if (tsHistoryUndo.length > TS_HISTORY_LIMIT) tsHistoryUndo.shift();
  tsHistoryRedo = [];
  updateUndoRedoButtons();
}

/**
 * 直前の操作を取り消す
 */
function undoMarkers() {
  if (tsHistoryUndo.length === 0) return;
  tsHistoryRedo.push(snapshotMarkers());
  restoreMarkerSnapshot(tsHistoryUndo.pop());
}

/**
 * 取り消した操作をやり直す
 */
function redoMarkers() {
  if (tsHistoryRedo.length === 0) return;
  tsHistoryUndo.push(snapshotMarkers());
  restoreMarkerSnapshot(tsHistoryRedo.pop());
}

/**
 * スナップショットからマーカー状態を復元
 */
function restoreMarkerSnapshot(snapshot) {
  tsMarkers = snapshot.markers.map(m => ({ ...m }));
  selectedMarkerId = snapshot.selectedId;
  nextMarkerId = snapshot.nextId;
  // Undo/Redo直後の操作が履歴にまとめられないようにリセット
  lastHistoryTag = null;
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();
  updateUndoRedoButtons();
}

/**
 * 戻す/やり直すボタンの有効状態を更新
 */
function updateUndoRedoButtons() {
  const undoBtn = volumeGraphContainer?.querySelector('#vdg-ts-undo-btn');
  const redoBtn = volumeGraphContainer?.querySelector('#vdg-ts-redo-btn');
  if (undoBtn) undoBtn.disabled = tsHistoryUndo.length === 0;
  if (redoBtn) redoBtn.disabled = tsHistoryRedo.length === 0;
}

/**
 * 曲名入力欄が編集中ならフォーカスを外して入力状態を解除する
 * （mousedownのpreventDefaultで自動のフォーカス移動が起きないケース用）
 */
function blurMarkerTextInput() {
  // キーボード操作で入力を抜ける場合はポップアップのmousedown経由の後始末が働かないため、
  // ここで明示的に閉じる（開いたまま残るとリスナーが生き続け、後続のクリックで誤挿入される）
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  const active = document.activeElement;
  if (active instanceof HTMLElement && active.classList.contains('vdg-ts-text-input')) {
    active.blur();
  }
}

/**
 * タイムスタンプ一覧の選択ハイライトのみ更新（DOMは再生成しない）
 */
function updateTimestampListSelection() {
  const listEl = volumeGraphContainer?.querySelector('#vdg-ts-list');
  if (!listEl) return;
  listEl.querySelectorAll('.vdg-ts-row').forEach(row => {
    row.classList.toggle('selected', parseInt(row.dataset.markerId) === selectedMarkerId);
  });
  scrollSelectedRowIntoView();
  scrollGraphToSelectedMarker();
}

/**
 * 選択中のマーカーがグラフの表示範囲（ズーム時の横スクロール領域）の外にある場合、
 * グラフのスクロール位置を調整して画面内に表示する
 */
function scrollGraphToSelectedMarker() {
  if (selectedMarkerId === null || !videoDuration) return;
  const container = volumeGraphContainer?.querySelector('#vdg-canvas-container');
  if (!container) return;

  const marker = tsMarkers.find(m => m.id === selectedMarkerId);
  if (!marker) return;

  const visibleWidth = container.clientWidth;
  const totalWidth = container.getBoundingClientRect().width * getZoomLevel();
  // ズームしていない（スクロール不要）場合は何もしない
  if (visibleWidth === 0 || totalWidth <= visibleWidth) return;

  const x = (marker.time / videoDuration) * totalWidth;
  // 端の判定に少し余白を持たせる
  const margin = Math.min(20, visibleWidth / 4);
  if (x >= container.scrollLeft + margin && x <= container.scrollLeft + visibleWidth - margin) return;

  // 表示範囲外なら中央に寄せる
  const target = x - visibleWidth / 2;
  container.scrollLeft = Math.max(0, Math.min(target, totalWidth - visibleWidth));
}

/**
 * 選択中のタイムスタンプ行が一覧の表示範囲外にある場合、一覧内のスクロール位置を調整して表示する
 * （ページ全体はスクロールさせない）
 */
function scrollSelectedRowIntoView() {
  const listEl = volumeGraphContainer?.querySelector('#vdg-ts-list');
  if (!listEl) return;
  const row = listEl.querySelector('.vdg-ts-row.selected');
  if (!row) return;

  const listRect = listEl.getBoundingClientRect();
  const rowRect = row.getBoundingClientRect();
  // グラフ非表示時はサイズが取れないため何もしない
  if (listRect.height === 0) return;

  if (rowRect.top < listRect.top) {
    listEl.scrollTop += rowRect.top - listRect.top;
  } else if (rowRect.bottom > listRect.bottom) {
    listEl.scrollTop += rowRect.bottom - listRect.bottom;
  }
}

/**
 * マーカーの選択を解除
 */
function deselectMarker() {
  if (selectedMarkerId === null) return;
  selectedMarkerId = null;
  updateTimestampListSelection();
  drawVolumeGraph();
}

/**
 * マーカーを削除
 */
function deleteSelectedMarker() {
  if (selectedMarkerId === null) return;
  const marker = tsMarkers.find(m => m.id === selectedMarkerId);
  if (!marker) return;
  // 曲名入力済みのマーカーは誤削除防止のため確認を挟む
  const text = (marker.text || '').trim();
  if (text !== '' && !confirm(`「${text}」(${formatTimestamp(marker.time)}) を削除しますか？`)) return;
  pushMarkerHistory();
  tsMarkers = tsMarkers.filter(m => m.id !== selectedMarkerId);
  // 削除直後にDeleteの連打で意図しないマーカーが消えないよう、選択は解除する
  selectedMarkerId = null;
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();
}

/**
 * 選択中のマーカーを移動
 * @param {number} deltaSec - 移動量（秒）
 */
function moveSelectedMarker(deltaSec) {
  if (selectedMarkerId === null) return;
  const marker = tsMarkers.find(m => m.id === selectedMarkerId);
  if (!marker || !videoElement) return;

  // 連打での移動は1履歴にまとめる
  pushMarkerHistory(`move:${selectedMarkerId}`);
  marker.time = Math.max(0, Math.min(videoDuration, marker.time + deltaSec));
  tsMarkers.sort((a, b) => a.time - b.time);
  videoElement.currentTime = marker.time;
  updateTimeMarker();
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();
}

/**
 * 歌詞検索サイトの検索結果タイトル「アーティスト名 曲名 歌詞 ...」から
 * 「曲名 / アーティスト名」の変換候補一覧を生成する
 * 区切り位置はテキストだけでは一意に決まらないため、すべての分割候補を返し、
 * ユーザーに選択させる（例: 「BBB CCC AAA 歌詞」→「AAA / BBB CCC」「CCC AAA / BBB」）
 * @param {string} text - ペーストされたテキスト
 * @returns {string[]|null} 変換候補（曲名が短い順）。形式に合わない場合はnull
 */
function buildLyricsSplitCandidates(text) {
  const tokens = text.trim().split(/\s+/);
  // 単独の「歌詞」トークンより前の部分を「アーティスト名+曲名」とみなす
  // （「歌詞検索」のような複合語は区切りとして扱わない）
  const idx = tokens.indexOf('歌詞');
  if (idx < 2) return null; // 「歌詞」がない、または手前が1語以下で分割できない
  const parts = tokens.slice(0, idx);
  const candidates = [];
  for (let k = parts.length - 1; k >= 1; k--) {
    const artist = parts.slice(0, k).join(' ');
    const title = parts.slice(k).join(' ');
    candidates.push(`${title} / ${artist}`);
  }
  return candidates;
}

/**
 * 表示中の変換候補ポップアップ
 */
let lyricsPastePopup = null;
let lyricsPastePopupCleanup = null;

function closeLyricsPastePopup() {
  if (lyricsPastePopupCleanup) {
    lyricsPastePopupCleanup();
    lyricsPastePopupCleanup = null;
  }
  if (lyricsPastePopup) {
    lyricsPastePopup.remove();
    lyricsPastePopup = null;
  }
}

/**
 * 曲名入力欄の下に変換候補ポップアップを表示する
 * 候補クリックで挿入、Esc・外側クリックは元のテキストのまま挿入、
 * それ以外のキー入力はポップアップを閉じるだけ（挿入しない）
 * @param {HTMLInputElement} input - ペースト先の入力欄
 * @param {string[]} candidates - 変換候補
 * @param {string} rawText - 元のペーストテキスト
 */
function showLyricsPastePopup(input, candidates, rawText) {
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  if (!volumeGraphContainer) return;

  // 候補値は属性に埋め込まずインデックスで参照する（escapeHtmlは引用符をエスケープしないため）
  const values = [...candidates, rawText];
  const popup = document.createElement('div');
  popup.className = 'vdg-paste-popup';
  popup.innerHTML = `
    <div class="vdg-paste-popup-title">変換候補（クリックで挿入）</div>
    ${candidates.map((c, i) => `<div class="vdg-paste-popup-item" data-index="${i}">${escapeHtml(c)}</div>`).join('')}
    <div class="vdg-paste-popup-item raw" data-index="${candidates.length}">そのまま貼り付け</div>
  `;

  // 入力欄の直下に配置（グラフコンテナ基準の絶対配置）
  // 一覧のスクロールに追従し、コンテナ右端からはみ出さないようにクランプする
  const listEl = volumeGraphContainer.querySelector('#vdg-ts-list');
  const reposition = () => {
    const containerRect = volumeGraphContainer.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const maxLeft = containerRect.width - popup.offsetWidth - 4;
    popup.style.left = `${Math.max(0, Math.min(inputRect.left - containerRect.left, maxLeft))}px`;
    popup.style.top = `${inputRect.bottom - containerRect.top + 2}px`;
  };

  // 挿入して閉じる（execCommandならネイティブのinputイベント発火とUndo履歴が維持される）
  const insertAndClose = (value) => {
    closeLyricsPastePopup();
    input.focus({ preventScroll: true });
    document.execCommand('insertText', false, value);
  };

  // mousedownで処理して入力欄のフォーカスを維持する
  popup.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const item = e.target.closest('.vdg-paste-popup-item');
    if (item) {
      insertAndClose(values[parseInt(item.dataset.index)]);
    }
  });

  // Esc: 元のテキストのまま挿入 / その他のキー: 閉じるだけ
  const onKeydown = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      insertAndClose(rawText);
    } else {
      closeLyricsPastePopup();
    }
  };
  // 外側クリック: 元のテキストのまま挿入（入力欄内のクリックはカーソル移動なので閉じない）
  const onOutsideMousedown = (e) => {
    if (popup.contains(e.target) || e.target === input) return;
    insertAndClose(rawText);
  };

  input.addEventListener('keydown', onKeydown, true);
  document.addEventListener('mousedown', onOutsideMousedown, true);
  listEl?.addEventListener('scroll', reposition);
  lyricsPastePopupCleanup = () => {
    input.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('mousedown', onOutsideMousedown, true);
    listEl?.removeEventListener('scroll', reposition);
  };

  lyricsPastePopup = popup;
  volumeGraphContainer.appendChild(popup);
  // offsetWidthを使うためDOM追加後に配置
  reposition();
}

/**
 * 曲名候補ポップアップ（字幕フィンガープリントによるマッチング候補・#595）
 */
let songCandidatePopup = null;
let songCandidatePopupCleanup = null;

// 表示要求の世代。await中にポップアップが閉じられた/別マーカーで開き直された場合、
// 古い要求の結果で上書きしないためのガード
let songCandidateRequestSeq = 0;

// 字幕準備フロー（取得→送信）の実行中Promise（動画ID単位の相乗り用）。
// 複数マーカーで連打しても字幕の取得・送信は1本しか走らない
let subtitlePrepareFlow = null;

function closeSongCandidatePopup() {
  songCandidateRequestSeq++;
  if (songCandidatePopupCleanup) {
    songCandidatePopupCleanup();
    songCandidatePopupCleanup = null;
  }
  if (songCandidatePopup) {
    songCandidatePopup.remove();
    songCandidatePopup = null;
  }
}

/**
 * 「候補」ボタンから曲名候補を表示するフロー
 * 字幕がサーバー未送信の場合は取得→送信してから再問い合わせする
 */
async function showSongCandidates(marker) {
  const input = volumeGraphContainer?.querySelector(`.vdg-ts-text-input[data-marker-id="${marker.id}"]`);
  if (!input) return;

  // openSongCandidatePopupは開き直しのたびに内部で世代を進めるため、
  // 自分で開いた直後の世代を控えて「外部から閉じられた/開き直された」を検出する
  let seq;
  const open = (items) => {
    openSongCandidatePopup(input, items);
    seq = songCandidateRequestSeq;
  };
  const isStale = () => seq !== songCandidateRequestSeq;

  open([{ type: 'message', label: '候補を検索しています…' }]);

  try {
    if (!ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!ycsApiToken) {
      if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: 'APIトークンが未設定です。プロフィール画面で発行し、拡張の設定に登録してください' }]);
      return;
    }

    const videoId = getVideoId();
    if (!videoId) {
      if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: '動画IDを取得できませんでした' }]);
      return;
    }

    const sec = Math.floor(marker.time);
    let result = await fetchSongCandidates(videoId, sec);
    if (isStale()) return;

    // 字幕が未送信なら取得→送信してから再問い合わせ
    if (result.has_subtitles === false) {
      openSongCandidatePopup(input, [{ type: 'message', label: '字幕を取得しています…' }]);
      await ensureSubtitlesOnServer(videoId);
      if (isStale()) return;
      result = await fetchSongCandidates(videoId, sec);
      if (isStale()) return;
    }

    if (result.has_fingerprint === false) {
      openSongCandidatePopup(input, [{ type: 'message', label: 'この位置の字幕から候補を計算できませんでした（歌声の字幕が少ない可能性があります）' }]);
      return;
    }

    const candidates = (result.candidates || []).slice(0, 5);
    if (candidates.length === 0) {
      openSongCandidatePopup(input, [{ type: 'message', label: '候補が見つかりませんでした' }]);
      return;
    }

    openSongCandidatePopup(input, candidates.map(c => ({
      type: 'candidate',
      label: c.song_title || c.normalized_text || '',
      artist: c.song_artist || '',
      similarity: c.similarity,
    })));
  } catch (error) {
    console.warn('[YCS] 曲名候補の取得エラー:', error.message);
    if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: 'エラー: ' + error.message }]);
  }
}

/**
 * 曲名候補APIを呼び出す
 */
async function fetchSongCandidates(videoId, sec) {
  const response = await fetch(
    `${ycsServerUrl}/api/extension/subtitle-matches?video_id=${encodeURIComponent(videoId)}&sec=${sec}`,
    {
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${ycsApiToken}`,
      },
    }
  );

  if (response.status === 401) throw new Error('APIトークンが無効です');
  if (response.status === 403) throw new Error('このチャンネルへのアクセス権限がありません');
  if (response.status === 404) throw new Error('この動画はアーカイブに登録されていません');
  if (!response.ok) throw new Error(`候補の取得に失敗しました (${response.status})`);

  return response.json();
}

/**
 * 字幕をサーバーに送信済みの状態にする（取得→送信）
 * 実行中の再呼び出しは同じPromiseに相乗りし、二重送信を防ぐ
 */
function ensureSubtitlesOnServer(videoId) {
  if (subtitlePrepareFlow && subtitlePrepareFlow.videoId === videoId) {
    return subtitlePrepareFlow.promise;
  }

  const promise = (async () => {
    const tracks = await getCaptionTracksFromPage();
    if (!tracks || tracks.length === 0) {
      throw new Error('この動画には字幕がありません');
    }
    const track = pickPreferredCaptionTrack(tracks);
    const segments = await fetchTimedText(videoId, track.languageCode);
    if (!segments || segments.length === 0) {
      throw new Error('字幕を取得できませんでした');
    }
    await postSubtitlesToServer(videoId, track.languageCode, track.kind === 'asr' ? 'asr' : '', segments);
  })().finally(() => {
    if (subtitlePrepareFlow?.videoId === videoId) {
      subtitlePrepareFlow = null;
    }
  });

  subtitlePrepareFlow = { videoId, promise };

  return promise;
}

/**
 * 候補計算に使う字幕トラックを選ぶ（サーバー側の言語優先と同じ考え方）
 * 日本語（手動 > 自動生成）> 先頭のトラック
 */
function pickPreferredCaptionTrack(tracks) {
  const ja = tracks.filter(t => (t.languageCode || '').startsWith('ja'));
  return ja.find(t => t.kind !== 'asr') || ja[0] || tracks[0];
}

/**
 * 曲名候補ポップアップを表示する（既存のペースト変換ポップアップと同じ操作感）
 * 候補クリックで入力欄の内容を置き換え、Esc・外側クリック・他のキーで閉じる
 * @param {HTMLInputElement} input - 挿入先の入力欄
 * @param {Array<{type: string, label: string, artist?: string, similarity?: number}>} items
 */
function openSongCandidatePopup(input, items) {
  closeSongCandidatePopup();
  closeLyricsPastePopup();
  if (!volumeGraphContainer) return;

  const popup = document.createElement('div');
  popup.className = 'vdg-paste-popup';
  popup.innerHTML = `
    <div class="vdg-paste-popup-title">曲名候補（クリックで挿入）</div>
    ${items.map((item, i) => item.type === 'candidate' ? `
      <div class="vdg-paste-popup-item" data-index="${i}">${escapeHtml(item.label)}${item.artist ? `<span class="artist">${escapeHtml(item.artist)}</span>` : ''}<span class="similarity">${Math.round((item.similarity || 0) * 100)}%</span></div>
    ` : `
      <div class="vdg-paste-popup-item message">${escapeHtml(item.label)}</div>
    `).join('')}
  `;

  // 入力欄の直下に配置（グラフコンテナ基準の絶対配置）
  const listEl = volumeGraphContainer.querySelector('#vdg-ts-list');
  const reposition = () => {
    const containerRect = volumeGraphContainer.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const maxLeft = containerRect.width - popup.offsetWidth - 4;
    popup.style.left = `${Math.max(0, Math.min(inputRect.left - containerRect.left, maxLeft))}px`;
    popup.style.top = `${inputRect.bottom - containerRect.top + 2}px`;
  };

  // 候補クリック: 入力欄の内容を候補で置き換える
  // （execCommandならネイティブのinputイベント発火とUndo履歴が維持される）
  popup.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const item = e.target.closest('.vdg-paste-popup-item');
    if (item && item.dataset.index !== undefined) {
      const value = items[parseInt(item.dataset.index)]?.label ?? '';
      closeSongCandidatePopup();
      input.focus({ preventScroll: true });
      input.select();
      document.execCommand('insertText', false, value);
    }
  });

  // Escや他のキー入力: 閉じるだけ（挿入しない）
  const onKeydown = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
    }
    closeSongCandidatePopup();
  };

  // 外側クリック: 閉じるだけ（入力欄内のクリックはカーソル移動なので閉じない）
  const onOutsideMousedown = (e) => {
    if (popup.contains(e.target) || e.target === input) return;
    closeSongCandidatePopup();
  };

  input.addEventListener('keydown', onKeydown, true);
  document.addEventListener('mousedown', onOutsideMousedown, true);
  listEl?.addEventListener('scroll', reposition);
  songCandidatePopupCleanup = () => {
    input.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('mousedown', onOutsideMousedown, true);
    listEl?.removeEventListener('scroll', reposition);
  };

  songCandidatePopup = popup;
  volumeGraphContainer.appendChild(popup);
  // offsetWidthを使うためDOM追加後に配置
  reposition();
}

/**
 * タイムスタンプをフォーマット（ゼロ埋め設定考慮）
 * @param {number} seconds - 秒数
 * @returns {string} フォーマットされた時刻文字列
 */
function formatTimestamp(seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);

  const needsHour = videoDuration >= 3600;

  if (tsZeroPad) {
    if (needsHour) {
      return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  } else {
    if (needsHour) {
      return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m}:${s.toString().padStart(2, '0')}`;
  }
}

/**
 * タイムスタンプをテキストとしてコピー
 */
function copyTimestamps() {
  if (tsMarkers.length === 0) return;

  const text = tsMarkers
    .map(m => `${formatTimestamp(m.time)} ${m.text}`)
    .join('\n');

  navigator.clipboard.writeText(text).then(() => {
    const copyBtn = volumeGraphContainer?.querySelector('#vdg-ts-copy-btn');
    if (copyBtn) {
      const original = copyBtn.textContent;
      copyBtn.textContent = 'コピー済み';
      setTimeout(() => { copyBtn.textContent = original; }, 1500);
    }
  });
}

/**
 * マーカーをストレージに保存
 */
function saveMarkersToStorage() {
  const videoId = getVideoId();
  if (!videoId) return;
  const key = `tsMarkers_${videoId}`;
  chrome.storage.local.set({ [key]: { markers: tsMarkers, nextId: nextMarkerId } });
}

/**
 * マーカーをストレージから復元
 */
function loadMarkersFromStorage() {
  const videoId = getVideoId();
  if (!videoId) return;
  const key = `tsMarkers_${videoId}`;
  chrome.storage.local.get(key, (result) => {
    // 読み込み中に別の動画へ遷移していた場合は破棄する（別動画のマーカーを適用しない）
    if (getVideoId() !== videoId) return;

    const saved = result[key];
    if (saved && saved.markers) {
      tsMarkers = saved.markers;
      nextMarkerId = saved.nextId || tsMarkers.length + 1;
      selectedMarkerId = null;
      // 別動画の履歴を引き継がないようリセット
      tsHistoryUndo = [];
      tsHistoryRedo = [];
      lastHistoryTag = null;
      updateUndoRedoButtons();
      updateTimestampList();
      drawVolumeGraph();
    }
  });
}

/**
 * 動画切替時にタイムスタンプエディタの状態を初期化し、新しい動画の保存データを読み込む
 *
 * YouTubeのSPA遷移では content script が再読み込みされないため、これを呼ばないと
 * 前の動画のマーカーが残ったままになり、編集すると saveMarkersToStorage() が
 * 「新しい動画のキー」に「前の動画のマーカー」を保存してデータが混ざる
 */
function resetTimestampEditorForVideoChange() {
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  tsMarkers = [];
  selectedMarkerId = null;
  nextMarkerId = 1;
  tsHistoryUndo = [];
  tsHistoryRedo = [];
  lastHistoryTag = null;
  updateUndoRedoButtons();
  updateTimestampList();
  drawVolumeGraph();
  // 新しい動画に保存済みマーカーがあれば読み込む（なければ空のまま）
  loadMarkersFromStorage();
}

/**
 * 再生位置マーカーを更新
 */
function updateTimeMarker() {
  if (!videoElement || !videoDuration || !volumeGraphContainer) return;
  const marker = volumeGraphContainer.querySelector('#vdg-time-marker');
  if (!marker) return;

  const ratio = videoElement.currentTime / videoDuration;
  marker.style.left = `${ratio * 100}%`;
}

/**
 * 時間表示をフォーマット
 */
function formatTimeDisplay(seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);

  if (h > 0) {
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }
  return `${m}:${s.toString().padStart(2, '0')}`;
}

/**
 * 進捗を更新
 */
function updateProgress(percent) {
  if (!volumeGraphContainer) return;
  const progress = volumeGraphContainer.querySelector('#vdg-progress');
  if (progress) {
    progress.textContent = `分析 ${Math.round(percent)}%`;
  }
}

/**
 * 動画時間を更新
 */
function updateVideoDuration() {
  if (!videoElement) return;
  videoDuration = videoElement.duration || 0;

  if (!volumeGraphContainer) return;
  const endTime = volumeGraphContainer.querySelector('#vdg-end-time');
  if (endTime && videoDuration) {
    endTime.textContent = formatTimeDisplay(videoDuration);
  }
}

/**
 * YouTubeページ変更を監視（SPA対応）
 */
function observePageChanges() {
  let lastUrl = location.href;
  let lastVideoId = getVideoId();
  let wasWatchPage = isWatchPage();

  /**
   * ページ遷移時の共通処理
   */
  function handleNavigation() {
    const currentUrl = location.href;
    if (currentUrl === lastUrl) return;

    lastUrl = currentUrl;
    const nowWatchPage = isWatchPage();
    const currentVideoId = getVideoId();

    if (nowWatchPage && !wasWatchPage) {
      // 非watchページ → watchページへのSPA遷移（ホームや検索結果からの遷移）
      lastVideoId = currentVideoId;

      // 以前のwatchページのステートが残っている場合のためリセット
      volumeData = [];
      videoDuration = 0;
      detectedTimestamps = [];
      zoomIndex = 0;
      currentSubtitles = [];
      currentCaptionTracks = [];
      pageBridgeReady = null;
      // ハイライトパネルが開いていれば閉じる（前動画の結果が残らないよう）
      if (highlightPanelVisible) hideHighlightPanel();
      if (mediaElementSource) {
        mediaElementSource.disconnect();
        mediaElementSource = null;
      }
      analyserNode = null;
      gainNode = null;
      audioInitialized = false;

      initWatchPageUI();
      loadVolumeData();
      // 前のwatchページのマーカーが残らないようリセットして読み込み直す
      resetTimestampEditorForVideoChange();
    } else if (nowWatchPage && currentVideoId !== lastVideoId) {
      // watchページ → 別の動画のwatchページへの遷移
      lastVideoId = currentVideoId;
      volumeData = [];
      videoDuration = 0;
      detectedTimestamps = []; // タイムスタンプもクリア
      zoomIndex = 0; // ズームレベルをリセット
      currentSubtitles = []; // 字幕データをクリア
      currentCaptionTracks = [];
      pageBridgeReady = null;
      // ハイライトパネルが開いていれば閉じる（前動画の結果が残らないよう）
      if (highlightPanelVisible) hideHighlightPanel();

      // 音声解析の状態をリセット（新しいVideo要素用）
      if (mediaElementSource) {
        mediaElementSource.disconnect();
        mediaElementSource = null;
      }
      analyserNode = null;
      gainNode = null;
      audioInitialized = false;
      // AudioContextは再利用可能なのでそのまま

      drawVolumeGraph();
      findVideoElement();
      insertVolumeGraph();

      // 再生リストUIを更新
      updatePlaylistUI();

      // 保存済みデータを読み込み
      loadVolumeData();
      // 前の動画のマーカー・Undo履歴が残らないようリセットして読み込み直す
      resetTimestampEditorForVideoChange();

      // 自動スキャンモードの場合は継続
      if (isAutoScanMode && !autoScanStopRequested) {
        console.log('自動スキャン継続: 新しい動画を検出');
        // 少し待ってから次の動画のスキャンを開始
        setTimeout(async () => {
          if (!isAutoScanMode || autoScanStopRequested) return;

          const alreadyScanned = await isCurrentVideoScanned();
          if (alreadyScanned) {
            console.log('この動画はスキャン済み、次へ');
            proceedToNextVideoOrFinish();
          } else {
            // tabCapture方式でスキャン開始（常にミュート）
            chrome.runtime.sendMessage({ type: 'START_SCAN' });
          }
        }, 3000); // 動画の読み込みを待つ
      }
    } else if (!nowWatchPage && wasWatchPage) {
      // watchページ → 非watchページへの遷移（ホームや検索結果への遷移）
      hideWatchPageUI();
    }

    wasWatchPage = nowWatchPage;
  }

  // YouTube SPAナビゲーションイベントを監視（最も信頼性が高い）
  document.addEventListener('yt-navigate-finish', handleNavigation);

  // MutationObserverもフォールバックとして残す
  const observer = new MutationObserver(() => {
    if (location.href !== lastUrl) {
      handleNavigation();
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * メッセージハンドラ
 */
function handleMessage(message, sender, sendResponse) {
  switch (message.type) {
    case 'PING':
      sendResponse('PONG');
      return true;

    case 'SHOW_EMBEDDED_UI':
      showEmbeddedUI();
      sendResponse({ success: true });
      return true;

    case 'HIDE_EMBEDDED_UI':
      hideEmbeddedUI();
      sendResponse({ success: true });
      return true;

    case 'TIMESTAMP_DETECTED':
      // グラフ用にも保存
      detectedTimestamps.push(message.timestamp);
      // グラフを再描画してマーカーを表示
      drawVolumeGraph();
      break;

    // 音量グラフ関連
    case 'SHOW_VOLUME_GRAPH':
      if (volumeGraphContainer) {
        volumeGraphContainer.classList.add('visible');
        isGraphVisible = true;
        updateVideoDuration();
        resizeCanvas();
      } else {
        console.log('volumeGraphContainer が存在しません');
      }
      break;

    case 'HIDE_VOLUME_GRAPH':
      hideVolumeGraphPanel();
      break;

    case 'TOGGLE_VOLUME_GRAPH':
      console.log('TOGGLE_VOLUME_GRAPH 受信', { volumeGraphContainer: !!volumeGraphContainer, inDOM: volumeGraphContainer?.parentNode });
      if (volumeGraphContainer) {
        // DOMに追加されていない場合は再度挿入を試みる
        if (!volumeGraphContainer.parentNode) {
          console.log('グラフがDOMに存在しないため再挿入を試みます');
          insertVolumeGraph();
        }
        volumeGraphContainer.classList.toggle('visible');
        isGraphVisible = volumeGraphContainer.classList.contains('visible');
        if (!isGraphVisible) {
          // ポップアップを開いたまま非表示になるとリスナーが残留するため閉じる
          closeLyricsPastePopup();
          closeSongCandidatePopup();
        }
        const computed = getComputedStyle(volumeGraphContainer);
        console.log('グラフ表示状態:', isGraphVisible, {
          classList: volumeGraphContainer.className,
          computedDisplay: computed.display,
          computedVisibility: computed.visibility,
          computedOpacity: computed.opacity,
          computedHeight: computed.height,
          computedWidth: computed.width,
          boundingRect: volumeGraphContainer.getBoundingClientRect()
        });
        if (isGraphVisible) {
          updateVideoDuration();
          resizeCanvas();
        }
      } else {
        console.log('volumeGraphContainer が存在しません。createVolumeGraph を呼び出します');
        createVolumeGraph();
        if (volumeGraphContainer) {
          volumeGraphContainer.classList.add('visible');
          isGraphVisible = true;
          updateVideoDuration();
          resizeCanvas();
        }
      }
      break;

    case 'VOLUME_DATA_UPDATE':
      // 音量データを更新
      volumeData = message.data;
      updateProgress(message.progress || 0);
      drawVolumeGraph();
      // 定期的にデータを保存（スキャン中断に備える）
      const now = Date.now();
      if (now - lastSaveTime >= SAVE_INTERVAL && volumeData.some(v => v > 0)) {
        lastSaveTime = now;
        saveVolumeData();
        console.log('スキャン中: 音量データを自動保存');
      }
      break;

    case 'SCAN_STARTED':
      if (volumeGraphContainer) {
        const scanBtn = volumeGraphContainer.querySelector('#vdg-scan-btn');
        if (scanBtn) {
          scanBtn.classList.add('scanning');
          scanBtn.textContent = '停止';
        }
        // エラーメッセージを非表示
        hidePermissionError();
      }
      // リストスキャンボタンの状態更新
      updateListScanButtonState(true);
      break;

    case 'SCAN_PERMISSION_ERROR':
      // 権限エラー: ポップアップからの操作を促すメッセージを表示
      showPermissionError();
      break;

    case 'SCAN_STOPPED':
      if (volumeGraphContainer) {
        const scanBtnStop = volumeGraphContainer.querySelector('#vdg-scan-btn');
        if (scanBtnStop) {
          scanBtnStop.classList.remove('scanning');
        }
      }
      // スキャン完了時に結果を保存
      if (volumeData.length > 0) {
        saveVolumeData();
      }
      // ボタン状態を更新
      getScanStatus().then(status => {
        updateScanButtonState(status);
      });
      // 自動スキャンモード（再生リスト）の場合は次の動画へ
      if (isAutoScanMode && !autoScanStopRequested) {
        proceedToNextVideoOrFinish();
      }
      // リストスキャンモードの場合は次の動画へ
      if (isListScanMode) {
        hideListScanButton();
        proceedToNextListScanVideo();
      }
      break;

    case 'GET_VIDEO_INFO':
      updateVideoDuration();
      sendResponse({
        duration: videoDuration,
        currentTime: videoElement?.currentTime || 0
      });
      return true;

    case 'GET_SCAN_STATUS':
      // スキャン状況を返す
      getScanStatus().then(status => sendResponse(status));
      return true;

    case 'SEEK_VIDEO':
      if (videoElement) {
        videoElement.currentTime = message.time;
      }
      break;

    case 'SET_PLAYBACK_RATE':
      if (videoElement) {
        videoElement.playbackRate = message.rate;
      }
      break;

    case 'SET_MUTED':
      if (videoElement) {
        videoElement.muted = message.muted;
      }
      break;

    case 'PLAY_VIDEO':
      if (videoElement) {
        videoElement.play();
      }
      break;

    case 'PAUSE_VIDEO':
      if (videoElement) {
        videoElement.pause();
      }
      break;
  }
}

// ページ遷移時のクリーンアップと保存
window.addEventListener('beforeunload', () => {
  if (timeUpdateInterval) {
    clearInterval(timeUpdateInterval);
  }
  // スキャン中のデータを保存
  if (volumeData.length > 0 && volumeData.some(v => v > 0)) {
    saveVolumeData();
    console.log('ページ遷移: 音量データを保存');
  }
});

// 初期化実行
init();
