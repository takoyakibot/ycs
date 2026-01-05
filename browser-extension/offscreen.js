/**
 * 歌枠タイムスタンプ検出 - Offscreen Audio Processor
 *
 * Offscreen DocumentでYouTubeタブの音声をキャプチャし、
 * 音量変化を検出してタイムスタンプ候補を生成する
 * 音量ダイナミクスグラフ用のデータを蓄積する
 */

// 状態管理
let captureStream = null;
let audioContext = null;
let analyser = null;
let lastVolume = 0;
let quietDuration = 0;
let currentVideoTime = 0;
let lastDetectionTime = 0;
let isProcessing = false;

// 音量グラフ用
let isVolumeScanning = false;
let videoDuration = 0;
let graphResolution = 500;

// 設定（background.jsから受け取る）
let CONFIG = {
  volumeThreshold: 0.15,
  quietThreshold: 0.05,
  quietMinDuration: 1.0,
  sampleInterval: 100,
  cooldown: 3.0
};

// メッセージリスナー
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'START_AUDIO_PROCESSING':
      if (message.graphResolution) {
        graphResolution = message.graphResolution;
      }
      startAudioProcessing(message.streamId, message.config)
        .then(result => sendResponse(result))
        .catch(error => sendResponse({ success: false, error: error.message }));
      return true;

    case 'STOP_AUDIO_PROCESSING':
      stopAudioProcessing();
      sendResponse({ success: true });
      return true;

    case 'UPDATE_VIDEO_TIME':
      currentVideoTime = message.time;
      // スキャン中は音量データを記録
      if (isVolumeScanning && analyser && videoDuration > 0) {
        recordVolumeData();
      } else if (isVolumeScanning && Math.random() < 0.05) {
        // デバッグ: なぜ記録されないか
        console.log('UPDATE_VIDEO_TIME: 記録スキップ', {
          isVolumeScanning,
          hasAnalyser: !!analyser,
          videoDuration,
          currentVideoTime
        });
      }
      return false;

    case 'UPDATE_CONFIG':
      Object.assign(CONFIG, message.config);
      sendResponse({ success: true, config: CONFIG });
      return true;

    case 'START_VOLUME_SCAN':
      videoDuration = message.duration;
      if (message.graphResolution) {
        graphResolution = message.graphResolution;
      }
      isVolumeScanning = true;
      console.log('START_VOLUME_SCAN受信', { videoDuration, graphResolution, isVolumeScanning, hasAnalyser: !!analyser });
      sendResponse({ success: true });
      return true;

    case 'STOP_VOLUME_SCAN':
      isVolumeScanning = false;
      sendResponse({ success: true });
      return true;
  }
});

/**
 * 音声処理を開始
 * @param {string} streamId - タブキャプチャのストリームID
 * @param {object} config - 設定オブジェクト
 */
async function startAudioProcessing(streamId, config) {
  if (isProcessing) {
    return { success: false, error: '既に処理中です' };
  }

  if (config) {
    Object.assign(CONFIG, config);
  }

  try {
    // streamIdからMediaStreamを取得
    captureStream = await navigator.mediaDevices.getUserMedia({
      audio: {
        mandatory: {
          chromeMediaSource: 'tab',
          chromeMediaSourceId: streamId
        }
      }
    });

    if (!captureStream) {
      return { success: false, error: 'ストリームを取得できませんでした' };
    }

    // Web Audio APIでセットアップ
    audioContext = new AudioContext();
    const source = audioContext.createMediaStreamSource(captureStream);

    analyser = audioContext.createAnalyser();
    analyser.fftSize = 2048;
    analyser.smoothingTimeConstant = 0.8;

    // 解析用にのみ接続（destinationには接続しない）
    // tabCaptureは音声を「コピー」するAPIなので、
    // destinationに接続しなければ元のタブの音声はそのまま出力される
    source.connect(analyser);
    console.log('音声解析を開始（元のタブの音声はそのまま出力されます）');

    isProcessing = true;
    lastVolume = 0;
    quietDuration = 0;
    lastDetectionTime = 0;

    // 音量監視を開始
    startVolumeMonitoring();

    return { success: true };
  } catch (error) {
    // エラー時はリソースをクリーンアップ
    stopAudioProcessing();
    console.error('音声処理開始エラー:', error);
    return { success: false, error: error.message };
  }
}

/**
 * 音声処理を停止
 */
function stopAudioProcessing() {
  if (captureStream) {
    captureStream.getTracks().forEach(track => track.stop());
    captureStream = null;
  }

  if (audioContext) {
    audioContext.close();
    audioContext = null;
  }

  analyser = null;
  isProcessing = false;
}

/**
 * 音量監視ループ
 */
function startVolumeMonitoring() {
  const dataArray = new Float32Array(analyser.fftSize);

  function monitor() {
    if (!isProcessing || !analyser) return;

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

      lastDetectionTime = now;

      // background.jsに通知
      chrome.runtime.sendMessage({
        type: 'TIMESTAMP_DETECTED_FROM_OFFSCREEN',
        timestamp
      });

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
 * 音量データを記録してbackground.jsに送信
 */
let lastLoggedIndex = -1;
function recordVolumeData() {
  if (!analyser || !videoDuration) return;

  const dataArray = new Float32Array(analyser.fftSize);
  analyser.getFloatTimeDomainData(dataArray);

  // RMS計算
  let sum = 0;
  for (let i = 0; i < dataArray.length; i++) {
    sum += dataArray[i] * dataArray[i];
  }
  const rms = Math.sqrt(sum / dataArray.length);

  // 正規化（0-1の範囲に）
  const normalizedVolume = Math.min(1, rms * 5);

  // データポイントのインデックスを計算
  const index = Math.floor((currentVideoTime / videoDuration) * graphResolution);

  if (index >= 0 && index < graphResolution) {
    // デバッグ: 10インデックスごとにログ
    if (Math.floor(index / 10) !== Math.floor(lastLoggedIndex / 10)) {
      console.log('recordVolumeData送信', { index, volume: normalizedVolume.toFixed(3), currentVideoTime: currentVideoTime.toFixed(1) });
      lastLoggedIndex = index;
    }

    // background.jsに送信
    chrome.runtime.sendMessage({
      type: 'VOLUME_DATA_FROM_OFFSCREEN',
      index: index,
      volume: normalizedVolume
    });
  }
}
