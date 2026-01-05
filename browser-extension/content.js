/**
 * 歌枠タイムスタンプ検出 - Content Script
 *
 * YouTubeページに注入され、動画の再生時刻を取得してbackground.jsに送信する
 * また、検出されたタイムスタンプをオーバーレイ表示する
 * 音量ダイナミクスグラフを表示してシークバーとして機能させる
 */

let videoElement = null;
let timeUpdateInterval = null;
let overlay = null;
let volumeGraphContainer = null;
let volumeCanvas = null;
let volumeCtx = null;
let volumeData = []; // 音量データを蓄積
let videoDuration = 0;
let isGraphVisible = false;

// 再生リスト自動スキャン用
let isAutoScanMode = false;
let autoScanStopRequested = false;

// 検出されたタイムスタンプ候補
let detectedTimestamps = [];

// 文字起こし機能用
let speechRecognition = null;
let isTranscribing = false;
let currentTranscriptIndex = 0;
const TRANSCRIPT_DURATION = 4; // 各タイムスタンプで再生する秒数

// 直接音声解析用（tabCaptureを使わない方式）
let audioContext = null;
let analyserNode = null;
let gainNode = null; // 音量制御用
let mediaElementSource = null;
let isScanning = false;
let scanInterval = null;
const GRAPH_RESOLUTION = 500; // グラフのデータポイント数
let originalPlaybackRate = 1;
let audioInitialized = false; // 音声解析が初期化済みか
let lastSaveTime = 0; // 最後に音量データを保存した時刻
const SAVE_INTERVAL = 3000; // 保存間隔（ミリ秒）

/**
 * 現在の動画IDを取得
 */
function getVideoId() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get('v');
}

/**
 * 音量データをストレージに保存
 */
function saveVolumeData() {
  const videoId = getVideoId();
  if (!videoId || volumeData.length === 0) return;

  const storageKey = `volumeData_${videoId}`;
  const dataToSave = {
    data: volumeData,
    duration: videoDuration,
    timestamps: detectedTimestamps, // タイムスタンプも保存
    savedAt: Date.now()
  };

  chrome.storage.local.set({ [storageKey]: dataToSave }, () => {
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
        // オーバーレイにも追加
        for (const ts of detectedTimestamps) {
          addTimestamp(ts);
        }
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
  if (overlay) {
    const list = overlay.querySelector('.timestamp-list');
    if (list) list.innerHTML = '';
  }

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
        addTimestamp(timestamp);
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
      const GRAPH_RESOLUTION = 500;

      // 進捗を計算（データがある部分の割合）
      let lastFilledIndex = -1;
      for (let i = data.length - 1; i >= 0; i--) {
        if (data[i] > 0) {
          lastFilledIndex = i;
          break;
        }
      }

      const progress = lastFilledIndex >= 0
        ? ((lastFilledIndex + 1) / GRAPH_RESOLUTION) * 100
        : 0;

      // 95%以上なら完了とみなす
      const isComplete = progress >= 95;

      // 再開時刻を計算
      const resumeTime = duration > 0 && lastFilledIndex >= 0
        ? (lastFilledIndex / GRAPH_RESOLUTION) * duration
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

// 初期化
function init() {
  // メッセージリスナーを最初に設定（他の処理でエラーが出ても通信可能にする）
  chrome.runtime.onMessage.addListener(handleMessage);

  try {
    // 動画要素を取得
    findVideoElement();

    // オーバーレイを作成
    createOverlay();

    // 音量グラフを作成
    createVolumeGraph();

    // YouTube SPAナビゲーション対応
    observePageChanges();
  } catch (error) {
    console.error('Content script初期化エラー:', error);
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

  // 動画のメタデータ読み込み時に長さを取得
  videoElement.addEventListener('loadedmetadata', () => {
    updateVideoDuration();
  });

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
 * オーバーレイUIを作成
 */
function createOverlay() {
  overlay = document.createElement('div');
  overlay.id = 'timestamp-detector-overlay';
  overlay.innerHTML = `
    <style>
      #timestamp-detector-overlay {
        position: fixed;
        top: 80px;
        right: 20px;
        width: 320px;
        max-height: 400px;
        background: rgba(0, 0, 0, 0.9);
        border: 1px solid #444;
        border-radius: 8px;
        color: white;
        font-family: 'Segoe UI', sans-serif;
        font-size: 13px;
        z-index: 9999;
        display: none;
        flex-direction: column;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      }

      #timestamp-detector-overlay.visible {
        display: flex;
      }

      .tsd-header {
        padding: 12px;
        background: #1a1a1a;
        border-bottom: 1px solid #444;
        border-radius: 8px 8px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .tsd-header h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
      }

      .tsd-status {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
      }

      .tsd-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #666;
      }

      .tsd-status-dot.active {
        background: #4caf50;
        animation: pulse 1.5s infinite;
      }

      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
      }

      .tsd-content {
        padding: 12px;
        overflow-y: auto;
        flex: 1;
      }

      .tsd-timestamps {
        list-style: none;
        padding: 0;
        margin: 0;
      }

      .tsd-timestamp-item {
        display: flex;
        align-items: center;
        padding: 8px;
        margin-bottom: 6px;
        background: #2a2a2a;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.2s;
      }

      .tsd-timestamp-item:hover {
        background: #3a3a3a;
      }

      .tsd-timestamp-item.new {
        animation: highlight 1s ease-out;
      }

      .tsd-timestamp-item.transcribing {
        background: #1565c0;
        border-left: 3px solid #4fc3f7;
      }

      @keyframes highlight {
        0% { background: #4a6a4a; }
        100% { background: #2a2a2a; }
      }

      .tsd-time {
        font-family: monospace;
        font-size: 14px;
        font-weight: 600;
        color: #4fc3f7;
        margin-right: 12px;
        min-width: 60px;
      }

      .tsd-label {
        flex: 1;
        color: #999;
        font-size: 12px;
      }

      .tsd-remove {
        background: none;
        border: none;
        color: #666;
        cursor: pointer;
        padding: 4px;
        font-size: 16px;
      }

      .tsd-remove:hover {
        color: #f44336;
      }

      .tsd-empty {
        color: #666;
        text-align: center;
        padding: 20px;
      }

      .tsd-footer {
        padding: 12px;
        border-top: 1px solid #444;
        display: flex;
        gap: 8px;
      }

      .tsd-btn {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        transition: background 0.2s;
      }

      .tsd-btn-primary {
        background: #1976d2;
        color: white;
      }

      .tsd-btn-primary:hover {
        background: #1565c0;
      }

      .tsd-btn-secondary {
        background: #333;
        color: white;
      }

      .tsd-btn-secondary:hover {
        background: #444;
      }

      .tsd-btn-danger {
        background: #c62828;
        color: white;
      }

      .tsd-btn-danger:hover {
        background: #b71c1c;
      }
    </style>
    <div class="tsd-header">
      <h3>タイムスタンプ検出</h3>
      <div class="tsd-status">
        <span class="tsd-status-dot" id="tsd-status-dot"></span>
        <span id="tsd-status-text">停止中</span>
      </div>
    </div>
    <div class="tsd-content">
      <ul class="tsd-timestamps" id="tsd-timestamp-list">
        <li class="tsd-empty">検出されたタイムスタンプがありません</li>
      </ul>
    </div>
    <div class="tsd-footer">
      <button class="tsd-btn tsd-btn-secondary" id="tsd-copy-btn">コピー</button>
      <button class="tsd-btn tsd-btn-danger" id="tsd-clear-btn">クリア</button>
    </div>
  `;

  document.body.appendChild(overlay);

  // イベントリスナーを設定
  document.getElementById('tsd-copy-btn').addEventListener('click', copyTimestamps);
  document.getElementById('tsd-clear-btn').addEventListener('click', clearTimestamps);
}

/**
 * タイムスタンプを追加
 */
function addTimestamp(timestamp) {
  const list = document.getElementById('tsd-timestamp-list');
  const empty = list.querySelector('.tsd-empty');
  if (empty) {
    empty.remove();
  }

  // 文字起こし結果があれば表示
  const labelText = timestamp.transcript || '楽曲開始候補';

  const item = document.createElement('li');
  item.className = 'tsd-timestamp-item new';
  item.dataset.time = timestamp.time;
  item.innerHTML = `
    <span class="tsd-time">${timestamp.formattedTime}</span>
    <span class="tsd-label">${labelText}</span>
    <button class="tsd-remove" title="削除">&times;</button>
  `;

  // クリックで動画をシーク
  item.addEventListener('click', (e) => {
    if (e.target.classList.contains('tsd-remove')) {
      item.remove();
      if (list.children.length === 0) {
        list.innerHTML = '<li class="tsd-empty">検出されたタイムスタンプがありません</li>';
      }
      return;
    }
    if (videoElement) {
      videoElement.currentTime = parseFloat(item.dataset.time);
    }
  });

  list.appendChild(item);

  // newクラスを削除
  setTimeout(() => item.classList.remove('new'), 1000);
}

/**
 * タイムスタンプをクリップボードにコピー
 */
async function copyTimestamps() {
  const items = document.querySelectorAll('.tsd-timestamp-item');
  if (items.length === 0) return;

  const lines = Array.from(items).map(item => {
    const time = item.querySelector('.tsd-time').textContent;
    const label = item.querySelector('.tsd-label').textContent;
    // 文字起こし結果があれば一緒にコピー
    if (label && label !== '楽曲開始候補') {
      return `${time} ${label}`;
    }
    return time;
  });

  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    const btn = document.getElementById('tsd-copy-btn');
    const originalText = btn.textContent;
    btn.textContent = 'コピーしました!';
    setTimeout(() => btn.textContent = originalText, 1500);
  } catch (err) {
    console.error('コピーに失敗しました:', err);
  }
}

/**
 * タイムスタンプをクリア
 */
function clearTimestamps() {
  chrome.runtime.sendMessage({ type: 'CLEAR_TIMESTAMPS' });
  const list = document.getElementById('tsd-timestamp-list');
  list.innerHTML = '<li class="tsd-empty">検出されたタイムスタンプがありません</li>';
}

/**
 * 音量グラフUIを作成
 */
function createVolumeGraph() {
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
        flex: 1;
        position: relative;
        min-height: 40px;
      }

      #volume-canvas {
        width: 100%;
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

      .vdg-params-panel {
        border-top: 1px solid #333;
        margin-top: 4px;
      }

      .vdg-params-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        cursor: pointer;
        font-size: 11px;
        color: #888;
      }

      .vdg-params-header:hover {
        color: #fff;
      }

      .vdg-params-header.expanded span:first-child::before {
        content: '▼ ';
      }

      .vdg-params-header span:first-child::before {
        content: '▶ ';
      }

      .vdg-params-header span:first-child {
        margin-left: -12px;
        padding-left: 12px;
      }

      .vdg-btn-small {
        padding: 2px 8px !important;
        font-size: 10px !important;
      }

      .vdg-params-content {
        padding: 8px;
        background: #1a1a1a;
      }

      .vdg-param-row {
        display: flex;
        flex-direction: column;
        margin-bottom: 8px;
      }

      .vdg-param-row:last-child {
        margin-bottom: 0;
      }

      .vdg-param-row label {
        font-size: 10px;
        color: #aaa;
        margin-bottom: 2px;
      }

      .vdg-param-row input[type="range"] {
        width: 100%;
        height: 4px;
        -webkit-appearance: none;
        background: #333;
        border-radius: 2px;
        outline: none;
      }

      .vdg-param-row input[type="range"]::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 12px;
        height: 12px;
        background: #4fc3f7;
        border-radius: 50%;
        cursor: pointer;
      }
    </style>
    <div class="vdg-header">
      <span class="vdg-title">音量ダイナミクス</span>
      <div class="vdg-controls">
        <span class="vdg-playlist-info" id="vdg-playlist-info"></span>
        <span class="vdg-progress" id="vdg-progress">0%</span>
        <button class="vdg-btn" id="vdg-scan-btn" title="動画全体をスキャンしてグラフを生成">スキャン</button>
        <button class="vdg-btn" id="vdg-transcribe-btn" title="検出された候補を順番に文字起こし">文字起こし</button>
        <button class="vdg-btn" id="vdg-auto-scan-btn" title="再生リスト内の動画を順番にスキャン">自動</button>
      </div>
    </div>
    <div class="vdg-canvas-container">
      <canvas id="volume-canvas"></canvas>
      <div class="vdg-time-marker" id="vdg-time-marker"></div>
      <div class="vdg-hover-time" id="vdg-hover-time">0:00</div>
    </div>
    <div class="vdg-time-labels">
      <span id="vdg-start-time">0:00</span>
      <span id="vdg-end-time">--:--</span>
    </div>
    <div class="vdg-params-panel" id="vdg-params-panel">
      <div class="vdg-params-header" id="vdg-params-toggle">
        <span>検出パラメータ</span>
        <button class="vdg-btn vdg-btn-small" id="vdg-redetect-btn" title="現在のパラメータでタイムスタンプを再検出">再検出</button>
      </div>
      <div class="vdg-params-content" id="vdg-params-content" style="display: none;">
        <div class="vdg-param-row">
          <label>音量しきい値: <span id="vdg-volume-threshold-val">0.15</span></label>
          <input type="range" id="vdg-volume-threshold" min="0.05" max="0.5" step="0.01" value="0.15">
        </div>
        <div class="vdg-param-row">
          <label>静音しきい値: <span id="vdg-quiet-threshold-val">0.05</span></label>
          <input type="range" id="vdg-quiet-threshold" min="0.01" max="0.2" step="0.01" value="0.05">
        </div>
        <div class="vdg-param-row">
          <label>静音最小時間(秒): <span id="vdg-quiet-duration-val">1.0</span></label>
          <input type="range" id="vdg-quiet-duration" min="0.5" max="5.0" step="0.1" value="1.0">
        </div>
        <div class="vdg-param-row">
          <label>クールダウン(秒): <span id="vdg-cooldown-val">3.0</span></label>
          <input type="range" id="vdg-cooldown" min="1.0" max="10.0" step="0.5" value="3.0">
        </div>
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
    console.log('insertVolumeGraph: belowContainer=', belowContainer?.id, 'already exists=', !!document.getElementById('volume-dynamics-graph'));
    if (belowContainer && !document.getElementById('volume-dynamics-graph')) {
      belowContainer.insertBefore(volumeGraphContainer, belowContainer.firstChild);
      console.log('グラフを挿入しました:', {
        parent: belowContainer.id,
        inserted: volumeGraphContainer.id,
        computedDisplay: getComputedStyle(volumeGraphContainer).display,
        classList: volumeGraphContainer.className
      });
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
  if (!volumeCanvas) return;
  const container = volumeCanvas.parentElement;
  if (!container) return;

  const rect = container.getBoundingClientRect();
  volumeCanvas.width = rect.width * window.devicePixelRatio;
  volumeCanvas.height = rect.height * window.devicePixelRatio;
  volumeCtx.scale(window.devicePixelRatio, window.devicePixelRatio);
  drawVolumeGraph();
}

/**
 * 音量グラフのイベント設定
 */
function setupVolumeGraphEvents() {
  const container = volumeGraphContainer.querySelector('.vdg-canvas-container');
  const hoverTime = volumeGraphContainer.querySelector('#vdg-hover-time');
  const scanBtn = volumeGraphContainer.querySelector('#vdg-scan-btn');
  const autoScanBtn = volumeGraphContainer.querySelector('#vdg-auto-scan-btn');
  const transcribeBtn = volumeGraphContainer.querySelector('#vdg-transcribe-btn');
  const playlistInfo = volumeGraphContainer.querySelector('#vdg-playlist-info');

  console.log('setupVolumeGraphEvents: ', {
    container: !!container,
    hoverTime: !!hoverTime,
    scanBtn: !!scanBtn,
    autoScanBtn: !!autoScanBtn,
    transcribeBtn: !!transcribeBtn
  });

  // 再生リスト情報を更新
  updatePlaylistUI();

  // クリックでシーク
  if (container) {
    container.addEventListener('click', (e) => {
      if (!videoElement || !videoDuration) return;
      const rect = container.getBoundingClientRect();
      const x = e.clientX - rect.left;
      const ratio = x / rect.width;
      const seekTime = ratio * videoDuration;
      videoElement.currentTime = seekTime;
    });

    // ホバーで時間表示
    container.addEventListener('mousemove', (e) => {
      if (!videoDuration) return;
      const rect = container.getBoundingClientRect();
      const x = e.clientX - rect.left;
      const ratio = Math.max(0, Math.min(1, x / rect.width));
      const time = ratio * videoDuration;
      if (hoverTime) {
        hoverTime.textContent = formatTimeDisplay(time);
        hoverTime.style.left = `${x}px`;
      }
    });
  }

  // スキャンボタン（tabCapture方式、常にミュート）
  if (scanBtn) {
    scanBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      chrome.runtime.sendMessage({ type: 'START_SCAN' });
    });
  }

  // 文字起こしボタン
  if (transcribeBtn) {
    transcribeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (isTranscribing) {
        stopTranscription();
      } else {
        startTranscription();
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

  // パラメータパネルのトグル
  const paramsToggle = volumeGraphContainer.querySelector('#vdg-params-toggle');
  const paramsContent = volumeGraphContainer.querySelector('#vdg-params-content');
  const redetectBtn = volumeGraphContainer.querySelector('#vdg-redetect-btn');

  if (paramsToggle && paramsContent) {
    paramsToggle.addEventListener('click', (e) => {
      // 再検出ボタンのクリックは除外
      if (e.target.id === 'vdg-redetect-btn') return;

      const isExpanded = paramsContent.style.display !== 'none';
      paramsContent.style.display = isExpanded ? 'none' : 'block';
      paramsToggle.classList.toggle('expanded', !isExpanded);
    });
  }

  // 再検出ボタン
  if (redetectBtn) {
    redetectBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      redetectTimestamps();
    });
  }

  // パラメータスライダー
  const volumeThresholdSlider = volumeGraphContainer.querySelector('#vdg-volume-threshold');
  const quietThresholdSlider = volumeGraphContainer.querySelector('#vdg-quiet-threshold');
  const quietDurationSlider = volumeGraphContainer.querySelector('#vdg-quiet-duration');
  const cooldownSlider = volumeGraphContainer.querySelector('#vdg-cooldown');

  if (volumeThresholdSlider) {
    volumeThresholdSlider.addEventListener('input', (e) => {
      detectParams.volumeThreshold = parseFloat(e.target.value);
      volumeGraphContainer.querySelector('#vdg-volume-threshold-val').textContent = detectParams.volumeThreshold.toFixed(2);
    });
  }

  if (quietThresholdSlider) {
    quietThresholdSlider.addEventListener('input', (e) => {
      detectParams.quietThreshold = parseFloat(e.target.value);
      volumeGraphContainer.querySelector('#vdg-quiet-threshold-val').textContent = detectParams.quietThreshold.toFixed(2);
    });
  }

  if (quietDurationSlider) {
    quietDurationSlider.addEventListener('input', (e) => {
      detectParams.quietMinDuration = parseFloat(e.target.value);
      volumeGraphContainer.querySelector('#vdg-quiet-duration-val').textContent = detectParams.quietMinDuration.toFixed(1);
    });
  }

  if (cooldownSlider) {
    cooldownSlider.addEventListener('input', (e) => {
      detectParams.cooldown = parseFloat(e.target.value);
      volumeGraphContainer.querySelector('#vdg-cooldown-val').textContent = detectParams.cooldown.toFixed(1);
    });
  }
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
  volumeData = new Array(GRAPH_RESOLUTION).fill(0);

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
    const index = Math.floor((videoElement.currentTime / videoDuration) * GRAPH_RESOLUTION);

    if (index >= 0 && index < GRAPH_RESOLUTION) {
      if (normalizedVolume > volumeData[index]) {
        volumeData[index] = normalizedVolume;
      }
    }

    // 進捗を更新
    const progress = (volumeData.filter(v => v > 0).length / GRAPH_RESOLUTION) * 100;
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
    const barHeight = volumeData[i] * height;
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
  if (!volumeCtx || !videoDuration || detectedTimestamps.length === 0) return;

  volumeCtx.strokeStyle = '#ff5722'; // オレンジ色
  volumeCtx.lineWidth = 2;
  volumeCtx.setLineDash([4, 2]); // 破線

  for (const ts of detectedTimestamps) {
    const x = (ts.time / videoDuration) * width;

    // 縦線を描画
    volumeCtx.beginPath();
    volumeCtx.moveTo(x, 0);
    volumeCtx.lineTo(x, height);
    volumeCtx.stroke();

    // 三角マーカーを上部に描画
    volumeCtx.fillStyle = '#ff5722';
    volumeCtx.beginPath();
    volumeCtx.moveTo(x, 0);
    volumeCtx.lineTo(x - 4, 8);
    volumeCtx.lineTo(x + 4, 8);
    volumeCtx.closePath();
    volumeCtx.fill();
  }

  volumeCtx.setLineDash([]); // 破線をリセット
}

/**
 * Web Speech APIを初期化
 */
function initSpeechRecognition() {
  if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
    console.error('このブラウザはWeb Speech APIに対応していません');
    return null;
  }

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const recognition = new SpeechRecognition();

  recognition.lang = 'ja-JP';
  recognition.interimResults = true;
  recognition.continuous = true;
  recognition.maxAlternatives = 1;

  return recognition;
}

/**
 * 文字起こしを開始
 */
function startTranscription() {
  if (detectedTimestamps.length === 0) {
    console.log('文字起こし: 検出されたタイムスタンプがありません');
    alert('先にスキャンを実行してタイムスタンプ候補を検出してください');
    return;
  }

  if (!videoElement) {
    console.error('文字起こし: Video要素が見つかりません');
    return;
  }

  // Speech Recognition初期化
  speechRecognition = initSpeechRecognition();
  if (!speechRecognition) {
    alert('このブラウザはWeb Speech APIに対応していません');
    return;
  }

  isTranscribing = true;
  currentTranscriptIndex = 0;

  // UIを更新
  updateTranscribeButtonUI(true);

  // オーバーレイを表示（結果を確認できるように）
  if (overlay) {
    overlay.classList.add('visible');
  }

  console.log(`文字起こし開始: ${detectedTimestamps.length}件の候補`);

  // 最初のタイムスタンプから開始
  transcribeNextTimestamp();
}

/**
 * 文字起こしを停止
 */
function stopTranscription() {
  isTranscribing = false;

  if (speechRecognition) {
    speechRecognition.stop();
    speechRecognition = null;
  }

  if (videoElement) {
    videoElement.pause();
  }

  updateTranscribeButtonUI(false);

  // ハイライトを解除
  clearTranscribingHighlight();

  // 進捗表示をリセット
  getScanStatus().then(status => {
    updateProgress(status.progress);
  });

  console.log('文字起こし停止');
}

/**
 * 次のタイムスタンプを文字起こし
 */
function transcribeNextTimestamp() {
  if (!isTranscribing || currentTranscriptIndex >= detectedTimestamps.length) {
    // 完了
    stopTranscription();
    console.log('文字起こし完了');
    return;
  }

  const timestamp = detectedTimestamps[currentTranscriptIndex];
  console.log(`文字起こし中: ${currentTranscriptIndex + 1}/${detectedTimestamps.length} - ${timestamp.formattedTime}`);

  // 進捗を表示
  updateTranscribeProgress(currentTranscriptIndex + 1, detectedTimestamps.length);

  // 現在処理中のアイテムをハイライト
  highlightTranscribingItem(currentTranscriptIndex);

  // 動画をシーク
  videoElement.currentTime = timestamp.time;
  videoElement.muted = false; // 音声ON（文字起こしに必要）

  // 少し待ってから再生開始（シーク完了を待つ）
  setTimeout(() => {
    if (!isTranscribing) return;

    videoElement.play();

    // 音声認識開始
    let transcriptText = '';
    let recognitionEnded = false;

    speechRecognition.onresult = (event) => {
      let interim = '';
      let final = '';

      for (let i = event.resultIndex; i < event.results.length; i++) {
        const transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          final += transcript;
        } else {
          interim += transcript;
        }
      }

      transcriptText = final || interim;
      // リアルタイムで表示を更新
      updateTimestampTranscript(currentTranscriptIndex, transcriptText, !event.results[event.results.length - 1].isFinal);
    };

    speechRecognition.onerror = (event) => {
      console.error('音声認識エラー:', event.error);
      if (event.error !== 'no-speech') {
        // no-speech以外のエラーは記録
        updateTimestampTranscript(currentTranscriptIndex, `(認識エラー: ${event.error})`, false);
      }
    };

    speechRecognition.onend = () => {
      if (recognitionEnded) return;
      recognitionEnded = true;

      // 認識結果がなかった場合
      if (!transcriptText) {
        updateTimestampTranscript(currentTranscriptIndex, '(音声なし)', false);
      }
    };

    try {
      speechRecognition.start();
    } catch (e) {
      console.error('音声認識開始エラー:', e);
    }

    // 指定秒数後に停止して次へ
    setTimeout(() => {
      if (!isTranscribing) return;

      recognitionEnded = true;
      speechRecognition.stop();
      videoElement.pause();

      // 最終結果を保存
      if (transcriptText) {
        detectedTimestamps[currentTranscriptIndex].transcript = transcriptText;
      } else {
        detectedTimestamps[currentTranscriptIndex].transcript = '(音声なし)';
      }

      // データを保存
      saveVolumeData();

      // 次のタイムスタンプへ
      currentTranscriptIndex++;

      // 少し待ってから次へ
      setTimeout(() => {
        // 新しいrecognitionインスタンスを作成
        speechRecognition = initSpeechRecognition();
        transcribeNextTimestamp();
      }, 500);
    }, TRANSCRIPT_DURATION * 1000);
  }, 500);
}

/**
 * タイムスタンプの文字起こし結果を更新
 */
function updateTimestampTranscript(index, text, isInterim) {
  const timestamp = detectedTimestamps[index];
  if (!timestamp) return;

  // オーバーレイのリストアイテムを更新
  const list = document.getElementById('tsd-timestamp-list');
  if (!list) return;

  const items = list.querySelectorAll('.tsd-timestamp-item');
  const item = items[index];
  if (!item) return;

  // 既存のラベルを更新
  let label = item.querySelector('.tsd-label');
  if (label) {
    label.textContent = text;
    label.style.color = isInterim ? '#888' : '#fff';
  }

  // グラフも再描画
  drawVolumeGraph();
}

/**
 * 文字起こしボタンのUIを更新
 */
function updateTranscribeButtonUI(transcribing) {
  if (!volumeGraphContainer) return;
  const transcribeBtn = volumeGraphContainer.querySelector('#vdg-transcribe-btn');
  if (!transcribeBtn) return;

  if (transcribing) {
    transcribeBtn.classList.add('scanning');
    transcribeBtn.textContent = '停止';
    transcribeBtn.style.background = '#c62828';
  } else {
    transcribeBtn.classList.remove('scanning');
    transcribeBtn.textContent = '文字起こし';
    transcribeBtn.style.background = '#333';
  }
}

/**
 * 文字起こし進捗を更新
 */
function updateTranscribeProgress(current, total) {
  if (!volumeGraphContainer) return;
  const progress = volumeGraphContainer.querySelector('#vdg-progress');
  if (progress) {
    progress.textContent = `文字起こし ${current}/${total}`;
  }
}

/**
 * 現在文字起こし中のアイテムをハイライト
 */
function highlightTranscribingItem(index) {
  const list = document.getElementById('tsd-timestamp-list');
  if (!list) return;

  // 全てのハイライトを解除
  const items = list.querySelectorAll('.tsd-timestamp-item');
  items.forEach(item => item.classList.remove('transcribing'));

  // 現在のアイテムをハイライト
  if (items[index]) {
    items[index].classList.add('transcribing');
    // スクロールして見えるようにする
    items[index].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

/**
 * 全てのハイライトを解除
 */
function clearTranscribingHighlight() {
  const list = document.getElementById('tsd-timestamp-list');
  if (!list) return;
  const items = list.querySelectorAll('.tsd-timestamp-item');
  items.forEach(item => item.classList.remove('transcribing'));
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
    progress.textContent = `${Math.round(percent)}%`;
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

  const observer = new MutationObserver(() => {
    if (location.href !== lastUrl) {
      lastUrl = location.href;
      const currentVideoId = getVideoId();

      // 新しい動画ページに遷移したらリセット
      if (location.href.includes('/watch') && currentVideoId !== lastVideoId) {
        lastVideoId = currentVideoId;
        volumeData = [];
        videoDuration = 0;
        detectedTimestamps = []; // タイムスタンプもクリア

        // 文字起こし状態をリセット
        if (isTranscribing) {
          stopTranscription();
        }
        currentTranscriptIndex = 0;

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
      }
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * ステータス表示を更新
 */
function updateStatus(isCapturing) {
  const dot = document.getElementById('tsd-status-dot');
  const text = document.getElementById('tsd-status-text');

  if (isCapturing) {
    dot.classList.add('active');
    text.textContent = '検出中';
  } else {
    dot.classList.remove('active');
    text.textContent = '停止中';
  }
}

/**
 * メッセージハンドラ
 */
function handleMessage(message, sender, sendResponse) {
  switch (message.type) {
    case 'PING':
      sendResponse('PONG');
      return true;

    case 'TIMESTAMP_DETECTED':
      addTimestamp(message.timestamp);
      // グラフ用にも保存
      detectedTimestamps.push(message.timestamp);
      // グラフを再描画してマーカーを表示
      drawVolumeGraph();
      break;

    case 'SHOW_OVERLAY':
      overlay.classList.add('visible');
      break;

    case 'HIDE_OVERLAY':
      overlay.classList.remove('visible');
      break;

    case 'TOGGLE_OVERLAY':
      overlay.classList.toggle('visible');
      break;

    case 'CAPTURE_STARTED':
      updateStatus(true);
      overlay.classList.add('visible');
      break;

    case 'CAPTURE_STOPPED':
      updateStatus(false);
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
      if (volumeGraphContainer) {
        volumeGraphContainer.classList.remove('visible');
        isGraphVisible = false;
      }
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
      }
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
      // 自動スキャンモードの場合は次の動画へ
      if (isAutoScanMode && !autoScanStopRequested) {
        proceedToNextVideoOrFinish();
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
