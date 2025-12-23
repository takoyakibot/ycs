/**
 * 歌枠タイムスタンプ検出 - Background Service Worker
 *
 * tabCapture APIを使用してYouTubeタブの音声をキャプチャし、
 * 音量変化を検出してタイムスタンプ候補を生成する
 */

// 状態管理
let isCapturing = false;
let captureStream = null;
let audioContext = null;
let analyser = null;
let timestamps = [];
let lastVolume = 0;
let quietDuration = 0;
let currentVideoTime = 0;

// 設定
const CONFIG = {
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

let lastDetectionTime = 0;

// メッセージリスナー
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'START_CAPTURE':
      startCapture(message.tabId).then(sendResponse);
      return true;

    case 'STOP_CAPTURE':
      stopCapture();
      sendResponse({ success: true });
      return true;

    case 'GET_STATUS':
      sendResponse({
        isCapturing,
        timestamps,
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
      currentVideoTime = message.time;
      return false;

    case 'UPDATE_CONFIG':
      Object.assign(CONFIG, message.config);
      sendResponse({ success: true, config: CONFIG });
      return true;
  }
});

/**
 * タブの音声キャプチャを開始
 */
async function startCapture(tabId) {
  if (isCapturing) {
    return { success: false, error: '既にキャプチャ中です' };
  }

  try {
    // タブの音声をキャプチャ
    captureStream = await chrome.tabCapture.capture({
      audio: true,
      video: false
    });

    if (!captureStream) {
      return { success: false, error: 'キャプチャストリームを取得できませんでした' };
    }

    // Web Audio APIでセットアップ
    audioContext = new AudioContext();
    const source = audioContext.createMediaStreamSource(captureStream);

    analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;
    analyser.smoothingTimeConstant = 0.8;

    source.connect(analyser);

    // 音声を出力にも接続（ユーザーが聞けるように）
    source.connect(audioContext.destination);

    isCapturing = true;
    timestamps = [];
    lastVolume = 0;
    quietDuration = 0;
    lastDetectionTime = 0;

    // 音量監視を開始
    startVolumeMonitoring();

    return { success: true };
  } catch (error) {
    console.error('キャプチャ開始エラー:', error);
    return { success: false, error: error.message };
  }
}

/**
 * キャプチャを停止
 */
function stopCapture() {
  if (captureStream) {
    captureStream.getTracks().forEach(track => track.stop());
    captureStream = null;
  }

  if (audioContext) {
    audioContext.close();
    audioContext = null;
  }

  analyser = null;
  isCapturing = false;
}

/**
 * 音量監視ループ
 */
function startVolumeMonitoring() {
  const dataArray = new Float32Array(analyser.fftSize);

  function monitor() {
    if (!isCapturing || !analyser) return;

    // 音声データを取得
    analyser.getFloatTimeDomainData(dataArray);

    // RMS（二乗平均平方根）で音量を計算
    let sum = 0;
    for (let i = 0; i < dataArray.length; i++) {
      sum += dataArray[i] * dataArray[i];
    }
    const rms = Math.sqrt(sum / dataArray.length);

    // 音量変化を検出
    detectVolumeChange(rms);

    lastVolume = rms;

    // 次のサンプリング
    setTimeout(monitor, CONFIG.sampleInterval);
  }

  monitor();
}

/**
 * 音量変化から楽曲開始を検出
 */
function detectVolumeChange(currentVolume) {
  const now = currentVideoTime;

  // 静かな状態をトラッキング
  if (currentVolume < CONFIG.quietThreshold) {
    quietDuration += CONFIG.sampleInterval / 1000;
  } else {
    // 静かな状態から急に大きくなった場合
    if (quietDuration >= CONFIG.quietMinDuration &&
        currentVolume > CONFIG.volumeThreshold &&
        (now - lastDetectionTime) > CONFIG.cooldown) {

      // タイムスタンプを記録
      const timestamp = {
        time: now,
        formattedTime: formatTime(now),
        volume: currentVolume,
        detectedAt: new Date().toISOString()
      };

      timestamps.push(timestamp);
      lastDetectionTime = now;

      // コンテンツスクリプトに通知
      notifyContentScript(timestamp);

      console.log('楽曲開始を検出:', timestamp);
    }

    quietDuration = 0;
  }
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
 * コンテンツスクリプトに通知
 */
async function notifyContentScript(timestamp) {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id) {
      chrome.tabs.sendMessage(tab.id, {
        type: 'TIMESTAMP_DETECTED',
        timestamp
      });
    }
  } catch (error) {
    console.error('通知エラー:', error);
  }
}
