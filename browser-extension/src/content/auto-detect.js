import state from './state.js';
import {
  SONG_DETECT_CONFIG,
  CHAT_SIGNAL_CONFIG,
  CLAP_PATTERN,
  AUTO_DETECT_SKIP_NEAR_MARKER_SEC,
  SAMPLING_INTERVAL_SEC,
} from './config.js';
import { getVideoId } from './utils.js';
import { initChatDB, loadChatDataForVideo, getChatContinuation, fetchAllChatReplays, saveChatsToDB } from './chat-search.js';
import { pushMarkerHistory, updateTimestampList } from './timestamp-editor.js';
import { drawVolumeGraph } from './volume-graph.js';
import { saveMarkersToStorage } from './timestamp-io.js';

let isAutoDetectRunning = false;
let tsEditorNoticeTimer = null;

export function formatTimestamp(seconds) {
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

export function movingAverageCentered(values, windowSize) {
  const half = Math.floor(windowSize / 2);
  const result = new Array(values.length);
  for (let i = 0; i < values.length; i++) {
    let sum = 0;
    let count = 0;
    for (let j = Math.max(0, i - half); j <= Math.min(values.length - 1, i + half); j++) {
      sum += values[j];
      count++;
    }
    result[i] = sum / count;
  }
  return result;
}

export function percentileOf(sortedValues, p) {
  if (sortedValues.length === 0) return 0;
  const index = Math.min(sortedValues.length - 1, Math.floor(sortedValues.length * p));
  return sortedValues[index];
}

export function buildLocalReference(values, intervalSec) {
  const cfg = SONG_DETECT_CONFIG;
  const globalRef = percentileOf(values.filter(v => v > 0).sort((a, b) => a - b), cfg.REF_PERCENTILE);
  const half = Math.max(1, Math.round(cfg.LOCAL_REF_WINDOW_SEC / intervalSec / 2));

  const ref = new Array(values.length);
  for (let i = 0; i < values.length; i++) {
    const from = Math.max(0, i - half);
    const to = Math.min(values.length - 1, i + half);
    const windowNonzero = [];
    for (let j = from; j <= to; j++) {
      if (values[j] > 0) windowNonzero.push(values[j]);
    }
    ref[i] = windowNonzero.length >= 5
      ? percentileOf(windowNonzero.sort((a, b) => a - b), cfg.LOCAL_REF_PERCENTILE)
      : globalRef;
  }
  return { ref, globalRef };
}

export function detectSongSegments(data, intervalSec) {
  if (!Array.isArray(data) || data.length === 0 || !intervalSec || intervalSec <= 0) return [];

  // 末尾の未スキャン領域（0埋め）を解析対象から外す
  let lastFilled = -1;
  for (let i = data.length - 1; i >= 0; i--) {
    if (data[i] > 0) {
      lastFilled = i;
      break;
    }
  }
  if (lastFilled < 0) return [];
  const values = data.slice(0, lastFilled + 1);

  const cfg = SONG_DETECT_CONFIG;
  const minSegmentSamples = Math.max(1, Math.round(cfg.MIN_SEGMENT_SEC / intervalSec));

  if (values.filter(v => v > 0).length < minSegmentSamples) return [];

  const { ref, globalRef } = buildLocalReference(values, intervalSec);
  const absFloor = globalRef * cfg.ABS_ACTIVE_FLOOR_RATIO;
  const active = values.map((v, i) => (v >= Math.max(ref[i] * cfg.ACTIVE_LEVEL_RATIO, absFloor) ? 1 : 0));
  const windowSamples = Math.max(1, Math.round(cfg.ACTIVITY_WINDOW_SEC / intervalSec));
  const activity = movingAverageCentered(active, windowSamples);

  const exitToleranceSamples = Math.max(1, Math.round(cfg.EXIT_TOLERANCE_SEC / intervalSec));
  const adjustMaxSamples = Math.max(1, Math.round(cfg.START_ADJUST_MAX_SEC / intervalSec));

  // 活動率の立ち上がりは実際の開始と数サンプルずれるため、activeの実データで開始位置を合わせる
  const refineStartIndex = (crossIndex) => {
    let best = active[crossIndex] ? crossIndex : -1;
    let activeCount = active[crossIndex] ? 1 : 0;
    let total = 1;
    for (let j = crossIndex - 1; j >= 0 && crossIndex - j <= adjustMaxSamples; j--) {
      total++;
      if (active[j]) {
        activeCount++;
        if (activeCount / total >= 0.5) best = j;
      }
    }
    if (best >= 0) return best;
    let forward = crossIndex;
    while (forward < active.length - 1 && forward - crossIndex < adjustMaxSamples && !active[forward]) forward++;
    return forward;
  };

  const segments = [];
  let inSegment = false;
  let segStart = 0;
  let segLastAbove = 0;
  let belowCount = 0;

  const flushSegment = () => {
    if (segLastAbove - segStart + 1 >= minSegmentSamples) {
      segments.push({
        start: Math.floor(refineStartIndex(segStart) * intervalSec),
        end: Math.ceil((segLastAbove + 1) * intervalSec),
      });
    }
  };

  for (let i = 0; i < activity.length; i++) {
    if (!inSegment) {
      if (activity[i] >= cfg.ENTER_ACTIVITY) {
        inSegment = true;
        segStart = i;
        segLastAbove = i;
        belowCount = 0;
      }
    } else if (activity[i] < cfg.EXIT_ACTIVITY) {
      belowCount++;
      if (belowCount >= exitToleranceSamples) {
        flushSegment();
        inSegment = false;
      }
    } else {
      belowCount = 0;
      segLastAbove = i;
    }
  }
  if (inSegment) flushSegment();

  return segments;
}

export function detectClapBursts(chats, videoDurationSec) {
  const cfg = CHAT_SIGNAL_CONFIG;
  const clapTimes = (chats || [])
    .filter(c => typeof c.message === 'string' && CLAP_PATTERN.test(c.message))
    .map(c => (Number(c.timestamp) || 0) / 1000 - cfg.CHAT_DELAY_SEC)
    .filter(t => t >= 0 && (!videoDurationSec || t <= videoDurationSec))
    .sort((a, b) => a - b);

  const bursts = [];
  let clusterStart = null;
  let clusterCount = 0;
  let lastTime = null;
  for (const t of clapTimes) {
    if (lastTime !== null && t - lastTime <= cfg.CLUSTER_GAP_SEC) {
      clusterCount++;
    } else {
      if (clusterCount >= cfg.MIN_CLAPS_PER_BURST) bursts.push(clusterStart);
      clusterStart = t;
      clusterCount = 1;
    }
    lastTime = t;
  }
  if (clusterCount >= cfg.MIN_CLAPS_PER_BURST) bursts.push(clusterStart);
  return bursts;
}

export function fuseSegmentsWithChat(segments, bursts) {
  const cfg = CHAT_SIGNAL_CONFIG;
  const chatActive = bursts.length >= cfg.MIN_BURSTS_TO_TRUST;

  let merged = segments.map(s => ({ ...s }));
  let mergedCount = 0;
  let splitCount = 0;

  if (chatActive) {
    const out = [];
    for (const seg of merged) {
      const prev = out[out.length - 1];
      if (
        prev &&
        seg.start - prev.end <= cfg.MERGE_MAX_GAP_SEC &&
        !bursts.some(b => b >= prev.end - 15 && b <= seg.start + 5)
      ) {
        prev.end = seg.end;
        mergedCount++;
      } else {
        out.push(seg);
      }
    }
    merged = out;
  }

  const starts = [];
  for (const seg of merged) {
    starts.push(seg.start);
    if (chatActive) {
      for (const b of bursts) {
        if (b >= seg.start + cfg.SPLIT_MIN_HEAD_SEC && b <= seg.end - cfg.SPLIT_MIN_TAIL_SEC) {
          starts.push(Math.floor(b + cfg.SPLIT_START_OFFSET_SEC));
          splitCount++;
        }
      }
    }
  }

  starts.sort((a, b) => a - b);
  const deduped = [];
  for (const t of starts) {
    if (deduped.length === 0 || t - deduped[deduped.length - 1] > cfg.DEDUPE_SEC) deduped.push(t);
  }

  return { starts: deduped, mergedCount, splitCount, chatActive };
}

export async function autoDetectSongStarts() {
  if (isAutoDetectRunning) return;

  // 数値以外の要素が混ざっていた場合の防御
  const numericData = state.volumeData.map(v => {
    if (typeof v === 'number' && Number.isFinite(v)) return v;
    return Number.isFinite(v?.value) ? v.value : 0;
  });

  if (!state.videoDuration || numericData.length === 0 || !numericData.some(v => v > 0)) {
    showTsEditorNotice('音量データがありません。先にスキャンを実行してください', true);
    return;
  }

  isAutoDetectRunning = true;
  try {
    const intervalSec = state.videoDuration / numericData.length;
    const segments = detectSongSegments(numericData, intervalSec);
    if (segments.length === 0) {
      showTsEditorNotice('楽曲らしい区間が見つかりませんでした', true);
      return;
    }

    let chats = [];
    let chatUnavailable = false;
    const videoId = getVideoId();
    if (videoId) {
      try {
        await initChatDB();
        chats = await loadChatDataForVideo(videoId);
      } catch (e) {
        console.warn('[YCS 自動検出] チャットDB読込失敗:', e);
      }
      if (chats.length === 0) {
        try {
          showTsEditorNotice('チャットを取得しています…');
          const continuation = await getChatContinuation();
          if (continuation) {
            const fetched = await fetchAllChatReplays(continuation, (count) => {
              showTsEditorNotice(`チャットを取得中... (${count}件)`);
            });
            if (fetched.length > 0) {
              await saveChatsToDB(videoId, fetched);
              chats = fetched;
            } else {
              chatUnavailable = true;
            }
          } else {
            chatUnavailable = true;
          }
        } catch (e) {
          console.warn('[YCS 自動検出] チャット取得失敗（音量のみで判定します）:', e);
          chatUnavailable = true;
        }
      }
    }
    // チャット取得中に別の動画へ遷移していた場合は破棄する
    if (videoId && getVideoId() !== videoId) return;

    const bursts = detectClapBursts(chats, state.videoDuration);
    const fused = fuseSegmentsWithChat(segments, bursts);

    const newTimes = fused.starts.filter(
      t => !state.tsMarkers.some(m => Math.abs(m.time - t) <= AUTO_DETECT_SKIP_NEAR_MARKER_SEC)
    );
    const skippedCount = fused.starts.length - newTimes.length;
    if (newTimes.length === 0) {
      showTsEditorNotice(`候補${fused.starts.length}件はすべて既存マーカー付近のためスキップしました`, true);
      return;
    }

    pushMarkerHistory();
    for (const time of newTimes) {
      state.tsMarkers.push({ id: state.nextMarkerId++, time, text: '' });
    }
    state.tsMarkers.sort((a, b) => a.time - b.time);
    updateTimestampList();
    drawVolumeGraph();
    saveMarkersToStorage();

    const sourceNote = fused.chatActive
      ? '音量+拍手チャット'
      : (chats.length > 0 ? '音量のみ（拍手が少ない配信）' : (chatUnavailable ? '音量のみ（チャットなし）' : '音量のみ'));
    const skippedNote = skippedCount > 0 ? `、既存マーカー付近の${skippedCount}件はスキップ` : '';
    showTsEditorNotice(`${newTimes.length}件の候補マーカーを追加しました（${sourceNote}${skippedNote}）`);
  } finally {
    isAutoDetectRunning = false;
  }
}

export function showTsEditorNotice(text, isWarning = false) {
  const noticeEl = state.volumeGraphContainer?.querySelector('#vdg-ts-notice');
  if (!noticeEl) return;
  noticeEl.textContent = text;
  noticeEl.classList.toggle('warning', isWarning);
  if (tsEditorNoticeTimer) clearTimeout(tsEditorNoticeTimer);
  tsEditorNoticeTimer = setTimeout(() => {
    noticeEl.textContent = '';
    tsEditorNoticeTimer = null;
  }, 6000);
}
