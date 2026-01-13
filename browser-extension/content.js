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

// ズーム関連
const ZOOM_LEVELS = [1, 1.5, 2, 3, 4, 5, 6, 7, 8];
let zoomIndex = 0; // ZOOM_LEVELSのインデックス
let lastSaveTime = 0; // 最後に音量データを保存した時刻
const SAVE_INTERVAL = 3000; // 保存間隔（ミリ秒）

// 音量表示モード（false: 絶対値, true: 相対値）
let isRelativeVolumeMode = false;

// 埋め込みUI設定
let embeddedUIVisible = true; // デフォルトは表示
let embeddedTriggerButton = null;

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

  embeddedTriggerButton = document.createElement('button');
  embeddedTriggerButton.id = 'ycs-trigger-button';
  embeddedTriggerButton.innerHTML = `
    <style>
      #ycs-trigger-button {
        position: fixed;
        bottom: 80px;
        right: 16px;
        z-index: 9999;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: 14px;
        font-weight: bold;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      #ycs-trigger-button:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 16px rgba(0,0,0,0.4);
      }
      #ycs-trigger-button.active {
        background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
      }
      #ycs-trigger-button.hidden {
        display: none !important;
      }
    </style>
    YCS
  `;

  embeddedTriggerButton.title = 'タイムスタンプ検出グラフを表示/非表示';

  embeddedTriggerButton.addEventListener('click', () => {
    toggleEmbeddedUI();
  });

  document.body.appendChild(embeddedTriggerButton);
  updateTriggerButtonState();
}

/**
 * 埋め込みUIの表示/非表示をトグル
 */
function toggleEmbeddedUI() {
  if (volumeGraphContainer) {
    const isVisible = volumeGraphContainer.classList.contains('visible');
    if (isVisible) {
      volumeGraphContainer.classList.remove('visible');
      isGraphVisible = false;
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

  if (isGraphVisible) {
    embeddedTriggerButton.classList.add('active');
  } else {
    embeddedTriggerButton.classList.remove('active');
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
 * 埋め込みUIを非表示
 */
function hideEmbeddedUI() {
  embeddedUIVisible = false;
  if (embeddedTriggerButton) {
    embeddedTriggerButton.classList.add('hidden');
  }
  // グラフも非表示
  if (volumeGraphContainer) {
    volumeGraphContainer.classList.remove('visible');
    isGraphVisible = false;
  }
}

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
async function init() {
  // メッセージリスナーを最初に設定（他の処理でエラーが出ても通信可能にする）
  chrome.runtime.onMessage.addListener(handleMessage);

  // ストレージ変更リスナー（2窓リアルタイム同期用）
  chrome.storage.onChanged.addListener(handleStorageChange);

  try {
    // 埋め込みUI設定を読み込み
    await loadEmbeddedUISettings();

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

    // YouTube SPAナビゲーション対応
    observePageChanges();

    // リストスキャンモードをチェック
    checkAndStartListScan();
  } catch (error) {
    console.error('Content script初期化エラー:', error);
  }
}

/**
 * ストレージ変更を監視（別タブでのスキャン結果をリアルタイム反映）
 */
function handleStorageChange(changes, areaName) {
  if (areaName !== 'local') return;

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
        const coverage = (volumeData.length / GRAPH_RESOLUTION) * 100;
        progress.textContent = `${Math.round(coverage)}%`;
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
        <span class="vdg-zoom-info" id="vdg-zoom-info">1x</span>
        <button class="vdg-volume-mode" id="vdg-volume-mode-btn" title="固定スケールでの絶対値表示中（クリックで相対表示に切替）">絶対</button>
        <button class="vdg-btn" id="vdg-scan-btn" title="動画全体をスキャンしてグラフを生成">スキャン</button>
        <button class="vdg-btn" id="vdg-transcribe-btn" title="検出された候補を順番に文字起こし">文字起こし</button>
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
  if (!volumeCanvas || !volumeGraphContainer) return;

  const canvasContainer = volumeGraphContainer.querySelector('#vdg-canvas-container');
  const canvasWrapper = volumeGraphContainer.querySelector('#vdg-canvas-wrapper');
  if (!canvasContainer || !canvasWrapper) return;

  const containerRect = canvasContainer.getBoundingClientRect();
  const baseWidth = containerRect.width;
  const zoomedWidth = baseWidth * getZoomLevel();

  // ラッパーとCanvasの幅を設定
  canvasWrapper.style.width = `${zoomedWidth}px`;
  volumeCanvas.style.width = `${zoomedWidth}px`;

  // Canvas解像度を設定
  volumeCanvas.width = zoomedWidth * window.devicePixelRatio;
  volumeCanvas.height = containerRect.height * window.devicePixelRatio;

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
    zoomInfo.textContent = `${newZoom}x`;
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

  // クリックでシーク（ズーム対応）
  if (container) {
    const canvasWrapper = container.querySelector('#vdg-canvas-wrapper');

    container.addEventListener('click', (e) => {
      if (!videoElement || !videoDuration) return;

      // スクロール位置とズームを考慮した座標計算
      const containerRect = container.getBoundingClientRect();
      const scrollLeft = container.scrollLeft;
      const x = e.clientX - containerRect.left + scrollLeft;
      const totalWidth = containerRect.width * getZoomLevel();
      const ratio = x / totalWidth;
      const seekTime = ratio * videoDuration;
      videoElement.currentTime = seekTime;
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

      if (hoverTime) {
        hoverTime.textContent = formatTimeDisplay(time);
        // ホバー位置はスクロール位置を引いた画面上の位置
        hoverTime.style.left = `${e.clientX - containerRect.left}px`;
      }
    });

    // マウスホイールイベント
    container.addEventListener('wheel', (e) => {
      if (e.ctrlKey) {
        // Ctrl + ホイール: ズーム
        e.preventDefault();
        const zoomDelta = e.deltaY > 0 ? -1 : 1;
        changeZoomLevel(zoomDelta);
      } else {
        // ホイールのみ: 横スクロール
        e.preventDefault();
        container.scrollLeft += e.deltaY;
      }
    }, { passive: false });
  }

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
        zoomIndex = 0; // ズームレベルをリセット

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
      // リストスキャンボタンの状態更新
      updateListScanButtonState(true);
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
