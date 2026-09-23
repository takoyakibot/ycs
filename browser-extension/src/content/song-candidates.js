import state from './state.js';
import { reportSubtitlesUnavailable } from './subtitle-scan.js';
import { getVideoId } from './utils.js';
import { loadYcsApiSettings, postSubtitlesToServer, missingTokenMessage } from './api.js';
import {
  pickPreferredCaptionTrack,
  getCaptionTracks,
  fetchSubtitleSegments,
} from './caption-fetch.js';
import {
  closeSongCandidatePopup,
  openSongCandidatePopup,
  getSongCandidateRequestSeq,
} from './song-candidates-shared.js';

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

// pickPreferredCaptionTrackをcaption-fetch.jsから再エクスポート
// （subtitle-scan.jsなどが song-candidates.js 経由で使っているため）
export { pickPreferredCaptionTrack } from './caption-fetch.js';

let subtitlePrepareFlow = null;

export async function showSongCandidates(marker, threshold = null) {
  const input = state.volumeGraphContainer?.querySelector(`.vdg-ts-text-input[data-marker-id="${marker.id}"]`);
  if (!input) return;

  // openSongCandidatePopupは開き直しのたびに内部で世代を進めるため、
  // 自分で開いた直後の世代を控えて「外部から閉じられた/開き直された」を検出する
  let seq;
  const open = (items) => {
    openSongCandidatePopup(input, items);
    seq = getSongCandidateRequestSeq();
  };
  const isStale = () => seq !== getSongCandidateRequestSeq();

  open([{ type: 'message', label: '候補を検索しています…' }]);

  try {
    if (!state.ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!state.ycsApiToken) {
      if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: missingTokenMessage() }]);
      return;
    }

    const videoId = getVideoId();
    if (!videoId) {
      if (!isStale()) openSongCandidatePopup(input, [{ type: 'message', label: '動画IDを取得できませんでした' }]);
      return;
    }

    const sec = Math.floor(marker.time);
    let result = await fetchSongCandidates(videoId, sec, threshold);
    if (isStale()) return;

    if (result.has_subtitles === false) {
      open([{ type: 'message', label: '字幕を取得しています…' }]);
      await ensureSubtitlesOnServer(videoId);
      if (isStale()) return;
      result = await fetchSongCandidates(videoId, sec, threshold);
      if (isStale()) return;
    }

    if (result.has_fingerprint === false) {
      openSongCandidatePopup(input, [{ type: 'message', label: 'この位置の字幕から候補を計算できませんでした（歌声の字幕が少ない可能性があります）' }]);
      return;
    }

    const candidates = (result.candidates || []).slice(0, 5);
    const currentThreshold = result.threshold || 0.15;

    if (candidates.length === 0) {
      if (currentThreshold > 0.05) {
        const lowerThreshold = Math.max(0.05, Math.round((currentThreshold - 0.05) * 100) / 100);
        openSongCandidatePopup(input, [{
          type: 'action',
          label: `候補が見つかりませんでした（閾値を下げて再検索）`,
          action: () => showSongCandidates(marker, lowerThreshold),
        }]);
      } else {
        openSongCandidatePopup(input, [{ type: 'message', label: '候補が見つかりませんでした' }]);
      }
      return;
    }

    const items = candidates.map(c => {
      // マスタ未登録の候補は元の表記（text）を優先する
      const title = c.song_title || c.text || c.normalized_text || '';
      return {
        type: 'candidate',
        label: title,
        artist: c.song_artist || '',
        // 挿入値はタイムスタンプの表記慣習（「曲名 / アーティスト」）に合わせる
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

export async function fetchSongCandidates(videoId, sec, threshold = null) {
  let url = `${state.ycsServerUrl}/api/extension/subtitle-matches?video_id=${encodeURIComponent(videoId)}&sec=${sec}`;
  if (threshold !== null) {
    url += `&threshold=${threshold}`;
  }
  const response = await fetch(url, {
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${state.ycsApiToken}`,
    },
  });

  if (response.status === 401) throw new Error('APIトークンが無効です');
  if (response.status === 403) throw new Error('このチャンネルへのアクセス権限がありません');
  if (response.status === 404) throw new Error('この動画はアーカイブに登録されていません');
  if (!response.ok) throw new Error(`候補の取得に失敗しました (${response.status})`);

  return response.json();
}

export function ensureSubtitlesOnServer(videoId) {
  if (subtitlePrepareFlow && subtitlePrepareFlow.videoId === videoId) {
    return subtitlePrepareFlow.promise;
  }

  const promise = (async () => {
    const { tracks, direct } = await getCaptionTracks(videoId);
    if (!tracks || tracks.length === 0) {
      reportSubtitlesUnavailable(videoId);
      throw new Error('この動画には字幕がありません');
    }
    const track = pickPreferredCaptionTrack(tracks);
    const segments = await fetchSubtitleSegments(track, videoId, direct);
    if (!segments || segments.length === 0) {
      throw new Error('字幕を取得できませんでした');
    }
    await postSubtitlesToServer(videoId, track.languageCode, track.kind === 'asr' ? 'asr' : '', segments);
  })().finally(() => {
    if (subtitlePrepareFlow?.videoId === videoId) {
      subtitlePrepareFlow = null;
    }
  });

  subtitlePrepareFlow = { videoId, promise };
  return promise;
}
