import state from './state.js';
import { SAMPLING_INTERVAL_SEC } from './config.js';
import {
  getVideoId, calcGraphResolution, isSavedVolumeDataStale,
  updateProgress, getScanStatus, isAdShowing, updateVideoDuration,
  isCurrentVideoScanned,
} from './utils.js';
import { drawVolumeGraph } from './volume-graph.js';
import { loadYcsApiSettings } from './api.js';
import { proceedToNextVideoOrFinish } from './playlist.js';
import { hideListScanButton, showListScanButton, proceedToNextListScanVideo } from './list-scan.js';

export function computeSpectralFeatures(freqData, sampleRate) {
  const binCount = freqData.length;
  const binWidth = sampleRate / (binCount * 2);

  // dB→リニアパワーに変換（無音ビンは極小値にクランプ）
  const minDb = -100;
  const powers = new Float32Array(binCount);
  for (let i = 0; i < binCount; i++) {
    const db = Math.max(freqData[i], minDb);
    powers[i] = Math.pow(10, db / 10);
  }

  let totalEnergy = 0;
  for (let i = 0; i < binCount; i++) {
    totalEnergy += powers[i];
  }
  if (totalEnergy === 0) return null;

  // 歌声帯域 (80Hz - 1100Hz) のエネルギー比率
  const voiceLowBin = Math.ceil(80 / binWidth);
  const voiceHighBin = Math.min(Math.floor(1100 / binWidth), binCount - 1);
  let voiceEnergy = 0;
  for (let i = voiceLowBin; i <= voiceHighBin; i++) {
    voiceEnergy += powers[i];
  }
  const voiceBandRatio = voiceEnergy / totalEnergy;

  // Spectral flatness（幾何平均 / 算術平均）
  // 対数空間で計算して数値アンダーフロー防止
  let logSum = 0;
  let linearSum = 0;
  let count = 0;
  for (let i = voiceLowBin; i <= voiceHighBin; i++) {
    if (powers[i] > 0) {
      logSum += Math.log(powers[i]);
      linearSum += powers[i];
      count++;
    }
  }
  let flatness = 1;
  if (count > 0 && linearSum > 0) {
    const geometricMean = Math.exp(logSum / count);
    const arithmeticMean = linearSum / count;
    flatness = geometricMean / arithmeticMean;
  }

  return {
    flatness: Math.round(flatness * 1000) / 1000,
    voiceBandRatio: Math.round(voiceBandRatio * 1000) / 1000,
  };
}

export function initAudioAnalysis() {
  if (!state.videoElement) {
    console.error('Video要素が見つかりません');
    return false;
  }

  if (state.audioInitialized && state.mediaElementSource) {
    return true;
  }

  try {
    if (!state.audioContext) {
      state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
    }

    // MediaElementSourceは一度だけ作成可能
    if (!state.mediaElementSource) {
      state.mediaElementSource = state.audioContext.createMediaElementSource(state.videoElement);

      state.analyserNode = state.audioContext.createAnalyser();
      state.analyserNode.fftSize = 2048;
      state.analyserNode.smoothingTimeConstant = 0.8;

      state.gainNode = state.audioContext.createGain();
      state.gainNode.gain.value = 1;

      // Video → Analyser → Gain → 出力
      state.mediaElementSource.connect(state.analyserNode);
      state.analyserNode.connect(state.gainNode);
      state.gainNode.connect(state.audioContext.destination);

      state.audioInitialized = true;
      console.log('音声解析を初期化しました（Video要素から直接解析）');
    }

    return true;
  } catch (error) {
    console.error('音声解析の初期化に失敗:', error);
    return false;
  }
}

export function setAudioGain(muted) {
  if (state.gainNode) {
    state.gainNode.gain.value = muted ? 0 : 1;
    console.log('音声ゲイン設定:', muted ? 'ミュート' : '音声ON');
  }
}

export async function startDirectScan() {
  if (state.isScanning) {
    stopDirectScan();
    return false;
  }

  if (!state.videoElement) {
    console.error('Video要素が見つかりません');
    return false;
  }

  if (!initAudioAnalysis()) {
    console.error('音声解析の初期化に失敗しました');
    return false;
  }

  if (state.audioContext.state === 'suspended') {
    await state.audioContext.resume();
  }

  // 広告再生中は実動画のdurationが取れないため、終了を待つ（最大120秒）
  for (let i = 0; isAdShowing() && i < 120; i++) {
    await new Promise(resolve => setTimeout(resolve, 1000));
  }
  if (isAdShowing()) {
    console.error('広告が終了しないためスキャンを開始できません');
    return false;
  }

  updateVideoDuration();
  if (!state.videoDuration || !isFinite(state.videoDuration)) {
    console.error('動画の長さを取得できません');
    return false;
  }

  state.isScanning = true;
  const resolution = calcGraphResolution(state.videoDuration);
  state.volumeData = new Array(resolution).fill(0);
  state.spectralData = new Array(resolution).fill(null);

  state.originalPlaybackRate = state.videoElement.playbackRate;

  setAudioGain(true);

  state.videoElement.playbackRate = 4;
  state.videoElement.currentTime = 0;
  state.videoElement.play();

  updateScanButtonUI(true);
  if (state.volumeGraphContainer) {
    state.volumeGraphContainer.classList.add('visible');
    state.isGraphVisible = true;
  }

  console.log('スキャン開始（直接音声解析）');

  const dataArray = new Float32Array(state.analyserNode.fftSize);
  const freqArray = new Float32Array(state.analyserNode.frequencyBinCount);

  state.scanInterval = setInterval(() => {
    if (!state.isScanning || !state.analyserNode) {
      stopDirectScan();
      return;
    }

    state.analyserNode.getFloatTimeDomainData(dataArray);

    // RMS（二乗平均平方根）で音量を計算
    let sum = 0;
    for (let i = 0; i < dataArray.length; i++) {
      sum += dataArray[i] * dataArray[i];
    }
    const rms = Math.sqrt(sum / dataArray.length);
    const normalizedVolume = Math.min(1, rms * 5);

    // 周波数スペクトルを取得してスペクトル特徴量を計算
    state.analyserNode.getFloatFrequencyData(freqArray);
    const spectralFeatures = computeSpectralFeatures(freqArray, state.audioContext.sampleRate);

    const currentResolution = state.volumeData.length;
    const index = Math.floor((state.videoElement.currentTime / state.videoDuration) * currentResolution);

    if (index >= 0 && index < currentResolution) {
      if (normalizedVolume > state.volumeData[index]) {
        state.volumeData[index] = normalizedVolume;
        if (spectralFeatures) state.spectralData[index] = spectralFeatures;
      } else if (!state.spectralData[index] && spectralFeatures) {
        state.spectralData[index] = spectralFeatures;
      }
    }

    const progress = (state.volumeData.filter(v => v > 0).length / currentResolution) * 100;
    updateProgress(progress);
    drawVolumeGraph();

    if (state.videoElement.currentTime >= state.videoDuration - 1) {
      stopDirectScan();
    }
  }, 50);

  return true;
}

export function stopDirectScan() {
  if (!state.isScanning) return;

  state.isScanning = false;

  if (state.scanInterval) {
    clearInterval(state.scanInterval);
    state.scanInterval = null;
  }

  if (state.videoElement) {
    state.videoElement.playbackRate = state.originalPlaybackRate;
    state.videoElement.pause();
    state.videoElement.currentTime = 0;
  }

  setAudioGain(false);
  updateScanButtonUI(false);

  // スキャン完了時に結果を保存
  if (state.volumeData.length > 0 && state.volumeData.some(v => v > 0)) {
    saveVolumeData();
    sendSpectralDataToServer();
  }

  console.log('スキャン停止');

  // 自動スキャンモードの場合は次の動画へ
  if (state.isAutoScanMode && !state.autoScanStopRequested) {
    proceedToNextVideoOrFinish();
  }

  // リストスキャンモードの場合は完了判定して次の動画へ
  if (state.isListScanMode) {
    isCurrentVideoScanned().then(async (completed) => {
      if (completed) {
        hideListScanButton();
        proceedToNextListScanVideo();
      } else {
        const st = await chrome.storage.local.get(['listScanCurrentIndex', 'listScanVideoIds']);
        if (st.listScanVideoIds) {
          showListScanButton(st.listScanCurrentIndex || 0, st.listScanVideoIds.length);
        }
      }
    });
  }
}

export function updateScanButtonUI(scanning) {
  if (!state.volumeGraphContainer) return;
  const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
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

export async function ensureStorageCapacity() {
  const STORAGE_LIMIT = 10 * 1024 * 1024;
  const THRESHOLD = 0.8;

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

        volumeEntries.sort((a, b) => a.savedAt - b.savedAt);

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

export async function saveVolumeData() {
  const videoId = getVideoId();
  if (!videoId || state.volumeData.length === 0) return;

  await ensureStorageCapacity();

  const storageKey = `volumeData_${videoId}`;
  const dataToSave = {
    version: 3,
    samplingInterval: SAMPLING_INTERVAL_SEC,
    data: state.volumeData,
    spectral: state.spectralData.some(s => s !== null) ? state.spectralData : undefined,
    duration: state.videoDuration,
    timestamps: state.detectedTimestamps,
    savedAt: Date.now()
  };

  chrome.storage.local.set({ [storageKey]: dataToSave }, () => {
    if (chrome.runtime.lastError) {
      console.error(`音量データの保存に失敗しました: ${chrome.runtime.lastError.message}`);
      return;
    }
    const spectralCount = state.spectralData.filter(s => s !== null).length;
    console.log(`音量データを保存しました: ${videoId} (${state.volumeData.length}サンプル, スペクトル${spectralCount}件, ${state.detectedTimestamps.length}候補)`);
  });
}

export async function sendSpectralDataToServer() {
  const videoId = getVideoId();
  if (!videoId) return;

  const hasSpectral = state.spectralData.some(s => s !== null);
  if (!hasSpectral) return;

  if (!state.ycsApiToken) {
    await loadYcsApiSettings();
  }
  if (!state.ycsApiToken) return;

  try {
    const response = await fetch(`${state.ycsServerUrl}/api/extension/spectral-data`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${state.ycsApiToken}`,
      },
      body: JSON.stringify({
        video_id: videoId,
        sampling_interval: SAMPLING_INTERVAL_SEC,
        duration: state.videoDuration,
        spectral_data: state.spectralData,
      }),
    });

    if (response.ok) {
      console.log(`[YCS] スペクトルデータをサーバーに送信しました: ${videoId}`);
    } else {
      console.warn(`[YCS] スペクトルデータ送信エラー: ${response.status}`);
    }
  } catch (error) {
    console.warn('[YCS] スペクトルデータ送信エラー:', error.message);
  }
}

export function loadVolumeData() {
  const videoId = getVideoId();
  if (!videoId) return;

  const storageKey = `volumeData_${videoId}`;
  chrome.storage.local.get(storageKey, (result) => {
    const saved = result[storageKey];
    if (saved && saved.data && saved.data.length > 0) {
      // 実動画とdurationが乖離した壊れたデータは復元しない
      if (isSavedVolumeDataStale(saved)) {
        console.warn(`保存された音量データのdurationが動画と一致しないため破棄します: ${videoId}`);
        chrome.storage.local.remove(storageKey);
        return;
      }

      state.volumeData = saved.data;
      state.spectralData = saved.spectral || new Array(saved.data.length).fill(null);
      if (saved.duration) {
        state.videoDuration = saved.duration;
      }
      if (saved.timestamps && saved.timestamps.length > 0) {
        state.detectedTimestamps = saved.timestamps;
      }
      console.log(`音量データを読み込みました: ${videoId} (${state.volumeData.length}サンプル, ${state.detectedTimestamps.length}候補)`);
      drawVolumeGraph();

      getScanStatus().then(status => {
        updateProgress(status.progress);
        updateScanButtonState(status);
      });
    }
  });
}

export function updateScanButtonState(status) {
  if (!state.volumeGraphContainer) return;
  const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
  if (!scanBtn) return;

  if (status.isComplete) {
    scanBtn.textContent = '完了';
    scanBtn.title = 'スキャン完了済み（クリアボタンでリセット可能）';
    scanBtn.style.background = '#2e7d32';
  } else if (status.hasData && status.progress > 0) {
    scanBtn.textContent = `再開 (${status.progress.toFixed(0)}%)`;
    scanBtn.title = `${status.progress.toFixed(1)}%完了 - クリックで続きをスキャン`;
    scanBtn.style.background = '#f57c00';
  } else {
    scanBtn.textContent = 'スキャン';
    scanBtn.title = '動画全体をスキャンしてグラフを生成';
    scanBtn.style.background = '#333';
  }
}

export function showPermissionError() {
  if (!state.volumeGraphContainer) return;

  if (state.volumeGraphContainer.querySelector('.vdg-permission-error')) return;

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

  const graphContainer = state.volumeGraphContainer.querySelector('.vdg-canvas-container');
  if (graphContainer) {
    graphContainer.parentNode.insertBefore(message, graphContainer);
  } else {
    state.volumeGraphContainer.appendChild(message);
  }
}

export function hidePermissionError() {
  if (!state.volumeGraphContainer) return;
  const errorMsg = state.volumeGraphContainer.querySelector('.vdg-permission-error');
  if (errorMsg) {
    errorMsg.remove();
  }
}

export async function discardVolumeDataAndReset() {
  const videoId = getVideoId();
  if (videoId) {
    await chrome.storage.local.remove(`volumeData_${videoId}`);
    console.log(`音量データを破棄しました: ${videoId}`);
  }

  state.volumeData = [];
  state.spectralData = [];
  state.detectedTimestamps = [];
  drawVolumeGraph();
  updateProgress(0);
  getScanStatus().then(status => updateScanButtonState(status));
}
