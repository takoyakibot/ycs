import state from './state.js';
import { getVideoId, escapeHtml } from './utils.js';
import { loadYcsApiSettings } from './api.js';
import {
  getCaptionTracksViaInnerTube,
  fetchTimedTextDirect,
  pickPreferredCaptionTrack,
  extractSubtitleWindow,
} from './caption-fetch.js';

let lyricsPastePopup = null;
let lyricsPastePopupCleanup = null;
let songCandidatePopup = null;
let songCandidatePopupCleanup = null;
let songCandidateRequestSeq = 0;

export function isLyricsPastePopupOpen() {
  return !!lyricsPastePopup;
}

export function buildLyricsSplitCandidates(text) {
  const tokens = text.trim().split(/\s+/);
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

  const values = [...candidates, rawText];
  const popup = document.createElement('div');
  popup.className = 'vdg-paste-popup';
  popup.innerHTML = `
    <div class="vdg-paste-popup-title">変換候補（クリックで挿入）</div>
    ${candidates.map((c, i) => `<div class="vdg-paste-popup-item" data-index="${i}">${escapeHtml(c)}</div>`).join('')}
    <div class="vdg-paste-popup-item raw" data-index="${candidates.length}">そのまま貼り付け</div>
  `;

  const listEl = state.volumeGraphContainer.querySelector('#vdg-ts-list');
  const reposition = () => {
    const containerRect = state.volumeGraphContainer.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const maxLeft = containerRect.width - popup.offsetWidth - 4;
    popup.style.left = `${Math.max(0, Math.min(inputRect.left - containerRect.left, maxLeft))}px`;
    popup.style.top = `${inputRect.bottom - containerRect.top + 2}px`;
  };

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

  const onKeydown = (e) => {
    if (e.key === 'Escape') {
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

function openSongCandidatePopup(input, items) {
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
      document.execCommand('insertText', false, value);
    }
  });

  const onKeydown = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
    }
    closeSongCandidatePopup();
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

let subtitleCache = null;

async function getSubtitleTextForPosition(videoId, sec) {
  if (!subtitleCache || subtitleCache.videoId !== videoId) {
    const tracks = await getCaptionTracksViaInnerTube(videoId);
    if (!tracks || tracks.length === 0) {
      throw new Error('この動画には字幕がありません');
    }
    const track = pickPreferredCaptionTrack(tracks);
    const segments = await fetchTimedTextDirect(track.baseUrl);
    if (!segments || segments.length === 0) {
      throw new Error('字幕を取得できませんでした');
    }
    subtitleCache = { videoId, segments };
  }
  return extractSubtitleWindow(subtitleCache.segments, sec);
}

async function fetchPublicSongCandidates(subtitleText, threshold = null) {
  if (!state.ycsServerUrl) {
    await loadYcsApiSettings();
  }
  const body = { subtitle_text: subtitleText };
  if (threshold !== null) {
    body.threshold = threshold;
  }
  const response = await fetch(`${state.ycsServerUrl}/api/public/subtitle-matches`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(body),
  });
  if (!response.ok) throw new Error(`候補の取得に失敗しました (${response.status})`);
  return response.json();
}

export async function showSongCandidates(marker, threshold = null) {
  const input = state.volumeGraphContainer?.querySelector(`.vdg-ts-text-input[data-marker-id="${marker.id}"]`);
  if (!input) return;

  let seq;
  const open = (items) => {
    openSongCandidatePopup(input, items);
    seq = songCandidateRequestSeq;
  };
  const isStale = () => seq !== songCandidateRequestSeq;

  open([{ type: 'message', label: '候補を検索しています…' }]);

  try {
    const videoId = getVideoId();
    if (!videoId) {
      if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: '動画IDを取得できませんでした' }]);
      return;
    }

    const sec = Math.floor(marker.time);
    let subtitleText;
    try {
      subtitleText = await getSubtitleTextForPosition(videoId, sec);
    } catch (e) {
      if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: e.message }]);
      return;
    }
    if (isStale()) return;

    if (!subtitleText || subtitleText.trim().length < 10) {
      openSongCandidatePopup(input, [{ type: 'message', label: 'この位置の字幕から候補を計算できませんでした（歌声の字幕が少ない可能性があります）' }]);
      return;
    }

    const result = await fetchPublicSongCandidates(subtitleText, threshold);
    if (isStale()) return;

    const candidates = (result.candidates || []).slice(0, 5);
    const currentThreshold = threshold || 0.15;

    if (candidates.length === 0) {
      if (currentThreshold > 0.05) {
        const lowerThreshold = Math.max(0.05, Math.round((currentThreshold - 0.05) * 100) / 100);
        openSongCandidatePopup(input, [{
          type: 'action',
          label: '候補が見つかりませんでした（閾値を下げて再検索）',
          action: () => retryWithLowerThreshold(marker, lowerThreshold),
        }]);
      } else {
        openSongCandidatePopup(input, [{ type: 'message', label: '候補が見つかりませんでした' }]);
      }
      return;
    }

    const items = candidates.map(c => {
      const title = c.song_title || c.text || '';
      return {
        type: 'candidate',
        label: title,
        artist: c.song_artist || '',
        insertValue: c.song_artist ? `${title} / ${c.song_artist}` : title,
        similarity: c.similarity,
      };
    });

    if (candidates.length < 3 && currentThreshold > 0.05) {
      const lowerThreshold = Math.max(0.05, Math.round((currentThreshold - 0.05) * 100) / 100);
      items.push({
        type: 'action',
        label: '閾値を下げてもっと検索',
        action: () => retryWithLowerThreshold(marker, lowerThreshold),
      });
    }

    openSongCandidatePopup(input, items);
  } catch (error) {
    console.warn('[YCS] 曲名候補の取得エラー:', error.message);
    if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: 'エラー: ' + error.message }]);
  }
}

export function retryWithLowerThreshold(marker, threshold) {
  showSongCandidates(marker, threshold);
}

export async function ensureSubtitlesOnServer() {}

export function fetchSongCandidates() {
  return Promise.reject(new Error('authenticated API is not available in general edition'));
}
