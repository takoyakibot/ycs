import state from './state.js';
import { getVideoId } from './utils.js';
import { formatTimestamp, showTsEditorNotice } from './auto-detect.js';
import { pushMarkerHistory, updateTimestampList, updateUndoRedoButtons } from './timestamp-editor.js';
import { drawVolumeGraph } from './volume-graph.js';
import { closeLyricsPastePopup, closeSongCandidatePopup } from './song-candidates.js';
import { ensureSubtitlesOnServer } from './song-candidates.js';

export function copyTimestamps() {
  if (state.tsMarkers.length === 0) return;

  const text = state.tsMarkers
    .map(m => `${formatTimestamp(m.time)} ${m.text}`)
    .join('\n');

  navigator.clipboard.writeText(text).then(() => {
    const copyBtn = state.volumeGraphContainer?.querySelector('#vdg-ts-copy-btn');
    if (copyBtn) {
      const original = copyBtn.textContent;
      copyBtn.textContent = 'コピー済み';
      setTimeout(() => { copyBtn.textContent = original; }, 1500);
    }
  });
}

export function parseTimestampText(text) {
  const parsed = [];
  let skippedLines = 0;
  const lines = text.split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    const stripped = trimmed.replace(/^(?:\d+[.)]\s*|[・\-]\s*)/, '');
    const match = stripped.match(/^(\d{1,2}):(\d{2}):(\d{2})\s*(.*)/);
    if (match) {
      const time = parseInt(match[1], 10) * 3600 + parseInt(match[2], 10) * 60 + parseInt(match[3], 10);
      parsed.push({ time, text: match[4].trim() });
      continue;
    }
    const matchShort = stripped.match(/^(\d{1,2}):(\d{2})\s*(.*)/);
    if (matchShort) {
      const time = parseInt(matchShort[1], 10) * 60 + parseInt(matchShort[2], 10);
      parsed.push({ time, text: matchShort[3].trim() });
      continue;
    }
    skippedLines++;
  }
  return { parsed, skippedLines };
}

export async function importTimestamps() {
  let clipText;
  try {
    clipText = await navigator.clipboard.readText();
  } catch {
    showTsEditorNotice('クリップボードの読み取りに失敗しました', true);
    return;
  }

  if (!clipText || !clipText.trim()) {
    showTsEditorNotice('クリップボードにテキストがありません', true);
    return;
  }

  const { parsed, skippedLines } = parseTimestampText(clipText);
  if (parsed.length === 0) {
    showTsEditorNotice('タイムスタンプを検出できませんでした', true);
    return;
  }

  let outOfRange = 0;
  const valid = state.videoDuration
    ? parsed.filter(p => {
        if (p.time > state.videoDuration) { outOfRange++; return false; }
        return true;
      })
    : parsed;

  if (valid.length === 0) {
    showTsEditorNotice('すべてのタイムスタンプが動画の長さを超えています', true);
    return;
  }

  if (state.tsMarkers.length > 0) {
    if (!confirm(`既存の${state.tsMarkers.length}件のマーカーを削除して、${valid.length}件のタイムスタンプを取り込みますか？`)) return;
  }

  pushMarkerHistory();
  state.tsMarkers = valid.map(p => ({ id: state.nextMarkerId++, time: p.time, text: p.text }));
  state.tsMarkers.sort((a, b) => a.time - b.time);
  state.selectedMarkerId = null;
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();

  const notes = [];
  if (skippedLines > 0) notes.push(`${skippedLines}行はスキップ`);
  if (outOfRange > 0) notes.push(`${outOfRange}件は動画長超過で除外`);
  const suffix = notes.length > 0 ? `（${notes.join('、')}）` : '';
  showTsEditorNotice(`${valid.length}件のタイムスタンプを取り込みました${suffix}`);

  const videoId = getVideoId();
  if (videoId && state.ycsApiToken) {
    try {
      await ensureSubtitlesOnServer(videoId);
    } catch { /* 字幕取得失敗は候補ボタン押下時に再試行される */ }
  }
}

export function saveMarkersToStorage() {
  const videoId = getVideoId();
  if (!videoId) return;
  const key = `tsMarkers_${videoId}`;
  chrome.storage.local.set({ [key]: { markers: state.tsMarkers, nextId: state.nextMarkerId } });
}

export function loadMarkersFromStorage() {
  const videoId = getVideoId();
  if (!videoId) return;
  const key = `tsMarkers_${videoId}`;
  chrome.storage.local.get(key, (result) => {
    // 読み込み中に別の動画へ遷移していた場合は破棄する
    if (getVideoId() !== videoId) return;

    const saved = result[key];
    if (saved && saved.markers) {
      state.tsMarkers = saved.markers;
      state.nextMarkerId = saved.nextId || state.tsMarkers.length + 1;
      state.selectedMarkerId = null;
      state.tsHistoryUndo = [];
      state.tsHistoryRedo = [];
      state.lastHistoryTag = null;
      updateUndoRedoButtons();
      updateTimestampList();
      drawVolumeGraph();
    }
  });
}

export function resetTimestampEditorForVideoChange() {
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  state.tsMarkers = [];
  state.selectedMarkerId = null;
  state.nextMarkerId = 1;
  state.tsHistoryUndo = [];
  state.tsHistoryRedo = [];
  state.lastHistoryTag = null;
  updateUndoRedoButtons();
  updateTimestampList();
  drawVolumeGraph();
  loadMarkersFromStorage();
}
