/**
 * 歌枠タイムスタンプ検出 - Background Service Worker
 *
 * Offscreen Documentを使用してYouTubeタブの音声をキャプチャし、
 * 音量変化を検出してタイムスタンプ候補を生成する
 * 音量ダイナミクスグラフ用のデータを蓄積する
 */

// 状態管理
let isCapturing = false;
let timestamps = [];
let currentTabId = null;

// 音量グラフ用データ
let volumeGraphData = [];
let videoDuration = 0;
let isScanning = false;
const GRAPH_RESOLUTION = 500; // グラフのデータポイント数
let lastVolumeUpdateTime = 0; // 最後にUI更新した時刻

// デフォルト設定
const DEFAULT_CONFIG = {
  // 音量のしきい値（0-1）
  volumeThreshold: 0.15,
  // 静かな状態と判定する音量
  quietThreshold: 0.05,
  // 静かな状態が続く最小時間（秒）
  quietMinDuration: 1.0,
  // サンプリング間隔（ミリ秒）
  sampleInterval: 100,
  // 連続検出を防ぐクールダウン（秒）
  cooldown: 3.0
};

// 現在の設定（ストレージから読み込む）
let CONFIG = { ...DEFAULT_CONFIG };

// 起動時に設定を読み込む
chrome.storage.local.get('config', (result) => {
  if (result.config) {
    CONFIG = { ...DEFAULT_CONFIG, ...result.config };
  }
});

// メッセージリスナー
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'START_CAPTURE':
      startCapture(message.tabId).then(sendResponse);
      return true;

    case 'STOP_CAPTURE':
      stopCapture().then(sendResponse);
      return true;

    case 'GET_STATUS':
      sendResponse({
        isCapturing,
        isScanning,
        timestamps,
        volumeGraphData,
        config: CONFIG
      });
      return true;

    case 'GET_TIMESTAMPS':
      sendResponse({ timestamps });
      return true;

    case 'CLEAR_TIMESTAMPS':
      timestamps = [];
      sendResponse({ success: true });
      return true;

    case 'UPDATE_VIDEO_TIME':
      // Offscreen Documentに転送
      chrome.runtime.sendMessage({
        type: 'UPDATE_VIDEO_TIME',
        time: message.time
      }).catch(() => {});
      return false;

    case 'UPDATE_CONFIG':
      Object.assign(CONFIG, message.config);
      // ストレージに保存
      chrome.storage.local.set({ config: CONFIG });
      // Offscreen Documentにも転送
      chrome.runtime.sendMessage({
        type: 'UPDATE_CONFIG',
        config: CONFIG
      }).catch(() => {});
      sendResponse({ success: true, config: CONFIG });
      return true;

    case 'TIMESTAMP_DETECTED_FROM_OFFSCREEN':
      // Offscreen Documentからのタイムスタンプ検出通知
      timestamps.push(message.timestamp);
      notifyContentScript(message.timestamp);
      return false;

    case 'VOLUME_DATA_FROM_OFFSCREEN':
      // Offscreen Documentからの音量データ
      console.log('音量データ受信(raw)', { index: message.index, volume: message.volume });
      if (message.index >= 0 && message.index < GRAPH_RESOLUTION) {
        const oldValue = volumeGraphData[message.index] || 0;
        if (message.volume > oldValue) {
          volumeGraphData[message.index] = message.volume;
        }
      }
      // 200msごとにUIを更新
      const now = Date.now();
      if (now - lastVolumeUpdateTime >= 200) {
        lastVolumeUpdateTime = now;
        sendVolumeDataToContent();
      }
      return false;

    // 音量グラフ関連
    case 'START_SCAN':
      if (isScanning) {
        stopScan();
      } else {
        startScan(message.muted);
      }
      sendResponse({ success: true, isScanning });
      return true;

    case 'STOP_SCAN':
      stopScan();
      sendResponse({ success: true });
      return true;

    case 'GET_VOLUME_DATA':
      sendResponse({ data: volumeGraphData, duration: videoDuration });
      return true;

    case 'CLEAR_VOLUME_DATA':
      volumeGraphData = [];
      videoDuration = 0;
      sendResponse({ success: true });
      return true;

    case 'TOGGLE_VOLUME_GRAPH':
      toggleVolumeGraph();
      return false;

    case 'SHOW_VOLUME_GRAPH':
      showVolumeGraph();
      return false;
  }
});

/**
 * Offscreen Documentが存在するか確認
 */
async function hasOffscreenDocument() {
  const contexts = await chrome.runtime.getContexts({
    contextTypes: ['OFFSCREEN_DOCUMENT'],
    documentUrls: [chrome.runtime.getURL('offscreen.html')]
  });
  return contexts.length > 0;
}

/**
 * Offscreen Documentを作成（存在しない場合のみ）
 */
async function ensureOffscreenDocument() {
  if (await hasOffscreenDocument()) {
    return;
  }

  await chrome.offscreen.createDocument({
    url: 'offscreen.html',
    reasons: ['USER_MEDIA'],
    justification: 'Audio capture and volume analysis for timestamp detection'
  });
}

/**
 * Offscreen Documentを閉じる
 */
async function closeOffscreenDocument() {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument();
  }
}

/**
 * タブの音声キャプチャを開始
 */
async function startCapture(tabId) {
  if (isCapturing) {
    return { success: false, error: '既にキャプチャ中です' };
  }

  try {
    // Offscreen Documentを作成
    await ensureOffscreenDocument();

    // Stream IDを取得
    const streamId = await chrome.tabCapture.getMediaStreamId({
      targetTabId: tabId
    });

    if (!streamId) {
      return { success: false, error: 'Stream IDを取得できませんでした' };
    }

    // Offscreen Documentで音声処理を開始
    console.log('START_AUDIO_PROCESSING送信中...');
    const response = await chrome.runtime.sendMessage({
      type: 'START_AUDIO_PROCESSING',
      streamId: streamId,
      config: CONFIG,
      graphResolution: GRAPH_RESOLUTION
    });

    console.log('START_AUDIO_PROCESSING応答:', response);

    if (!response || !response.success) {
      await closeOffscreenDocument();
      return { success: false, error: response?.error || '音声処理の開始に失敗しました' };
    }

    isCapturing = true;
    currentTabId = tabId;
    timestamps = [];

    // Content scriptに通知
    chrome.tabs.sendMessage(tabId, { type: 'CAPTURE_STARTED' }).catch(() => {});

    return { success: true };
  } catch (error) {
    console.error('キャプチャ開始エラー:', error);
    await closeOffscreenDocument();
    return { success: false, error: error.message };
  }
}

/**
 * キャプチャを停止
 */
async function stopCapture() {
  try {
    // Offscreen Documentで音声処理を停止
    await chrome.runtime.sendMessage({ type: 'STOP_AUDIO_PROCESSING' }).catch(() => {});

    // Offscreen Documentを閉じる
    await closeOffscreenDocument();

    // Content scriptに通知
    if (currentTabId) {
      chrome.tabs.sendMessage(currentTabId, { type: 'CAPTURE_STOPPED' }).catch(() => {});
    }

    isCapturing = false;
    currentTabId = null;

    return { success: true };
  } catch (error) {
    console.error('キャプチャ停止エラー:', error);
    isCapturing = false;
    currentTabId = null;
    return { success: false, error: error.message };
  }
}

/**
 * コンテンツスクリプトに通知
 */
async function notifyContentScript(timestamp) {
  if (!currentTabId) return;

  try {
    await chrome.tabs.sendMessage(currentTabId, {
      type: 'TIMESTAMP_DETECTED',
      timestamp
    });
  } catch (error) {
    console.error('通知エラー:', error);
  }
}

/**
 * 音量データをコンテンツスクリプトに送信
 */
async function sendVolumeDataToContent() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id) {
      // スパース配列を埋める
      const filledData = [];
      for (let i = 0; i < GRAPH_RESOLUTION; i++) {
        filledData[i] = volumeGraphData[i] || 0;
      }

      const progress = (filledData.filter(v => v > 0).length / GRAPH_RESOLUTION) * 100;

      // デバッグ: 送信データを確認（毎回出力）
      console.log('音量データ送信', {
        progress: progress.toFixed(1) + '%',
        nonZeroCount: filledData.filter(v => v > 0).length
      });

      chrome.tabs.sendMessage(tab.id, {
        type: 'VOLUME_DATA_UPDATE',
        data: filledData,
        progress
      });
    }
  } catch (error) {
    console.error('音量データ送信エラー:', error);
  }
}

/**
 * 高速スキャンを開始
 * スキャン中は常にミュート（tabCaptureの仕様により音声出力は不可）
 */
async function startScan() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab?.id) return;

    // スキャン状況を確認
    const scanStatus = await chrome.tabs.sendMessage(tab.id, { type: 'GET_SCAN_STATUS' });

    // 既にスキャン完了済みなら実行しない
    if (scanStatus.isComplete) {
      console.log('この動画は既にスキャン完了済みです（進捗: ' + scanStatus.progress.toFixed(1) + '%）');
      // グラフを表示
      chrome.tabs.sendMessage(tab.id, { type: 'SHOW_VOLUME_GRAPH' });
      return;
    }

    // 動画情報を取得
    const videoInfo = await chrome.tabs.sendMessage(tab.id, { type: 'GET_VIDEO_INFO' });
    videoDuration = videoInfo.duration;

    if (!videoDuration) {
      console.error('動画の長さを取得できません');
      return;
    }

    // キャプチャを開始（既存のキャプチャは停止してから再開始）
    if (isCapturing) {
      await stopCapture();
    }
    const result = await startCapture(tab.id);
    if (!result.success) {
      console.error('キャプチャ開始失敗:', result.error);
      return;
    }

    isScanning = true;

    // 既存データがあれば引き継ぐ、なければ新規作成
    if (scanStatus.hasData && scanStatus.data) {
      volumeGraphData = [...scanStatus.data];
      // 配列の長さが足りなければ埋める
      while (volumeGraphData.length < GRAPH_RESOLUTION) {
        volumeGraphData.push(0);
      }
      // デバッグ: データの状態を確認
      const nonZeroCount = volumeGraphData.filter(v => v > 0).length;
      console.log('既存データから再開', {
        progress: scanStatus.progress.toFixed(1) + '%',
        resumeTime: scanStatus.resumeTime,
        dataLength: volumeGraphData.length,
        nonZeroCount: nonZeroCount,
        first10: volumeGraphData.slice(0, 10),
        last10: volumeGraphData.slice(-10)
      });
    } else {
      volumeGraphData = new Array(GRAPH_RESOLUTION).fill(0);
      console.log('新規スキャン開始');
    }

    // Offscreen Documentにスキャン開始を通知
    console.log('START_VOLUME_SCAN送信中...', { duration: videoDuration, graphResolution: GRAPH_RESOLUTION });
    chrome.runtime.sendMessage({
      type: 'START_VOLUME_SCAN',
      duration: videoDuration,
      graphResolution: GRAPH_RESOLUTION
    }).then(res => {
      console.log('START_VOLUME_SCAN応答:', res);
    }).catch(err => {
      console.error('START_VOLUME_SCAN失敗:', err);
    });

    // 動画を高速再生
    // 注意: tabCaptureはタブの音声出力をキャプチャするため、ミュートすると音声データが取得できない
    // そのため、スキャン中は音声が出力される
    await chrome.tabs.sendMessage(tab.id, { type: 'SET_MUTED', muted: false });
    await chrome.tabs.sendMessage(tab.id, { type: 'SET_PLAYBACK_RATE', rate: 4 });

    // 途中から再開する場合は、その時点からシーク
    const startTime = scanStatus.hasData && scanStatus.resumeTime > 0
      ? scanStatus.resumeTime
      : 0;
    await chrome.tabs.sendMessage(tab.id, { type: 'SEEK_VIDEO', time: startTime });
    await chrome.tabs.sendMessage(tab.id, { type: 'PLAY_VIDEO' });

    // UIに通知
    chrome.tabs.sendMessage(tab.id, { type: 'SCAN_STARTED' });
    chrome.tabs.sendMessage(tab.id, { type: 'SHOW_VOLUME_GRAPH' });

    // スキャン完了を監視
    monitorScanProgress(tab.id);

    console.log('高速スキャン開始' + (startTime > 0 ? `（${startTime.toFixed(0)}秒から再開）` : ''));
  } catch (error) {
    console.error('スキャン開始エラー:', error);
    isScanning = false;
  }
}

/**
 * スキャン進捗を監視
 */
function monitorScanProgress(tabId) {
  const checkProgress = setInterval(async () => {
    if (!isScanning) {
      clearInterval(checkProgress);
      return;
    }

    try {
      const videoInfo = await chrome.tabs.sendMessage(tabId, { type: 'GET_VIDEO_INFO' });

      // 動画の終わりに近づいたらスキャン完了
      if (videoInfo.currentTime >= videoDuration - 1) {
        clearInterval(checkProgress);
        stopScan();
      }

      // 進捗を送信
      sendVolumeDataToContent();
    } catch (error) {
      clearInterval(checkProgress);
      stopScan();
    }
  }, 200);
}

/**
 * 高速スキャンを停止
 */
async function stopScan() {
  if (!isScanning) return;

  isScanning = false;

  // Offscreen Documentにスキャン停止を通知
  chrome.runtime.sendMessage({ type: 'STOP_VOLUME_SCAN' }).catch(() => {});

  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id) {
      // 再生速度を元に戻し、ミュートを解除
      await chrome.tabs.sendMessage(tab.id, { type: 'SET_MUTED', muted: false });
      await chrome.tabs.sendMessage(tab.id, { type: 'SET_PLAYBACK_RATE', rate: 1 });
      await chrome.tabs.sendMessage(tab.id, { type: 'PAUSE_VIDEO' });
      await chrome.tabs.sendMessage(tab.id, { type: 'SEEK_VIDEO', time: 0 });

      // UIに通知
      chrome.tabs.sendMessage(tab.id, { type: 'SCAN_STOPPED' });

      // 最終データを送信
      sendVolumeDataToContent();
    }
  } catch (error) {
    console.error('スキャン停止エラー:', error);
  }

  console.log('高速スキャン停止');
}

/**
 * 音量グラフの表示をトグル
 */
async function toggleVolumeGraph() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id) {
      chrome.tabs.sendMessage(tab.id, { type: 'TOGGLE_VOLUME_GRAPH' });
    }
  } catch (error) {
    console.error('グラフトグルエラー:', error);
  }
}

/**
 * 音量グラフを表示
 */
async function showVolumeGraph() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id) {
      chrome.tabs.sendMessage(tab.id, { type: 'SHOW_VOLUME_GRAPH' });

      // 既存のデータがあれば送信
      if (volumeGraphData.length > 0) {
        sendVolumeDataToContent();
      }
    }
  } catch (error) {
    console.error('グラフ表示エラー:', error);
  }
}
