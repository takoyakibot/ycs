import state from './state.js';
import { escapeHtml } from './utils.js';
import { loadYcsApiSettings } from './api.js';
import { DEFAULT_YCS_SERVER_URL } from './config.js';

let lyricsPastePopup = null;
let lyricsPastePopupCleanup = null;
let songCandidatePopup = null;
let songCandidatePopupCleanup = null;
let songCandidateRequestSeq = 0;
let suggestDebounceTimer = null;
let suggestAbortController = null;
let popupSelectedIndex = -1;
// ポップアップからのinsertText直後にinputイベントでサジェストが再発火するのを防ぐ
let suggestInsertGuard = false;

export function getSongCandidateRequestSeq() { return songCandidateRequestSeq; }

function getSelectableItems(popup) {
  return popup.querySelectorAll('.vdg-paste-popup-item:not(.message)');
}

function updatePopupSelection(popup, index) {
  const items = getSelectableItems(popup);
  items.forEach(el => el.classList.remove('selected'));
  popupSelectedIndex = index;
  if (index >= 0 && index < items.length) {
    items[index].classList.add('selected');
    items[index].scrollIntoView({ block: 'nearest' });
  }
}

export function isLyricsPastePopupOpen() {
  return !!lyricsPastePopup;
}

export function isSongCandidatePopupOpen() {
  return !!songCandidatePopup;
}

export function buildLyricsSplitCandidates(text) {
  const tokens = text.trim().split(/\s+/);
  // 単独の「歌詞」トークンより前の部分を「アーティスト名+曲名」とみなす
  // （「歌詞検索」のような複合語は区切りとして扱わない）
  const idx = tokens.indexOf('歌詞');
  if (idx < 2) return null;
  const parts = tokens.slice(0, idx);
  const candidates = [];
  for (let k = parts.length - 1; k >= 1; k--) {
    const artist = parts.slice(0, k).join(' ');
    const title = parts.slice(k).join(' ');
    candidates.push(`${title} / ${artist}`);
  }
  return candidates;
}

export function closeLyricsPastePopup() {
  if (lyricsPastePopupCleanup) {
    lyricsPastePopupCleanup();
    lyricsPastePopupCleanup = null;
  }
  if (lyricsPastePopup) {
    lyricsPastePopup.remove();
    lyricsPastePopup = null;
  }
}

export function showLyricsPastePopup(input, candidates, rawText) {
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  if (!state.volumeGraphContainer) return;

  // 候補値は属性に埋め込まずインデックスで参照する（escapeHtmlは引用符をエスケープしないため）
  const values = [...candidates, rawText];
  const popup = document.createElement('div');
  popup.className = 'vdg-paste-popup';
  popup.innerHTML = `
    <div class="vdg-paste-popup-title">変換候補（クリックで挿入）</div>
    ${candidates.map((c, i) => `<div class="vdg-paste-popup-item" data-index="${i}">${escapeHtml(c)}</div>`).join('')}
    <div class="vdg-paste-popup-item raw" data-index="${candidates.length}">そのまま貼り付け</div>
  `;

  // 入力欄の直下に配置（グラフコンテナ基準の絶対配置）
  // 一覧のスクロールに追従し、コンテナ右端からはみ出さないようにクランプする
  const listEl = state.volumeGraphContainer.querySelector('#vdg-ts-list');
  const reposition = () => {
    const containerRect = state.volumeGraphContainer.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const maxLeft = containerRect.width - popup.offsetWidth - 4;
    popup.style.left = `${Math.max(0, Math.min(inputRect.left - containerRect.left, maxLeft))}px`;
    popup.style.top = `${inputRect.bottom - containerRect.top + 2}px`;
  };

  // execCommandならネイティブのinputイベント発火とUndo履歴が維持される
  const insertAndClose = (value) => {
    closeLyricsPastePopup();
    input.focus({ preventScroll: true });
    document.execCommand('insertText', false, value);
  };

  popup.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const item = e.target.closest('.vdg-paste-popup-item');
    if (item) {
      insertAndClose(values[parseInt(item.dataset.index)]);
    }
  });

  popupSelectedIndex = -1;
  const onKeydown = (e) => {
    const items = getSelectableItems(popup);
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      e.stopPropagation();
      updatePopupSelection(popup, popupSelectedIndex < items.length - 1 ? popupSelectedIndex + 1 : 0);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      e.stopPropagation();
      updatePopupSelection(popup, popupSelectedIndex > 0 ? popupSelectedIndex - 1 : items.length - 1);
    } else if (e.key === 'Enter' && popupSelectedIndex >= 0 && popupSelectedIndex < items.length) {
      e.preventDefault();
      e.stopPropagation();
      const idx = parseInt(items[popupSelectedIndex].dataset.index);
      insertAndClose(values[idx]);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      insertAndClose(rawText);
    } else {
      closeLyricsPastePopup();
    }
  };
  const onOutsideMousedown = (e) => {
    if (popup.contains(e.target) || e.target === input) return;
    insertAndClose(rawText);
  };

  input.addEventListener('keydown', onKeydown, true);
  document.addEventListener('mousedown', onOutsideMousedown, true);
  listEl?.addEventListener('scroll', reposition);
  lyricsPastePopupCleanup = () => {
    input.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('mousedown', onOutsideMousedown, true);
    listEl?.removeEventListener('scroll', reposition);
  };

  lyricsPastePopup = popup;
  state.volumeGraphContainer.appendChild(popup);
  reposition();
}

export function closeSongCandidatePopup() {
  songCandidateRequestSeq++;
  if (songCandidatePopupCleanup) {
    songCandidatePopupCleanup();
    songCandidatePopupCleanup = null;
  }
  if (songCandidatePopup) {
    songCandidatePopup.remove();
    songCandidatePopup = null;
  }
}

export function openSongCandidatePopup(input, items) {
  closeSongCandidatePopup();
  closeLyricsPastePopup();
  if (!state.volumeGraphContainer) return;

  const popup = document.createElement('div');
  popup.className = 'vdg-paste-popup';
  popup.innerHTML = `
    <div class="vdg-paste-popup-title">曲名候補（クリックで挿入）</div>
    ${items.map((item, i) => item.type === 'candidate' ? `
      <div class="vdg-paste-popup-item" data-index="${i}">${escapeHtml(item.label)}${item.artist ? `<span class="artist">${escapeHtml(item.artist)}</span>` : ''}<span class="similarity">${Math.round((item.similarity || 0) * 100)}%</span></div>
    ` : item.type === 'action' ? `
      <div class="vdg-paste-popup-item action" data-action-index="${i}">${escapeHtml(item.label)}</div>
    ` : `
      <div class="vdg-paste-popup-item message">${escapeHtml(item.label)}</div>
    `).join('')}
  `;

  const listEl = state.volumeGraphContainer.querySelector('#vdg-ts-list');
  const reposition = () => {
    const containerRect = state.volumeGraphContainer.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const maxLeft = containerRect.width - popup.offsetWidth - 4;
    popup.style.left = `${Math.max(0, Math.min(inputRect.left - containerRect.left, maxLeft))}px`;
    popup.style.top = `${inputRect.bottom - containerRect.top + 2}px`;
  };

  // 候補クリック: 入力欄の内容を候補で置き換える
  popup.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const el = e.target.closest('.vdg-paste-popup-item');
    if (!el) return;
    if (el.dataset.actionIndex !== undefined) {
      const selected = items[parseInt(el.dataset.actionIndex)];
      if (selected?.action) {
        closeSongCandidatePopup();
        selected.action();
      }
      return;
    }
    if (el.dataset.index !== undefined) {
      const selected = items[parseInt(el.dataset.index)];
      const value = selected?.insertValue ?? selected?.label ?? '';
      closeSongCandidatePopup();
      input.focus({ preventScroll: true });
      input.select();
      suggestInsertGuard = true;
      document.execCommand('insertText', false, value);
      suggestInsertGuard = false;
    }
  });

  popupSelectedIndex = -1;
  const onKeydown = (e) => {
    const selectables = getSelectableItems(popup);
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      e.stopPropagation();
      updatePopupSelection(popup, popupSelectedIndex < selectables.length - 1 ? popupSelectedIndex + 1 : 0);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      e.stopPropagation();
      updatePopupSelection(popup, popupSelectedIndex > 0 ? popupSelectedIndex - 1 : selectables.length - 1);
    } else if (e.key === 'Enter' && popupSelectedIndex >= 0 && popupSelectedIndex < selectables.length) {
      e.preventDefault();
      e.stopPropagation();
      const el = selectables[popupSelectedIndex];
      if (el.dataset.actionIndex !== undefined) {
        const selected = items[parseInt(el.dataset.actionIndex)];
        if (selected?.action) {
          closeSongCandidatePopup();
          selected.action();
        }
      } else if (el.dataset.index !== undefined) {
        const selected = items[parseInt(el.dataset.index)];
        const value = selected?.insertValue ?? selected?.label ?? '';
        closeSongCandidatePopup();
        input.focus({ preventScroll: true });
        input.select();
        suggestInsertGuard = true;
        document.execCommand('insertText', false, value);
        suggestInsertGuard = false;
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      closeSongCandidatePopup();
    } else {
      closeSongCandidatePopup();
    }
  };

  const onOutsideMousedown = (e) => {
    if (popup.contains(e.target) || e.target === input) return;
    closeSongCandidatePopup();
  };

  input.addEventListener('keydown', onKeydown, true);
  document.addEventListener('mousedown', onOutsideMousedown, true);
  listEl?.addEventListener('scroll', reposition);
  songCandidatePopupCleanup = () => {
    input.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('mousedown', onOutsideMousedown, true);
    listEl?.removeEventListener('scroll', reposition);
  };

  songCandidatePopup = popup;
  state.volumeGraphContainer.appendChild(popup);
  reposition();
}

export function cancelSongSuggest() {
  if (suggestDebounceTimer) {
    clearTimeout(suggestDebounceTimer);
    suggestDebounceTimer = null;
  }
  if (suggestAbortController) {
    suggestAbortController.abort();
    suggestAbortController = null;
  }
}

export function onSongInputForSuggest(input) {
  if (suggestInsertGuard) return;

  cancelSongSuggest();

  if (songCandidatePopup) return;

  const query = input.value.trim();
  if (query.length < 2) return;

  suggestDebounceTimer = setTimeout(async () => {
    suggestDebounceTimer = null;
    if (songCandidatePopup) return;
    if (!state.ycsServerUrl) {
      await loadYcsApiSettings();
    }
    fetchAndShowSuggestions(input, query);
  }, 300);
}

async function fetchAndShowSuggestions(input, query) {
  suggestAbortController = new AbortController();
  const seq = songCandidateRequestSeq;

  try {
    const serverUrl = state.ycsServerUrl || DEFAULT_YCS_SERVER_URL;
    const url = `${serverUrl}/api/public/song-suggest?q=${encodeURIComponent(query)}`;
    const headers = { 'Accept': 'application/json' };
    if (state.ycsApiToken) {
      headers['Authorization'] = `Bearer ${state.ycsApiToken}`;
    }
    const response = await fetch(url, {
      headers,
      signal: suggestAbortController.signal,
    });

    if (seq !== songCandidateRequestSeq) return;
    if (!response.ok) return;

    const data = await response.json();
    if (seq !== songCandidateRequestSeq) return;
    if (!document.activeElement || document.activeElement !== input) return;

    const suggestions = data.suggestions || [];
    if (suggestions.length === 0) return;

    openSongSuggestPopup(input, suggestions);
  } catch (e) {
    if (e.name !== 'AbortError') {
      console.warn('[YCS] サジェスト取得エラー:', e.message);
    }
  } finally {
    suggestAbortController = null;
  }
}

function openSongSuggestPopup(input, suggestions) {
  closeSongCandidatePopup();
  closeLyricsPastePopup();
  if (!state.volumeGraphContainer) return;

  const popup = document.createElement('div');
  popup.className = 'vdg-paste-popup';
  popup.innerHTML = `
    <div class="vdg-paste-popup-title">サジェスト</div>
    ${suggestions.map((s, i) => `
      <div class="vdg-paste-popup-item" data-index="${i}">${escapeHtml(s.text)}${s.ts_count ? `<span class="similarity">${s.ts_count}件</span>` : ''}</div>
    `).join('')}
  `;

  const listEl = state.volumeGraphContainer.querySelector('#vdg-ts-list');
  const reposition = () => {
    const containerRect = state.volumeGraphContainer.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const maxLeft = containerRect.width - popup.offsetWidth - 4;
    popup.style.left = `${Math.max(0, Math.min(inputRect.left - containerRect.left, maxLeft))}px`;
    popup.style.top = `${inputRect.bottom - containerRect.top + 2}px`;
  };

  popup.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const el = e.target.closest('.vdg-paste-popup-item');
    if (!el || el.dataset.index === undefined) return;
    const selected = suggestions[parseInt(el.dataset.index)];
    if (!selected) return;
    closeSongCandidatePopup();
    input.focus({ preventScroll: true });
    input.select();
    suggestInsertGuard = true;
    document.execCommand('insertText', false, selected.text);
    suggestInsertGuard = false;
  });

  popupSelectedIndex = -1;
  const onKeydown = (e) => {
    const selectables = getSelectableItems(popup);
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      e.stopPropagation();
      updatePopupSelection(popup, popupSelectedIndex < selectables.length - 1 ? popupSelectedIndex + 1 : 0);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      e.stopPropagation();
      updatePopupSelection(popup, popupSelectedIndex > 0 ? popupSelectedIndex - 1 : selectables.length - 1);
    } else if (e.key === 'Enter' && popupSelectedIndex >= 0 && popupSelectedIndex < selectables.length) {
      e.preventDefault();
      e.stopPropagation();
      const idx = parseInt(selectables[popupSelectedIndex].dataset.index);
      const selected = suggestions[idx];
      if (selected) {
        closeSongCandidatePopup();
        input.focus({ preventScroll: true });
        input.select();
        suggestInsertGuard = true;
        document.execCommand('insertText', false, selected.text);
        suggestInsertGuard = false;
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      closeSongCandidatePopup();
    } else {
      closeSongCandidatePopup();
    }
  };

  const onOutsideMousedown = (e) => {
    if (popup.contains(e.target) || e.target === input) return;
    closeSongCandidatePopup();
  };

  input.addEventListener('keydown', onKeydown, true);
  document.addEventListener('mousedown', onOutsideMousedown, true);
  listEl?.addEventListener('scroll', reposition);
  songCandidatePopupCleanup = () => {
    input.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('mousedown', onOutsideMousedown, true);
    listEl?.removeEventListener('scroll', reposition);
  };

  songCandidatePopup = popup;
  state.volumeGraphContainer.appendChild(popup);
  reposition();
}

