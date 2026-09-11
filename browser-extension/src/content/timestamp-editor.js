import state from './state.js';
import { TS_HISTORY_LIMIT, TS_HISTORY_COALESCE_MS } from './config.js';
import { escapeHtml, updateTimeMarker } from './utils.js';
import { drawVolumeGraph, getZoomLevel } from './volume-graph.js';
import { saveMarkersToStorage } from './timestamp-io.js';
import {
  closeLyricsPastePopup, closeSongCandidatePopup,
  showSongCandidates, buildLyricsSplitCandidates, showLyricsPastePopup,
} from './song-candidates.js';

function formatTimestamp(seconds) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);

  const needsHour = state.videoDuration >= 3600;

  if (state.tsZeroPad) {
    if (needsHour) {
      return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  } else {
    if (needsHour) {
      return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m}:${s.toString().padStart(2, '0')}`;
  }
}

export function updateTimestampList() {
  const listEl = state.volumeGraphContainer?.querySelector('#vdg-ts-list');
  if (!listEl) return;

  // リスト再構築でペースト変換ポップアップの対象入力欄が破棄されるため閉じる
  closeLyricsPastePopup();
  closeSongCandidatePopup();

  if (state.tsMarkers.length === 0) {
    listEl.innerHTML = '<div class="vdg-ts-empty">波形グラフをクリックしてタイムスタンプを追加</div>';
    return;
  }

  listEl.innerHTML = state.tsMarkers.map(marker => `
    <div class="vdg-ts-row ${marker.id === state.selectedMarkerId ? 'selected' : ''}" data-marker-id="${marker.id}">
      <button type="button" class="vdg-ts-offset-btn" data-marker-id="${marker.id}" data-delta="-1" title="-1秒" tabindex="-1">-1s</button>
      <span class="vdg-ts-time">${formatTimestamp(marker.time)}</span>
      <button type="button" class="vdg-ts-offset-btn" data-marker-id="${marker.id}" data-delta="1" title="+1秒" tabindex="-1">+1s</button>
      <input type="text" class="vdg-ts-text-input" value="${escapeHtml(marker.text)}" placeholder="曲名を入力..." data-marker-id="${marker.id}">
      <button type="button" class="vdg-ts-suggest-btn" data-marker-id="${marker.id}" title="字幕から曲名候補を表示" tabindex="-1">候補</button>
    </div>
  `).join('');

  // イベントリスナー
  listEl.querySelectorAll('.vdg-ts-offset-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(btn.dataset.markerId);
      const delta = parseInt(btn.dataset.delta);
      state.selectedMarkerId = id;
      moveSelectedMarker(delta);
    });
  });

  listEl.querySelectorAll('.vdg-ts-suggest-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(btn.dataset.markerId);
      state.selectedMarkerId = id;
      const marker = state.tsMarkers.find(m => m.id === id);
      if (marker) {
        showSongCandidates(marker);
      }
    });
  });

  listEl.querySelectorAll('.vdg-ts-row').forEach(row => {
    row.addEventListener('click', (e) => {
      if (e.target.classList.contains('vdg-ts-text-input') || e.target.classList.contains('vdg-ts-offset-btn') || e.target.classList.contains('vdg-ts-suggest-btn')) return;
      // テキスト入力中にドラッグ選択してテキストボックス外でmouseupした場合、
      // clickイベントが行要素に発火する。入力中のinputが存在する場合はスキップして
      // DOM再構築によるフォーカス喪失を防ぐ
      const inputInRow = row.querySelector('.vdg-ts-text-input');
      if (inputInRow && document.activeElement === inputInRow) return;
      const id = parseInt(row.dataset.markerId);
      state.selectedMarkerId = id;
      const marker = state.tsMarkers.find(m => m.id === id);
      if (marker && state.videoElement) {
        state.videoElement.currentTime = marker.time;
        updateTimeMarker();
      }
      updateTimestampList();
      drawVolumeGraph();
    });
  });

  listEl.querySelectorAll('.vdg-ts-text-input').forEach(input => {
    input.addEventListener('input', (e) => {
      const id = parseInt(input.dataset.markerId);
      const marker = state.tsMarkers.find(m => m.id === id);
      if (marker) {
        // タイピングは1履歴にまとめる（marker.text更新前に積むので編集前の曲名が復元される）
        pushMarkerHistory(`text:${id}`);
        marker.text = e.target.value;
        saveMarkersToStorage();
      }
    });
    input.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      // 未編集状態のシングルクリックは選択のみ（ダブルクリックで入力状態にする）
      if (document.activeElement === input) return;
      e.preventDefault(); // フォーカス取得（入力状態化）を抑止
      // 別の入力欄を編集中だった場合は入力状態を解除する
      blurMarkerTextInput();
      const id = parseInt(input.dataset.markerId);
      state.selectedMarkerId = id;
      const marker = state.tsMarkers.find(m => m.id === id);
      if (marker && state.videoElement) {
        state.videoElement.currentTime = marker.time;
        updateTimeMarker();
      }
      // リストを再構築するとこの入力欄が破棄されてダブルクリック判定が壊れるため、
      // ハイライトのみ更新する
      updateTimestampListSelection();
      drawVolumeGraph();
    });
    input.addEventListener('dblclick', (e) => {
      e.preventDefault();
      input.focus({ preventScroll: true });
      const len = input.value.length;
      input.setSelectionRange(len, len);
      scrollSelectedRowIntoView();
    });
    input.addEventListener('paste', (e) => {
      const pasted = e.clipboardData?.getData('text/plain');
      if (!pasted) return;
      // 「アーティスト名 曲名 歌詞 ...」形式なら変換候補を表示（通常のテキストはそのままペースト）
      const candidates = buildLyricsSplitCandidates(pasted);
      if (!candidates) return;
      e.preventDefault();
      showLyricsPastePopup(input, candidates, pasted.trim());
    });
    input.addEventListener('focus', () => {
      const id = parseInt(input.dataset.markerId);
      if (state.selectedMarkerId === id) return;
      state.selectedMarkerId = id;
      // ここでリスト全体をinnerHTML再生成するとフォーカス中の入力欄が破棄されて
      // 曲名が入力できなくなるため、選択ハイライトのみ更新する
      updateTimestampListSelection();
      drawVolumeGraph();
    });
  });

  // 選択中の行・マーカーが表示範囲外ならスクロールして表示
  scrollSelectedRowIntoView();
  scrollGraphToSelectedMarker();
}

export function snapshotMarkers() {
  return {
    markers: state.tsMarkers.map(m => ({ ...m })),
    selectedId: state.selectedMarkerId,
    nextId: state.nextMarkerId,
  };
}

export function pushMarkerHistory(tag = null, snapshot = null) {
  const now = Date.now();
  if (tag !== null && tag === state.lastHistoryTag && now - state.lastHistoryTime < TS_HISTORY_COALESCE_MS) {
    state.lastHistoryTime = now;
    return;
  }
  state.lastHistoryTag = tag;
  state.lastHistoryTime = now;
  state.tsHistoryUndo.push(snapshot || snapshotMarkers());
  if (state.tsHistoryUndo.length > TS_HISTORY_LIMIT) state.tsHistoryUndo.shift();
  state.tsHistoryRedo = [];
  updateUndoRedoButtons();
}

export function undoMarkers() {
  if (state.tsHistoryUndo.length === 0) return;
  state.tsHistoryRedo.push(snapshotMarkers());
  restoreMarkerSnapshot(state.tsHistoryUndo.pop());
}

export function redoMarkers() {
  if (state.tsHistoryRedo.length === 0) return;
  state.tsHistoryUndo.push(snapshotMarkers());
  restoreMarkerSnapshot(state.tsHistoryRedo.pop());
}

export function restoreMarkerSnapshot(snapshot) {
  state.tsMarkers = snapshot.markers.map(m => ({ ...m }));
  state.selectedMarkerId = snapshot.selectedId;
  state.nextMarkerId = snapshot.nextId;
  // Undo/Redo直後の操作が履歴にまとめられないようにリセット
  state.lastHistoryTag = null;
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();
  updateUndoRedoButtons();
}

export function updateUndoRedoButtons() {
  const undoBtn = state.volumeGraphContainer?.querySelector('#vdg-ts-undo-btn');
  const redoBtn = state.volumeGraphContainer?.querySelector('#vdg-ts-redo-btn');
  if (undoBtn) undoBtn.disabled = state.tsHistoryUndo.length === 0;
  if (redoBtn) redoBtn.disabled = state.tsHistoryRedo.length === 0;
}

export function blurMarkerTextInput() {
  // キーボード操作で入力を抜ける場合はポップアップのmousedown経由の後始末が働かないため、
  // ここで明示的に閉じる（開いたまま残るとリスナーが生き続け、後続のクリックで誤挿入される）
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  const active = document.activeElement;
  if (active instanceof HTMLElement && active.classList.contains('vdg-ts-text-input')) {
    active.blur();
  }
}

export function updateTimestampListSelection() {
  const listEl = state.volumeGraphContainer?.querySelector('#vdg-ts-list');
  if (!listEl) return;
  listEl.querySelectorAll('.vdg-ts-row').forEach(row => {
    row.classList.toggle('selected', parseInt(row.dataset.markerId) === state.selectedMarkerId);
  });
  scrollSelectedRowIntoView();
  scrollGraphToSelectedMarker();
}

export function scrollGraphToSelectedMarker() {
  if (state.selectedMarkerId === null || !state.videoDuration) return;
  const container = state.volumeGraphContainer?.querySelector('#vdg-canvas-container');
  if (!container) return;

  const marker = state.tsMarkers.find(m => m.id === state.selectedMarkerId);
  if (!marker) return;

  const visibleWidth = container.clientWidth;
  const totalWidth = container.getBoundingClientRect().width * getZoomLevel();
  // ズームしていない（スクロール不要）場合は何もしない
  if (visibleWidth === 0 || totalWidth <= visibleWidth) return;

  const x = (marker.time / state.videoDuration) * totalWidth;
  const margin = Math.min(20, visibleWidth / 4);
  if (x >= container.scrollLeft + margin && x <= container.scrollLeft + visibleWidth - margin) return;

  // 表示範囲外なら中央に寄せる
  const target = x - visibleWidth / 2;
  container.scrollLeft = Math.max(0, Math.min(target, totalWidth - visibleWidth));
}

export function scrollSelectedRowIntoView() {
  const listEl = state.volumeGraphContainer?.querySelector('#vdg-ts-list');
  if (!listEl) return;
  const row = listEl.querySelector('.vdg-ts-row.selected');
  if (!row) return;

  const listRect = listEl.getBoundingClientRect();
  const rowRect = row.getBoundingClientRect();
  // グラフ非表示時はサイズが取れないため何もしない
  if (listRect.height === 0) return;

  if (rowRect.top < listRect.top) {
    listEl.scrollTop += rowRect.top - listRect.top;
  } else if (rowRect.bottom > listRect.bottom) {
    listEl.scrollTop += rowRect.bottom - listRect.bottom;
  }
}

export function deselectMarker() {
  if (state.selectedMarkerId === null) return;
  state.selectedMarkerId = null;
  updateTimestampListSelection();
  drawVolumeGraph();
}

export function deleteSelectedMarker() {
  if (state.selectedMarkerId === null) return;
  const marker = state.tsMarkers.find(m => m.id === state.selectedMarkerId);
  if (!marker) return;
  // 曲名入力済みのマーカーは誤削除防止のため確認を挟む
  const text = (marker.text || '').trim();
  if (text !== '' && !confirm(`「${text}」(${formatTimestamp(marker.time)}) を削除しますか？`)) return;
  pushMarkerHistory();
  state.tsMarkers = state.tsMarkers.filter(m => m.id !== state.selectedMarkerId);
  // 削除直後にDeleteの連打で意図しないマーカーが消えないよう、選択は解除する
  state.selectedMarkerId = null;
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();
}

export function moveSelectedMarker(deltaSec) {
  if (state.selectedMarkerId === null) return;
  const marker = state.tsMarkers.find(m => m.id === state.selectedMarkerId);
  if (!marker || !state.videoElement) return;

  // 連打での移動は1履歴にまとめる
  pushMarkerHistory(`move:${state.selectedMarkerId}`);
  marker.time = Math.max(0, Math.min(state.videoDuration, marker.time + deltaSec));
  state.tsMarkers.sort((a, b) => a.time - b.time);
  state.videoElement.currentTime = marker.time;
  updateTimeMarker();
  updateTimestampList();
  drawVolumeGraph();
  saveMarkersToStorage();
}
