import state from './state.js';
import { getPlaylistInfo, isInPlaylist, goToNextVideo, isCurrentVideoScanned } from './utils.js';
import { startDirectScan, stopDirectScan } from './audio.js';

export function updatePlaylistUI() {
  if (!state.volumeGraphContainer) return;

  const autoScanBtn = state.volumeGraphContainer.querySelector('#vdg-auto-scan-btn');
  const playlistInfo = state.volumeGraphContainer.querySelector('#vdg-playlist-info');

  if (!isInPlaylist()) {
    if (autoScanBtn) autoScanBtn.classList.add('hidden');
    if (playlistInfo) playlistInfo.textContent = '';
    return;
  }

  if (autoScanBtn) autoScanBtn.classList.remove('hidden');

  const info = getPlaylistInfo();
  if (info && playlistInfo) {
    playlistInfo.textContent = `${info.currentIndex + 1}/${info.total}`;
  }
}

export async function startAutoScan() {
  if (!isInPlaylist()) {
    console.log('自動スキャン: 再生リスト外では使用できません');
    return;
  }

  state.isAutoScanMode = true;
  state.autoScanStopRequested = false;

  const autoScanBtn = state.volumeGraphContainer?.querySelector('#vdg-auto-scan-btn');
  if (autoScanBtn) {
    autoScanBtn.classList.add('auto-scanning');
    autoScanBtn.textContent = '停止';
  }

  console.log('自動スキャン開始');

  const alreadyScanned = await isCurrentVideoScanned();
  if (alreadyScanned) {
    console.log('現在の動画はスキャン済み、次の動画へ移動');
    proceedToNextVideoOrFinish();
  } else {
    startDirectScan();
  }
}

export function stopAutoScan() {
  state.isAutoScanMode = false;
  state.autoScanStopRequested = true;

  const autoScanBtn = state.volumeGraphContainer?.querySelector('#vdg-auto-scan-btn');
  if (autoScanBtn) {
    autoScanBtn.classList.remove('auto-scanning');
    autoScanBtn.textContent = '自動';
  }

  stopDirectScan();
  chrome.runtime.sendMessage({ type: 'STOP_SCAN' });

  console.log('自動スキャン停止');
}

export function proceedToNextVideoOrFinish() {
  if (!state.isAutoScanMode || state.autoScanStopRequested) {
    return;
  }

  const info = getPlaylistInfo();
  if (!info) {
    stopAutoScan();
    return;
  }

  if (info.currentIndex >= info.total - 1) {
    console.log('自動スキャン完了: 再生リストの最後に到達');
    stopAutoScan();
    return;
  }

  console.log(`次の動画へ移動 (${info.currentIndex + 1}/${info.total})`);

  setTimeout(() => {
    if (state.isAutoScanMode && !state.autoScanStopRequested) {
      goToNextVideo();
    }
  }, 1500);
}
