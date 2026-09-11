import state from './state.js';
import { isWatchPage, findVideoElement, loadGraphHeightSettings, loadEmbeddedUISettings, getVideoId, updateVideoDuration } from './utils.js';
import { createEmbeddedTriggerButton, showEmbeddedUI, hideEmbeddedUI, hideVolumeGraphPanel } from './ui.js';
import { createVolumeGraph, insertVolumeGraph, drawVolumeGraph, resizeCanvas } from './volume-graph.js';
import { loadVolumeData, stopDirectScan, startDirectScan, saveVolumeData } from './audio.js';
import { updatePlaylistUI, proceedToNextVideoOrFinish } from './playlist.js';
import { resetTimestampEditorForVideoChange } from './timestamp-io.js';
import { isCurrentVideoScanned } from './utils.js';
import { handleMessage, handleStorageChange } from './handlers.js';

state.edition = 'general';

function initWatchPageUI() {
  findVideoElement();
  createVolumeGraph();
  createEmbeddedTriggerButton();

  if (state.embeddedUIVisible) {
    showEmbeddedUI();
  } else {
    hideEmbeddedUI();
  }

  insertVolumeGraph();
}

function hideWatchPageUI() {
  if (state.embeddedTriggerButton) {
    state.embeddedTriggerButton.classList.add('hidden');
  }
  hideVolumeGraphPanel();

  if (state.isScanning) {
    stopDirectScan();
    chrome.runtime.sendMessage({ type: 'STOP_SCAN' });
  }
}

function observePageChanges() {
  let lastUrl = location.href;
  let lastVideoId = getVideoId();
  let wasWatchPage = isWatchPage();

  function handleNavigation() {
    const currentUrl = location.href;
    if (currentUrl === lastUrl) return;

    lastUrl = currentUrl;
    const nowWatchPage = isWatchPage();
    const currentVideoId = getVideoId();

    if (nowWatchPage && !wasWatchPage) {
      lastVideoId = currentVideoId;

      state.volumeData = [];
      state.spectralData = [];
      state.videoDuration = 0;
      state.backgroundScanVideoId = null;
      state.detectedTimestamps = [];
      state.zoomIndex = 0;
      state.currentSubtitles = [];
      state.currentCaptionTracks = [];
      if (state.mediaElementSource) {
        state.mediaElementSource.disconnect();
        state.mediaElementSource = null;
      }
      state.analyserNode = null;
      state.gainNode = null;
      state.audioInitialized = false;

      initWatchPageUI();
      loadVolumeData();
      resetTimestampEditorForVideoChange();
    } else if (nowWatchPage && currentVideoId !== lastVideoId) {
      lastVideoId = currentVideoId;
      state.volumeData = [];
      state.spectralData = [];
      state.videoDuration = 0;
      state.backgroundScanVideoId = null;
      state.detectedTimestamps = [];
      state.zoomIndex = 0;
      state.currentSubtitles = [];
      state.currentCaptionTracks = [];

      if (state.mediaElementSource) {
        state.mediaElementSource.disconnect();
        state.mediaElementSource = null;
      }
      state.analyserNode = null;
      state.gainNode = null;
      state.audioInitialized = false;

      drawVolumeGraph();
      findVideoElement();
      insertVolumeGraph();

      updatePlaylistUI();

      loadVolumeData();
      resetTimestampEditorForVideoChange();

      if (state.isAutoScanMode && !state.autoScanStopRequested) {
        console.log('自動スキャン継続: 新しい動画を検出');
        setTimeout(async () => {
          if (!state.isAutoScanMode || state.autoScanStopRequested) return;

          const alreadyScanned = await isCurrentVideoScanned();
          if (alreadyScanned) {
            console.log('この動画はスキャン済み、次へ');
            proceedToNextVideoOrFinish();
          } else {
            startDirectScan();
          }
        }, 3000);
      }
    } else if (!nowWatchPage && wasWatchPage) {
      hideWatchPageUI();
    }

    wasWatchPage = nowWatchPage;
  }

  document.addEventListener('yt-navigate-finish', handleNavigation);

  const observer = new MutationObserver(() => {
    if (location.href !== lastUrl) {
      handleNavigation();
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });
}

async function init() {
  chrome.runtime.onMessage.addListener(handleMessage);
  chrome.storage.onChanged.addListener(handleStorageChange);

  try {
    await loadEmbeddedUISettings();
    await loadGraphHeightSettings();

    if (isWatchPage()) {
      initWatchPageUI();
    }

    observePageChanges();
  } catch (error) {
    console.error('Content script初期化エラー:', error);
  }
}

window.addEventListener('beforeunload', () => {
  if (state.timeUpdateInterval) {
    clearInterval(state.timeUpdateInterval);
  }
  if (state.volumeData.length > 0 && state.volumeData.some(v => v > 0)) {
    saveVolumeData();
    console.log('ページ遷移: 音量データを保存');
  }
});

init();
