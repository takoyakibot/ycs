import state from './state.js';
import { pickPreferredCaptionTrack } from './song-candidates.js';
import { getVideoId, escapeHtml } from './utils.js';
import { loadYcsApiSettings, missingTokenMessage, postSubtitlesToServer } from './api.js';
import { getCaptionTracksFromPage, fetchTimedText } from './subtitle-panel.js';

let subtitleScanTargets = [];

export function getOwnTabId() {
  return new Promise(resolve => {
    try {
      chrome.runtime.sendMessage({ type: 'GET_TAB_ID' }, res => {
        resolve(res?.tabId ?? null);
      });
    } catch (e) {
      resolve(null);
    }
  });
}

export function setSubtitleScanStatus(text) {
  const el = state.listScanPanel?.querySelector('#ssp-status');
  if (el) el.textContent = text;
}

export async function loadSubtitleScanTargets() {
  if (!state.ycsApiToken) {
    await loadYcsApiSettings();
  }
  if (!state.ycsApiToken) {
    setSubtitleScanStatus(missingTokenMessage());
    return;
  }

  setSubtitleScanStatus('対象を読み込んでいます…');

  try {
    const response = await fetch(`${state.ycsServerUrl}/api/extension/subtitle-targets`, {
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${state.ycsApiToken}`,
      },
    });
    if (!response.ok) {
      throw new Error(`一覧の取得に失敗しました (${response.status})`);
    }

    const data = await response.json();
    subtitleScanTargets = data.targets || [];

    const listEl = state.listScanPanel?.querySelector('#ssp-video-list');
    if (listEl) {
      listEl.innerHTML = subtitleScanTargets.length === 0
        ? '<div class="lsp-empty">字幕未取得のアーカイブはありません</div>'
        : subtitleScanTargets.map((t, i) => `
            <div class="lsp-item">
              <span class="lsp-item-index">${i + 1}.</span>
              <span class="lsp-item-id" title="${escapeHtml(t.video_id)}">${escapeHtml(t.title || t.video_id)}</span>
            </div>
          `).join('');
    }
    setSubtitleScanStatus(`対象: ${subtitleScanTargets.length}件`);

    const startBtn = state.listScanPanel?.querySelector('#ssp-start-btn');
    if (startBtn) startBtn.disabled = subtitleScanTargets.length === 0;
  } catch (error) {
    console.error('[YCS] 字幕取得対象の読み込みエラー:', error);
    setSubtitleScanStatus('エラー: ' + error.message);
  }
}

export async function startSubtitleScan() {
  if (subtitleScanTargets.length === 0) return;

  // 音量リストスキャンと同時に走ると遷移を取り合うため開始を拒否する
  const { listScanActive } = await chrome.storage.local.get(['listScanActive']);
  if (listScanActive) {
    setSubtitleScanStatus('音量のリストスキャン実行中は開始できません。先に停止してください');
    return;
  }

  const tabId = await getOwnTabId();
  const videoIds = subtitleScanTargets.map(t => t.video_id);

  await chrome.storage.local.set({
    subtitleScanVideoIds: videoIds,
    subtitleScanIndex: 0,
    subtitleScanActive: true,
    subtitleScanTabId: tabId,
    subtitleScanResults: { sent: 0, skipped: 0, failed: 0 },
  });

  updateSubtitleScanButtons(true);

  const firstVideoId = videoIds[0];
  if (getVideoId() === firstVideoId) {
    checkAndStartSubtitleScan();
  } else {
    window.location.href = `https://www.youtube.com/watch?v=${firstVideoId}`;
  }
}

export async function stopSubtitleScan() {
  await chrome.storage.local.set({ subtitleScanActive: false });
  updateSubtitleScanButtons(false);
  setSubtitleScanStatus('停止しました');
}

export function updateSubtitleScanButtons(running) {
  if (!state.listScanPanel) return;
  state.listScanPanel.querySelector('#ssp-start-btn').style.display = running ? 'none' : 'block';
  state.listScanPanel.querySelector('#ssp-stop-btn').style.display = running ? 'block' : 'none';
}

export async function restoreSubtitleScanPanelState() {
  try {
    const result = await chrome.storage.local.get([
      'subtitleScanActive', 'subtitleScanVideoIds', 'subtitleScanIndex',
    ]);
    if (result.subtitleScanActive && result.subtitleScanVideoIds) {
      updateSubtitleScanButtons(true);
      setSubtitleScanStatus(`字幕取得中… ${(result.subtitleScanIndex || 0) + 1}/${result.subtitleScanVideoIds.length}`);
    }
  } catch (e) { /* 復元失敗は無視 */ }
}

export async function checkAndStartSubtitleScan() {
  try {
    const result = await chrome.storage.local.get([
      'subtitleScanVideoIds', 'subtitleScanIndex', 'subtitleScanActive', 'subtitleScanTabId',
    ]);

    if (!result.subtitleScanActive || !result.subtitleScanVideoIds) return;

    const tabId = await getOwnTabId();
    if (result.subtitleScanTabId != null && tabId !== result.subtitleScanTabId) return;

    const videoIds = result.subtitleScanVideoIds;
    const index = result.subtitleScanIndex || 0;
    const videoId = getVideoId();
    if (videoId !== videoIds[index]) return;

    console.log(`[YCS] 字幕スキャン: ${index + 1}/${videoIds.length} を処理します`);
    setSubtitleScanStatus(`字幕取得中… ${index + 1}/${videoIds.length}`);
    updateSubtitleScanButtons(true);

    waitForPlayerAndProcessSubtitle(videoId);
  } catch (error) {
    console.error('[YCS] 字幕スキャンチェックエラー:', error);
  }
}

export function waitForPlayerAndProcessSubtitle(videoId) {
  let attempt = 0;
  const check = () => {
    if (state.videoElement && state.videoElement.readyState >= 2) {
      processSubtitleScanVideo(videoId);
    } else if (attempt >= 30) {
      console.warn('[YCS] 字幕スキャン: プレイヤーが準備できないためスキップします', videoId);
      recordSubtitleScanResult('skipped').then(proceedToNextSubtitleScanVideo);
    } else {
      attempt++;
      setTimeout(check, 1000);
    }
  };
  // ページ読み込み直後の切り替わりを待つ
  setTimeout(check, 1500);
}

export async function reportSubtitlesUnavailable(videoId) {
  try {
    if (!state.ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!state.ycsApiToken) return;

    await fetch(`${state.ycsServerUrl}/api/extension/subtitles/unavailable`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${state.ycsApiToken}`,
      },
      body: JSON.stringify({ video_id: videoId }),
    });
    console.log('[YCS] 字幕なしを記録しました:', videoId);
  } catch (error) {
    console.warn('[YCS] 字幕なし報告エラー:', error.message);
  }
}

export async function processSubtitleScanVideo(videoId) {
  try {
    const tracks = await getCaptionTracksFromPage();
    if (!tracks || tracks.length === 0) {
      console.log('[YCS] 字幕スキャン: 字幕がないためスキップ', videoId);
      await reportSubtitlesUnavailable(videoId);
      await recordSubtitleScanResult('skipped');
    } else {
      const track = pickPreferredCaptionTrack(tracks);
      const segments = await fetchTimedText(videoId, track.languageCode);
      if (!segments || segments.length === 0) {
        await recordSubtitleScanResult('skipped');
      } else {
        await postSubtitlesToServer(videoId, track.languageCode, track.kind === 'asr' ? 'asr' : '', segments);
        await recordSubtitleScanResult('sent');
      }
    }
  } catch (error) {
    console.warn('[YCS] 字幕スキャン: 取得・送信に失敗', videoId, error.message);
    await recordSubtitleScanResult('failed');
  }

  await proceedToNextSubtitleScanVideo();
}

export async function recordSubtitleScanResult(kind) {
  const result = await chrome.storage.local.get(['subtitleScanResults']);
  const counts = result.subtitleScanResults || { sent: 0, skipped: 0, failed: 0 };
  counts[kind] = (counts[kind] || 0) + 1;
  await chrome.storage.local.set({ subtitleScanResults: counts });
}

export async function proceedToNextSubtitleScanVideo() {
  const result = await chrome.storage.local.get([
    'subtitleScanVideoIds', 'subtitleScanIndex', 'subtitleScanActive', 'subtitleScanTabId', 'subtitleScanResults',
  ]);

  if (!result.subtitleScanActive) return;

  const tabId = await getOwnTabId();
  if (result.subtitleScanTabId != null && tabId !== result.subtitleScanTabId) return;

  const videoIds = result.subtitleScanVideoIds || [];
  const nextIndex = (result.subtitleScanIndex || 0) + 1;

  if (nextIndex >= videoIds.length) {
    const counts = result.subtitleScanResults || {};
    const summary = `字幕一括取得が完了しました（送信 ${counts.sent || 0}件 / スキップ ${counts.skipped || 0}件 / 失敗 ${counts.failed || 0}件）`;
    console.log('[YCS] ' + summary);
    await chrome.storage.local.set({ subtitleScanActive: false });
    updateSubtitleScanButtons(false);
    setSubtitleScanStatus(summary);
    return;
  }

  await chrome.storage.local.set({ subtitleScanIndex: nextIndex });

  // 連続アクセスを避けるため少し待ってから遷移する
  setTimeout(async () => {
    const { subtitleScanActive } = await chrome.storage.local.get(['subtitleScanActive']);
    if (!subtitleScanActive) return;
    window.location.href = `https://www.youtube.com/watch?v=${videoIds[nextIndex]}`;
  }, 2000);
}

export async function loadScannedVideosList() {
  try {
    const allData = await chrome.storage.local.get(null);
    const videos = [];

    for (const key in allData) {
      if (key.startsWith('volumeData_')) {
        const videoId = key.replace('volumeData_', '');
        const data = allData[key];

        if (!data || !data.data) continue;

        const filledCount = data.data.filter(v => v > 0).length;
        const progress = Math.round((filledCount / data.data.length) * 100);

        videos.push({
          videoId,
          savedAt: data.savedAt || null,
          duration: data.duration || 0,
          progress: progress >= 95 ? 100 : progress
        });
      }
    }

    videos.sort((a, b) => {
      if (!a.savedAt) return 1;
      if (!b.savedAt) return -1;
      return new Date(b.savedAt) - new Date(a.savedAt);
    });

    renderScannedVideosList(videos);
  } catch (error) {
    console.error('スキャン済み動画一覧取得エラー:', error);
  }
}

export function renderScannedVideosList(videos) {
  if (!state.listScanPanel) return;

  const listContainer = state.listScanPanel.querySelector('#lsp-scanned-video-list');
  const countEl = state.listScanPanel.querySelector('#lsp-scanned-count');
  const clearAllBtn = state.listScanPanel.querySelector('#lsp-clear-all-btn');

  countEl.textContent = `${videos.length} 件のスキャン済み動画`;

  if (videos.length === 0) {
    listContainer.innerHTML = '<div class="lsp-empty">スキャン済みの動画がありません</div>';
    clearAllBtn.disabled = true;
    return;
  }

  clearAllBtn.disabled = false;

  const html = videos.map(video => {
    const dateStr = video.savedAt
      ? new Date(video.savedAt).toLocaleDateString('ja-JP', { month: 'numeric', day: 'numeric' })
      : '-';

    return `
      <div class="lsp-scanned-item" data-video-id="${video.videoId}">
        <span class="lsp-item-id">${video.videoId}</span>
        <span class="lsp-item-date">${dateStr}</span>
        <span class="lsp-item-status">${video.progress}%</span>
        <div class="lsp-item-actions">
          <button class="lsp-open-btn" data-action="open" data-video-id="${video.videoId}">開く</button>
          <button class="lsp-delete-btn" data-action="delete" data-video-id="${video.videoId}">×</button>
        </div>
      </div>
    `;
  }).join('');

  listContainer.innerHTML = html;

  listContainer.querySelectorAll('[data-action="open"]').forEach(btn => {
    btn.addEventListener('click', () => openYouTubeVideo(btn.dataset.videoId));
  });

  listContainer.querySelectorAll('[data-action="delete"]').forEach(btn => {
    btn.addEventListener('click', () => deleteScannedVideo(btn.dataset.videoId));
  });
}

export function openYouTubeVideo(videoId) {
  window.open(`https://www.youtube.com/watch?v=${videoId}`, '_blank');
}

export async function deleteScannedVideo(videoId) {
  const key = `volumeData_${videoId}`;
  await chrome.storage.local.remove(key);
  loadScannedVideosList();
}

export async function clearAllScannedVideos() {
  if (!confirm('全てのスキャン済みデータを削除しますか？')) {
    return;
  }

  try {
    const allData = await chrome.storage.local.get(null);
    const keysToRemove = [];

    for (const key in allData) {
      if (key.startsWith('volumeData_')) {
        keysToRemove.push(key);
      }
    }

    if (keysToRemove.length > 0) {
      await chrome.storage.local.remove(keysToRemove);
    }

    loadScannedVideosList();
  } catch (error) {
    console.error('全データ削除エラー:', error);
  }
}
