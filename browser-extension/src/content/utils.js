import state from './state.js';
import {
  SAMPLING_INTERVAL_SEC,
  LEGACY_GRAPH_RESOLUTION,
  DEFAULT_GRAPH_BASE_HEIGHT_PX,
  DEFAULT_GRAPH_HEIGHT_STEP_PX,
  GRAPH_BASE_HEIGHT_RANGE,
  GRAPH_HEIGHT_STEP_RANGE,
} from './config.js';

export function calcGraphResolution(duration) {
  // ライブ配信中はdurationがInfinityになり、Array確保で例外になるためガードする（#607）
  if (!duration || duration <= 0 || !isFinite(duration)) return LEGACY_GRAPH_RESOLUTION;
  return Math.ceil(duration / SAMPLING_INTERVAL_SEC);
}

export function clampGraphHeightValue(value, defaultValue, range) {
  if (value === null || value === undefined || value === '') return defaultValue;
  const num = Number(value);
  if (!Number.isFinite(num)) return defaultValue;
  return Math.max(range.min, Math.min(range.max, Math.round(num)));
}

export async function loadGraphHeightSettings() {
  try {
    const result = await chrome.storage.local.get(['graphBaseHeight', 'graphHeightStep']);
    state.graphBaseHeightPx = clampGraphHeightValue(
      result.graphBaseHeight, DEFAULT_GRAPH_BASE_HEIGHT_PX, GRAPH_BASE_HEIGHT_RANGE);
    state.graphHeightStepPx = clampGraphHeightValue(
      result.graphHeightStep, DEFAULT_GRAPH_HEIGHT_STEP_PX, GRAPH_HEIGHT_STEP_RANGE);
  } catch (error) {
    console.warn('[YCS] グラフ高さ設定の読み込みエラー:', error);
  }
}

export async function loadEmbeddedUISettings() {
  try {
    const result = await chrome.storage.local.get('showEmbeddedUI');
    state.embeddedUIVisible = result.showEmbeddedUI !== false;
    return state.embeddedUIVisible;
  } catch (error) {
    console.error('埋め込みUI設定読み込みエラー:', error);
    return true;
  }
}

export function getVideoId() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get('v');
}

export function formatTime(seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);

  if (h > 0) {
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }
  return `${m}:${s.toString().padStart(2, '0')}`;
}

export function getPlaylistId() {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get('list');
}

export function isInPlaylist() {
  return !!getPlaylistId();
}

export function getPlaylistInfo() {
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

export function goToNextVideo() {
  const nextButton = document.querySelector('.ytp-next-button');
  if (nextButton) {
    nextButton.click();
    return true;
  }
  return false;
}

export function isSavedVolumeDataStale(saved) {
  const actual = state.videoElement?.duration;
  if (!saved?.duration || !actual || !isFinite(actual)) return false;

  return Math.abs(saved.duration - actual) / actual > 0.05;
}

export async function isCurrentVideoScanned() {
  const videoId = getVideoId();
  if (!videoId) return false;

  const storageKey = `volumeData_${videoId}`;
  const result = await chrome.storage.local.get(storageKey);
  const saved = result[storageKey];
  if (!saved || !saved.data || saved.data.length === 0) return false;

  // durationが実動画と乖離した壊れたデータは破棄して未スキャン扱いにする
  if (isSavedVolumeDataStale(saved)) {
    console.warn('保存された音量データのdurationが動画と一致しないため破棄します', {
      videoId,
      saved: saved.duration,
      actual: state.videoElement?.duration,
    });
    await chrome.storage.local.remove(storageKey);
    return false;
  }

  const filledCount = saved.data.filter(v => v > 0).length;

  return (filledCount / saved.data.length) * 100 >= 95;
}

export function getScanStatus() {
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

      const resumeTime = duration > 0 && lastFilledIndex >= 0
        ? (lastFilledIndex / resolution) * duration
        : 0;

      resolve({
        hasData: true,
        progress: progress,
        resumeTime: resumeTime,
        isComplete: isComplete,
        data: data,
        spectral: saved.spectral || null,
        duration: duration
      });
    });
  });
}

export function isWatchPage() {
  return location.pathname === '/watch';
}

export function findVideoElement() {
  const checkVideo = () => {
    state.videoElement = document.querySelector('video.html5-main-video');
    if (state.videoElement) {
      console.log('動画要素を検出しました');
      startTimeSync();
    } else {
      setTimeout(checkVideo, 1000);
    }
  };
  checkVideo();
}

export function startTimeSync() {
  if (state.timeUpdateInterval) {
    clearInterval(state.timeUpdateInterval);
  }

  if (state.videoListenerTarget !== state.videoElement) {
    state.videoListenerTarget = state.videoElement;

    state.videoElement.addEventListener('loadedmetadata', () => {
      updateVideoDuration();
    });

    // 停止中のシークでも赤い再生位置ラインを追従させる
    state.videoElement.addEventListener('seeked', () => {
      if (state.isGraphVisible) {
        updateTimeMarker();
      }
    });
  }

  if (state.videoElement.duration) {
    updateVideoDuration();
  }

  state.timeUpdateInterval = setInterval(() => {
    if (state.videoElement && !state.videoElement.paused) {
      chrome.runtime.sendMessage({
        type: 'UPDATE_VIDEO_TIME',
        time: state.videoElement.currentTime
      });
      if (state.isGraphVisible) {
        updateTimeMarker();
      }
    }
  }, 100);
}

export function updateTimeMarker() {
  if (!state.videoElement || !state.videoDuration || !state.volumeGraphContainer) return;
  const marker = state.volumeGraphContainer.querySelector('#vdg-time-marker');
  if (!marker) return;

  const currentTime = state.videoElement.currentTime;
  const ratio = currentTime / state.videoDuration;
  marker.style.left = `${ratio * 100}%`;

  const timeDisplay = state.volumeGraphContainer.querySelector('#vdg-current-time');
  if (timeDisplay) {
    timeDisplay.textContent = formatTimeDisplay(currentTime);
  }
}

export function formatTimeDisplay(seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);

  if (h > 0) {
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }
  return `${m}:${s.toString().padStart(2, '0')}`;
}

export function updateProgress(percent) {
  if (!state.volumeGraphContainer) return;
  const progress = state.volumeGraphContainer.querySelector('#vdg-progress');
  if (progress) {
    progress.textContent = `分析 ${Math.round(percent)}%`;
  }
}

export function isAdShowing() {
  return !!document.querySelector('.html5-video-player.ad-showing');
}

export function updateVideoDuration() {
  if (!state.videoElement) return;
  // 広告のdurationを実動画の長さとして採用しない（#607）
  if (isAdShowing()) return;
  state.videoDuration = state.videoElement.duration || 0;

  if (!state.volumeGraphContainer) return;
  const endTime = state.volumeGraphContainer.querySelector('#vdg-end-time');
  if (endTime && state.videoDuration) {
    endTime.textContent = formatTimeDisplay(state.videoDuration);
  }
}

export function formatTimestampMsec(msec) {
  const totalSeconds = Math.floor(msec / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (hours > 0) {
    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

export function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text || '';
  return div.innerHTML;
}

export function formatSubtitleTime(totalSeconds) {
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = totalSeconds % 60;
  if (h > 0) {
    return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  }
  return `${m}:${s.toString().padStart(2, '0')}`;
}
