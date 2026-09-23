import state from './state.js';
import { getVideoId } from './utils.js';
import { loadYcsApiSettings } from './api.js';
import {
  closeSongCandidatePopup,
  openSongCandidatePopup,
  getSongCandidateRequestSeq,
} from './song-candidates-shared.js';
import {
  pickPreferredCaptionTrack,
  getCaptionTracks,
  fetchSubtitleSegments,
  extractSubtitleWindow,
} from './caption-fetch.js';

export {
  isLyricsPastePopupOpen,
  isSongCandidatePopupOpen,
  buildLyricsSplitCandidates,
  closeLyricsPastePopup,
  showLyricsPastePopup,
  closeSongCandidatePopup,
  openSongCandidatePopup,
  cancelSongSuggest,
  onSongInputForSuggest,
} from './song-candidates-shared.js';

let subtitleCache = null;

async function getSubtitleTextForPosition(videoId, sec) {
  if (!subtitleCache || subtitleCache.videoId !== videoId) {
    const { tracks, direct } = await getCaptionTracks(videoId);
    if (!tracks || tracks.length === 0) {
      throw new Error('この動画には字幕がありません');
    }
    const track = pickPreferredCaptionTrack(tracks);
    const segments = await fetchSubtitleSegments(track, videoId, direct);
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
    seq = getSongCandidateRequestSeq();
  };
  const isStale = () => seq !== getSongCandidateRequestSeq();

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
          action: () => showSongCandidates(marker, lowerThreshold),
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
        action: () => showSongCandidates(marker, lowerThreshold),
      });
    }

    openSongCandidatePopup(input, items);
  } catch (error) {
    console.warn('[YCS] 曲名候補の取得エラー:', error.message);
    if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: 'エラー: ' + error.message }]);
  }
}

export async function ensureSubtitlesOnServer() {}

export function fetchSongCandidates() {
  return Promise.reject(new Error('authenticated API is not available in general edition'));
}
