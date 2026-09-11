import state from './state.js';
import { SAVE_INTERVAL, DEFAULT_GRAPH_BASE_HEIGHT_PX, DEFAULT_GRAPH_HEIGHT_STEP_PX, GRAPH_BASE_HEIGHT_RANGE, GRAPH_HEIGHT_STEP_RANGE } from './config.js';
import { getVideoId, clampGraphHeightValue, updateVideoDuration, updateProgress, isCurrentVideoScanned, getScanStatus } from './utils.js';
import { showEmbeddedUI, hideEmbeddedUI, hideVolumeGraphPanel } from './ui.js';
import { drawVolumeGraph, createVolumeGraph, insertVolumeGraph, resizeCanvas } from './volume-graph.js';
import { closeLyricsPastePopup, closeSongCandidatePopup } from './song-candidates.js';
import { saveVolumeData, sendSpectralDataToServer, updateScanButtonState, showPermissionError, hidePermissionError } from './audio.js';
import { updateListScanButtonState, showListScanButton, hideListScanButton, proceedToNextListScanVideo } from './list-scan.js';
import { proceedToNextVideoOrFinish } from './playlist.js';

export function handleMessage(message, sender, sendResponse) {
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
      state.detectedTimestamps.push(message.timestamp);
      drawVolumeGraph();
      break;

    case 'SHOW_VOLUME_GRAPH':
      if (state.volumeGraphContainer) {
        state.volumeGraphContainer.classList.add('visible');
        state.isGraphVisible = true;
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
      console.log('TOGGLE_VOLUME_GRAPH 受信', { volumeGraphContainer: !!state.volumeGraphContainer, inDOM: state.volumeGraphContainer?.parentNode });
      if (state.volumeGraphContainer) {
        if (!state.volumeGraphContainer.parentNode) {
          console.log('グラフがDOMに存在しないため再挿入を試みます');
          insertVolumeGraph();
        }
        state.volumeGraphContainer.classList.toggle('visible');
        state.isGraphVisible = state.volumeGraphContainer.classList.contains('visible');
        if (!state.isGraphVisible) {
          closeLyricsPastePopup();
          closeSongCandidatePopup();
        }
        const computed = getComputedStyle(state.volumeGraphContainer);
        console.log('グラフ表示状態:', state.isGraphVisible, {
          classList: state.volumeGraphContainer.className,
          computedDisplay: computed.display,
          computedVisibility: computed.visibility,
          computedOpacity: computed.opacity,
          computedHeight: computed.height,
          computedWidth: computed.width,
          boundingRect: state.volumeGraphContainer.getBoundingClientRect()
        });
        if (state.isGraphVisible) {
          updateVideoDuration();
          resizeCanvas();
        }
      } else {
        console.log('volumeGraphContainer が存在しません。createVolumeGraph を呼び出します');
        createVolumeGraph();
        if (state.volumeGraphContainer) {
          state.volumeGraphContainer.classList.add('visible');
          state.isGraphVisible = true;
          updateVideoDuration();
          resizeCanvas();
        }
      }
      break;

    case 'VOLUME_DATA_UPDATE':
      if (!state.backgroundScanVideoId || state.backgroundScanVideoId !== getVideoId()) {
        break;
      }
      state.volumeData = message.data;
      if (message.spectral) state.spectralData = message.spectral;
      updateProgress(message.progress || 0);
      drawVolumeGraph();
      {
        const now = Date.now();
        if (now - state.lastSaveTime >= SAVE_INTERVAL && state.volumeData.some(v => v > 0)) {
          state.lastSaveTime = now;
          saveVolumeData();
          console.log('スキャン中: 音量データを自動保存');
        }
      }
      break;

    case 'SCAN_STARTED':
      state.backgroundScanVideoId = getVideoId();
      if (state.volumeGraphContainer) {
        const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
        if (scanBtn) {
          scanBtn.classList.add('scanning');
          scanBtn.textContent = '停止';
        }
        hidePermissionError();
      }
      updateListScanButtonState(true);
      break;

    case 'SCAN_PERMISSION_ERROR':
      showPermissionError();
      break;

    case 'SCAN_STOPPED':
      state.backgroundScanVideoId = null;
      if (state.volumeGraphContainer) {
        const scanBtnStop = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
        if (scanBtnStop) {
          scanBtnStop.classList.remove('scanning');
        }
      }
      if (state.volumeData.length > 0) {
        saveVolumeData();
        sendSpectralDataToServer();
      }
      getScanStatus().then(status => {
        updateScanButtonState(status);
      });
      if (state.isAutoScanMode && !state.autoScanStopRequested) {
        proceedToNextVideoOrFinish();
      }
      if (state.isListScanMode) {
        isCurrentVideoScanned().then(async (completed) => {
          if (completed) {
            hideListScanButton();
            proceedToNextListScanVideo();
          } else {
            const storageData = await chrome.storage.local.get(['listScanCurrentIndex', 'listScanVideoIds']);
            if (storageData.listScanVideoIds) {
              showListScanButton(storageData.listScanCurrentIndex || 0, storageData.listScanVideoIds.length);
            }
          }
        });
      }
      break;

    case 'GET_VIDEO_INFO':
      updateVideoDuration();
      sendResponse({
        duration: state.videoDuration,
        currentTime: state.videoElement?.currentTime || 0
      });
      return true;

    case 'GET_SCAN_STATUS':
      getScanStatus().then(status => sendResponse(status));
      return true;

    case 'SEEK_VIDEO':
      if (state.videoElement) {
        state.videoElement.currentTime = message.time;
      }
      break;

    case 'SET_PLAYBACK_RATE':
      if (state.videoElement) {
        state.videoElement.playbackRate = message.rate;
      }
      break;

    case 'SET_MUTED':
      if (state.videoElement) {
        state.videoElement.muted = message.muted;
      }
      break;

    case 'PLAY_VIDEO':
      if (state.videoElement) {
        state.videoElement.play();
      }
      break;

    case 'PAUSE_VIDEO':
      if (state.videoElement) {
        state.videoElement.pause();
      }
      break;
  }
}

export function handleStorageChange(changes, areaName) {
  if (areaName !== 'local') return;

  if (changes.graphBaseHeight || changes.graphHeightStep) {
    if (changes.graphBaseHeight) {
      state.graphBaseHeightPx = clampGraphHeightValue(
        changes.graphBaseHeight.newValue, DEFAULT_GRAPH_BASE_HEIGHT_PX, GRAPH_BASE_HEIGHT_RANGE);
    }
    if (changes.graphHeightStep) {
      state.graphHeightStepPx = clampGraphHeightValue(
        changes.graphHeightStep.newValue, DEFAULT_GRAPH_HEIGHT_STEP_PX, GRAPH_HEIGHT_STEP_RANGE);
    }
    if (state.volumeGraphContainer) {
      resizeCanvas();
    }
  }

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

  if (changes[storageKey]) {
    const newData = changes[storageKey].newValue;

    if (!newData) {
      if (state.isScanning) return;
      console.log(`音量データが削除されたためグラフをリセットします: ${videoId}`);
      state.volumeData = [];
      state.spectralData = [];
      state.detectedTimestamps = [];
      drawVolumeGraph();
      updateProgress(0);
      getScanStatus().then(status => updateScanButtonState(status));
      return;
    }

    if (newData && newData.data && newData.data.length > 0) {
      if (state.isScanning) return;

      console.log(`他タブからの音量データを受信: ${videoId}`);

      state.volumeData = newData.data;
      state.spectralData = newData.spectral || new Array(newData.data.length).fill(null);
      if (newData.duration) {
        state.videoDuration = newData.duration;
      }

      if (newData.timestamps) {
        state.detectedTimestamps = newData.timestamps;
      }

      drawVolumeGraph();

      const progress = state.volumeGraphContainer?.querySelector('#vdg-progress');
      if (progress && state.videoDuration > 0) {
        const coverage = state.volumeData.length > 0
          ? (state.volumeData.filter(v => v > 0).length / state.volumeData.length) * 100
          : 0;
        progress.textContent = `分析 ${Math.round(coverage)}%`;
      }
    }
  }
}
