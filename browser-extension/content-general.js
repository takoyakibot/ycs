(function () {
  'use strict';

  const state = {
    // Video element & time sync
    videoElement: null,
    timeUpdateInterval: null,
    videoDuration: 0,
    videoListenerTarget: null,

    // Volume graph
    volumeGraphContainer: null,
    volumeCanvas: null,
    volumeCtx: null,
    volumeData: [],
    spectralData: [],
    isGraphVisible: false,
    zoomIndex: 0,
    lastSaveTime: 0,
    isRelativeVolumeMode: false,
    graphBaseHeightPx: 60,
    graphHeightStepPx: 20,

    // Audio analysis
    audioContext: null,
    analyserNode: null,
    gainNode: null,
    mediaElementSource: null,
    isScanning: false,
    backgroundScanVideoId: null,
    scanInterval: null,
    originalPlaybackRate: 1,
    audioInitialized: false,

    // Auto-scan (playlist)
    isAutoScanMode: false,
    autoScanStopRequested: false,

    // List scan
    isListScanMode: false,
    listScanProceeding: false,
    listScanPanel: null,
    listScanPanelVisible: false,

    // Detected timestamps
    detectedTimestamps: [],

    // Timestamp editor
    tsMarkers: [],
    selectedMarkerId: null,
    nextMarkerId: 1,
    tsHistoryUndo: [],
    tsHistoryRedo: [],
    lastHistoryTag: null,
    lastHistoryTime: 0,
    tsZeroPad: false,
    tsEditorMode: 'marker',

    // Embedded UI
    embeddedUIVisible: true,
    embeddedTriggerButton: null,

    // Subtitle
    currentSubtitles: [],
    currentCaptionTracks: [],
    pageBridgeReady: null,

    // API
    ycsApiToken: null,
    ycsServerUrl: null,
  };

  const SAMPLING_INTERVAL_SEC = 2;
  const LEGACY_GRAPH_RESOLUTION = 500;

  const MARKER_SNAP_THRESHOLD_SEC = 3;
  const MARKER_SNAP_THRESHOLD_PX = 8;

  const ZOOM_LEVELS = [1, 1.5, 2, 3, 4, 5, 6, 7, 8];
  const DEFAULT_GRAPH_BASE_HEIGHT_PX = 60;
  const DEFAULT_GRAPH_HEIGHT_STEP_PX = 20;
  const GRAPH_BASE_HEIGHT_RANGE = { min: 40, max: 400 };
  const GRAPH_HEIGHT_STEP_RANGE = { min: 0, max: 100 };
  const SAVE_INTERVAL = 3000;

  const TS_HISTORY_LIMIT = 50;
  const TS_HISTORY_COALESCE_MS = 1500;

  const AUTO_DETECT_SKIP_NEAR_MARKER_SEC = 60;

  const SONG_DETECT_CONFIG = {
    REF_PERCENTILE: 0.99,
    LOCAL_REF_WINDOW_SEC: 300,
    LOCAL_REF_PERCENTILE: 0.90,
    ACTIVE_LEVEL_RATIO: 0.45,
    ABS_ACTIVE_FLOOR_RATIO: 0.35,
    ACTIVITY_WINDOW_SEC: 30,
    ENTER_ACTIVITY: 0.4,
    EXIT_ACTIVITY: 0.2,
    EXIT_TOLERANCE_SEC: 20,
    MIN_SEGMENT_SEC: 60,
    START_ADJUST_MAX_SEC: 20,
  };

  const CHAT_SIGNAL_CONFIG = {
    CHAT_DELAY_SEC: 5,
    CLUSTER_GAP_SEC: 20,
    MIN_CLAPS_PER_BURST: 3,
    MIN_BURSTS_TO_TRUST: 3,
    MERGE_MAX_GAP_SEC: 60,
    SPLIT_MIN_HEAD_SEC: 45,
    SPLIT_MIN_TAIL_SEC: 30,
    SPLIT_START_OFFSET_SEC: 5,
    DEDUPE_SEC: 30,
  };

  const CLAP_PATTERN = /8{3,}|８{3,}|👏|拍手|ぱちぱち|パチパチ|clap/i;

  const EMOJI_ONLY_RE = /^[\p{Extended_Pictographic}\p{Emoji_Presentation}\p{So}\s‍️︎]+$/u;

  const CHAT_ONLY_CONFIG = {
    BUCKET_SEC: 10,
    MIN_CHATS: 50,
    SMOOTH_WINDOW_BUCKETS: 5,
    EMOJI_RATIO_ENTER: 0.4,
    EMOJI_RATIO_EXIT: 0.15,
    EXIT_TOLERANCE_BUCKETS: 3,
    MIN_SEGMENT_SEC: 45,
    MERGE_GAP_SEC: 90,
    MIN_BUCKET_MESSAGES: 2,
  };

  const DEFAULT_YCS_SERVER_URL = 'https://ycs.alpacasandbag.jp';

  function calcGraphResolution(duration) {
    // ライブ配信中はdurationがInfinityになり、Array確保で例外になるためガードする（#607）
    if (!duration || duration <= 0 || !isFinite(duration)) return LEGACY_GRAPH_RESOLUTION;
    return Math.ceil(duration / SAMPLING_INTERVAL_SEC);
  }

  function clampGraphHeightValue(value, defaultValue, range) {
    if (value === null || value === undefined || value === '') return defaultValue;
    const num = Number(value);
    if (!Number.isFinite(num)) return defaultValue;
    return Math.max(range.min, Math.min(range.max, Math.round(num)));
  }

  async function loadGraphHeightSettings() {
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

  async function loadEmbeddedUISettings() {
    try {
      const result = await chrome.storage.local.get('showEmbeddedUI');
      state.embeddedUIVisible = result.showEmbeddedUI !== false;
      return state.embeddedUIVisible;
    } catch (error) {
      console.error('埋め込みUI設定読み込みエラー:', error);
      return true;
    }
  }

  function getVideoId() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('v');
  }

  function getPlaylistId() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('list');
  }

  function isInPlaylist() {
    return !!getPlaylistId();
  }

  function getPlaylistInfo() {
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

  function goToNextVideo() {
    const nextButton = document.querySelector('.ytp-next-button');
    if (nextButton) {
      nextButton.click();
      return true;
    }
    return false;
  }

  function isSavedVolumeDataStale(saved) {
    const actual = state.videoElement?.duration;
    if (!saved?.duration || !actual || !isFinite(actual)) return false;

    return Math.abs(saved.duration - actual) / actual > 0.05;
  }

  async function isCurrentVideoScanned() {
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

  function getScanStatus() {
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

  function isWatchPage() {
    return location.pathname === '/watch';
  }

  function findVideoElement() {
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

  function startTimeSync() {
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

  function updateTimeMarker() {
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

  function formatTimeDisplay(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);

    if (h > 0) {
      return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m}:${s.toString().padStart(2, '0')}`;
  }

  function updateProgress(percent) {
    if (!state.volumeGraphContainer) return;
    const progress = state.volumeGraphContainer.querySelector('#vdg-progress');
    if (progress) {
      progress.textContent = `分析 ${Math.round(percent)}%`;
    }
  }

  function isAdShowing() {
    return !!document.querySelector('.html5-video-player.ad-showing');
  }

  function updateVideoDuration() {
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

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function isChatSearchPanelVisible() { return false; }
  async function initChatDB() {}
  async function loadChatDataForVideo() { return []; }
  async function getChatContinuation() { return null; }
  async function fetchAllChatReplays() { return []; }
  async function saveChatsToDB() {}

  function isSubtitlePanelVisible() { return false; }

  function isHighlightPanelVisible() { return false; }

  async function loadYcsApiSettings() {
    try {
      const result = await chrome.storage.local.get(['ycsApiToken', 'ycsServerUrl']);
      state.ycsApiToken = result.ycsApiToken || null;
      // 末尾のスラッシュは除去する（APIパス連結時に「//」になるのを防ぐ）
      state.ycsServerUrl = (result.ycsServerUrl || DEFAULT_YCS_SERVER_URL).replace(/\/+$/, '');
    } catch (error) {
      console.warn('[YCS] API設定読み込みエラー:', error);
    }
  }

  const INNERTUBE_API_KEY = 'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8';

  function parseJson3(data) {
    const segments = [];
    for (const ev of (data.events || [])) {
      if (!ev.segs) continue;
      const t = ev.segs.map(s => s.utf8 || '').join('');
      if (!t.trim()) continue;
      segments.push({
        start: (ev.tStartMs || 0) / 1000,
        duration: (ev.dDurationMs || 0) / 1000,
        text: t,
      });
    }
    return segments;
  }

  function parseXml(text) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(text, 'text/xml');
    const textEls = doc.querySelectorAll('text');
    const segments = [];
    for (const el of textEls) {
      const content = el.textContent || '';
      if (!content.trim()) continue;
      segments.push({
        start: parseFloat(el.getAttribute('start') || '0'),
        duration: parseFloat(el.getAttribute('dur') || '0'),
        text: content,
      });
    }
    return segments;
  }

  function pickPreferredCaptionTrack(tracks) {
    const ja = tracks.filter(t => (t.languageCode || '').startsWith('ja'));
    return ja.find(t => t.kind !== 'asr') || ja[0] || tracks[0];
  }

  async function getCaptionTracksViaInnerTube(videoId) {
    const response = await fetch(`https://www.youtube.com/youtubei/v1/player?key=${INNERTUBE_API_KEY}&prettyPrint=false`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        context: {
          client: {
            clientName: 'WEB',
            clientVersion: '2.20250911.01.00',
            hl: document.documentElement.lang || 'ja',
          },
        },
        videoId: videoId,
      }),
    });
    if (!response.ok) throw new Error(`InnerTube API error: ${response.status}`);
    const data = await response.json();
    const captionTracks = data.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
    return captionTracks.map(track => ({
      languageCode: track.languageCode || '',
      name: track.name?.simpleText || '',
      kind: track.kind || '',
      baseUrl: track.baseUrl || '',
    }));
  }

  async function fetchTimedTextDirect(baseUrl) {
    const url = new URL(baseUrl);
    url.searchParams.set('fmt', 'json3');
    const response = await fetch(url.toString());
    if (!response.ok) throw new Error(`timedtext fetch error: ${response.status}`);
    const text = await response.text();
    if (text.trim().startsWith('{')) {
      return parseJson3(JSON.parse(text));
    }
    return parseXml(text);
  }

  function extractSubtitleWindow(segments, sec, windowSec = 60) {
    const halfWindow = windowSec / 2;
    const start = sec - halfWindow;
    const end = sec + halfWindow;
    return segments
      .filter(s => s.start >= start && s.start < end)
      .map(s => s.text)
      .join(' ');
  }

  let lyricsPastePopup = null;
  let lyricsPastePopupCleanup = null;
  let songCandidatePopup = null;
  let songCandidatePopupCleanup = null;
  let songCandidateRequestSeq = 0;

  function isLyricsPastePopupOpen() {
    return !!lyricsPastePopup;
  }

  function buildLyricsSplitCandidates(text) {
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

  function closeLyricsPastePopup() {
    if (lyricsPastePopupCleanup) {
      lyricsPastePopupCleanup();
      lyricsPastePopupCleanup = null;
    }
    if (lyricsPastePopup) {
      lyricsPastePopup.remove();
      lyricsPastePopup = null;
    }
  }

  function showLyricsPastePopup(input, candidates, rawText) {
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

  function closeSongCandidatePopup() {
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

  async function showSongCandidates(marker, threshold = null) {
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

  function retryWithLowerThreshold(marker, threshold) {
    showSongCandidates(marker, threshold);
  }

  async function ensureSubtitlesOnServer() {}

  function computeSpectralFeatures(freqData, sampleRate) {
    const binCount = freqData.length;
    const binWidth = sampleRate / (binCount * 2);

    // dB→リニアパワーに変換（無音ビンは極小値にクランプ）
    const minDb = -100;
    const powers = new Float32Array(binCount);
    for (let i = 0; i < binCount; i++) {
      const db = Math.max(freqData[i], minDb);
      powers[i] = Math.pow(10, db / 10);
    }

    let totalEnergy = 0;
    for (let i = 0; i < binCount; i++) {
      totalEnergy += powers[i];
    }
    if (totalEnergy === 0) return null;

    // 歌声帯域 (80Hz - 1100Hz) のエネルギー比率
    const voiceLowBin = Math.ceil(80 / binWidth);
    const voiceHighBin = Math.min(Math.floor(1100 / binWidth), binCount - 1);
    let voiceEnergy = 0;
    for (let i = voiceLowBin; i <= voiceHighBin; i++) {
      voiceEnergy += powers[i];
    }
    const voiceBandRatio = voiceEnergy / totalEnergy;

    // Spectral flatness（幾何平均 / 算術平均）
    // 対数空間で計算して数値アンダーフロー防止
    let logSum = 0;
    let linearSum = 0;
    let count = 0;
    for (let i = voiceLowBin; i <= voiceHighBin; i++) {
      if (powers[i] > 0) {
        logSum += Math.log(powers[i]);
        linearSum += powers[i];
        count++;
      }
    }
    let flatness = 1;
    if (count > 0 && linearSum > 0) {
      const geometricMean = Math.exp(logSum / count);
      const arithmeticMean = linearSum / count;
      flatness = geometricMean / arithmeticMean;
    }

    return {
      flatness: Math.round(flatness * 1000) / 1000,
      voiceBandRatio: Math.round(voiceBandRatio * 1000) / 1000,
    };
  }

  function initAudioAnalysis() {
    if (!state.videoElement) {
      console.error('Video要素が見つかりません');
      return false;
    }

    if (state.audioInitialized && state.mediaElementSource) {
      return true;
    }

    try {
      if (!state.audioContext) {
        state.audioContext = new (window.AudioContext || window.webkitAudioContext)();
      }

      // MediaElementSourceは一度だけ作成可能
      if (!state.mediaElementSource) {
        state.mediaElementSource = state.audioContext.createMediaElementSource(state.videoElement);

        state.analyserNode = state.audioContext.createAnalyser();
        state.analyserNode.fftSize = 2048;
        state.analyserNode.smoothingTimeConstant = 0.8;

        state.gainNode = state.audioContext.createGain();
        state.gainNode.gain.value = 1;

        // Video → Analyser → Gain → 出力
        state.mediaElementSource.connect(state.analyserNode);
        state.analyserNode.connect(state.gainNode);
        state.gainNode.connect(state.audioContext.destination);

        state.audioInitialized = true;
        console.log('音声解析を初期化しました（Video要素から直接解析）');
      }

      return true;
    } catch (error) {
      console.error('音声解析の初期化に失敗:', error);
      return false;
    }
  }

  function setAudioGain(muted) {
    if (state.gainNode) {
      state.gainNode.gain.value = muted ? 0 : 1;
      console.log('音声ゲイン設定:', muted ? 'ミュート' : '音声ON');
    }
  }

  async function startDirectScan() {
    if (state.isScanning) {
      stopDirectScan();
      return false;
    }

    if (!state.videoElement) {
      console.error('Video要素が見つかりません');
      return false;
    }

    if (!initAudioAnalysis()) {
      console.error('音声解析の初期化に失敗しました');
      return false;
    }

    if (state.audioContext.state === 'suspended') {
      await state.audioContext.resume();
    }

    // 広告再生中は実動画のdurationが取れないため、終了を待つ（最大120秒）
    for (let i = 0; isAdShowing() && i < 120; i++) {
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
    if (isAdShowing()) {
      console.error('広告が終了しないためスキャンを開始できません');
      return false;
    }

    updateVideoDuration();
    if (!state.videoDuration || !isFinite(state.videoDuration)) {
      console.error('動画の長さを取得できません');
      return false;
    }

    state.isScanning = true;
    const resolution = calcGraphResolution(state.videoDuration);
    state.volumeData = new Array(resolution).fill(0);
    state.spectralData = new Array(resolution).fill(null);

    state.originalPlaybackRate = state.videoElement.playbackRate;

    setAudioGain(true);

    state.videoElement.playbackRate = 4;
    state.videoElement.currentTime = 0;
    state.videoElement.play();

    updateScanButtonUI(true);
    if (state.volumeGraphContainer) {
      state.volumeGraphContainer.classList.add('visible');
      state.isGraphVisible = true;
    }

    console.log('スキャン開始（直接音声解析）');

    const dataArray = new Float32Array(state.analyserNode.fftSize);
    const freqArray = new Float32Array(state.analyserNode.frequencyBinCount);

    state.scanInterval = setInterval(() => {
      if (!state.isScanning || !state.analyserNode) {
        stopDirectScan();
        return;
      }

      state.analyserNode.getFloatTimeDomainData(dataArray);

      // RMS（二乗平均平方根）で音量を計算
      let sum = 0;
      for (let i = 0; i < dataArray.length; i++) {
        sum += dataArray[i] * dataArray[i];
      }
      const rms = Math.sqrt(sum / dataArray.length);
      const normalizedVolume = Math.min(1, rms * 5);

      // 周波数スペクトルを取得してスペクトル特徴量を計算
      state.analyserNode.getFloatFrequencyData(freqArray);
      const spectralFeatures = computeSpectralFeatures(freqArray, state.audioContext.sampleRate);

      const currentResolution = state.volumeData.length;
      const index = Math.floor((state.videoElement.currentTime / state.videoDuration) * currentResolution);

      if (index >= 0 && index < currentResolution) {
        if (normalizedVolume > state.volumeData[index]) {
          state.volumeData[index] = normalizedVolume;
          if (spectralFeatures) state.spectralData[index] = spectralFeatures;
        } else if (!state.spectralData[index] && spectralFeatures) {
          state.spectralData[index] = spectralFeatures;
        }
      }

      const progress = (state.volumeData.filter(v => v > 0).length / currentResolution) * 100;
      updateProgress(progress);
      drawVolumeGraph();

      if (state.videoElement.currentTime >= state.videoDuration - 1) {
        stopDirectScan();
      }
    }, 50);

    return true;
  }

  function stopDirectScan() {
    if (!state.isScanning) return;

    state.isScanning = false;

    if (state.scanInterval) {
      clearInterval(state.scanInterval);
      state.scanInterval = null;
    }

    if (state.videoElement) {
      state.videoElement.playbackRate = state.originalPlaybackRate;
      state.videoElement.pause();
      state.videoElement.currentTime = 0;
    }

    setAudioGain(false);
    updateScanButtonUI(false);

    // スキャン完了時に結果を保存
    if (state.volumeData.length > 0 && state.volumeData.some(v => v > 0)) {
      saveVolumeData();
      sendSpectralDataToServer();
    }

    console.log('スキャン停止');

    // 自動スキャンモードの場合は次の動画へ
    if (state.isAutoScanMode && !state.autoScanStopRequested) {
      proceedToNextVideoOrFinish();
    }
  }

  function updateScanButtonUI(scanning) {
    if (!state.volumeGraphContainer) return;
    const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
    if (scanBtn) {
      if (scanning) {
        scanBtn.classList.add('scanning');
        scanBtn.textContent = '停止';
      } else {
        scanBtn.classList.remove('scanning');
        scanBtn.textContent = 'スキャン';
      }
    }
  }

  async function ensureStorageCapacity() {
    const STORAGE_LIMIT = 10 * 1024 * 1024;
    const THRESHOLD = 0.8;

    return new Promise((resolve) => {
      chrome.storage.local.getBytesInUse(null, (bytesInUse) => {
        if (bytesInUse < STORAGE_LIMIT * THRESHOLD) {
          resolve();
          return;
        }

        console.warn(`ストレージ使用量が上限の${Math.round(bytesInUse / STORAGE_LIMIT * 100)}%に達しています。古いデータを削除します。`);

        chrome.storage.local.get(null, (allData) => {
          const volumeEntries = [];
          for (const key in allData) {
            if (key.startsWith('volumeData_') && allData[key]?.savedAt) {
              volumeEntries.push({ key, savedAt: allData[key].savedAt });
            }
          }

          volumeEntries.sort((a, b) => a.savedAt - b.savedAt);

          const deleteCount = Math.max(1, Math.floor(volumeEntries.length / 4));
          const keysToDelete = volumeEntries.slice(0, deleteCount).map(e => e.key);

          if (keysToDelete.length > 0) {
            chrome.storage.local.remove(keysToDelete, () => {
              console.log(`ストレージ容量確保のため${keysToDelete.length}件の古い波形データを削除しました`);
              resolve();
            });
          } else {
            resolve();
          }
        });
      });
    });
  }

  async function saveVolumeData() {
    const videoId = getVideoId();
    if (!videoId || state.volumeData.length === 0) return;

    await ensureStorageCapacity();

    const storageKey = `volumeData_${videoId}`;
    const dataToSave = {
      version: 3,
      samplingInterval: SAMPLING_INTERVAL_SEC,
      data: state.volumeData,
      spectral: state.spectralData.some(s => s !== null) ? state.spectralData : undefined,
      duration: state.videoDuration,
      timestamps: state.detectedTimestamps,
      savedAt: Date.now()
    };

    chrome.storage.local.set({ [storageKey]: dataToSave }, () => {
      if (chrome.runtime.lastError) {
        console.error(`音量データの保存に失敗しました: ${chrome.runtime.lastError.message}`);
        return;
      }
      const spectralCount = state.spectralData.filter(s => s !== null).length;
      console.log(`音量データを保存しました: ${videoId} (${state.volumeData.length}サンプル, スペクトル${spectralCount}件, ${state.detectedTimestamps.length}候補)`);
    });
  }

  async function sendSpectralDataToServer() {
    const videoId = getVideoId();
    if (!videoId) return;

    const hasSpectral = state.spectralData.some(s => s !== null);
    if (!hasSpectral) return;

    if (!state.ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!state.ycsApiToken) return;

    try {
      const response = await fetch(`${state.ycsServerUrl}/api/extension/spectral-data`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${state.ycsApiToken}`,
        },
        body: JSON.stringify({
          video_id: videoId,
          sampling_interval: SAMPLING_INTERVAL_SEC,
          duration: state.videoDuration,
          spectral_data: state.spectralData,
        }),
      });

      if (response.ok) {
        console.log(`[YCS] スペクトルデータをサーバーに送信しました: ${videoId}`);
      } else {
        console.warn(`[YCS] スペクトルデータ送信エラー: ${response.status}`);
      }
    } catch (error) {
      console.warn('[YCS] スペクトルデータ送信エラー:', error.message);
    }
  }

  function loadVolumeData() {
    const videoId = getVideoId();
    if (!videoId) return;

    const storageKey = `volumeData_${videoId}`;
    chrome.storage.local.get(storageKey, (result) => {
      const saved = result[storageKey];
      if (saved && saved.data && saved.data.length > 0) {
        // 実動画とdurationが乖離した壊れたデータは復元しない
        if (isSavedVolumeDataStale(saved)) {
          console.warn(`保存された音量データのdurationが動画と一致しないため破棄します: ${videoId}`);
          chrome.storage.local.remove(storageKey);
          return;
        }

        state.volumeData = saved.data;
        state.spectralData = saved.spectral || new Array(saved.data.length).fill(null);
        if (saved.duration) {
          state.videoDuration = saved.duration;
        }
        if (saved.timestamps && saved.timestamps.length > 0) {
          state.detectedTimestamps = saved.timestamps;
        }
        console.log(`音量データを読み込みました: ${videoId} (${state.volumeData.length}サンプル, ${state.detectedTimestamps.length}候補)`);
        drawVolumeGraph();

        getScanStatus().then(status => {
          updateProgress(status.progress);
          updateScanButtonState(status);
        });
      }
    });
  }

  function updateScanButtonState(status) {
    if (!state.volumeGraphContainer) return;
    const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
    if (!scanBtn) return;

    if (status.isComplete) {
      scanBtn.textContent = '完了';
      scanBtn.title = 'スキャン完了済み（クリアボタンでリセット可能）';
      scanBtn.style.background = '#2e7d32';
    } else if (status.hasData && status.progress > 0) {
      scanBtn.textContent = `再開 (${status.progress.toFixed(0)}%)`;
      scanBtn.title = `${status.progress.toFixed(1)}%完了 - クリックで続きをスキャン`;
      scanBtn.style.background = '#f57c00';
    } else {
      scanBtn.textContent = 'スキャン';
      scanBtn.title = '動画全体をスキャンしてグラフを生成';
      scanBtn.style.background = '#333';
    }
  }

  function showPermissionError() {
    if (!state.volumeGraphContainer) return;

    if (state.volumeGraphContainer.querySelector('.vdg-permission-error')) return;

    const message = document.createElement('div');
    message.className = 'vdg-permission-error';
    message.innerHTML = `
    <div style="
      background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
      border: 1px solid #ffb74d;
      border-radius: 8px;
      padding: 12px 16px;
      margin: 8px 0;
      font-size: 13px;
      color: #e65100;
      display: flex;
      align-items: center;
      gap: 10px;
    ">
      <span style="font-size: 18px;">⚠️</span>
      <div>
        <div style="font-weight: 600; margin-bottom: 4px;">スキャンを開始できません</div>
        <div style="font-size: 12px; color: #f57c00;">
          拡張機能アイコンをクリックしてポップアップを開き、<br>
          「スキャン開始」ボタンからスキャンしてください。
        </div>
      </div>
    </div>
  `;

    const graphContainer = state.volumeGraphContainer.querySelector('.vdg-canvas-container');
    if (graphContainer) {
      graphContainer.parentNode.insertBefore(message, graphContainer);
    } else {
      state.volumeGraphContainer.appendChild(message);
    }
  }

  function hidePermissionError() {
    if (!state.volumeGraphContainer) return;
    const errorMsg = state.volumeGraphContainer.querySelector('.vdg-permission-error');
    if (errorMsg) {
      errorMsg.remove();
    }
  }

  async function discardVolumeDataAndReset() {
    const videoId = getVideoId();
    if (videoId) {
      await chrome.storage.local.remove(`volumeData_${videoId}`);
      console.log(`音量データを破棄しました: ${videoId}`);
    }

    state.volumeData = [];
    state.spectralData = [];
    state.detectedTimestamps = [];
    drawVolumeGraph();
    updateProgress(0);
    getScanStatus().then(status => updateScanButtonState(status));
  }

  function updatePlaylistUI() {
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

  async function startAutoScan() {
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

  function stopAutoScan() {
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

  function proceedToNextVideoOrFinish() {
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

  let isAutoDetectRunning = false;
  let tsEditorNoticeTimer = null;

  function formatTimestamp$1(seconds) {
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

  function movingAverageCentered(values, windowSize) {
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

  function percentileOf(sortedValues, p) {
    if (sortedValues.length === 0) return 0;
    const index = Math.min(sortedValues.length - 1, Math.floor(sortedValues.length * p));
    return sortedValues[index];
  }

  function buildLocalReference(values, intervalSec) {
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

  function detectSongSegments(data, intervalSec) {
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

  function detectClapBursts(chats, videoDurationSec) {
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

  function isEmojiOnlyMessage(text) {
    if (!text || typeof text !== 'string') return false;
    const trimmed = text.trim();
    return trimmed.length > 0 && EMOJI_ONLY_RE.test(trimmed);
  }

  function buildChatBuckets(chats, videoDurationSec, bucketSec) {
    const delaySec = CHAT_SIGNAL_CONFIG.CHAT_DELAY_SEC;
    const numBuckets = Math.ceil(videoDurationSec / bucketSec);
    const buckets = Array.from({ length: numBuckets }, () => ({ total: 0, emojiOnly: 0 }));

    for (const c of chats) {
      if (typeof c.message !== 'string') continue;
      const timeSec = (Number(c.timestamp) || 0) / 1000 - delaySec;
      if (timeSec < 0 || timeSec >= videoDurationSec) continue;
      const idx = Math.min(numBuckets - 1, Math.floor(timeSec / bucketSec));
      buckets[idx].total++;
      if (isEmojiOnlyMessage(c.message)) buckets[idx].emojiOnly++;
    }
    return buckets;
  }

  function detectEmojiSingingSegments(buckets, bucketSec) {
    const cfg = CHAT_ONLY_CONFIG;
    const half = Math.floor(cfg.SMOOTH_WINDOW_BUCKETS / 2);

    const emojiRatios = buckets.map((b, i) => {
      let totalMsg = 0;
      let emojiMsg = 0;
      for (let j = Math.max(0, i - half); j <= Math.min(buckets.length - 1, i + half); j++) {
        totalMsg += buckets[j].total;
        emojiMsg += buckets[j].emojiOnly;
      }
      if (totalMsg < cfg.MIN_BUCKET_MESSAGES) return 0;
      return emojiMsg / totalMsg;
    });

    const minBuckets = Math.max(1, Math.ceil(cfg.MIN_SEGMENT_SEC / bucketSec));
    const segments = [];
    let inSegment = false;
    let segStart = 0;
    let segLastAbove = 0;
    let belowCount = 0;

    const flush = () => {
      if (segLastAbove - segStart + 1 >= minBuckets) {
        segments.push({
          start: segStart * bucketSec,
          end: (segLastAbove + 1) * bucketSec,
        });
      }
    };

    for (let i = 0; i < emojiRatios.length; i++) {
      if (!inSegment) {
        if (emojiRatios[i] >= cfg.EMOJI_RATIO_ENTER) {
          inSegment = true;
          segStart = i;
          segLastAbove = i;
          belowCount = 0;
        }
      } else if (emojiRatios[i] < cfg.EMOJI_RATIO_EXIT) {
        belowCount++;
        if (belowCount >= cfg.EXIT_TOLERANCE_BUCKETS) {
          flush();
          inSegment = false;
        }
      } else {
        belowCount = 0;
        segLastAbove = i;
      }
    }
    if (inSegment) flush();

    const merged = [];
    for (const seg of segments) {
      const prev = merged[merged.length - 1];
      if (prev && seg.start - prev.end <= cfg.MERGE_GAP_SEC) {
        prev.end = seg.end;
      } else {
        merged.push({ ...seg });
      }
    }
    return merged;
  }

  function chatOnlyDetectSongStarts(chats, videoDurationSec) {
    const cfg = CHAT_ONLY_CONFIG;
    const clapCfg = CHAT_SIGNAL_CONFIG;

    if (!chats || chats.length < cfg.MIN_CHATS) {
      return { starts: [], method: 'insufficient' };
    }

    const buckets = buildChatBuckets(chats, videoDurationSec, cfg.BUCKET_SEC);
    const emojiSegments = detectEmojiSingingSegments(buckets, cfg.BUCKET_SEC);
    const bursts = detectClapBursts(chats, videoDurationSec);
    const chatActive = bursts.length >= clapCfg.MIN_BURSTS_TO_TRUST;

    if (emojiSegments.length === 0 && bursts.length === 0) {
      return { starts: [], method: 'no_signal' };
    }

    const starts = [];

    if (emojiSegments.length > 0) {
      for (const seg of emojiSegments) {
        starts.push(seg.start);
      }

      if (chatActive) {
        for (const burst of bursts) {
          const inSegment = emojiSegments.some(s => burst >= s.start && burst <= s.end + 30);
          if (inSegment) {
            const nextStart = burst + clapCfg.SPLIT_START_OFFSET_SEC;
            if (nextStart < videoDurationSec - 60) {
              const alreadyCovered = emojiSegments.some(
                s => nextStart >= s.start - 30 && nextStart <= s.start + 30
              );
              if (!alreadyCovered) starts.push(nextStart);
            }
          }
        }
      }
    } else if (chatActive) {
      for (let i = 0; i < bursts.length; i++) {
        if (i === 0 && bursts[0] > 120) {
          starts.push(0);
        }
        const nextStart = bursts[i] + clapCfg.SPLIT_START_OFFSET_SEC;
        if (nextStart < videoDurationSec - 60) {
          starts.push(nextStart);
        }
      }
    }

    starts.sort((a, b) => a - b);
    const deduped = [];
    for (const t of starts) {
      if (deduped.length === 0 || t - deduped[deduped.length - 1] > clapCfg.DEDUPE_SEC) {
        deduped.push(Math.floor(t));
      }
    }

    const method = emojiSegments.length > 0
      ? (chatActive ? 'emoji+clap' : 'emoji')
      : 'clap';

    return { starts: deduped, method };
  }

  function fuseSegmentsWithChat(segments, bursts) {
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

  async function fetchChats(videoId) {
    let chats = [];
    let chatUnavailable = false;
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
          console.warn('[YCS 自動検出] チャット取得失敗:', e);
          chatUnavailable = true;
        }
      }
    }
    return { chats, chatUnavailable };
  }

  function addDetectedMarkers(starts, sourceNote) {
    const newTimes = starts.filter(
      t => !state.tsMarkers.some(m => Math.abs(m.time - t) <= AUTO_DETECT_SKIP_NEAR_MARKER_SEC)
    );
    const skippedCount = starts.length - newTimes.length;
    if (newTimes.length === 0) {
      showTsEditorNotice(`候補${starts.length}件はすべて既存マーカー付近のためスキップしました`, true);
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

    const skippedNote = skippedCount > 0 ? `、既存マーカー付近の${skippedCount}件はスキップ` : '';
    showTsEditorNotice(`${newTimes.length}件の候補マーカーを追加しました（${sourceNote}${skippedNote}）`);
  }

  async function autoDetectSongStarts() {
    if (isAutoDetectRunning) return;
    if (!state.videoDuration) {
      showTsEditorNotice('動画の長さを取得できません', true);
      return;
    }

    const numericData = state.volumeData.map(v => {
      if (typeof v === 'number' && Number.isFinite(v)) return v;
      return Number.isFinite(v?.value) ? v.value : 0;
    });
    const hasVolumeData = numericData.length > 0 && numericData.some(v => v > 0);

    isAutoDetectRunning = true;
    try {
      const videoId = getVideoId();
      const { chats, chatUnavailable } = await fetchChats(videoId);
      if (videoId && getVideoId() !== videoId) return;

      if (hasVolumeData) {
        const intervalSec = state.videoDuration / numericData.length;
        const segments = detectSongSegments(numericData, intervalSec);
        if (segments.length === 0) {
          showTsEditorNotice('楽曲らしい区間が見つかりませんでした', true);
          return;
        }

        const bursts = detectClapBursts(chats, state.videoDuration);
        const fused = fuseSegmentsWithChat(segments, bursts);

        const sourceNote = fused.chatActive
          ? '音量+拍手チャット'
          : (chats.length > 0 ? '音量のみ（拍手が少ない配信）' : (chatUnavailable ? '音量のみ（チャットなし）' : '音量のみ'));
        addDetectedMarkers(fused.starts, sourceNote);
      } else {
        const result = chatOnlyDetectSongStarts(chats, state.videoDuration);
        if (result.starts.length === 0) {
          const reason = result.method === 'insufficient'
            ? 'チャットデータが不足しています'
            : 'チャットから楽曲区間を検出できませんでした';
          showTsEditorNotice(`音量データなし。${reason}`, true);
          return;
        }

        const methodLabels = {
          'emoji+clap': '絵文字+拍手チャット（⚠ 精度低）',
          'emoji': '絵文字チャット（⚠ 精度低）',
          'clap': '拍手チャット（⚠ 精度低）',
        };
        addDetectedMarkers(result.starts, methodLabels[result.method] || 'チャットのみ');
      }
    } finally {
      isAutoDetectRunning = false;
    }
  }

  function showTsEditorNotice(text, isWarning = false) {
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

  function copyTimestamps() {
    if (state.tsMarkers.length === 0) return;

    const text = state.tsMarkers
      .map(m => `${formatTimestamp$1(m.time)} ${m.text}`)
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

  function parseTimestampText(text) {
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

  async function importTimestamps() {
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

  function saveMarkersToStorage() {
    const videoId = getVideoId();
    if (!videoId) return;
    const key = `tsMarkers_${videoId}`;
    chrome.storage.local.set({ [key]: { markers: state.tsMarkers, nextId: state.nextMarkerId } });
  }

  function loadMarkersFromStorage() {
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

  function resetTimestampEditorForVideoChange() {
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

  function updateTimestampList() {
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

  function snapshotMarkers() {
    return {
      markers: state.tsMarkers.map(m => ({ ...m })),
      selectedId: state.selectedMarkerId,
      nextId: state.nextMarkerId,
    };
  }

  function pushMarkerHistory(tag = null, snapshot = null) {
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

  function undoMarkers() {
    if (state.tsHistoryUndo.length === 0) return;
    state.tsHistoryRedo.push(snapshotMarkers());
    restoreMarkerSnapshot(state.tsHistoryUndo.pop());
  }

  function redoMarkers() {
    if (state.tsHistoryRedo.length === 0) return;
    state.tsHistoryUndo.push(snapshotMarkers());
    restoreMarkerSnapshot(state.tsHistoryRedo.pop());
  }

  function restoreMarkerSnapshot(snapshot) {
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

  function updateUndoRedoButtons() {
    const undoBtn = state.volumeGraphContainer?.querySelector('#vdg-ts-undo-btn');
    const redoBtn = state.volumeGraphContainer?.querySelector('#vdg-ts-redo-btn');
    if (undoBtn) undoBtn.disabled = state.tsHistoryUndo.length === 0;
    if (redoBtn) redoBtn.disabled = state.tsHistoryRedo.length === 0;
  }

  function blurMarkerTextInput() {
    // キーボード操作で入力を抜ける場合はポップアップのmousedown経由の後始末が働かないため、
    // ここで明示的に閉じる（開いたまま残るとリスナーが生き続け、後続のクリックで誤挿入される）
    closeLyricsPastePopup();
    closeSongCandidatePopup();
    const active = document.activeElement;
    if (active instanceof HTMLElement && active.classList.contains('vdg-ts-text-input')) {
      active.blur();
    }
  }

  function updateTimestampListSelection() {
    const listEl = state.volumeGraphContainer?.querySelector('#vdg-ts-list');
    if (!listEl) return;
    listEl.querySelectorAll('.vdg-ts-row').forEach(row => {
      row.classList.toggle('selected', parseInt(row.dataset.markerId) === state.selectedMarkerId);
    });
    scrollSelectedRowIntoView();
    scrollGraphToSelectedMarker();
  }

  function scrollGraphToSelectedMarker() {
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

  function scrollSelectedRowIntoView() {
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

  function deselectMarker() {
    if (state.selectedMarkerId === null) return;
    state.selectedMarkerId = null;
    updateTimestampListSelection();
    drawVolumeGraph();
  }

  function deleteSelectedMarker() {
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

  function moveSelectedMarker(deltaSec) {
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

  function createVolumeGraph() {
    if (state.volumeGraphContainer) return;

    state.volumeGraphContainer = document.createElement('div');
    state.volumeGraphContainer.id = 'volume-dynamics-graph';
    state.volumeGraphContainer.innerHTML = `
    <style>
      #volume-dynamics-graph {
        position: relative !important;
        width: 100% !important;
        min-height: 60px !important;
        height: auto !important;
        background: rgba(0, 0, 0, 0.85) !important;
        border-radius: 4px !important;
        margin-top: 8px !important;
        margin-bottom: 8px !important;
        display: none !important;
        flex-direction: column !important;
        cursor: pointer !important;
        user-select: none !important;
        z-index: 1 !important;
        box-sizing: border-box !important;
      }

      #volume-dynamics-graph.visible {
        display: flex !important;
      }

      .vdg-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        font-size: 11px;
        color: #aaa;
        background: rgba(0, 0, 0, 0.5);
        border-radius: 4px 4px 0 0;
      }

      .vdg-title {
        font-weight: 500;
      }

      .vdg-current-time {
        font-size: 12px;
        color: #aaa;
        margin-left: 8px;
        font-variant-numeric: tabular-nums;
      }

      .vdg-controls {
        display: flex;
        gap: 8px;
        align-items: center;
      }

      .vdg-playlist-info {
        font-size: 10px;
        color: #4fc3f7;
        margin-right: 4px;
      }

      .vdg-btn {
        background: #333;
        border: none;
        color: #fff;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 10px;
        cursor: pointer;
        transition: background 0.2s;
      }

      .vdg-btn:hover {
        background: #555;
      }

      .vdg-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
      }

      .vdg-btn:disabled:hover {
        background: #333;
      }

      .vdg-btn.scanning {
        background: #c62828;
      }

      .vdg-btn.auto-scanning {
        background: #1565c0;
      }

      .vdg-btn.hidden {
        display: none;
      }

      .vdg-canvas-container {
        /* flex伸縮させない（高さフィードバックループ防止） */
        flex: 0 0 auto;
        position: relative;
        height: 60px;
        min-height: 40px;
        overflow-x: scroll;
        overflow-y: hidden;
      }

      .vdg-canvas-container::-webkit-scrollbar {
        height: 6px;
      }

      .vdg-canvas-container::-webkit-scrollbar-track {
        background: #1a1a1a;
        border-radius: 3px;
      }

      .vdg-canvas-container::-webkit-scrollbar-thumb {
        background: #444;
        border-radius: 3px;
      }

      .vdg-canvas-container::-webkit-scrollbar-thumb:hover {
        background: #555;
      }

      .vdg-canvas-wrapper {
        position: relative;
        height: 100%;
        min-width: 100%;
      }

      #volume-canvas {
        height: 100%;
        display: block;
      }

      .vdg-time-marker {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #ff5722;
        pointer-events: none;
        z-index: 10;
      }

      .vdg-time-labels {
        display: flex;
        justify-content: space-between;
        padding: 2px 8px;
        font-size: 9px;
        color: #666;
        font-family: monospace;
      }

      .vdg-hover-time {
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0, 0, 0, 0.9);
        color: #fff;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-family: monospace;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s;
        z-index: 20;
      }

      #volume-dynamics-graph:hover .vdg-hover-time {
        opacity: 1;
      }

      .vdg-progress {
        font-size: 10px;
        color: #4fc3f7;
      }

      .vdg-zoom-info {
        font-size: 10px;
        color: #888;
        margin-left: 4px;
      }

      .vdg-zoom-btn {
        font-size: 12px;
        line-height: 1;
        padding: 1px 5px;
        background: #333;
        color: #ccc;
        border: 1px solid #555;
        border-radius: 3px;
        cursor: pointer;
        vertical-align: middle;
      }
      .vdg-zoom-btn:hover {
        background: #444;
        color: #fff;
      }
      .vdg-zoom-btn:disabled {
        opacity: 0.3;
        cursor: default;
      }

      .vdg-volume-mode {
        font-size: 9px;
        padding: 2px 6px;
        margin-left: 4px;
        background: #333;
        border: 1px solid #555;
        border-radius: 3px;
        color: #aaa;
        cursor: pointer;
        transition: all 0.2s;
      }

      .vdg-volume-mode:hover {
        background: #444;
        color: #fff;
      }

      .vdg-volume-mode.active {
        background: #1b5e20;
        border-color: #4caf50;
        color: #4caf50;
      }

      /* タイムスタンプエディタ */
      .vdg-ts-editor {
        border-top: 1px solid #333;
        margin-top: 4px;
      }

      .vdg-ts-editor-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        font-size: 11px;
        color: #888;
      }

      .vdg-ts-mode-toggle {
        display: flex;
        border-radius: 4px;
        overflow: hidden;
        border: 1px solid #444;
      }

      .vdg-mode-btn {
        padding: 2px 8px;
        font-size: 10px;
        background: #222;
        color: #888;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
      }

      .vdg-mode-btn:hover {
        background: #333;
        color: #ccc;
      }

      .vdg-mode-btn.active {
        background: #1565c0;
        color: #fff;
      }

      .vdg-ts-editor-actions {
        display: flex;
        gap: 4px;
        flex-shrink: 0;
      }

      .vdg-ts-notice {
        flex: 1;
        margin: 0 8px;
        font-size: 11px;
        color: #4fc3f7;
        text-align: right;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
      }

      .vdg-ts-notice.warning {
        color: #ffb74d;
      }

      .vdg-btn-detect {
        background: #2e7d32 !important;
      }

      .vdg-btn-detect:hover {
        background: #388e3c !important;
      }

      .vdg-btn-copy {
        background: #1565c0 !important;
      }

      .vdg-btn-copy:hover {
        background: #1976d2 !important;
      }

      .vdg-btn-import {
        background: #6a1b9a !important;
      }

      .vdg-btn-import:hover {
        background: #7b1fa2 !important;
      }

      .vdg-btn-clear-markers {
        background: #555 !important;
      }

      .vdg-btn-clear-markers:hover {
        background: #666 !important;
      }

      .vdg-ts-list {
        max-height: 200px;
        overflow-y: auto;
        /* 一覧の上下端に達してもYouTubeページ側へスクロールを伝播させない */
        overscroll-behavior-y: contain;
        padding: 0 4px;
      }

      .vdg-ts-empty {
        text-align: center;
        color: #555;
        font-size: 11px;
        padding: 12px 0;
      }

      .vdg-ts-row {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 3px 4px;
        border-radius: 3px;
        cursor: pointer;
        transition: background 0.15s;
      }

      .vdg-ts-row:hover {
        background: #2a2a2a;
      }

      .vdg-ts-row.selected {
        background: #1b3a1b;
        outline: 1px solid #4caf50;
      }

      .vdg-ts-time {
        font-family: monospace;
        font-size: 12px;
        color: #4fc3f7;
        flex-shrink: 0;
        min-width: 50px;
      }

      .vdg-ts-text-input {
        flex: 1;
        background: transparent;
        border: none;
        border-bottom: 1px solid #333;
        color: #ddd;
        font-size: 12px;
        padding: 2px 4px;
        outline: none;
        min-width: 0;
      }

      .vdg-ts-text-input:focus {
        border-bottom-color: #4fc3f7;
      }

      .vdg-ts-text-input::placeholder {
        color: #555;
      }

      .vdg-ts-offset-btn {
        background: #333;
        border: 1px solid #555;
        color: #ccc;
        font-size: 10px;
        padding: 1px 4px;
        border-radius: 3px;
        cursor: pointer;
        flex-shrink: 0;
        line-height: 1.2;
      }

      .vdg-ts-offset-btn:hover {
        background: #444;
        color: #fff;
      }

      .vdg-ts-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 8px;
        border-top: 1px solid #2a2a2a;
      }

      .vdg-ts-help {
        font-size: 10px;
        color: #999;
      }

      .vdg-paste-popup {
        position: absolute;
        background: #222;
        border: 1px solid #555;
        border-radius: 4px;
        z-index: 100;
        min-width: 180px;
        max-width: 320px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        font-size: 11px;
      }

      .vdg-paste-popup-title {
        padding: 4px 8px;
        color: #888;
        font-size: 10px;
        border-bottom: 1px solid #444;
      }

      .vdg-paste-popup-item {
        padding: 5px 8px;
        color: #eee;
        cursor: pointer;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .vdg-paste-popup-item:hover {
        background: #444;
      }

      .vdg-paste-popup-item.raw {
        color: #999;
        border-top: 1px solid #444;
      }

      .vdg-ts-suggest-btn {
        background: #2a3a4a;
        border: 1px solid #456;
        color: #9cf;
        font-size: 10px;
        padding: 1px 4px;
        border-radius: 3px;
        cursor: pointer;
        flex-shrink: 0;
        line-height: 1.2;
      }

      .vdg-ts-suggest-btn:hover {
        background: #345;
        color: #cef;
      }

      .vdg-paste-popup-item .similarity {
        color: #8a8;
        font-size: 10px;
        margin-left: 6px;
      }

      .vdg-paste-popup-item .artist {
        color: #999;
        font-size: 10px;
        margin-left: 6px;
      }

      .vdg-paste-popup-item.message {
        color: #999;
        cursor: default;
      }
      .vdg-paste-popup-item.action {
        color: #6af;
        cursor: pointer;
        text-align: center;
        border-top: 1px solid #444;
        margin-top: 2px;
        padding-top: 6px;
      }
      .vdg-paste-popup-item.action:hover {
        color: #8cf;
      }

      .vdg-ts-format-toggle {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        color: #666;
        cursor: pointer;
      }

      .vdg-ts-format-toggle input {
        width: 12px;
        height: 12px;
        cursor: pointer;
      }

    </style>
    <div class="vdg-header">
      <span class="vdg-title">音量ダイナミクス</span><span class="vdg-current-time" id="vdg-current-time"></span>
      <div class="vdg-controls">
        <span class="vdg-playlist-info" id="vdg-playlist-info"></span>
        <span class="vdg-progress" id="vdg-progress" title="音量分析の完了率">分析 0%</span>
        <button class="vdg-zoom-btn" id="vdg-zoom-out-btn" title="縮小">−</button>
        <span class="vdg-zoom-info" id="vdg-zoom-info" title="グラフの表示倍率（Ctrl+ホイールで変更）">倍率 1x</span>
        <button class="vdg-zoom-btn" id="vdg-zoom-in-btn" title="拡大">+</button>
        <button class="vdg-volume-mode" id="vdg-volume-mode-btn" title="固定スケールでの絶対値表示中（クリックで相対表示に切替）">絶対</button>
        <button class="vdg-btn" id="vdg-scan-btn" title="動画全体をスキャンしてグラフを生成">スキャン</button>
        <button class="vdg-btn" id="vdg-rescan-btn" title="保存された音量データを破棄して最初からスキャンし直す">やり直し</button>
        <button class="vdg-btn" id="vdg-auto-scan-btn" title="再生リスト内の動画を順番にスキャン">自動</button>
        <button class="vdg-btn" id="vdg-audio-recover-btn" title="スキャン後に音量が戻らない場合に、ミュート・音声ゲイン・再生速度を通常状態へ復旧する">🔊復旧</button>
      </div>
    </div>
    <div class="vdg-canvas-container" id="vdg-canvas-container">
      <div class="vdg-canvas-wrapper" id="vdg-canvas-wrapper">
        <canvas id="volume-canvas"></canvas>
        <div class="vdg-time-marker" id="vdg-time-marker"></div>
      </div>
      <div class="vdg-hover-time" id="vdg-hover-time">0:00</div>
    </div>
    <div class="vdg-time-labels">
      <span id="vdg-start-time">0:00</span>
      <span id="vdg-end-time">--:--</span>
    </div>
    <div class="vdg-ts-editor" id="vdg-ts-editor">
      <div class="vdg-ts-editor-header">
        <div class="vdg-ts-mode-toggle">
          <button class="vdg-mode-btn active" id="vdg-mode-marker" title="クリックでマーカーを追加">+ マーカー</button>
          <button class="vdg-mode-btn" id="vdg-mode-seek" title="クリックで再生位置を移動">シーク</button>
        </div>
        <span class="vdg-ts-notice" id="vdg-ts-notice"></span>
        <div class="vdg-ts-editor-actions">
          <button class="vdg-btn vdg-btn-detect" id="vdg-ts-detect-btn" title="音量とチャット（拍手）から楽曲の開始位置を検出し、候補マーカーを一括追加（既存マーカー付近は除く）">自動検出</button>
          <button class="vdg-btn" id="vdg-ts-undo-btn" title="元に戻す (Ctrl+Z)" disabled>↶ 戻る</button>
          <button class="vdg-btn" id="vdg-ts-redo-btn" title="やり直す (Ctrl+Y)" disabled>↷ 進む</button>
          <button class="vdg-btn vdg-btn-copy" id="vdg-ts-copy-btn" title="テキストとしてコピー">コピー</button>
          <button class="vdg-btn vdg-btn-import" id="vdg-ts-import-btn" title="クリップボードのタイムスタンプテキストを取り込み（既存マーカーは洗い替え）">貼り付け</button>
          <button class="vdg-btn vdg-btn-clear-markers" id="vdg-ts-clear-btn" title="すべてのマーカーを削除">クリア</button>
        </div>
      </div>
      <div class="vdg-ts-list" id="vdg-ts-list">
        <div class="vdg-ts-empty">波形グラフをクリックしてタイムスタンプを追加</div>
      </div>
      <div class="vdg-ts-footer">
        <div class="vdg-ts-help">
          クリック: マーカー追加(付近は選択/ドラッグで移動) | Enter: 曲名入力/入力終了 | Del/BS: 削除 | Esc: 入力終了・選択解除 | ←→: 1秒移動(2度押し5秒) | ↑↓: マーカー移動(入力中は行頭/行末へ) | Space: 再生/停止 | J/L: 再生を10秒戻す/進める | Ctrl+Z/Y: 操作を戻す/やり直す | −/+ボタン or Ctrl+ホイール: 拡大/縮小
        </div>
        <label class="vdg-ts-format-toggle">
          <input type="checkbox" id="vdg-ts-zeropad">
          <span>ゼロ埋め (00:03:45)</span>
        </label>
      </div>
    </div>
  `;

    state.volumeCanvas = state.volumeGraphContainer.querySelector('#volume-canvas');
    if (state.volumeCanvas) {
      state.volumeCtx = state.volumeCanvas.getContext('2d');
    }

    setupVolumeGraphEvents();
    insertVolumeGraph();
    loadVolumeData();
  }

  function insertVolumeGraph() {
    const insertGraph = () => {
      // #below（動画の下のコンテンツセクション）の先頭に挿入
      const belowContainer = document.querySelector('ytd-watch-flexy #below');
      if (belowContainer && !document.getElementById('volume-dynamics-graph')) {
        belowContainer.insertBefore(state.volumeGraphContainer, belowContainer.firstChild);
        resizeCanvas();
        return true;
      }
      return false;
    };

    if (!insertGraph()) {
      const observer = new MutationObserver((mutations, obs) => {
        if (insertGraph()) {
          obs.disconnect();
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
      setTimeout(() => observer.disconnect(), 10000);
    }
  }

  function resizeCanvas() {
    if (!state.volumeCanvas || !state.volumeGraphContainer) return;

    const canvasContainer = state.volumeGraphContainer.querySelector('#vdg-canvas-container');
    const canvasWrapper = state.volumeGraphContainer.querySelector('#vdg-canvas-wrapper');
    if (!canvasContainer || !canvasWrapper) return;

    const containerRect = canvasContainer.getBoundingClientRect();
    const baseWidth = containerRect.width;
    const zoomedWidth = baseWidth * getZoomLevel();
    // 高さはズームレベルから決定論的に算出する
    canvasContainer.style.height = `${state.graphBaseHeightPx + state.zoomIndex * state.graphHeightStepPx}px`;
    // 描画領域は水平スクロールバーを除いた実表示高さ
    const height = canvasContainer.clientHeight;

    canvasWrapper.style.width = `${zoomedWidth}px`;
    state.volumeCanvas.style.width = `${zoomedWidth}px`;
    state.volumeCanvas.style.height = `${height}px`;

    state.volumeCanvas.width = zoomedWidth * window.devicePixelRatio;
    state.volumeCanvas.height = height * window.devicePixelRatio;

    state.volumeCtx = state.volumeCanvas.getContext('2d');
    state.volumeCtx.scale(window.devicePixelRatio, window.devicePixelRatio);

    drawVolumeGraph();
  }

  function getZoomLevel() {
    return ZOOM_LEVELS[state.zoomIndex];
  }

  function changeZoomLevel(delta) {
    const oldZoom = getZoomLevel();
    const newIndex = Math.max(0, Math.min(ZOOM_LEVELS.length - 1, state.zoomIndex + delta));

    if (newIndex === state.zoomIndex) return;
    state.zoomIndex = newIndex;

    const newZoom = getZoomLevel();

    const zoomInfo = state.volumeGraphContainer?.querySelector('#vdg-zoom-info');
    if (zoomInfo) {
      zoomInfo.textContent = `倍率 ${newZoom}x`;
    }

    updateZoomButtons();

    // スクロール位置を維持するための計算
    const canvasContainer = state.volumeGraphContainer?.querySelector('#vdg-canvas-container');
    if (canvasContainer) {
      const containerRect = canvasContainer.getBoundingClientRect();
      const scrollRatio = (canvasContainer.scrollLeft + containerRect.width / 2) / (containerRect.width * oldZoom);

      resizeCanvas();

      const newScrollLeft = scrollRatio * containerRect.width * newZoom - containerRect.width / 2;
      canvasContainer.scrollLeft = Math.max(0, newScrollLeft);
    } else {
      resizeCanvas();
    }
  }

  function updateZoomButtons() {
    const zoomInBtn = state.volumeGraphContainer?.querySelector('#vdg-zoom-in-btn');
    const zoomOutBtn = state.volumeGraphContainer?.querySelector('#vdg-zoom-out-btn');
    if (zoomInBtn) zoomInBtn.disabled = state.zoomIndex >= ZOOM_LEVELS.length - 1;
    if (zoomOutBtn) zoomOutBtn.disabled = state.zoomIndex <= 0;
  }

  function getMarkerSnapThresholdSec(totalWidth) {
    if (!state.videoDuration || !totalWidth) return MARKER_SNAP_THRESHOLD_SEC;
    const pxPerSec = totalWidth / state.videoDuration;
    return Math.max(MARKER_SNAP_THRESHOLD_SEC, MARKER_SNAP_THRESHOLD_PX / pxPerSec);
  }

  function setupVolumeGraphEvents() {
    const container = state.volumeGraphContainer.querySelector('.vdg-canvas-container');
    const hoverTime = state.volumeGraphContainer.querySelector('#vdg-hover-time');
    const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
    const autoScanBtn = state.volumeGraphContainer.querySelector('#vdg-auto-scan-btn');

    console.log('setupVolumeGraphEvents: ', {
      container: !!container,
      hoverTime: !!hoverTime,
      scanBtn: !!scanBtn,
      autoScanBtn: !!autoScanBtn
    });

    updatePlaylistUI();

    if (container) {
      container.querySelector('#vdg-canvas-wrapper');

      let draggingMarkerId = null;
      let dragMoved = false;
      let dragStartClientX = 0;
      let dragStartSnapshot = null;
      let suppressClickAfterDrag = false;
      const DRAG_START_THRESHOLD_PX = 3;

      // スクロール位置とズームを考慮して、マウス位置から動画内の時刻を計算
      const getTimeFromMouseEvent = (e) => {
        const containerRect = container.getBoundingClientRect();
        const x = e.clientX - containerRect.left + container.scrollLeft;
        const totalWidth = containerRect.width * getZoomLevel();
        const ratio = Math.max(0, Math.min(1, x / totalWidth));
        return ratio * state.videoDuration;
      };

      // マーカーモード: 既存マーカーの上でmousedownしたらドラッグ開始
      container.addEventListener('mousedown', (e) => {
        if (e.button !== 0) return;
        if (state.tsEditorMode !== 'marker') return;
        if (!state.videoElement || !state.videoDuration) return;

        const containerRect = container.getBoundingClientRect();
        const totalWidth = containerRect.width * getZoomLevel();
        const time = getTimeFromMouseEvent(e);
        const snapSec = getMarkerSnapThresholdSec(totalWidth);
        const nearMarker = state.tsMarkers.find(m => Math.abs(m.time - time) < snapSec);
        if (!nearMarker) return;

        draggingMarkerId = nearMarker.id;
        dragMoved = false;
        dragStartClientX = e.clientX;
        dragStartSnapshot = snapshotMarkers();
        // 曲名編集中なら入力状態を解除する（preventDefaultで自動blurが起きないため）
        blurMarkerTextInput();
        state.selectedMarkerId = nearMarker.id;
        updateTimestampList();
        drawVolumeGraph();
        e.preventDefault();
      });

      // ドラッグ中の移動（グラフ外に出ても追従できるようdocumentで監視）
      document.addEventListener('mousemove', (e) => {
        if (draggingMarkerId === null) return;
        if (!dragMoved && Math.abs(e.clientX - dragStartClientX) < DRAG_START_THRESHOLD_PX) return;
        dragMoved = true;

        const marker = state.tsMarkers.find(m => m.id === draggingMarkerId);
        if (!marker || !state.videoDuration) return;

        marker.time = Math.floor(getTimeFromMouseEvent(e));
        container.style.cursor = 'grabbing';
        // 一覧の更新はドラッグ確定時にまとめて行う
        drawVolumeGraph();
      });

      // ドラッグ確定
      document.addEventListener('mouseup', (e) => {
        if (e.button !== 0) return;
        if (draggingMarkerId === null) return;
        const marker = state.tsMarkers.find(m => m.id === draggingMarkerId);
        draggingMarkerId = null;
        container.style.cursor = 'grab';
        if (!dragMoved || !marker) {
          dragStartSnapshot = null;
          return;
        }

        // ドラッグ直後に発火するclickでマーカー追加/選択が走らないようにする
        suppressClickAfterDrag = true;
        setTimeout(() => { suppressClickAfterDrag = false; }, 0);
        if (dragStartSnapshot) {
          pushMarkerHistory(null, dragStartSnapshot);
          dragStartSnapshot = null;
        }
        state.tsMarkers.sort((a, b) => a.time - b.time);
        if (state.videoElement) {
          state.videoElement.currentTime = marker.time;
          updateTimeMarker();
        }
        updateTimestampList();
        drawVolumeGraph();
        saveMarkersToStorage();
      });

      container.addEventListener('click', (e) => {
        if (!state.videoElement || !state.videoDuration) return;
        if (suppressClickAfterDrag) return;

        const containerRect = container.getBoundingClientRect();
        const scrollLeft = container.scrollLeft;
        const x = e.clientX - containerRect.left + scrollLeft;
        const totalWidth = containerRect.width * getZoomLevel();
        const ratio = x / totalWidth;
        const clickTime = ratio * state.videoDuration;

        if (state.tsEditorMode === 'seek') {
          state.videoElement.currentTime = clickTime;
        } else {
          // マーカーモード: 既存マーカーの近くは選択、それ以外は追加
          const snapSec = getMarkerSnapThresholdSec(totalWidth);
          const nearMarker = state.tsMarkers.find(m => Math.abs(m.time - clickTime) < snapSec);
          if (nearMarker) {
            state.selectedMarkerId = nearMarker.id;
            state.videoElement.currentTime = nearMarker.time;
            updateTimestampList();
          } else {
            pushMarkerHistory();
            const marker = { id: state.nextMarkerId++, time: Math.floor(clickTime), text: '' };
            state.tsMarkers.push(marker);
            state.tsMarkers.sort((a, b) => a.time - b.time);
            state.selectedMarkerId = marker.id;
            state.videoElement.currentTime = marker.time;
            updateTimestampList();
            saveMarkersToStorage();
          }
        }
        updateTimeMarker();
        drawVolumeGraph();
      });

      // ホバーで時間表示（ズーム対応）
      container.addEventListener('mousemove', (e) => {
        if (!state.videoDuration) return;

        const containerRect = container.getBoundingClientRect();
        const scrollLeft = container.scrollLeft;
        const x = e.clientX - containerRect.left + scrollLeft;
        const totalWidth = containerRect.width * getZoomLevel();
        const ratio = Math.max(0, Math.min(1, x / totalWidth));
        const time = ratio * state.videoDuration;

        // カーソル形状の判定
        if (draggingMarkerId !== null) ; else if (state.tsEditorMode === 'marker') {
          const snapSec = getMarkerSnapThresholdSec(totalWidth);
          const isNearMarker = state.tsMarkers.some(m => Math.abs(m.time - time) < snapSec);
          container.style.cursor = isNearMarker ? 'grab' : 'crosshair';
        } else {
          container.style.cursor = 'pointer';
        }

        if (hoverTime) {
          hoverTime.textContent = formatTimeDisplay(time);
          hoverTime.style.left = `${e.clientX - containerRect.left}px`;
        }
      });

      // グラフ外のクリックで選択を解除する（キャプチャフェーズで監視）
      document.addEventListener('click', (e) => {
        if (suppressClickAfterDrag) return;
        if (state.selectedMarkerId === null) return;
        if (e.target instanceof Element && state.volumeGraphContainer && state.volumeGraphContainer.contains(e.target)) return;
        deselectMarker();
      }, true);

      // マウスホイールイベント（ホイールのみ: 横スクロール）
      container.addEventListener('wheel', (e) => {
        if (e.ctrlKey) return;
        e.preventDefault();
        container.scrollLeft += e.deltaY;
      }, { passive: false });
    }

    // Ctrl + ホイール: ズーム（パネルのどこでも反応）
    state.volumeGraphContainer.addEventListener('wheel', (e) => {
      if (!e.ctrlKey) return;
      e.preventDefault();
      changeZoomLevel(e.deltaY > 0 ? -1 : 1);
    }, { passive: false });

    // ズームボタン
    const zoomInBtn = state.volumeGraphContainer.querySelector('#vdg-zoom-in-btn');
    const zoomOutBtn = state.volumeGraphContainer.querySelector('#vdg-zoom-out-btn');
    if (zoomInBtn) {
      zoomInBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        changeZoomLevel(1);
        updateZoomButtons();
      });
    }
    if (zoomOutBtn) {
      zoomOutBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        changeZoomLevel(-1);
        updateZoomButtons();
      });
    }

    // 音量表示モード切り替えボタン
    const volumeModeBtn = state.volumeGraphContainer.querySelector('#vdg-volume-mode-btn');
    if (volumeModeBtn) {
      volumeModeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        state.isRelativeVolumeMode = !state.isRelativeVolumeMode;
        volumeModeBtn.classList.toggle('active', state.isRelativeVolumeMode);
        volumeModeBtn.textContent = state.isRelativeVolumeMode ? '相対' : '絶対';
        volumeModeBtn.title = state.isRelativeVolumeMode
          ? 'アーカイブ内の最大音量を100%とした相対表示中'
          : '固定スケールでの絶対値表示中';
        drawVolumeGraph();
      });
    }

    if (scanBtn) {
      scanBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (state.edition === 'general') {
          startDirectScan();
        } else {
          try {
            const response = await chrome.runtime.sendMessage({ type: 'START_SCAN' });
            console.log('START_SCAN応答:', response);
          } catch (error) {
            console.error('START_SCANエラー:', error);
          }
        }
      });
    }

    // 音量復旧ボタン
    const audioRecoverBtn = state.volumeGraphContainer.querySelector('#vdg-audio-recover-btn');
    if (audioRecoverBtn) {
      audioRecoverBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        setAudioGain(false);
        if (state.videoElement) {
          state.videoElement.muted = false;
          state.videoElement.playbackRate = 1;
        }
        console.log('[YCS] 音量・再生速度を復旧しました');
      });
    }

    const rescanBtn = state.volumeGraphContainer.querySelector('#vdg-rescan-btn');
    if (rescanBtn) {
      rescanBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        if (state.isScanning) return;
        if (!confirm('保存された音量データを破棄して、最初からスキャンし直しますか？')) return;

        await discardVolumeDataAndReset();

        if (state.edition === 'general') {
          startDirectScan();
        } else {
          try {
            await chrome.runtime.sendMessage({ type: 'START_SCAN' });
          } catch (error) {
            console.error('START_SCANエラー:', error);
          }
        }
      });
    }

    // 自動スキャンボタン（再生リスト用）
    if (autoScanBtn) {
      autoScanBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (state.isAutoScanMode) {
          stopAutoScan();
        } else {
          startAutoScan();
        }
      });
    }

    window.addEventListener('resize', () => {
      resizeCanvas();
    });

    // タイムスタンプエディタ: 戻す/やり直すボタン
    const tsUndoBtn = state.volumeGraphContainer.querySelector('#vdg-ts-undo-btn');
    const tsRedoBtn = state.volumeGraphContainer.querySelector('#vdg-ts-redo-btn');
    if (tsUndoBtn) {
      tsUndoBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        undoMarkers();
      });
    }
    if (tsRedoBtn) {
      tsRedoBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        redoMarkers();
      });
    }

    // 自動検出ボタン
    const tsDetectBtn = state.volumeGraphContainer.querySelector('#vdg-ts-detect-btn');
    if (tsDetectBtn) {
      tsDetectBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        autoDetectSongStarts();
      });
    }

    // コピーボタン
    const tsCopyBtn = state.volumeGraphContainer.querySelector('#vdg-ts-copy-btn');
    if (tsCopyBtn) {
      tsCopyBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        copyTimestamps();
      });
    }

    // 貼り付け（インポート）ボタン
    const tsImportBtn = state.volumeGraphContainer.querySelector('#vdg-ts-import-btn');
    if (tsImportBtn) {
      tsImportBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        importTimestamps();
      });
    }

    // クリアボタン
    const tsClearBtn = state.volumeGraphContainer.querySelector('#vdg-ts-clear-btn');
    if (tsClearBtn) {
      tsClearBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (state.tsMarkers.length === 0) return;
        if (!confirm(`${state.tsMarkers.length}件のマーカーをすべて削除しますか？`)) return;
        pushMarkerHistory();
        state.tsMarkers = [];
        state.selectedMarkerId = null;
        updateTimestampList();
        drawVolumeGraph();
        saveMarkersToStorage();
      });
    }

    // モード切替
    const modeMarkerBtn = state.volumeGraphContainer.querySelector('#vdg-mode-marker');
    const modeSeekBtn = state.volumeGraphContainer.querySelector('#vdg-mode-seek');
    if (modeMarkerBtn && modeSeekBtn) {
      const updateModeUI = () => {
        modeMarkerBtn.classList.toggle('active', state.tsEditorMode === 'marker');
        modeSeekBtn.classList.toggle('active', state.tsEditorMode === 'seek');
      };
      modeMarkerBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        state.tsEditorMode = 'marker';
        updateModeUI();
      });
      modeSeekBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        state.tsEditorMode = 'seek';
        updateModeUI();
      });
    }

    // 保存済みマーカーを復元
    loadMarkersFromStorage();

    // ゼロ埋め設定
    const zeroPadCheckbox = state.volumeGraphContainer.querySelector('#vdg-ts-zeropad');
    if (zeroPadCheckbox) {
      chrome.storage.local.get('tsZeroPad', (result) => {
        state.tsZeroPad = result.tsZeroPad === true;
        zeroPadCheckbox.checked = state.tsZeroPad;
      });
      zeroPadCheckbox.addEventListener('change', () => {
        state.tsZeroPad = zeroPadCheckbox.checked;
        chrome.storage.local.set({ tsZeroPad: state.tsZeroPad });
        updateTimestampList();
      });
    }

    // キーボード操作
    // YouTube本体のショートカットと二重に発火すると打ち消し合うため、
    // キャプチャフェーズで先に処理してstopImmediatePropagationで止める
    let lastArrowTime = 0;
    document.addEventListener('keydown', (e) => {
      const isTextInput = e.target.classList.contains('vdg-ts-text-input');
      if (isTextInput && !['Delete', 'ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(e.key)) return;
      // IME変換中は確定/取消/候補選択をIME側の処理に委ねる
      if (isTextInput && (e.isComposing || e.keyCode === 229)) return;
      // エディタ以外の編集可能要素への入力は妨げない
      if (!isTextInput && isEditableTarget(e.target)) return;
      // YouTube本体のUI部品にフォーカスがある場合は本体の操作を優先
      if (!isTsEditorKeyScope()) return;
      if (!state.isGraphVisible) return;

      // Undo/Redo
      if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (e.shiftKey) {
          redoMarkers();
        } else {
          undoMarkers();
        }
        return;
      }
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        e.stopImmediatePropagation();
        redoMarkers();
        return;
      }

      // 曲名入力中のEnter/Escは入力状態を終了して選択状態に戻す
      if (isTextInput && (e.key === 'Enter' || e.key === 'Escape')) {
        // ペースト変換ポップアップが開いている間は、ポップアップ側のキー処理を優先する
        if (isLyricsPastePopupOpen()) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        blurMarkerTextInput();
        return;
      }

      // 曲名入力中の↑↓はカーソルを文字列の先頭/末尾へ移動する
      if (isTextInput && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        e.stopImmediatePropagation();
        closeLyricsPastePopup();
        closeSongCandidatePopup();
        const pos = e.key === 'ArrowUp' ? 0 : e.target.value.length;
        e.target.setSelectionRange(pos, pos);
        return;
      }

      if (state.selectedMarkerId === null) return;

      const now = Date.now();

      if ((e.key === 'Delete' || e.key === 'Backspace') && !isTextInput) {
        e.preventDefault();
        e.stopImmediatePropagation();
        deleteSelectedMarker();
      } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        e.stopImmediatePropagation();
        const currentIndex = state.tsMarkers.findIndex(m => m.id === state.selectedMarkerId);
        if (currentIndex < 0) return;
        const nextIndex = e.key === 'ArrowUp'
          ? Math.max(0, currentIndex - 1)
          : Math.min(state.tsMarkers.length - 1, currentIndex + 1);
        if (nextIndex !== currentIndex) {
          state.selectedMarkerId = state.tsMarkers[nextIndex].id;
          if (state.videoElement) {
            state.videoElement.currentTime = state.tsMarkers[nextIndex].time;
            updateTimeMarker();
          }
          updateTimestampList();
          drawVolumeGraph();
        }
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        e.stopImmediatePropagation();
        const direction = e.key === 'ArrowLeft' ? -1 : 1;
        // 200ms以内の連続押下で5秒移動
        const delta = (now - lastArrowTime < 200) ? 5 : 1;
        lastArrowTime = now;
        moveSelectedMarker(direction * delta);
      } else if (e.key === 'Enter' && !isTextInput) {
        // 選択中マーカーの曲名入力欄にフォーカス
        e.preventDefault();
        e.stopImmediatePropagation();
        const input = state.volumeGraphContainer?.querySelector(`.vdg-ts-text-input[data-marker-id="${state.selectedMarkerId}"]`);
        if (input) {
          input.focus({ preventScroll: true });
          const len = input.value.length;
          input.setSelectionRange(len, len);
          scrollSelectedRowIntoView();
        }
      } else if (e.key === 'Escape') {
        closeSongCandidatePopup();
        deselectMarker();
      } else if (e.key === ' ') {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (state.videoElement) {
          if (state.videoElement.paused) {
            state.videoElement.play();
          } else {
            state.videoElement.pause();
          }
        }
      }
    }, true);

    // Spaceのkeyupも止める（フォーカス中の要素がSpaceで反応して再生状態が再度トグルされるのを防ぐ）
    document.addEventListener('keyup', (e) => {
      if (e.key !== ' ') return;
      if (isEditableTarget(e.target)) return;
      if (!isTsEditorKeyScope()) return;
      if (!state.isGraphVisible || state.selectedMarkerId === null) return;
      e.preventDefault();
      e.stopImmediatePropagation();
    }, true);
  }

  function isEditableTarget(target) {
    if (!(target instanceof Element)) return false;
    if (target.isContentEditable) return true;
    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
  }

  function isTsEditorKeyScope() {
    const active = document.activeElement;
    if (!active || active === document.body || active === document.documentElement) return true;
    if (state.volumeGraphContainer && state.volumeGraphContainer.contains(active)) return true;
    if (active === state.videoElement) return true;
    if (active.id === 'movie_player') return true;
    return false;
  }

  function drawSingingOverlay(height, barWidth, maxVolume) {
    if (!state.volumeCtx || state.spectralData.length === 0) return;

    const flatnessValues = [];
    for (let i = 0; i < state.spectralData.length; i++) {
      if (state.spectralData[i] && state.spectralData[i].flatness !== undefined) {
        flatnessValues.push(state.spectralData[i].flatness);
      }
    }
    if (flatnessValues.length < 10) return;

    flatnessValues.sort((a, b) => a - b);
    const lo = flatnessValues[Math.floor(flatnessValues.length * 0.1)];
    const hi = flatnessValues[Math.floor(flatnessValues.length * 0.9)];
    const range = hi - lo;
    if (range <= 0) return;

    const sGrad = state.volumeCtx.createLinearGradient(0, height, 0, 0);
    sGrad.addColorStop(0, '#1a237e');
    sGrad.addColorStop(0.5, '#5c6bc0');
    sGrad.addColorStop(0.8, '#ce93d8');
    sGrad.addColorStop(1, '#f48fb1');

    state.volumeCtx.fillStyle = sGrad;
    const barW = Math.ceil(barWidth) + 0.5;

    for (let i = 0; i < state.volumeData.length; i++) {
      const s = i < state.spectralData.length ? state.spectralData[i] : null;
      if (!s || s.flatness === undefined) continue;

      const t = Math.max(0, Math.min(1, (s.flatness - lo) / range));
      const alpha = (1 - t) * 0.7;
      if (alpha < 0.03) continue;

      const val = state.isRelativeVolumeMode ? state.volumeData[i] / maxVolume : state.volumeData[i];
      const barHeight = val * height;

      state.volumeCtx.globalAlpha = alpha;
      state.volumeCtx.fillRect(i * barWidth, height - barHeight, barW, barHeight);
    }

    state.volumeCtx.globalAlpha = 1;
  }

  function drawVolumeGraph() {
    if (!state.volumeCtx || !state.volumeCanvas) return;

    const width = state.volumeCanvas.width / window.devicePixelRatio;
    const height = state.volumeCanvas.height / window.devicePixelRatio;

    state.volumeCtx.clearRect(0, 0, width, height);

    if (state.volumeData.length === 0) {
      state.volumeCtx.fillStyle = '#333';
      state.volumeCtx.fillRect(0, 0, width, height);
      state.volumeCtx.fillStyle = '#666';
      state.volumeCtx.font = '11px sans-serif';
      state.volumeCtx.textAlign = 'center';
      state.volumeCtx.fillText('再生またはスキャンで音量データを収集', width / 2, height / 2 + 4);
      return;
    }

    state.volumeCtx.fillStyle = '#1a1a1a';
    state.volumeCtx.fillRect(0, 0, width, height);

    let maxVolume = 1;
    if (state.isRelativeVolumeMode) {
      maxVolume = Math.max(...state.volumeData.filter(v => v > 0)) || 1;
    }

    const gradient = state.volumeCtx.createLinearGradient(0, height, 0, 0);
    gradient.addColorStop(0, '#1b5e20');
    gradient.addColorStop(0.5, '#4caf50');
    gradient.addColorStop(0.8, '#ffeb3b');
    gradient.addColorStop(1, '#f44336');

    state.volumeCtx.fillStyle = gradient;
    state.volumeCtx.beginPath();
    state.volumeCtx.moveTo(0, height);

    const barWidth = width / state.volumeData.length;
    for (let i = 0; i < state.volumeData.length; i++) {
      const normalizedValue = state.isRelativeVolumeMode ? state.volumeData[i] / maxVolume : state.volumeData[i];
      const barHeight = normalizedValue * height;
      const x = i * barWidth;
      state.volumeCtx.lineTo(x, height - barHeight);
    }

    state.volumeCtx.lineTo(width, height);
    state.volumeCtx.closePath();
    state.volumeCtx.fill();

    drawSingingOverlay(height, barWidth, maxVolume);

    // 中心線
    state.volumeCtx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
    state.volumeCtx.lineWidth = 1;
    state.volumeCtx.beginPath();
    state.volumeCtx.moveTo(0, height / 2);
    state.volumeCtx.lineTo(width, height / 2);
    state.volumeCtx.stroke();

    drawTimestampMarkers(width, height);
  }

  function drawTimestampMarkers(width, height) {
    if (!state.volumeCtx || !state.videoDuration) return;

    for (const marker of state.tsMarkers) {
      const x = (marker.time / state.videoDuration) * width;
      const isSelected = marker.id === state.selectedMarkerId;

      state.volumeCtx.strokeStyle = isSelected ? '#4caf50' : '#ffd54f';
      state.volumeCtx.lineWidth = isSelected ? 2.5 : 1.5;
      state.volumeCtx.setLineDash([]);
      state.volumeCtx.beginPath();
      state.volumeCtx.moveTo(x, 0);
      state.volumeCtx.lineTo(x, height);
      state.volumeCtx.stroke();

      // 三角マーカー（上部）
      state.volumeCtx.fillStyle = isSelected ? '#4caf50' : '#ffd54f';
      state.volumeCtx.beginPath();
      state.volumeCtx.moveTo(x, 0);
      state.volumeCtx.lineTo(x - 5, 10);
      state.volumeCtx.lineTo(x + 5, 10);
      state.volumeCtx.closePath();
      state.volumeCtx.fill();
    }
  }

  function createEmbeddedTriggerButton() {
    if (state.embeddedTriggerButton) return;

    // ボタンコンテナを作成
    const buttonContainer = document.createElement('div');
    buttonContainer.id = 'ycs-button-container';
    buttonContainer.innerHTML = `
    <style>
      #ycs-button-container {
        position: fixed;
        bottom: 80px;
        right: 16px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 8px;
      }
      #ycs-button-container.hidden {
        display: none !important;
      }
      .ycs-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: 12px;
        font-weight: bold;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .ycs-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 16px rgba(0,0,0,0.4);
      }
      .ycs-btn.active {
        background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
      }
      #ycs-list-btn {
        font-size: 16px;
      }
      #ycs-chat-btn {
        font-size: 14px;
      }
      #ycs-subtitle-btn {
        font-size: 14px;
      }
      #ycs-highlight-btn {
        font-size: 14px;
      }
    </style>
    <button class="ycs-btn" id="ycs-trigger-btn" title="タイムスタンプ検出グラフを表示/非表示">YCS</button>
    ${state.edition !== 'general' ? `
    <button class="ycs-btn" id="ycs-list-btn" title="リストスキャンパネルを開く">☰</button>
    <button class="ycs-btn" id="ycs-chat-btn" title="チャット検索パネルを開く">💬</button>
    <button class="ycs-btn" id="ycs-subtitle-btn" title="字幕取得パネルを開く">📝</button>
    <button class="ycs-btn" id="ycs-highlight-btn" title="ハイライト検出パネルを開く">✨</button>
    ` : ''}
  `;

    document.body.appendChild(buttonContainer);
    state.embeddedTriggerButton = buttonContainer;

    // YCSボタンのイベント
    buttonContainer.querySelector('#ycs-trigger-btn').addEventListener('click', () => {
      toggleEmbeddedUI();
    });

    if (state.edition !== 'general') {
      // リストボタンのイベント
      buttonContainer.querySelector('#ycs-list-btn')?.addEventListener('click', () => {
      });

      // チャット検索ボタンのイベント
      buttonContainer.querySelector('#ycs-chat-btn')?.addEventListener('click', () => {
      });

      // 字幕取得ボタンのイベント
      buttonContainer.querySelector('#ycs-subtitle-btn')?.addEventListener('click', () => {
      });

      // ハイライト検出ボタンのイベント
      buttonContainer.querySelector('#ycs-highlight-btn')?.addEventListener('click', () => {
      });
    }

    updateTriggerButtonState();
  }

  function toggleEmbeddedUI() {
    if (state.volumeGraphContainer) {
      const isVisible = state.volumeGraphContainer.classList.contains('visible');
      if (isVisible) {
        hideVolumeGraphPanel();
      } else {
        state.volumeGraphContainer.classList.add('visible');
        state.isGraphVisible = true;
        updateVideoDuration();
        resizeCanvas();
      }
      updateTriggerButtonState();
    }
  }

  function updateTriggerButtonState() {
    if (!state.embeddedTriggerButton) return;

    const ycsBtn = state.embeddedTriggerButton.querySelector('#ycs-trigger-btn');
    if (ycsBtn) {
      if (state.isGraphVisible) {
        ycsBtn.classList.add('active');
      } else {
        ycsBtn.classList.remove('active');
      }
    }

    if (state.edition !== 'general') {
      const listBtn = state.embeddedTriggerButton.querySelector('#ycs-list-btn');
      if (listBtn) {
        {
          listBtn.classList.remove('active');
        }
      }

      const chatBtn = state.embeddedTriggerButton.querySelector('#ycs-chat-btn');
      if (chatBtn) {
        chatBtn.classList.toggle('active', isChatSearchPanelVisible());
      }

      const subtitleBtn = state.embeddedTriggerButton.querySelector('#ycs-subtitle-btn');
      if (subtitleBtn) {
        subtitleBtn.classList.toggle('active', isSubtitlePanelVisible());
      }

      const highlightBtn = state.embeddedTriggerButton.querySelector('#ycs-highlight-btn');
      if (highlightBtn) {
        highlightBtn.classList.toggle('active', isHighlightPanelVisible());
      }
    }
  }

  function showEmbeddedUI() {
    state.embeddedUIVisible = true;
    if (state.embeddedTriggerButton) {
      state.embeddedTriggerButton.classList.remove('hidden');
    }
  }

  // ペースト変換ポップアップのリスナー残留を防ぐため、非表示化は必ずここを経由すること
  function hideVolumeGraphPanel() {
    closeLyricsPastePopup();
    closeSongCandidatePopup();
    if (state.volumeGraphContainer) {
      state.volumeGraphContainer.classList.remove('visible');
    }
    state.isGraphVisible = false;
  }

  function hideEmbeddedUI() {
    state.embeddedUIVisible = false;
    if (state.embeddedTriggerButton) {
      state.embeddedTriggerButton.classList.add('hidden');
    }
    // グラフも非表示
    hideVolumeGraphPanel();
  }

  function handleMessage(message, sender, sendResponse) {
    switch (message.type) {
      case 'PING':
        sendResponse('PONG');
        return true;

      case 'SHOW_EMBEDDED_UI':
        showEmbeddedUI();
        sendResponse({ success: true });
        return true;

      case 'HIDE_EMBEDDED_UI':
        hideEmbeddedUI();
        sendResponse({ success: true });
        return true;

      case 'TIMESTAMP_DETECTED':
        state.detectedTimestamps.push(message.timestamp);
        drawVolumeGraph();
        break;

      case 'SHOW_VOLUME_GRAPH':
        if (state.volumeGraphContainer) {
          state.volumeGraphContainer.classList.add('visible');
          state.isGraphVisible = true;
          updateVideoDuration();
          resizeCanvas();
        } else {
          console.log('volumeGraphContainer が存在しません');
        }
        break;

      case 'HIDE_VOLUME_GRAPH':
        hideVolumeGraphPanel();
        break;

      case 'TOGGLE_VOLUME_GRAPH':
        console.log('TOGGLE_VOLUME_GRAPH 受信', { volumeGraphContainer: !!state.volumeGraphContainer, inDOM: state.volumeGraphContainer?.parentNode });
        if (state.volumeGraphContainer) {
          if (!state.volumeGraphContainer.parentNode) {
            console.log('グラフがDOMに存在しないため再挿入を試みます');
            insertVolumeGraph();
          }
          state.volumeGraphContainer.classList.toggle('visible');
          state.isGraphVisible = state.volumeGraphContainer.classList.contains('visible');
          if (!state.isGraphVisible) {
            closeLyricsPastePopup();
            closeSongCandidatePopup();
          }
          const computed = getComputedStyle(state.volumeGraphContainer);
          console.log('グラフ表示状態:', state.isGraphVisible, {
            classList: state.volumeGraphContainer.className,
            computedDisplay: computed.display,
            computedVisibility: computed.visibility,
            computedOpacity: computed.opacity,
            computedHeight: computed.height,
            computedWidth: computed.width,
            boundingRect: state.volumeGraphContainer.getBoundingClientRect()
          });
          if (state.isGraphVisible) {
            updateVideoDuration();
            resizeCanvas();
          }
        } else {
          console.log('volumeGraphContainer が存在しません。createVolumeGraph を呼び出します');
          createVolumeGraph();
          if (state.volumeGraphContainer) {
            state.volumeGraphContainer.classList.add('visible');
            state.isGraphVisible = true;
            updateVideoDuration();
            resizeCanvas();
          }
        }
        break;

      case 'VOLUME_DATA_UPDATE':
        if (!state.backgroundScanVideoId || state.backgroundScanVideoId !== getVideoId()) {
          break;
        }
        state.volumeData = message.data;
        if (message.spectral) state.spectralData = message.spectral;
        updateProgress(message.progress || 0);
        drawVolumeGraph();
        {
          const now = Date.now();
          if (now - state.lastSaveTime >= SAVE_INTERVAL && state.volumeData.some(v => v > 0)) {
            state.lastSaveTime = now;
            saveVolumeData();
            console.log('スキャン中: 音量データを自動保存');
          }
        }
        break;

      case 'SCAN_STARTED':
        state.backgroundScanVideoId = getVideoId();
        if (state.volumeGraphContainer) {
          const scanBtn = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
          if (scanBtn) {
            scanBtn.classList.add('scanning');
            scanBtn.textContent = '停止';
          }
          hidePermissionError();
        }
        break;

      case 'SCAN_PERMISSION_ERROR':
        showPermissionError();
        break;

      case 'SCAN_STOPPED':
        state.backgroundScanVideoId = null;
        if (state.volumeGraphContainer) {
          const scanBtnStop = state.volumeGraphContainer.querySelector('#vdg-scan-btn');
          if (scanBtnStop) {
            scanBtnStop.classList.remove('scanning');
          }
        }
        if (state.volumeData.length > 0) {
          saveVolumeData();
          sendSpectralDataToServer();
        }
        getScanStatus().then(status => {
          updateScanButtonState(status);
        });
        if (state.isAutoScanMode && !state.autoScanStopRequested) {
          proceedToNextVideoOrFinish();
        }
        break;

      case 'GET_VIDEO_INFO':
        updateVideoDuration();
        sendResponse({
          duration: state.videoDuration,
          currentTime: state.videoElement?.currentTime || 0
        });
        return true;

      case 'GET_SCAN_STATUS':
        getScanStatus().then(status => sendResponse(status));
        return true;

      case 'SEEK_VIDEO':
        if (state.videoElement) {
          state.videoElement.currentTime = message.time;
        }
        break;

      case 'SET_PLAYBACK_RATE':
        if (state.videoElement) {
          state.videoElement.playbackRate = message.rate;
        }
        break;

      case 'SET_MUTED':
        if (state.videoElement) {
          state.videoElement.muted = message.muted;
        }
        break;

      case 'PLAY_VIDEO':
        if (state.videoElement) {
          state.videoElement.play();
        }
        break;

      case 'PAUSE_VIDEO':
        if (state.videoElement) {
          state.videoElement.pause();
        }
        break;
    }
  }

  function handleStorageChange(changes, areaName) {
    if (areaName !== 'local') return;

    if (changes.graphBaseHeight || changes.graphHeightStep) {
      if (changes.graphBaseHeight) {
        state.graphBaseHeightPx = clampGraphHeightValue(
          changes.graphBaseHeight.newValue, DEFAULT_GRAPH_BASE_HEIGHT_PX, GRAPH_BASE_HEIGHT_RANGE);
      }
      if (changes.graphHeightStep) {
        state.graphHeightStepPx = clampGraphHeightValue(
          changes.graphHeightStep.newValue, DEFAULT_GRAPH_HEIGHT_STEP_PX, GRAPH_HEIGHT_STEP_RANGE);
      }
      if (state.volumeGraphContainer) {
        resizeCanvas();
      }
    }

    if (changes.showEmbeddedUI) {
      const showUI = changes.showEmbeddedUI.newValue !== false;
      if (showUI) {
        showEmbeddedUI();
      } else {
        hideEmbeddedUI();
      }
    }

    const videoId = getVideoId();
    if (!videoId) return;

    const storageKey = `volumeData_${videoId}`;

    if (changes[storageKey]) {
      const newData = changes[storageKey].newValue;

      if (!newData) {
        if (state.isScanning) return;
        console.log(`音量データが削除されたためグラフをリセットします: ${videoId}`);
        state.volumeData = [];
        state.spectralData = [];
        state.detectedTimestamps = [];
        drawVolumeGraph();
        updateProgress(0);
        getScanStatus().then(status => updateScanButtonState(status));
        return;
      }

      if (newData && newData.data && newData.data.length > 0) {
        if (state.isScanning) return;

        console.log(`他タブからの音量データを受信: ${videoId}`);

        state.volumeData = newData.data;
        state.spectralData = newData.spectral || new Array(newData.data.length).fill(null);
        if (newData.duration) {
          state.videoDuration = newData.duration;
        }

        if (newData.timestamps) {
          state.detectedTimestamps = newData.timestamps;
        }

        drawVolumeGraph();

        const progress = state.volumeGraphContainer?.querySelector('#vdg-progress');
        if (progress && state.videoDuration > 0) {
          const coverage = state.volumeData.length > 0
            ? (state.volumeData.filter(v => v > 0).length / state.volumeData.length) * 100
            : 0;
          progress.textContent = `分析 ${Math.round(coverage)}%`;
        }
      }
    }
  }

  state.edition = 'general';

  function initWatchPageUI() {
    findVideoElement();
    createVolumeGraph();
    createEmbeddedTriggerButton();

    if (state.embeddedUIVisible) {
      showEmbeddedUI();
    } else {
      hideEmbeddedUI();
    }

    insertVolumeGraph();
  }

  function hideWatchPageUI() {
    if (state.embeddedTriggerButton) {
      state.embeddedTriggerButton.classList.add('hidden');
    }
    hideVolumeGraphPanel();

    if (state.isScanning) {
      stopDirectScan();
      chrome.runtime.sendMessage({ type: 'STOP_SCAN' });
    }
  }

  function observePageChanges() {
    let lastUrl = location.href;
    let lastVideoId = getVideoId();
    let wasWatchPage = isWatchPage();

    function handleNavigation() {
      const currentUrl = location.href;
      if (currentUrl === lastUrl) return;

      lastUrl = currentUrl;
      const nowWatchPage = isWatchPage();
      const currentVideoId = getVideoId();

      if (nowWatchPage && !wasWatchPage) {
        lastVideoId = currentVideoId;

        state.volumeData = [];
        state.spectralData = [];
        state.videoDuration = 0;
        state.backgroundScanVideoId = null;
        state.detectedTimestamps = [];
        state.zoomIndex = 0;
        state.currentSubtitles = [];
        state.currentCaptionTracks = [];
        if (state.mediaElementSource) {
          state.mediaElementSource.disconnect();
          state.mediaElementSource = null;
        }
        state.analyserNode = null;
        state.gainNode = null;
        state.audioInitialized = false;

        initWatchPageUI();
        loadVolumeData();
        resetTimestampEditorForVideoChange();
      } else if (nowWatchPage && currentVideoId !== lastVideoId) {
        lastVideoId = currentVideoId;
        state.volumeData = [];
        state.spectralData = [];
        state.videoDuration = 0;
        state.backgroundScanVideoId = null;
        state.detectedTimestamps = [];
        state.zoomIndex = 0;
        state.currentSubtitles = [];
        state.currentCaptionTracks = [];

        if (state.mediaElementSource) {
          state.mediaElementSource.disconnect();
          state.mediaElementSource = null;
        }
        state.analyserNode = null;
        state.gainNode = null;
        state.audioInitialized = false;

        drawVolumeGraph();
        findVideoElement();
        insertVolumeGraph();

        updatePlaylistUI();

        loadVolumeData();
        resetTimestampEditorForVideoChange();

        if (state.isAutoScanMode && !state.autoScanStopRequested) {
          console.log('自動スキャン継続: 新しい動画を検出');
          setTimeout(async () => {
            if (!state.isAutoScanMode || state.autoScanStopRequested) return;

            const alreadyScanned = await isCurrentVideoScanned();
            if (alreadyScanned) {
              console.log('この動画はスキャン済み、次へ');
              proceedToNextVideoOrFinish();
            } else {
              startDirectScan();
            }
          }, 3000);
        }
      } else if (!nowWatchPage && wasWatchPage) {
        hideWatchPageUI();
      }

      wasWatchPage = nowWatchPage;
    }

    document.addEventListener('yt-navigate-finish', handleNavigation);

    const observer = new MutationObserver(() => {
      if (location.href !== lastUrl) {
        handleNavigation();
      }
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  async function init() {
    chrome.runtime.onMessage.addListener(handleMessage);
    chrome.storage.onChanged.addListener(handleStorageChange);

    try {
      await loadEmbeddedUISettings();
      await loadGraphHeightSettings();

      if (isWatchPage()) {
        initWatchPageUI();
      }

      observePageChanges();
    } catch (error) {
      console.error('Content script初期化エラー:', error);
    }
  }

  window.addEventListener('beforeunload', () => {
    if (state.timeUpdateInterval) {
      clearInterval(state.timeUpdateInterval);
    }
    if (state.volumeData.length > 0 && state.volumeData.some(v => v > 0)) {
      saveVolumeData();
      console.log('ページ遷移: 音量データを保存');
    }
  });

  init();

})();
