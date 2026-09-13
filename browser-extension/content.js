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

  const LIST_SCAN_AUTO_CLICK_DELAY = 3000;

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

  const EMOJI_SHORTCODE_RE = /:[a-zA-Z0-9_]+:/g;

  const CHAT_ONLY_CONFIG = {
    BUCKET_SEC: 10,
    MIN_CHATS: 50,
    SMOOTH_WINDOW_BUCKETS: 5,
    EMOJI_RATIO_ENTER: 0.4,
    EMOJI_RATIO_EXIT: 0.15,
    EXIT_TOLERANCE_BUCKETS: 3,
    MIN_SEGMENT_SEC: 45,
    MERGE_GAP_SEC: 90,
    MIN_WINDOW_MESSAGES: 10,
    FIRST_SONG_MIN_OFFSET_SEC: 120,
    NEAR_SEGMENT_TOLERANCE_SEC: 30,
    TAIL_GUARD_SEC: 60,
    REACTION_DELAY_SEC: 10,
  };

  const CHAT_DB_NAME = 'YCSChatDB';
  const CHAT_DB_VERSION = 1;
  const CHAT_STORE_NAME = 'chats';
  const CHAT_MAX_AGE_DAYS = 30;

  const HIGHLIGHT_DB_NAME = 'YCSHighlightDB';
  const HIGHLIGHT_DB_VERSION = 1;
  const HIGHLIGHT_STORE_NAME = 'highlights';
  const HIGHLIGHT_MAX_AGE_DAYS = 90;

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

  function formatTimestampMsec(msec) {
    const totalSeconds = Math.floor(msec / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
      return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function formatSubtitleTime(totalSeconds) {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    if (h > 0) {
      return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m}:${s.toString().padStart(2, '0')}`;
  }

  const subtitleSentCache = new Set();
  const subtitleSendInFlight = new Map();

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

  function isExtensionContextValid() {
    try {
      return !!chrome.runtime?.id;
    } catch (e) {
      return false;
    }
  }

  function missingTokenMessage() {
    return isExtensionContextValid()
      ? 'APIトークンが未設定です。プロフィール画面で発行し、拡張の設定に登録してください'
      : '拡張機能が更新されました。ページを再読み込みしてください';
  }

  async function sendSubtitlesToServer(videoId, lang, subtitles) {
    // 選択中のトラックからkindを判定
    const selectEl = document.querySelector('#stp-lang-select');
    const selectedOption = selectEl?.selectedOptions?.[0];
    const selectedLang = selectedOption?.dataset?.lang || lang;
    const selectedTrack = state.currentCaptionTracks.find(t => t.languageCode === selectedLang);
    const kind = selectedTrack?.kind === 'asr' ? 'asr' : '';

    try {
      await postSubtitlesToServer(videoId, selectedLang, kind, subtitles);
    } catch (error) {
      console.warn('[YCS] 字幕データ送信エラー:', error.message);
    }
  }

  async function postSubtitlesToServer(videoId, languageCode, kind, subtitles) {
    if (!videoId || !subtitles || subtitles.length === 0) return;

    // 重複送信防止
    const cacheKey = `${videoId}_${languageCode}_${kind}`;
    if (subtitleSentCache.has(cacheKey)) return;

    // 送信中に再トリガーされた場合は実行中のPromiseを返す
    const inFlight = subtitleSendInFlight.get(cacheKey);
    if (inFlight) return inFlight;

    const sendPromise = (async () => {
      if (!state.ycsApiToken) {
        await loadYcsApiSettings();
      }
      if (!state.ycsApiToken) {
        throw new Error(missingTokenMessage());
      }

      const response = await fetch(`${state.ycsServerUrl}/api/manage/archives/subtitles/store`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${state.ycsApiToken}`,
        },
        body: JSON.stringify({
          video_id: videoId,
          language_code: languageCode,
          kind: kind,
          subtitles: subtitles.map(s => ({
            start: s.start,
            duration: s.duration,
            text: s.text,
          })),
        }),
      });

      if (!response.ok) {
        console.warn(`[YCS] 字幕データ送信失敗: ${response.status}`);
        throw new Error(`字幕データの送信に失敗しました (${response.status})`);
      }

      subtitleSentCache.add(cacheKey);
      const data = await response.json();
      console.log(`[YCS] 字幕データ送信成功: ${videoId} (${data.segment_count}セグメント, FP: ${data.fingerprints_generated}件)`);
    })();

    subtitleSendInFlight.set(cacheKey, sendPromise);
    try {
      return await sendPromise;
    } finally {
      subtitleSendInFlight.delete(cacheKey);
    }
  }

  let highlightPanel = null;
  let highlightPanelVisible = false;
  let highlightDB = null;
  let highlightTooltipHideTimer = null;

  function toggleHighlightPanel() {
    if (highlightPanelVisible) {
      hideHighlightPanel();
    } else {
      showHighlightPanel();
    }
  }

  function showHighlightPanel() {
    if (isChatSearchPanelVisible()) hideChatSearchPanel();
    if (isSubtitlePanelVisible()) hideSubtitlePanel();

    if (!highlightPanel) {
      createHighlightPanel();
    }
    highlightPanel.classList.add('visible');
    highlightPanelVisible = true;
    updateTriggerButtonState();
    updateHighlightDataStatus();
    // 保存済みのハイライト検出結果を自動復元
    restoreSavedHighlightResult();
  }

  async function restoreSavedHighlightResult() {
    const videoId = getVideoId();
    if (!videoId) return;
    const resultsEl = highlightPanel?.querySelector('#hlp-results');
    if (!resultsEl) return;
    // 既に結果カードが表示されている場合はスキップ（再検出後の状態を維持）
    if (resultsEl.querySelector('.hlp-result-item')) return;

    const saved = await loadHighlightResult(videoId);
    if (saved && Array.isArray(saved.candidates) && saved.candidates.length > 0) {
      renderHighlightResults(saved.candidates);
      const savedDate = new Date(saved.savedAt).toLocaleString('ja-JP');
      setHighlightStatus(`保存済みの検出結果を表示中（${savedDate}）`, false);
    }
  }

  function hideHighlightPanel() {
    if (highlightPanel) {
      highlightPanel.classList.remove('visible');
    }
    hideHighlightSubtitleTooltip();
    highlightPanelVisible = false;
    updateTriggerButtonState();
  }

  function isHighlightPanelVisible() {
    return highlightPanelVisible;
  }

  function createHighlightPanel() {
    if (highlightPanel) return;

    highlightPanel = document.createElement('div');
    highlightPanel.id = 'ycs-highlight-panel';
    highlightPanel.innerHTML = `
    <style>
      #ycs-highlight-panel {
        position: fixed;
        bottom: 140px;
        right: 70px;
        z-index: 9997;
        width: 420px;
        max-height: 600px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-highlight-panel.visible {
        display: flex !important;
      }
      .hlp-header {
        padding: 12px 16px;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .hlp-header-title {
        font-weight: 600;
        font-size: 14px;
      }
      .hlp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
      }
      .hlp-close-btn:hover { background: rgba(255,255,255,0.35); }
      .hlp-body {
        padding: 12px 16px;
        overflow-y: auto;
        flex: 1;
      }
      .hlp-data-status {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 12px;
        font-size: 12px;
        background: rgba(255,255,255,0.05);
        padding: 8px 12px;
        border-radius: 6px;
        margin-bottom: 12px;
      }
      .hlp-data-status .label { color: #aaa; }
      .hlp-data-status .ok { color: #4ade80; }
      .hlp-data-status .ng { color: #f87171; }
      .hlp-detect-btn {
        width: 100%;
        padding: 10px;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        margin-bottom: 12px;
      }
      .hlp-detect-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
      .hlp-detect-btn:not(:disabled):hover { filter: brightness(1.1); }
      .hlp-status {
        font-size: 12px;
        color: #aaa;
        min-height: 16px;
        margin-bottom: 8px;
      }
      .hlp-status.error { color: #f87171; }
      .hlp-status.loading::after {
        content: '...';
        animation: hlp-blink 1s infinite;
      }
      @keyframes hlp-blink {
        0%, 50% { opacity: 1; }
        51%, 100% { opacity: 0.3; }
      }
      .hlp-results {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .hlp-result-item {
        background: rgba(255,255,255,0.06);
        border-radius: 6px;
        padding: 8px 10px;
        cursor: pointer;
        border-left: 3px solid #f59e0b;
        transition: background 0.15s;
      }
      .hlp-result-item:hover { background: rgba(255,255,255,0.12); }
      .hlp-result-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
      }
      .hlp-result-time {
        color: #fbbf24;
        font-family: monospace;
        font-weight: 600;
      }
      .hlp-result-type {
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 4px;
        background: rgba(255,255,255,0.1);
        color: #ddd;
      }
      .hlp-result-confidence {
        font-size: 11px;
        color: #a3e635;
      }
      .hlp-result-label {
        font-size: 13px;
        color: #fff;
        margin-bottom: 3px;
        word-break: break-word;
      }
      .hlp-result-reason {
        font-size: 11px;
        color: #aaa;
        word-break: break-word;
      }
      .hlp-empty {
        color: #888;
        text-align: center;
        padding: 20px 0;
        font-size: 12px;
      }
      #ycs-highlight-subtitle-tooltip {
        position: fixed;
        z-index: 9998;
        max-width: 360px;
        max-height: 320px;
        overflow-y: auto;
        background: rgba(15, 15, 15, 0.96);
        border: 1px solid #444;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.5);
        padding: 8px 10px;
        font-size: 11px;
        color: #ddd;
        display: none;
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
      }
      #ycs-highlight-subtitle-tooltip.visible { display: block; }
      .hlp-tooltip-title {
        font-size: 11px;
        color: #aaa;
        margin-bottom: 6px;
        padding-bottom: 4px;
        border-bottom: 1px solid #333;
      }
      .hlp-tooltip-line {
        display: flex;
        gap: 6px;
        padding: 2px 0;
        line-height: 1.4;
      }
      .hlp-tooltip-line.in-range {
        color: #fff;
        font-weight: 600;
      }
      .hlp-tooltip-time {
        flex-shrink: 0;
        color: #4fc3f7;
        font-family: monospace;
        min-width: 44px;
      }
      .hlp-tooltip-text {
        word-break: break-word;
      }
      .hlp-tooltip-empty {
        color: #777;
        font-style: italic;
        padding: 4px 0;
      }
    </style>
    <div class="hlp-header">
      <span class="hlp-header-title">✨ ハイライト検出 (AI)</span>
      <button class="hlp-close-btn" title="閉じる">×</button>
    </div>
    <div class="hlp-body">
      <div class="hlp-data-status" id="hlp-data-status">
        <span class="label">音量:</span><span id="hlp-status-volumes" class="ng">未取得</span>
        <span class="label">字幕:</span><span id="hlp-status-subtitles" class="ng">未取得</span>
        <span class="label">コメント:</span><span id="hlp-status-chats" class="ng">未取得</span>
        <span class="label">動画長:</span><span id="hlp-status-duration" class="ng">不明</span>
      </div>
      <button class="hlp-detect-btn" id="hlp-detect-btn">AIに送信してハイライトを検出</button>
      <div class="hlp-status" id="hlp-status"></div>
      <div class="hlp-results" id="hlp-results">
        <div class="hlp-empty">まだ検出していません</div>
      </div>
    </div>
  `;
    document.body.appendChild(highlightPanel);

    highlightPanel.querySelector('.hlp-close-btn').addEventListener('click', () => {
      hideHighlightPanel();
    });
    highlightPanel.querySelector('#hlp-detect-btn').addEventListener('click', () => {
      detectHighlights();
    });
  }

  function updateHighlightDataStatus() {
    if (!highlightPanel) return;
    const setStatus = (id, statusState, text) => {
      const el = highlightPanel.querySelector(`#${id}`);
      if (!el) return;
      el.textContent = text;
      el.className = statusState === 'ok' ? 'ok' : statusState === 'ng' ? 'ng' : '';
    };

    setStatus(
      'hlp-status-volumes',
      state.volumeData.length > 0 ? 'ok' : 'ng',
      state.volumeData.length > 0 ? `${state.volumeData.length}サンプル` : '未取得（スキャン実行が必要）'
    );
    setStatus(
      'hlp-status-subtitles',
      state.currentSubtitles.length > 0 ? 'ok' : 'ng',
      state.currentSubtitles.length > 0 ? `${state.currentSubtitles.length}件` : '未取得（字幕パネルで取得）'
    );
    // コメント件数をIndexedDBから非同期で取得して表示
    // loadChatDataForVideo()はチャット検索パネルのステータスを副作用で更新するため、
    // ここでは件数のカウントのみを行う専用処理を使う
    (async () => {
      try {
        const videoId = getVideoId();
        if (!videoId) {
          setStatus('hlp-status-chats', 'ng', '動画ID不明');
          return;
        }
        const chatDB = await initChatDB();
        const count = await new Promise((resolve, reject) => {
          const tx = chatDB.transaction([CHAT_STORE_NAME], 'readonly');
          const req = tx.objectStore(CHAT_STORE_NAME).index('videoId').count(videoId);
          req.onsuccess = () => resolve(req.result || 0);
          req.onerror = () => reject(req.error);
        });
        if (count > 0) {
          setStatus('hlp-status-chats', 'ok', `${count}件（取得済み）`);
        } else {
          setStatus('hlp-status-chats', 'ng', '未取得（💬ボタンで取得）');
        }
      } catch (e) {
        setStatus('hlp-status-chats', 'ng', '確認失敗');
      }
    })();
    setStatus(
      'hlp-status-duration',
      state.videoDuration > 0 ? 'ok' : 'ng',
      state.videoDuration > 0 ? `${Math.round(state.videoDuration)}秒` : '不明'
    );

    // 検出ボタンの活性化条件: 動画長 > 0
    const detectBtn = highlightPanel.querySelector('#hlp-detect-btn');
    if (detectBtn) {
      detectBtn.disabled = !(state.videoDuration > 0);
    }
  }

  async function detectHighlights() {
    const statusEl = highlightPanel?.querySelector('#hlp-status');
    const detectBtn = highlightPanel?.querySelector('#hlp-detect-btn');
    const resultsEl = highlightPanel?.querySelector('#hlp-results');

    if (!statusEl || !detectBtn || !resultsEl) return;
    // ダブルクリック・連打による多重実行を防止
    if (detectBtn.disabled) return;
    detectBtn.disabled = true;

    try {
      const videoId = getVideoId();
      if (!videoId) {
        setHighlightStatus('動画IDが取得できません', true);
        return;
      }
      if (!state.videoDuration || state.videoDuration <= 0) {
        setHighlightStatus('動画長が取得できていません。動画を少し再生してください', true);
        return;
      }

      // API設定を読み込み
      if (!state.ycsApiToken) {
        await loadYcsApiSettings();
      }
      if (!state.ycsApiToken) {
        setHighlightStatus(missingTokenMessage(), true);
        return;
      }

      setHighlightStatus('データを収集中', false, true);

      // チャットデータをIndexedDBから取得
      let rawChats = [];
      try {
        await initChatDB();
        rawChats = await loadChatDataForVideo(videoId);
      } catch (e) {
        console.warn('[YCS Highlight] チャットDB読込失敗:', e);
      }
      const chats = (rawChats || []).map(c => ({
        offsetMs: c.timestamp,
        message: c.message,
        isSuperchat: !!c.isSuperchat,
      })).filter(c => Number.isFinite(c.offsetMs) && typeof c.message === 'string' && c.message.length > 0);

      const subtitlesPayload = (state.currentSubtitles || []).map(s => ({
        start: Number(s.start) || 0,
        // サーバーバリデーション (max:60) に合わせてクランプ
        duration: Math.min(60, Math.max(0, Number(s.duration) || 0)),
        text: String(s.text ?? ''),
      })).filter(s => s.text.length > 0);

      const volumesPayload = (state.volumeData || []).map(v => {
        if (typeof v === 'number') return v;
        // 万一オブジェクトで保存されていた場合のフォールバック
        return Number.isFinite(v?.value) ? v.value : 0;
      });

      if (volumesPayload.length === 0 && subtitlesPayload.length === 0 && chats.length === 0) {
        setHighlightStatus('音量・字幕・コメントのいずれも未取得です', true);
        return;
      }

      setHighlightStatus(
        `送信中 (音量${volumesPayload.length} / 字幕${subtitlesPayload.length} / コメント${chats.length})`,
        false,
        true
      );

      const response = await fetch(`${state.ycsServerUrl}/api/extension/highlights/detect`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'Authorization': `Bearer ${state.ycsApiToken}`,
        },
        body: JSON.stringify({
          video_id: videoId,
          duration: state.videoDuration,
          volumes: volumesPayload,
          subtitles: subtitlesPayload,
          chats: chats,
        }),
      });

      if (!response.ok) {
        let errorMsg = `HTTP ${response.status}`;
        try {
          const errBody = await response.json();
          if (errBody?.message) errorMsg += `: ${errBody.message}`;
        } catch (_) { /* ignore */ }
        throw new Error(errorMsg);
      }

      const data = await response.json();
      const candidates = data?.candidates || [];
      renderHighlightResults(candidates);
      setHighlightStatus(`${candidates.length}件の候補を検出しました`, false);
      // 自動保存（次回パネルを開いた時に自動復元される）
      saveHighlightResult(videoId, candidates);
    } catch (error) {
      console.error('[YCS Highlight] 検出エラー:', error);
      setHighlightStatus(`検出に失敗しました: ${error.message}`, true);
    } finally {
      detectBtn.disabled = false;
    }
  }

  function setHighlightStatus(text, isError, isLoading) {
    const statusEl = highlightPanel?.querySelector('#hlp-status');
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.classList.toggle('error', !!isError);
    statusEl.classList.toggle('loading', !!isLoading);
  }

  function getOrCreateHighlightTooltip() {
    let tooltip = document.getElementById('ycs-highlight-subtitle-tooltip');
    if (!tooltip) {
      tooltip = document.createElement('div');
      tooltip.id = 'ycs-highlight-subtitle-tooltip';
      document.body.appendChild(tooltip);
      // ツールチップ上にマウスがある間は非表示タイマーをキャンセル
      tooltip.addEventListener('mouseenter', () => {
        if (highlightTooltipHideTimer) {
          clearTimeout(highlightTooltipHideTimer);
          highlightTooltipHideTimer = null;
        }
      });
      // ツールチップから離れたら非表示
      tooltip.addEventListener('mouseleave', () => {
        hideHighlightSubtitleTooltip();
      });
    }
    return tooltip;
  }

  function getSubtitlesAround(startSec, endSec, marginSec = 30) {
    if (!Array.isArray(state.currentSubtitles) || state.currentSubtitles.length === 0) return [];
    const fromSec = Math.max(0, startSec - marginSec);
    const toSec = endSec + marginSec;
    const result = [];
    for (const sub of state.currentSubtitles) {
      const subStart = Number(sub.start) || 0;
      const subEnd = subStart + (Number(sub.duration) || 0);
      // 字幕区間が表示範囲と重なるか
      if (subEnd >= fromSec && subStart <= toSec) {
        const inRange = subStart <= endSec && subEnd >= startSec;
        result.push({
          start: subStart,
          duration: Number(sub.duration) || 0,
          text: String(sub.text || ''),
          inRange,
        });
      }
    }
    return result;
  }

  function showHighlightSubtitleTooltip(candidate, anchorEl) {
    const tooltip = getOrCreateHighlightTooltip();
    // 表示要求が来たら消失タイマーはキャンセル
    if (highlightTooltipHideTimer) {
      clearTimeout(highlightTooltipHideTimer);
      highlightTooltipHideTimer = null;
    }
    const startSec = Number(candidate.time) || 0;
    const endSec = Number.isFinite(candidate.end_time) ? Number(candidate.end_time) : startSec;

    if (!Array.isArray(state.currentSubtitles) || state.currentSubtitles.length === 0) {
      tooltip.innerHTML = `
      <div class="hlp-tooltip-title">前後30秒の字幕</div>
      <div class="hlp-tooltip-empty">字幕が未取得です（📝ボタンで取得してください）</div>
    `;
    } else {
      const subs = getSubtitlesAround(startSec, endSec, 30);
      if (subs.length === 0) {
        tooltip.innerHTML = `
        <div class="hlp-tooltip-title">前後30秒の字幕</div>
        <div class="hlp-tooltip-empty">この区間に字幕はありません</div>
      `;
      } else {
        const lines = subs.map(s => {
          const timeStr = formatSubtitleTime(Math.floor(s.start));
          return `<div class="hlp-tooltip-line ${s.inRange ? 'in-range' : ''}">
          <span class="hlp-tooltip-time">${timeStr}</span>
          <span class="hlp-tooltip-text">${escapeHtml(s.text)}</span>
        </div>`;
        }).join('');
        tooltip.innerHTML = `
        <div class="hlp-tooltip-title">字幕（区間±30秒、太字は区間内）</div>
        ${lines}
      `;
      }
    }

    // 位置決め: カードの右側に配置、右が画面外ならカードの左側に
    const rect = anchorEl.getBoundingClientRect();
    const tooltipWidth = 360; // max-widthと一致
    const margin = 8;
    let left = rect.right + margin;
    if (left + tooltipWidth > window.innerWidth) {
      left = Math.max(8, rect.left - tooltipWidth - margin);
    }
    tooltip.style.left = `${left}px`;
    // 縦位置決定のために実高さが必要だが、ユーザーに位置決定途中が見えないよう
    // 一時的に visibility: hidden にしてレイアウトのみ走らせる
    tooltip.style.visibility = 'hidden';
    tooltip.style.top = '0px';
    tooltip.classList.add('visible');
    const tooltipHeight = tooltip.offsetHeight;
    const top = Math.min(
      Math.max(8, window.innerHeight - tooltipHeight - 8),
      Math.max(8, rect.top)
    );
    tooltip.style.top = `${top}px`;
    tooltip.style.visibility = '';
  }

  function hideHighlightSubtitleTooltip() {
    if (highlightTooltipHideTimer) {
      clearTimeout(highlightTooltipHideTimer);
      highlightTooltipHideTimer = null;
    }
    const tooltip = document.getElementById('ycs-highlight-subtitle-tooltip');
    if (tooltip) tooltip.classList.remove('visible');
  }

  // カードから離れた時に少し待ってからツールチップを非表示にする
  // （ツールチップ上にマウスが移動した場合は mouseenter でキャンセルされる）
  function scheduleHideHighlightSubtitleTooltip() {
    if (highlightTooltipHideTimer) {
      clearTimeout(highlightTooltipHideTimer);
    }
    highlightTooltipHideTimer = setTimeout(() => {
      hideHighlightSubtitleTooltip();
    }, 200);
  }

  function initHighlightDB() {
    return new Promise((resolve, reject) => {
      if (highlightDB) {
        resolve(highlightDB);
        return;
      }
      const request = indexedDB.open(HIGHLIGHT_DB_NAME, HIGHLIGHT_DB_VERSION);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        highlightDB = request.result;
        resolve(highlightDB);
      };
      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(HIGHLIGHT_STORE_NAME)) {
          const store = db.createObjectStore(HIGHLIGHT_STORE_NAME, { keyPath: 'videoId' });
          store.createIndex('savedAt', 'savedAt', { unique: false });
        }
      };
    });
  }

  async function saveHighlightResult(videoId, candidates) {
    if (!videoId || !Array.isArray(candidates) || candidates.length === 0) return;
    try {
      await initHighlightDB();
      const tx = highlightDB.transaction([HIGHLIGHT_STORE_NAME], 'readwrite');
      const store = tx.objectStore(HIGHLIGHT_STORE_NAME);
      store.put({
        videoId,
        candidates,
        savedAt: Date.now(),
      });
    } catch (e) {
      console.warn('[YCS Highlight] 保存に失敗:', e);
    }
  }

  async function cleanupOldHighlightData() {
    try {
      await initHighlightDB();
      const cutoffMs = Date.now() - HIGHLIGHT_MAX_AGE_DAYS * 24 * 60 * 60 * 1000;
      const tx = highlightDB.transaction([HIGHLIGHT_STORE_NAME], 'readwrite');
      const store = tx.objectStore(HIGHLIGHT_STORE_NAME);
      const index = store.index('savedAt');
      const range = IDBKeyRange.upperBound(cutoffMs);
      const request = index.openCursor(range);
      let deletedCount = 0;
      request.onsuccess = () => {
        const cursor = request.result;
        if (cursor) {
          cursor.delete();
          deletedCount++;
          cursor.continue();
        }
      };
      await new Promise((resolve) => {
        tx.oncomplete = () => {
          if (deletedCount > 0) {
            console.log(`[YCS] ${deletedCount}件の古いハイライト結果を削除しました`);
          }
          resolve();
        };
      });
    } catch (e) {
      console.error('[YCS Highlight] クリーンアップエラー:', e);
    }
  }

  async function loadHighlightResult(videoId) {
    if (!videoId) return null;
    try {
      await initHighlightDB();
      return await new Promise((resolve, reject) => {
        const tx = highlightDB.transaction([HIGHLIGHT_STORE_NAME], 'readonly');
        const req = tx.objectStore(HIGHLIGHT_STORE_NAME).get(videoId);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
      });
    } catch (e) {
      console.warn('[YCS Highlight] 読込に失敗:', e);
      return null;
    }
  }

  function renderHighlightResults(candidates) {
    const resultsEl = highlightPanel?.querySelector('#hlp-results');
    if (!resultsEl) return;
    resultsEl.innerHTML = '';

    if (!candidates || candidates.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'hlp-empty';
      empty.textContent = '候補が見つかりませんでした';
      resultsEl.appendChild(empty);
      return;
    }

    // 時刻順（既にサーバー側でソート済みのはずだが念のため）
    const sorted = [...candidates].sort((a, b) => (a.time || 0) - (b.time || 0));

    const fragment = document.createDocumentFragment();
    for (const c of sorted) {
      const item = document.createElement('div');
      item.className = 'hlp-result-item';

      const head = document.createElement('div');
      head.className = 'hlp-result-head';

      const time = document.createElement('span');
      time.className = 'hlp-result-time';
      time.textContent = formatSubtitleTime(Math.floor(c.time || 0));

      const type = document.createElement('span');
      type.className = 'hlp-result-type';
      type.textContent = c.type || 'other';

      const conf = document.createElement('span');
      conf.className = 'hlp-result-confidence';
      const confValue = Number.isFinite(c.confidence) ? c.confidence : 0;
      conf.textContent = `score ${(confValue * 100).toFixed(0)}%`;

      head.appendChild(time);
      head.appendChild(type);
      head.appendChild(conf);

      const label = document.createElement('div');
      label.className = 'hlp-result-label';
      label.textContent = c.label || '(ラベルなし)';

      const reason = document.createElement('div');
      reason.className = 'hlp-result-reason';
      reason.textContent = c.reason || '';

      item.appendChild(head);
      item.appendChild(label);
      if (reason.textContent) {
        item.appendChild(reason);
      }

      item.addEventListener('click', () => {
        if (state.videoElement && Number.isFinite(c.time)) {
          state.videoElement.currentTime = c.time;
        }
      });

      // ホバー時に区間前後の字幕をツールチップ表示
      // mouseleave時は遅延付きで非表示にし、その間にツールチップ上に移動すれば消えない
      item.addEventListener('mouseenter', () => {
        showHighlightSubtitleTooltip(c, item);
      });
      item.addEventListener('mouseleave', () => {
        scheduleHideHighlightSubtitleTooltip();
      });

      fragment.appendChild(item);
    }
    resultsEl.appendChild(fragment);
  }

  // 起動時に古いデータをクリーンアップ
  setTimeout(cleanupOldHighlightData, 6000);

  let chatSearchPanel = null;
  let chatSearchPanelVisible = false;
  let chatSearchDB = null;

  function toggleChatSearchPanel() {
    if (chatSearchPanelVisible) {
      hideChatSearchPanel();
    } else {
      showChatSearchPanel();
    }
  }

  async function showChatSearchPanel() {
    // 同一位置の他パネルと排他
    if (isSubtitlePanelVisible()) {
      hideSubtitlePanel();
    }
    if (isHighlightPanelVisible()) {
      hideHighlightPanel();
    }

    if (!chatSearchPanel) {
      createChatSearchPanel();
    }
    chatSearchPanel.classList.add('visible');
    chatSearchPanelVisible = true;
    updateTriggerButtonState();

    // IndexedDBを初期化
    await initChatDB();

    // 既存のチャットデータがあるか確認
    const videoId = getVideoId();
    if (videoId) {
      await loadChatDataForVideo(videoId);
    }
  }

  function hideChatSearchPanel() {
    if (chatSearchPanel) {
      chatSearchPanel.classList.remove('visible');
    }
    chatSearchPanelVisible = false;
    updateTriggerButtonState();
  }

  function isChatSearchPanelVisible() {
    return chatSearchPanelVisible;
  }

  function createChatSearchPanel() {
    if (chatSearchPanel) return;

    chatSearchPanel = document.createElement('div');
    chatSearchPanel.id = 'ycs-chat-search-panel';
    chatSearchPanel.innerHTML = `
    <style>
      #ycs-chat-search-panel {
        position: fixed;
        bottom: 140px;
        right: 70px;
        z-index: 9997;
        width: 360px;
        max-height: 500px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-chat-search-panel.visible {
        display: flex !important;
      }
      .csp-header {
        padding: 12px 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .csp-header-title {
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .csp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .csp-close-btn:hover {
        background: rgba(255,255,255,0.3);
      }
      .csp-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow-y: auto;
        max-height: 400px;
      }
      .csp-search-row {
        display: flex;
        gap: 8px;
      }
      .csp-search-input {
        flex: 1;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 8px 12px;
        font-size: 13px;
      }
      .csp-search-input::placeholder {
        color: #888;
      }
      .csp-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }
      .csp-btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
      }
      .csp-btn-primary:hover {
        filter: brightness(1.1);
      }
      .csp-btn-secondary {
        background: #444;
        color: white;
      }
      .csp-btn-secondary:hover {
        background: #555;
      }
      .csp-filters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }
      .csp-filter-btn {
        padding: 4px 12px;
        border: 1px solid #444;
        border-radius: 16px;
        background: transparent;
        color: #aaa;
        font-size: 11px;
        cursor: pointer;
        transition: all 0.2s;
      }
      .csp-filter-btn:hover {
        border-color: #666;
        color: #fff;
      }
      .csp-filter-btn.active {
        background: #667eea;
        border-color: #667eea;
        color: #fff;
      }
      .csp-status {
        font-size: 12px;
        color: #888;
        text-align: center;
        padding: 4px;
      }
      .csp-status.loading {
        color: #ff9800;
      }
      .csp-results {
        display: flex;
        flex-direction: column;
        gap: 4px;
        max-height: 280px;
        overflow-y: auto;
      }
      .csp-result-item {
        display: flex;
        gap: 8px;
        padding: 8px;
        background: #2a2a2a;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.2s;
      }
      .csp-result-item:hover {
        background: #3a3a3a;
      }
      .csp-result-time {
        color: #667eea;
        font-family: monospace;
        font-size: 11px;
        flex-shrink: 0;
        width: 60px;
      }
      .csp-result-message {
        flex: 1;
        font-size: 12px;
        color: #ddd;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .csp-result-badge {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        flex-shrink: 0;
      }
      .csp-badge-superchat {
        background: #ff6b6b;
        color: white;
      }
      .csp-empty {
        text-align: center;
        color: #888;
        padding: 20px;
        font-size: 12px;
      }
      .csp-actions {
        display: flex;
        gap: 8px;
        padding-top: 8px;
        border-top: 1px solid #333;
      }
    </style>
    <div class="csp-header">
      <span class="csp-header-title">💬 チャット検索</span>
      <button class="csp-close-btn" id="csp-close-btn">×</button>
    </div>
    <div class="csp-content">
      <div class="csp-search-row">
        <input type="text" class="csp-search-input" id="csp-search-input" placeholder="検索ワードを入力...">
        <button class="csp-btn csp-btn-primary" id="csp-search-btn">検索</button>
      </div>
      <div class="csp-filters">
        <button class="csp-filter-btn active" data-filter="all">全て</button>
        <button class="csp-filter-btn" data-filter="superchat">スパチャ</button>
      </div>
      <div class="csp-status" id="csp-status">チャットを読み込み中...</div>
      <div class="csp-results" id="csp-results">
        <div class="csp-empty">検索結果がここに表示されます</div>
      </div>
      <div class="csp-actions">
        <button class="csp-btn csp-btn-secondary" id="csp-fetch-btn">チャット取得</button>
        <button class="csp-btn csp-btn-secondary" id="csp-clear-btn">データ削除</button>
      </div>
    </div>
  `;

    document.body.appendChild(chatSearchPanel);

    // イベントリスナー設定
    chatSearchPanel.querySelector('#csp-close-btn').addEventListener('click', hideChatSearchPanel);
    chatSearchPanel.querySelector('#csp-search-btn').addEventListener('click', searchChats);
    chatSearchPanel.querySelector('#csp-search-input').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') searchChats();
    });
    chatSearchPanel.querySelector('#csp-fetch-btn').addEventListener('click', fetchChatData);
    chatSearchPanel.querySelector('#csp-clear-btn').addEventListener('click', clearChatDataForVideo);

    // フィルターボタンのイベント
    chatSearchPanel.querySelectorAll('.csp-filter-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        chatSearchPanel.querySelectorAll('.csp-filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        searchChats();
      });
    });
  }

  // initChatDB returns the DB handle so other modules can use it without accessing chatSearchDB directly
  function initChatDB() {
    return new Promise((resolve, reject) => {
      if (chatSearchDB) {
        resolve(chatSearchDB);
        return;
      }

      const request = indexedDB.open(CHAT_DB_NAME, CHAT_DB_VERSION);

      request.onerror = () => reject(request.error);

      request.onsuccess = () => {
        chatSearchDB = request.result;
        resolve(chatSearchDB);
      };

      request.onupgradeneeded = (event) => {
        const db = event.target.result;

        if (!db.objectStoreNames.contains(CHAT_STORE_NAME)) {
          const store = db.createObjectStore(CHAT_STORE_NAME, { keyPath: 'id' });
          store.createIndex('videoId', 'videoId', { unique: false });
          store.createIndex('timestamp', 'timestamp', { unique: false });
          store.createIndex('savedAt', 'savedAt', { unique: false });
        }
      };
    });
  }

  async function loadChatDataForVideo(videoId) {
    const statusEl = chatSearchPanel?.querySelector('#csp-status');

    try {
      await initChatDB();

      const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readonly');
      const store = transaction.objectStore(CHAT_STORE_NAME);
      const index = store.index('videoId');
      const request = index.getAll(videoId);

      return new Promise((resolve) => {
        request.onsuccess = () => {
          const chats = request.result || [];
          if (statusEl) {
            statusEl.textContent = chats.length > 0
              ? `${chats.length}件のチャットを読み込み済み`
              : 'チャットデータがありません。「チャット取得」ボタンで取得してください';
            statusEl.classList.remove('loading');
          }
          resolve(chats);
        };
        request.onerror = () => {
          if (statusEl) {
            statusEl.textContent = 'チャット読み込みエラー';
          }
          resolve([]);
        };
      });
    } catch (error) {
      console.error('チャット読み込みエラー:', error);
      if (statusEl) {
        statusEl.textContent = 'チャット読み込みエラー';
      }
      return [];
    }
  }

  async function fetchChatData() {
    const videoId = getVideoId();
    if (!videoId) return;

    const statusEl = chatSearchPanel?.querySelector('#csp-status');
    if (statusEl) {
      statusEl.textContent = 'チャットを取得中...';
      statusEl.classList.add('loading');
    }

    try {
      // ytInitialDataからcontinuationトークンを取得
      const continuation = await getChatContinuation();
      if (!continuation) {
        if (statusEl) {
          statusEl.textContent = 'チャットリプレイが見つかりません（チャットが無い動画、またはチャットリプレイが無効な動画です）';
          statusEl.classList.remove('loading');
        }
        return;
      }

      // チャットを取得
      const chats = await fetchAllChatReplays(continuation);

      // IndexedDBに保存
      await saveChatsToDB(videoId, chats);

      if (statusEl) {
        statusEl.textContent = `${chats.length}件のチャットを取得しました`;
        statusEl.classList.remove('loading');
      }
    } catch (error) {
      console.error('チャット取得エラー:', error);
      if (statusEl) {
        statusEl.textContent = 'チャット取得エラー: ' + error.message;
        statusEl.classList.remove('loading');
      }
    }
  }

  // page-bridge.js経由でチャットリプレイのcontinuationトークンを取得
  async function getChatContinuation() {
    await ensurePageBridge();

    return new Promise((resolve) => {
      const timeout = setTimeout(() => {
        window.removeEventListener('message', handler);
        console.warn('[YCS] チャットcontinuation取得タイムアウト');
        resolve(null);
      }, 5000);

      function handler(event) {
        if (event.source !== window) return;
        if (event.data?.type === 'YCS_CHAT_CONTINUATION_RESPONSE') {
          window.removeEventListener('message', handler);
          clearTimeout(timeout);
          resolve(event.data.continuation || null);
        }
      }

      window.addEventListener('message', handler);
      window.postMessage({ type: 'YCS_GET_CHAT_CONTINUATION' }, '*');
    });
  }

  async function fetchAllChatReplays(initialContinuation, onProgress) {
    const chats = [];
    let continuation = initialContinuation;
    let iterations = 0;
    const maxIterations = 100; // 安全のため上限を設定

    while (continuation && iterations < maxIterations) {
      iterations++;

      const statusEl = chatSearchPanel?.querySelector('#csp-status');
      if (statusEl) {
        statusEl.textContent = `チャットを取得中... (${chats.length}件)`;
      }
      if (onProgress) onProgress(chats.length);

      const response = await fetchChatReplayPage(continuation);
      if (!response) break;

      const newChats = parseChatResponse(response);
      chats.push(...newChats);

      // 次のcontinuationを取得
      continuation = getNextContinuation(response);

      // レート制限対策
      await new Promise(resolve => setTimeout(resolve, 100));
    }

    return chats;
  }

  async function fetchChatReplayPage(continuation) {
    try {
      const response = await fetch('https://www.youtube.com/youtubei/v1/live_chat/get_live_chat_replay?key=AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          context: {
            client: {
              clientName: 'WEB',
              clientVersion: '2.20231219.04.00'
            }
          },
          continuation: continuation
        })
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      return await response.json();
    } catch (error) {
      console.error('チャットページ取得エラー:', error);
      return null;
    }
  }

  function parseChatResponse(response) {
    const chats = [];

    try {
      const actions = response?.continuationContents?.liveChatContinuation?.actions || [];

      for (const action of actions) {
        const replayAction = action?.replayChatItemAction;
        if (!replayAction) continue;

        const chatActions = replayAction?.actions || [];
        for (const chatAction of chatActions) {
          const item = chatAction?.addChatItemAction?.item;
          if (!item) continue;

          const chat = parseChatItem(item, replayAction.videoOffsetTimeMsec);
          if (chat) {
            chats.push(chat);
          }
        }
      }
    } catch (error) {
      console.error('チャットパースエラー:', error);
    }

    return chats;
  }

  function parseChatItem(item, offsetMsec) {
    try {
      // 通常メッセージ
      if (item.liveChatTextMessageRenderer) {
        const renderer = item.liveChatTextMessageRenderer;
        return {
          type: 'normal',
          message: getMessageText(renderer.message),
          timestamp: parseInt(offsetMsec) || 0,
          isSuperchat: false
        };
      }

      // スーパーチャット
      if (item.liveChatPaidMessageRenderer) {
        const renderer = item.liveChatPaidMessageRenderer;
        return {
          type: 'superchat',
          message: getMessageText(renderer.message),
          timestamp: parseInt(offsetMsec) || 0,
          amount: renderer.purchaseAmountText?.simpleText || '',
          isSuperchat: true
        };
      }

      return null;
    } catch (error) {
      return null;
    }
  }

  function getMessageText(message) {
    if (!message) return '';

    const runs = message.runs || [];
    return runs.map(run => {
      if (run.text) return run.text;
      if (run.emoji) return run.emoji.shortcuts?.[0] || '';
      return '';
    }).join('');
  }

  function getNextContinuation(response) {
    try {
      const continuations = response?.continuationContents?.liveChatContinuation?.continuations || [];
      for (const cont of continuations) {
        if (cont?.liveChatReplayContinuationData?.continuation) {
          return cont.liveChatReplayContinuationData.continuation;
        }
      }
      return null;
    } catch (error) {
      return null;
    }
  }

  async function deleteChatsByVideoId(videoId) {
    await initChatDB();

    const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
    const store = transaction.objectStore(CHAT_STORE_NAME);
    const index = store.index('videoId');

    return new Promise((resolve, reject) => {
      const request = index.openCursor(IDBKeyRange.only(videoId));

      request.onsuccess = (event) => {
        const cursor = event.target.result;
        if (cursor) {
          cursor.delete();
          cursor.continue();
        }
      };

      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
    });
  }

  async function saveChatsToDB(videoId, chats) {
    if (chats.length === 0) return;

    await initChatDB();

    // 既存データを削除して重複を防ぐ
    await deleteChatsByVideoId(videoId);

    const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
    const store = transaction.objectStore(CHAT_STORE_NAME);

    const savedAt = new Date().toISOString();

    for (let i = 0; i < chats.length; i++) {
      const chat = chats[i];
      const record = {
        id: `${videoId}_${chat.timestamp}_${i}`,
        videoId,
        ...chat,
        savedAt
      };
      store.put(record);
    }

    return new Promise((resolve, reject) => {
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
    });
  }

  async function searchChats() {
    const videoId = getVideoId();
    if (!videoId) return;

    const searchInput = chatSearchPanel?.querySelector('#csp-search-input');
    chatSearchPanel?.querySelector('#csp-results');
    const activeFilter = chatSearchPanel?.querySelector('.csp-filter-btn.active')?.dataset.filter || 'all';

    const query = searchInput?.value?.trim().toLowerCase() || '';

    // チャットを取得
    const chats = await loadChatDataForVideo(videoId);

    // フィルタリング
    let filtered = chats;

    if (activeFilter === 'superchat') {
      filtered = filtered.filter(c => c.isSuperchat);
    }

    // 検索
    if (query) {
      filtered = filtered.filter(c =>
        c.message?.toLowerCase().includes(query)
      );
    }

    // 時刻でソート
    filtered.sort((a, b) => a.timestamp - b.timestamp);

    // 結果を表示
    renderChatResults(filtered);
  }

  function renderChatResults(chats) {
    const resultsEl = chatSearchPanel?.querySelector('#csp-results');
    if (!resultsEl) return;

    if (chats.length === 0) {
      resultsEl.innerHTML = '<div class="csp-empty">検索結果がありません</div>';
      return;
    }

    const html = chats.slice(0, 200).map(chat => {
      const timeStr = formatTimestampMsec(chat.timestamp);
      const badge = chat.isSuperchat
        ? `<span class="csp-result-badge csp-badge-superchat">${chat.amount || 'SC'}</span>`
        : '';

      return `
      <div class="csp-result-item" data-timestamp="${chat.timestamp}">
        <span class="csp-result-time">${timeStr}</span>
        <span class="csp-result-message">${escapeHtml(chat.message)}</span>
        ${badge}
      </div>
    `;
    }).join('');

    resultsEl.innerHTML = html;

    // クリックでシーク
    resultsEl.querySelectorAll('.csp-result-item').forEach(item => {
      item.addEventListener('click', () => {
        const timestamp = parseInt(item.dataset.timestamp);
        if (state.videoElement && !isNaN(timestamp)) {
          state.videoElement.currentTime = timestamp / 1000;
        }
      });
    });
  }

  async function clearChatDataForVideo() {
    const videoId = getVideoId();
    if (!videoId) return;

    if (!confirm('この動画のチャットデータを削除しますか？')) {
      return;
    }

    try {
      await initChatDB();

      const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
      const store = transaction.objectStore(CHAT_STORE_NAME);
      const index = store.index('videoId');
      const request = index.openCursor(IDBKeyRange.only(videoId));

      request.onsuccess = () => {
        const cursor = request.result;
        if (cursor) {
          cursor.delete();
          cursor.continue();
        }
      };

      await new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error);
      });

      const statusEl = chatSearchPanel?.querySelector('#csp-status');
      if (statusEl) {
        statusEl.textContent = 'チャットデータを削除しました';
      }

      const resultsEl = chatSearchPanel?.querySelector('#csp-results');
      if (resultsEl) {
        resultsEl.innerHTML = '<div class="csp-empty">検索結果がここに表示されます</div>';
      }
    } catch (error) {
      console.error('チャット削除エラー:', error);
    }
  }

  async function cleanupOldChatData() {
    try {
      await initChatDB();

      const cutoffDate = new Date();
      cutoffDate.setDate(cutoffDate.getDate() - CHAT_MAX_AGE_DAYS);
      const cutoffStr = cutoffDate.toISOString();

      const transaction = chatSearchDB.transaction([CHAT_STORE_NAME], 'readwrite');
      const store = transaction.objectStore(CHAT_STORE_NAME);
      const index = store.index('savedAt');
      const range = IDBKeyRange.upperBound(cutoffStr);

      const request = index.openCursor(range);
      let deletedCount = 0;

      request.onsuccess = () => {
        const cursor = request.result;
        if (cursor) {
          cursor.delete();
          deletedCount++;
          cursor.continue();
        }
      };

      await new Promise((resolve) => {
        transaction.oncomplete = () => {
          if (deletedCount > 0) {
            console.log(`[YCS] ${deletedCount}件の古いチャットデータを削除しました`);
          }
          resolve();
        };
      });
    } catch (error) {
      console.error('チャットクリーンアップエラー:', error);
    }
  }

  // 起動時に古いデータをクリーンアップ
  setTimeout(cleanupOldChatData, 5000);

  let subtitlePanel = null;
  let subtitlePanelVisible = false;

  function toggleSubtitlePanel() {
    if (subtitlePanelVisible) {
      hideSubtitlePanel();
    } else {
      showSubtitlePanel();
    }
  }

  async function showSubtitlePanel() {
    // 同一位置の他パネルと排他
    if (isChatSearchPanelVisible()) {
      hideChatSearchPanel();
    }
    if (isHighlightPanelVisible()) {
      hideHighlightPanel();
    }

    if (!subtitlePanel) {
      createSubtitlePanel();
    }
    subtitlePanel.classList.add('visible');
    subtitlePanelVisible = true;
    updateTriggerButtonState();

    // 字幕トラックを自動取得
    const videoId = getVideoId();
    if (videoId) {
      await fetchSubtitleTracks(videoId);
    }
  }

  function hideSubtitlePanel() {
    if (subtitlePanel) {
      subtitlePanel.classList.remove('visible');
    }
    subtitlePanelVisible = false;
    updateTriggerButtonState();
  }

  function isSubtitlePanelVisible() {
    return subtitlePanelVisible;
  }

  function createSubtitlePanel() {
    if (subtitlePanel) return;

    subtitlePanel = document.createElement('div');
    subtitlePanel.id = 'ycs-subtitle-panel';
    subtitlePanel.innerHTML = `
    <style>
      #ycs-subtitle-panel {
        position: fixed;
        bottom: 140px;
        right: 70px;
        z-index: 9997;
        width: 400px;
        max-height: 550px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-subtitle-panel.visible {
        display: flex !important;
      }
      .stp-header {
        padding: 12px 16px;
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
        display: flex;
        justify-content: space-between;
        align-items: center;
      }
      .stp-header-title {
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .stp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .stp-close-btn:hover {
        background: rgba(255,255,255,0.3);
      }
      .stp-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        overflow-y: auto;
        max-height: 460px;
      }
      .stp-controls {
        display: flex;
        gap: 8px;
        align-items: center;
      }
      .stp-lang-select {
        flex: 1;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 6px 10px;
        font-size: 12px;
      }
      .stp-btn {
        padding: 6px 14px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }
      .stp-btn-primary {
        background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
        color: white;
      }
      .stp-btn-primary:hover {
        filter: brightness(1.1);
      }
      .stp-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
      .stp-search-row {
        display: flex;
        gap: 8px;
      }
      .stp-search-input {
        flex: 1;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 6px 10px;
        font-size: 12px;
      }
      .stp-search-input::placeholder {
        color: #888;
      }
      .stp-status {
        font-size: 12px;
        color: #888;
        text-align: center;
        padding: 4px;
      }
      .stp-status.loading {
        color: #a78bfa;
      }
      .stp-results {
        display: flex;
        flex-direction: column;
        gap: 2px;
        max-height: 340px;
        overflow-y: auto;
      }
      .stp-result-item {
        display: flex;
        gap: 8px;
        padding: 6px 8px;
        background: #2a2a2a;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.2s;
        align-items: flex-start;
      }
      .stp-result-item:hover {
        background: #3a3a3a;
      }
      .stp-result-time {
        color: #a78bfa;
        font-family: monospace;
        font-size: 11px;
        flex-shrink: 0;
        width: 58px;
        text-align: right;
      }
      .stp-result-text {
        flex: 1;
        font-size: 12px;
        color: #ddd;
        line-height: 1.4;
      }
      .stp-empty {
        text-align: center;
        color: #888;
        padding: 20px;
        font-size: 12px;
      }
      .stp-load-more {
        text-align: center;
        color: #aaa;
        padding: 8px;
        font-size: 12px;
        cursor: pointer;
        border-top: 1px solid #444;
        margin-top: 4px;
      }
      .stp-load-more:hover {
        color: #fff;
        background: #444;
      }
    </style>
    <div class="stp-header">
      <span class="stp-header-title">📝 字幕取得</span>
      <button class="stp-close-btn" id="stp-close-btn">×</button>
    </div>
    <div class="stp-content">
      <div class="stp-controls">
        <select class="stp-lang-select" id="stp-lang-select">
          <option value="">字幕トラックを読み込み中...</option>
        </select>
        <button class="stp-btn stp-btn-primary" id="stp-fetch-btn" disabled>取得</button>
      </div>
      <div class="stp-search-row">
        <input type="text" class="stp-search-input" id="stp-search-input" placeholder="字幕内を検索...">
      </div>
      <div class="stp-status" id="stp-status">字幕トラックを読み込み中...</div>
      <div class="stp-results" id="stp-results">
        <div class="stp-empty">字幕がここに表示されます</div>
      </div>
    </div>
  `;

    document.body.appendChild(subtitlePanel);

    // イベントリスナー設定
    subtitlePanel.querySelector('#stp-close-btn').addEventListener('click', hideSubtitlePanel);
    subtitlePanel.querySelector('#stp-fetch-btn').addEventListener('click', () => {
      const videoId = getVideoId();
      if (videoId) fetchSubtitleContent(videoId);
    });
    subtitlePanel.querySelector('#stp-search-input').addEventListener('input', filterSubtitleResults);
  }

  function ensurePageBridge() {
    if (state.pageBridgeReady) return state.pageBridgeReady;
    state.pageBridgeReady = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = chrome.runtime.getURL('page-bridge.js');
      script.onload = () => { script.remove(); resolve(); };
      script.onerror = () => {
        script.remove();
        // 失敗時はキャッシュをクリアして次回呼び出しで再試行可能にする
        state.pageBridgeReady = null;
        reject(new Error('page-bridge.jsのロードに失敗しました'));
      };
      document.documentElement.appendChild(script);
    });
    return state.pageBridgeReady;
  }

  async function getCaptionTracksFromPage() {
    await ensurePageBridge();

    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        window.removeEventListener('message', handler);
        reject(new Error('字幕データの取得がタイムアウトしました'));
      }, 5000);

      function handler(event) {
        if (event.source !== window || event.data?.type !== 'YCS_CAPTION_TRACKS_RESPONSE') return;
        window.removeEventListener('message', handler);
        clearTimeout(timeout);

        if (event.data.playabilityStatus !== 'OK') {
          reject(new Error('動画を取得できません。動画が非公開・削除済み、または年齢制限がある可能性があります'));
          return;
        }
        resolve(event.data.tracks);
      }

      window.addEventListener('message', handler);
      window.postMessage({ type: 'YCS_GET_CAPTION_TRACKS' }, '*');
    });
  }

  async function fetchSubtitleTracks(videoId) {
    const statusEl = subtitlePanel?.querySelector('#stp-status');
    const selectEl = subtitlePanel?.querySelector('#stp-lang-select');
    const fetchBtn = subtitlePanel?.querySelector('#stp-fetch-btn');

    if (statusEl) {
      statusEl.textContent = '字幕トラックを読み込み中...';
      statusEl.classList.add('loading');
    }

    try {
      // ページコンテキストのytInitialPlayerResponseから字幕トラックデータを取得
      const captionTracks = await getCaptionTracksFromPage();
      state.currentCaptionTracks = captionTracks;

      if (selectEl) {
        if (captionTracks.length === 0) {
          selectEl.innerHTML = '<option value="">字幕がありません</option>';
          if (fetchBtn) fetchBtn.disabled = true;
          if (statusEl) {
            statusEl.textContent = 'この動画には字幕がありません';
            statusEl.classList.remove('loading');
          }
          return;
        }

        selectEl.innerHTML = '';
        let jaOption = null;
        captionTracks.forEach(track => {
          const option = document.createElement('option');
          option.value = track.languageCode || '';
          option.dataset.lang = track.languageCode || '';
          option.textContent = (track.name || track.languageCode || '') + (track.kind === 'asr' ? ' (自動生成)' : '');
          selectEl.appendChild(option);
          if (track.languageCode === 'ja' && !jaOption) jaOption = option;
        });

        // 日本語を優先選択
        if (jaOption) jaOption.selected = true;

        if (fetchBtn) fetchBtn.disabled = false;
      }

      if (statusEl) {
        statusEl.textContent = `${captionTracks.length}件の字幕トラックが見つかりました`;
        statusEl.classList.remove('loading');
      }

      // トラックが見つかったら自動で字幕を取得
      await fetchSubtitleContent(videoId);
    } catch (error) {
      console.error('字幕トラック取得エラー:', error);
      if (statusEl) {
        statusEl.textContent = 'エラー: ' + error.message;
        statusEl.classList.remove('loading');
      }
      if (selectEl) {
        selectEl.innerHTML = '<option value="">取得エラー</option>';
      }
      if (fetchBtn) fetchBtn.disabled = true;
    }
  }

  async function fetchSubtitleContent(videoId) {
    const statusEl = subtitlePanel?.querySelector('#stp-status');
    const fetchBtn = subtitlePanel?.querySelector('#stp-fetch-btn');
    const selectEl = subtitlePanel?.querySelector('#stp-lang-select');

    if (!videoId) return;

    if (fetchBtn) fetchBtn.disabled = true;
    if (statusEl) {
      statusEl.textContent = '字幕を取得中...';
      statusEl.classList.add('loading');
    }

    try {
      const selectedLang = selectEl?.value || 'ja';

      // InnerTube player APIで最新のbaseUrlを取得してtimedtextをfetch
      state.currentSubtitles = await fetchTimedText(videoId, selectedLang);

      if (statusEl) {
        statusEl.textContent = `${state.currentSubtitles.length}件の字幕を取得しました`;
        statusEl.classList.remove('loading');
      }

      renderSubtitleResults(state.currentSubtitles);

      // サーバーに自動送信
      sendSubtitlesToServer(videoId, selectedLang, state.currentSubtitles);
    } catch (error) {
      console.error('字幕取得エラー:', error);
      if (statusEl) {
        statusEl.textContent = 'エラー: ' + error.message;
        statusEl.classList.remove('loading');
      }
    } finally {
      if (fetchBtn) fetchBtn.disabled = false;
    }
  }

  // InnerTube player API経由で最新の字幕を取得（page-bridge.js経由）
  // 毎回InnerTube APIを呼ぶことでbaseUrlの署名期限切れを回避する
  function fetchTimedText(videoId, lang) {
    return new Promise((resolve, reject) => {
      const timeout = setTimeout(() => {
        window.removeEventListener('message', handler);
        reject(new Error('字幕の取得がタイムアウトしました'));
      }, 15000);

      function handler(event) {
        if (event.source !== window || event.data?.type !== 'YCS_TIMEDTEXT_RESPONSE') return;
        window.removeEventListener('message', handler);
        clearTimeout(timeout);
        if (event.data.error) {
          reject(new Error(event.data.error));
        } else {
          resolve(event.data.segments);
        }
      }

      window.addEventListener('message', handler);
      window.postMessage({ type: 'YCS_FETCH_TIMEDTEXT', videoId, lang }, '*');
    });
  }

  function filterSubtitleResults() {
    const query = subtitlePanel?.querySelector('#stp-search-input')?.value?.trim().toLowerCase() || '';
    if (!query) {
      renderSubtitleResults(state.currentSubtitles);
      return;
    }
    const filtered = state.currentSubtitles.filter(sub => sub.text.toLowerCase().includes(query));
    renderSubtitleResults(filtered);
  }

  function renderSubtitleResults(subtitles) {
    const resultsEl = subtitlePanel?.querySelector('#stp-results');
    if (!resultsEl) return;

    if (subtitles.length === 0) {
      resultsEl.innerHTML = '<div class="stp-empty">字幕がありません</div>';
      return;
    }

    const CHUNK_SIZE = 500;
    resultsEl.innerHTML = '';
    resultsEl._subtitles = subtitles;
    resultsEl._rendered = 0;

    appendSubtitleChunk(resultsEl, CHUNK_SIZE);
  }

  function appendSubtitleChunk(resultsEl, chunkSize) {
    const subtitles = resultsEl._subtitles;
    const start = resultsEl._rendered;
    const end = Math.min(start + chunkSize, subtitles.length);

    const fragment = document.createDocumentFragment();
    for (let i = start; i < end; i++) {
      const sub = subtitles[i];
      const sec = Math.floor(sub.start);
      const item = document.createElement('div');
      item.className = 'stp-result-item';
      item.dataset.time = sec;
      item.innerHTML = `<span class="stp-result-time">${formatSubtitleTime(sec)}</span><span class="stp-result-text">${escapeHtml(sub.text)}</span>`;
      item.addEventListener('click', () => {
        if (state.videoElement && !isNaN(sec)) {
          state.videoElement.currentTime = sec;
        }
      });
      fragment.appendChild(item);
    }
    resultsEl._rendered = end;

    // 既存の「もっと表示」ボタンがあれば削除
    const oldBtn = resultsEl.querySelector('.stp-load-more');
    if (oldBtn) oldBtn.remove();

    resultsEl.appendChild(fragment);

    // まだ残りがあれば「もっと表示」ボタンを追加
    if (end < subtitles.length) {
      const remaining = subtitles.length - end;
      const btn = document.createElement('div');
      btn.className = 'stp-load-more';
      btn.textContent = `もっと表示（残り ${remaining} 件）`;
      btn.addEventListener('click', () => {
        appendSubtitleChunk(resultsEl, chunkSize);
      });
      resultsEl.appendChild(btn);
    }
  }

  let lyricsPastePopup = null;
  let lyricsPastePopupCleanup = null;
  let songCandidatePopup = null;
  let songCandidatePopupCleanup = null;
  let songCandidateRequestSeq = 0;
  // 字幕準備フロー（取得→送信）の実行中Promise（動画ID単位の相乗り用）
  let subtitlePrepareFlow = null;

  function isLyricsPastePopupOpen() {
    return !!lyricsPastePopup;
  }

  function buildLyricsSplitCandidates(text) {
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

    // mousedownで処理して入力欄のフォーカスを維持する
    popup.addEventListener('mousedown', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const item = e.target.closest('.vdg-paste-popup-item');
      if (item) {
        insertAndClose(values[parseInt(item.dataset.index)]);
      }
    });

    // Esc: 元のテキストのまま挿入 / その他のキー: 閉じるだけ
    const onKeydown = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        insertAndClose(rawText);
      } else {
        closeLyricsPastePopup();
      }
    };
    // 外側クリック: 元のテキストのまま挿入（入力欄内のクリックはカーソル移動なので閉じない）
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
    // offsetWidthを使うためDOM追加後に配置
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

  async function showSongCandidates(marker, threshold = null) {
    const input = state.volumeGraphContainer?.querySelector(`.vdg-ts-text-input[data-marker-id="${marker.id}"]`);
    if (!input) return;

    // openSongCandidatePopupは開き直しのたびに内部で世代を進めるため、
    // 自分で開いた直後の世代を控えて「外部から閉じられた/開き直された」を検出する
    let seq;
    const open = (items) => {
      openSongCandidatePopup(input, items);
      seq = songCandidateRequestSeq;
    };
    const isStale = () => seq !== songCandidateRequestSeq;

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

      // 字幕が未送信なら取得→送信してから再問い合わせ
      if (result.has_subtitles === false) {
        openSongCandidatePopup(input, [{ type: 'message', label: '字幕を取得しています…' }]);
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
            action: () => retryWithLowerThreshold(marker, lowerThreshold),
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

      // 候補が少ない場合、閾値を下げて追加検索できるボタンを付与
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

  async function fetchSongCandidates(videoId, sec, threshold = null) {
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

  function ensureSubtitlesOnServer(videoId) {
    if (subtitlePrepareFlow && subtitlePrepareFlow.videoId === videoId) {
      return subtitlePrepareFlow.promise;
    }

    const promise = (async () => {
      const tracks = await getCaptionTracksFromPage();
      if (!tracks || tracks.length === 0) {
        // 候補ボタン経由でも「字幕なし」を記録し、字幕スキャン対象から除外する
        reportSubtitlesUnavailable(videoId);
        throw new Error('この動画には字幕がありません');
      }
      const track = pickPreferredCaptionTrack(tracks);
      const segments = await fetchTimedText(videoId, track.languageCode);
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

  function pickPreferredCaptionTrack(tracks) {
    const ja = tracks.filter(t => (t.languageCode || '').startsWith('ja'));
    return ja.find(t => t.kind !== 'asr') || ja[0] || tracks[0];
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
    // offsetWidthを使うためDOM追加後に配置
    reposition();
  }

  let subtitleScanTargets = [];

  function getOwnTabId() {
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

  function setSubtitleScanStatus(text) {
    const el = state.listScanPanel?.querySelector('#ssp-status');
    if (el) el.textContent = text;
  }

  async function loadSubtitleScanTargets() {
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

  async function startSubtitleScan() {
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

  async function stopSubtitleScan() {
    await chrome.storage.local.set({ subtitleScanActive: false });
    updateSubtitleScanButtons(false);
    setSubtitleScanStatus('停止しました');
  }

  function updateSubtitleScanButtons(running) {
    if (!state.listScanPanel) return;
    state.listScanPanel.querySelector('#ssp-start-btn').style.display = running ? 'none' : 'block';
    state.listScanPanel.querySelector('#ssp-stop-btn').style.display = running ? 'block' : 'none';
  }

  async function restoreSubtitleScanPanelState() {
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

  async function checkAndStartSubtitleScan() {
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

  function waitForPlayerAndProcessSubtitle(videoId) {
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

  async function reportSubtitlesUnavailable(videoId) {
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

  async function processSubtitleScanVideo(videoId) {
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

  async function recordSubtitleScanResult(kind) {
    const result = await chrome.storage.local.get(['subtitleScanResults']);
    const counts = result.subtitleScanResults || { sent: 0, skipped: 0, failed: 0 };
    counts[kind] = (counts[kind] || 0) + 1;
    await chrome.storage.local.set({ subtitleScanResults: counts });
  }

  async function proceedToNextSubtitleScanVideo() {
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

  async function loadScannedVideosList() {
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

  function renderScannedVideosList(videos) {
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

  function openYouTubeVideo(videoId) {
    window.open(`https://www.youtube.com/watch?v=${videoId}`, '_blank');
  }

  async function deleteScannedVideo(videoId) {
    const key = `volumeData_${videoId}`;
    await chrome.storage.local.remove(key);
    loadScannedVideosList();
  }

  async function clearAllScannedVideos() {
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

  var subtitleScan = /*#__PURE__*/Object.freeze({
    __proto__: null,
    checkAndStartSubtitleScan: checkAndStartSubtitleScan,
    clearAllScannedVideos: clearAllScannedVideos,
    deleteScannedVideo: deleteScannedVideo,
    getOwnTabId: getOwnTabId,
    loadScannedVideosList: loadScannedVideosList,
    loadSubtitleScanTargets: loadSubtitleScanTargets,
    openYouTubeVideo: openYouTubeVideo,
    proceedToNextSubtitleScanVideo: proceedToNextSubtitleScanVideo,
    processSubtitleScanVideo: processSubtitleScanVideo,
    recordSubtitleScanResult: recordSubtitleScanResult,
    renderScannedVideosList: renderScannedVideosList,
    reportSubtitlesUnavailable: reportSubtitlesUnavailable,
    restoreSubtitleScanPanelState: restoreSubtitleScanPanelState,
    setSubtitleScanStatus: setSubtitleScanStatus,
    startSubtitleScan: startSubtitleScan,
    stopSubtitleScan: stopSubtitleScan,
    updateSubtitleScanButtons: updateSubtitleScanButtons,
    waitForPlayerAndProcessSubtitle: waitForPlayerAndProcessSubtitle
  });

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
    if (trimmed.length === 0) return false;
    if (EMOJI_ONLY_RE.test(trimmed)) return true;
    // YouTube絵文字ピッカー・メンバー限定絵文字は :shortcode: 形式で保存される
    const withoutShortcodes = trimmed.replace(EMOJI_SHORTCODE_RE, '').trim();
    return withoutShortcodes.length === 0 || EMOJI_ONLY_RE.test(withoutShortcodes);
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
      if (totalMsg < cfg.MIN_WINDOW_MESSAGES) return 0;
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
      const adjusted = {
        start: Math.max(0, seg.start - cfg.REACTION_DELAY_SEC),
        end: seg.end,
      };
      const prev = merged[merged.length - 1];
      if (prev && adjusted.start - prev.end <= cfg.MERGE_GAP_SEC) {
        prev.end = adjusted.end;
      } else {
        merged.push(adjusted);
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

    const tolerance = cfg.NEAR_SEGMENT_TOLERANCE_SEC;
    const tailGuard = cfg.TAIL_GUARD_SEC;

    if (emojiSegments.length > 0) {
      for (const seg of emojiSegments) {
        starts.push(seg.start);
      }

      if (chatActive) {
        for (const burst of bursts) {
          const inSegment = emojiSegments.some(s => burst >= s.start && burst <= s.end + tolerance);
          if (inSegment) {
            const nextStart = burst + clapCfg.SPLIT_START_OFFSET_SEC;
            if (nextStart < videoDurationSec - tailGuard) {
              const alreadyCovered = emojiSegments.some(
                s => nextStart >= s.start - tolerance && nextStart <= s.start + tolerance
              );
              if (!alreadyCovered) starts.push(nextStart);
            }
          }
        }
      }
    } else if (chatActive) {
      for (let i = 0; i < bursts.length; i++) {
        if (i === 0 && bursts[0] > cfg.FIRST_SONG_MIN_OFFSET_SEC) {
          starts.push(0);
        }
        const nextStart = bursts[i] + clapCfg.SPLIT_START_OFFSET_SEC;
        if (nextStart < videoDurationSec - tailGuard) {
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

    // リストスキャンモードの場合は完了判定して次の動画へ
    if (state.isListScanMode) {
      isCurrentVideoScanned().then(async (completed) => {
        if (completed) {
          hideListScanButton();
          proceedToNextListScanVideo();
        } else {
          const st = await chrome.storage.local.get(['listScanCurrentIndex', 'listScanVideoIds']);
          if (st.listScanVideoIds) {
            showListScanButton(st.listScanCurrentIndex || 0, st.listScanVideoIds.length);
          }
        }
      });
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

  let currentListScanVideoIds = [];
  let listScanButtonContainer = null;
  let listScanAutoClickTimer = null;
  let listScanCountdownInterval = null;

  // Panel-internal tab switching
  function switchTab(tabId) {
    if (!state.listScanPanel) return;

    state.listScanPanel.querySelectorAll('.lsp-tab').forEach(tab => {
      tab.classList.toggle('active', tab.dataset.tab === tabId);
    });

    state.listScanPanel.querySelectorAll('.lsp-tab-content').forEach(content => {
      content.classList.toggle('active', content.id === `tab-${tabId}`);
    });

    // スキャン済み一覧タブに切り替えた場合はリストを更新
    if (tabId === 'scanned-list') {
      // loadScannedVideosList is in subtitle-scan.js (not yet extracted)
      Promise.resolve().then(function () { return subtitleScan; }).then(m => m.loadScannedVideosList?.());
    }
  }

  function createListScanPanel() {
    if (state.listScanPanel) return;

    state.listScanPanel = document.createElement('div');
    state.listScanPanel.id = 'ycs-list-scan-panel';
    state.listScanPanel.innerHTML = `
    <style>
      #ycs-list-scan-panel {
        position: fixed;
        bottom: 140px;
        right: 16px;
        z-index: 9998;
        width: 320px;
        max-height: 450px;
        background: rgba(20, 20, 20, 0.95);
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        font-size: 13px;
        color: #fff;
        display: none;
        flex-direction: column;
        overflow: hidden;
      }
      #ycs-list-scan-panel.visible {
        display: flex !important;
      }
      .lsp-header {
        padding: 8px 16px 0;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        flex-direction: column;
      }
      .lsp-header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
      }
      .lsp-header-title {
        font-weight: 600;
        font-size: 14px;
      }
      .lsp-close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .lsp-close-btn:hover {
        background: rgba(255,255,255,0.3);
      }
      .lsp-tabs {
        display: flex;
        gap: 0;
      }
      .lsp-tab {
        flex: 1;
        padding: 8px 12px;
        border: none;
        background: rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.7);
        font-size: 12px;
        cursor: pointer;
        border-radius: 8px 8px 0 0;
        transition: all 0.2s;
      }
      .lsp-tab:hover {
        background: rgba(255,255,255,0.15);
      }
      .lsp-tab.active {
        background: rgba(20, 20, 20, 0.95);
        color: #fff;
        font-weight: 500;
      }
      .lsp-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        /* リストはタブ内で個別にスクロールさせ、ボタンが画面外に流れないようにする */
        overflow: hidden;
        max-height: 350px;
      }
      .lsp-tab-content {
        display: none;
      }
      .lsp-tab-content.active {
        display: flex;
        flex-direction: column;
        gap: 12px;
        min-height: 0;
      }
      .lsp-input-section {
        display: flex;
        flex-direction: column;
        gap: 8px;
      }
      .lsp-textarea {
        width: 100%;
        height: 60px;
        background: #333;
        border: 1px solid #444;
        border-radius: 6px;
        color: #fff;
        padding: 8px;
        font-size: 11px;
        font-family: monospace;
        resize: vertical;
        box-sizing: border-box;
      }
      .lsp-textarea::placeholder {
        color: #888;
      }
      .lsp-btn-row {
        display: flex;
        gap: 8px;
      }
      .lsp-btn {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }
      .lsp-btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
      }
      .lsp-btn-primary:hover {
        filter: brightness(1.1);
      }
      .lsp-btn-secondary {
        background: #444;
        color: white;
      }
      .lsp-btn-secondary:hover {
        background: #555;
      }
      .lsp-btn-danger {
        background: #c62828;
        color: white;
      }
      .lsp-btn-danger:hover {
        background: #d32f2f;
      }
      .lsp-progress {
        font-size: 12px;
        color: #aaa;
        text-align: center;
        padding: 4px;
      }
      .lsp-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
        /* 数百件でもリスト内でスクロールし、前後の操作ボタンは常に見える位置に保つ */
        overflow-y: auto;
        min-height: 0;
      }
      .lsp-input-row {
        display: flex;
        gap: 8px;
      }
      .lsp-input-row .lsp-textarea {
        /* videoIdは11文字なので幅を絞り、余った横幅をボタンに使う */
        width: 140px;
        flex-shrink: 0;
        height: 92px;
      }
      .lsp-input-actions {
        display: flex;
        flex-direction: column;
        gap: 6px;
        flex: 1;
        min-width: 0;
      }
      .lsp-input-actions .lsp-btn {
        padding: 6px 8px;
        font-size: 11px;
      }
      .lsp-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        background: #2a2a2a;
        border-radius: 6px;
        font-size: 11px;
      }
      .lsp-item.current {
        background: #3a3a6a;
        border: 1px solid #667eea;
      }
      .lsp-item-index {
        color: #888;
        width: 20px;
      }
      .lsp-item-id {
        font-family: monospace;
        flex: 1;
        color: #ddd;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .lsp-ts-badge {
        font-size: 9px;
        padding: 1px 4px;
        border-radius: 3px;
        white-space: nowrap;
      }
      .lsp-ts-badge.has-ts {
        background: #1b5e20;
        color: #a5d6a7;
      }
      .lsp-ts-badge.no-ts {
        background: #333;
        color: #888;
      }
      .lsp-ts-badge.unknown {
        background: #333;
        color: #ff9800;
      }
      .lsp-item-status {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
      }
      .lsp-status-icon {
        width: 16px;
        text-align: center;
      }
      .lsp-status-icon.pending { color: #888; }
      .lsp-status-icon.scanning { color: #ff9800; animation: pulse 1s infinite; }
      .lsp-status-icon.completed { color: #4caf50; }
      .lsp-status-icon.partial { color: #ffeb3b; }
      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
      }
      .lsp-progress-bar {
        width: 40px;
        height: 4px;
        background: #444;
        border-radius: 2px;
        overflow: hidden;
      }
      .lsp-progress-fill {
        height: 100%;
        background: #4caf50;
        transition: width 0.3s;
      }
      .lsp-open-btn {
        background: #333;
        border: none;
        color: #aaa;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 10px;
        cursor: pointer;
      }
      .lsp-open-btn:hover {
        background: #444;
        color: #fff;
      }
      .lsp-delete-btn {
        background: transparent;
        border: none;
        color: #888;
        padding: 4px 6px;
        border-radius: 4px;
        font-size: 10px;
        cursor: pointer;
      }
      .lsp-delete-btn:hover {
        background: #c62828;
        color: #fff;
      }
      .lsp-empty {
        text-align: center;
        color: #888;
        padding: 20px;
        font-size: 12px;
      }
      .lsp-scanned-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
        background: #2a2a2a;
        border-radius: 6px;
        font-size: 11px;
      }
      .lsp-scanned-item .lsp-item-id {
        font-family: monospace;
        flex: 1;
        color: #ddd;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .lsp-scanned-item .lsp-item-date {
        color: #888;
        font-size: 10px;
      }
      .lsp-scanned-item .lsp-item-actions {
        display: flex;
        gap: 4px;
      }
      .lsp-scanned-count {
        font-size: 12px;
        color: #aaa;
        text-align: center;
        padding: 4px;
      }
    </style>
    <div class="lsp-header">
      <div class="lsp-header-top">
        <span class="lsp-header-title">スキャン管理</span>
        <button class="lsp-close-btn" id="lsp-close-btn">×</button>
      </div>
      <div class="lsp-tabs">
        <button class="lsp-tab active" data-tab="list-scan">リストスキャン</button>
        <button class="lsp-tab" data-tab="scanned-list">スキャン済み一覧</button>
        <button class="lsp-tab" data-tab="subtitle-scan">字幕取得</button>
      </div>
    </div>
    <div class="lsp-content">
      <!-- リストスキャン タブ -->
      <div class="lsp-tab-content active" id="tab-list-scan">
        <div class="lsp-input-section">
          <div class="lsp-input-row">
            <textarea class="lsp-textarea" id="lsp-video-ids" placeholder="videoId&#10;（1行に1件）"></textarea>
            <div class="lsp-input-actions">
              <button class="lsp-btn lsp-btn-primary" id="lsp-load-btn">読み込み</button>
              <button class="lsp-btn lsp-btn-secondary" id="lsp-fetch-targets-btn" title="タイムスタンプ未作成のアーカイブをサーバーから取得">サーバーから読み込み</button>
              <button class="lsp-btn lsp-btn-secondary" id="lsp-clear-btn">クリア</button>
            </div>
          </div>
        </div>
        <div class="lsp-btn-row">
          <button class="lsp-btn lsp-btn-primary" id="lsp-start-btn" disabled>▶ スキャン開始</button>
          <button class="lsp-btn lsp-btn-danger" id="lsp-stop-btn" style="display:none;">■ 停止</button>
        </div>
        <div class="lsp-progress" id="lsp-progress-info">0 / 0</div>
        <div class="lsp-list" id="lsp-video-list">
          <div class="lsp-empty">VideoIDを入力して読み込みボタンをクリック</div>
        </div>
      </div>
      <!-- スキャン済み一覧 タブ -->
      <div class="lsp-tab-content" id="tab-scanned-list">
        <div class="lsp-scanned-count" id="lsp-scanned-count">0 件のスキャン済み動画</div>
        <div class="lsp-list" id="lsp-scanned-video-list">
          <div class="lsp-empty">スキャン済みの動画がありません</div>
        </div>
        <div class="lsp-btn-row">
          <button class="lsp-btn lsp-btn-danger" id="lsp-clear-all-btn">全てクリア</button>
        </div>
      </div>
      <!-- 字幕一括取得 タブ -->
      <div class="lsp-tab-content" id="tab-subtitle-scan">
        <div class="lsp-progress" id="ssp-status">タイムスタンプあり＆字幕未取得のアーカイブを順次取得します</div>
        <div class="lsp-list" id="ssp-video-list">
          <div class="lsp-empty">「対象を読み込み」をクリック</div>
        </div>
        <div class="lsp-btn-row">
          <button class="lsp-btn lsp-btn-secondary" id="ssp-load-btn">対象を読み込み</button>
          <button class="lsp-btn lsp-btn-primary" id="ssp-start-btn" disabled>▶ 開始</button>
          <button class="lsp-btn lsp-btn-danger" id="ssp-stop-btn" style="display:none;">■ 停止</button>
        </div>
      </div>
    </div>
  `;

    document.body.appendChild(state.listScanPanel);

    // イベントリスナー設定
    state.listScanPanel.querySelector('#lsp-close-btn').addEventListener('click', hideListScanPanel);
    state.listScanPanel.querySelector('#lsp-load-btn').addEventListener('click', loadVideoIdList);
    state.listScanPanel.querySelector('#lsp-fetch-targets-btn').addEventListener('click', fetchListScanTargetsFromServer);
    state.listScanPanel.querySelector('#lsp-clear-btn').addEventListener('click', clearVideoIdList);
    state.listScanPanel.querySelector('#lsp-start-btn').addEventListener('click', startListScanFromPanel);
    state.listScanPanel.querySelector('#lsp-stop-btn').addEventListener('click', stopListScanFromPanel);
    state.listScanPanel.querySelector('#lsp-clear-all-btn').addEventListener('click', clearAllScannedVideos);

    // 字幕一括取得タブ
    state.listScanPanel.querySelector('#ssp-load-btn').addEventListener('click', loadSubtitleScanTargets);
    state.listScanPanel.querySelector('#ssp-start-btn').addEventListener('click', startSubtitleScan);
    state.listScanPanel.querySelector('#ssp-stop-btn').addEventListener('click', stopSubtitleScan);
    restoreSubtitleScanPanelState();

    // タブ切り替えイベント
    state.listScanPanel.querySelectorAll('.lsp-tab').forEach(tab => {
      tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });
  }

  function toggleListScanPanel() {
    if (state.listScanPanelVisible) {
      hideListScanPanel();
    } else {
      showListScanPanel();
    }
  }

  function showListScanPanel() {
    if (!state.listScanPanel) {
      createListScanPanel();
    }
    state.listScanPanel.classList.add('visible');
    state.listScanPanelVisible = true;
    updateTriggerButtonState();

    // 既存のリストスキャン状態を復元
    restoreListScanState();
  }

  function hideListScanPanel() {
    if (state.listScanPanel) {
      state.listScanPanel.classList.remove('visible');
    }
    state.listScanPanelVisible = false;
    updateTriggerButtonState();
  }

  function isListScanPanelVisible() {
    return state.listScanPanelVisible;
  }

  async function loadVideoIdList() {
    const textarea = state.listScanPanel.querySelector('#lsp-video-ids');
    const text = textarea.value.trim();

    if (!text) {
      return;
    }

    // videoIdをパース（1行1ID、空行・空白を除外）
    const videoIds = text
      .split('\n')
      .map(line => line.trim())
      .filter(id => id && /^[a-zA-Z0-9_-]{11}$/.test(id));

    if (videoIds.length === 0) {
      return;
    }

    currentListScanVideoIds = videoIds;

    // ストレージに保存
    await chrome.storage.local.set({
      listScanVideoIds: videoIds,
      listScanCurrentIndex: 0
    });

    // UIを更新
    await renderVideoList();
  }

  async function fetchListScanTargetsFromServer() {
    const progressInfo = state.listScanPanel.querySelector('#lsp-progress-info');

    if (!state.ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!state.ycsApiToken) {
      progressInfo.textContent = missingTokenMessage();
      return;
    }

    progressInfo.textContent = '対象を読み込んでいます…';

    try {
      const response = await fetch(`${state.ycsServerUrl}/api/extension/scan-targets`, {
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${state.ycsApiToken}`,
        },
      });
      if (!response.ok) {
        throw new Error(`一覧の取得に失敗しました (${response.status})`);
      }

      const data = await response.json();
      const targets = data.targets || [];

      if (targets.length === 0) {
        progressInfo.textContent = 'タイムスタンプ未作成のアーカイブはありません';
        return;
      }

      state.listScanPanel.querySelector('#lsp-video-ids').value = targets.map(t => t.video_id).join('\n');
      await loadVideoIdList();
    } catch (error) {
      console.error('[YCS] スキャン対象の読み込みエラー:', error);
      progressInfo.textContent = 'エラー: ' + error.message;
    }
  }

  async function clearVideoIdList() {
    currentListScanVideoIds = [];
    state.listScanPanel.querySelector('#lsp-video-ids').value = '';
    state.listScanPanel.querySelector('#lsp-video-list').innerHTML = '<div class="lsp-empty">VideoIDを入力して読み込みボタンをクリック</div>';
    state.listScanPanel.querySelector('#lsp-progress-info').textContent = '0 / 0';
    state.listScanPanel.querySelector('#lsp-start-btn').disabled = true;

    await chrome.storage.local.remove(['listScanVideoIds', 'listScanCurrentIndex', 'listScanActive']);
  }

  async function renderVideoList() {
    const listContainer = state.listScanPanel.querySelector('#lsp-video-list');
    const startBtn = state.listScanPanel.querySelector('#lsp-start-btn');
    const progressInfo = state.listScanPanel.querySelector('#lsp-progress-info');

    if (currentListScanVideoIds.length === 0) {
      listContainer.innerHTML = '<div class="lsp-empty">VideoIDを入力して読み込みボタンをクリック</div>';
      startBtn.disabled = true;
      return;
    }

    // 各動画のスキャン状況とタイムスタンプ作成状況を並行取得
    const [statuses, tsStatusMap] = await Promise.all([
      Promise.all(currentListScanVideoIds.map(id => getVideoScanStatus(id))),
      fetchTimestampStatuses(currentListScanVideoIds),
    ]);

    // 完了数をカウント（この表示は「完了した動画数/全体数」であり、選択中の位置ではない）
    const completedCount = statuses.filter(s => s.status === 'completed').length;
    progressInfo.textContent = `完了 ${completedCount} / ${currentListScanVideoIds.length}`;

    // 現在の動画ID
    const currentVideoId = getVideoId();

    // リストを描画
    listContainer.innerHTML = currentListScanVideoIds.map((videoId, index) => {
      const status = statuses[index];
      const isCurrent = videoId === currentVideoId;
      const statusIcon = {
        'not_scanned': '○',
        'scanning': '●',
        'completed': '✓',
        'partial': '△'
      }[status.status] || '○';

      const statusClass = status.status;
      let tsBadge;
      if (!tsStatusMap) {
        tsBadge = `<span class="lsp-ts-badge unknown" title="取得失敗">TS ?</span>`;
      } else {
        const tsCount = tsStatusMap[videoId] || 0;
        tsBadge = tsCount > 0
          ? `<span class="lsp-ts-badge has-ts" title="タイムスタンプ ${tsCount}件">TS ${tsCount}</span>`
          : `<span class="lsp-ts-badge no-ts" title="タイムスタンプ未作成">TS 0</span>`;
      }

      return `
      <div class="lsp-item ${isCurrent ? 'current' : ''}" data-video-id="${videoId}">
        <span class="lsp-item-index">${index + 1}.</span>
        <span class="lsp-item-id">${videoId}</span>
        ${tsBadge}
        <div class="lsp-item-status">
          <span class="lsp-status-icon ${statusClass}">${statusIcon}</span>
          <div class="lsp-progress-bar">
            <div class="lsp-progress-fill" style="width: ${status.progress}%"></div>
          </div>
          <span>${status.progress}%</span>
        </div>
        <button class="lsp-open-btn" data-video-id="${videoId}">開く</button>
      </div>
    `;
    }).join('');

    // [開く]ボタンのイベントリスナー
    listContainer.querySelectorAll('.lsp-open-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const videoId = e.target.dataset.videoId;
        window.location.href = `https://www.youtube.com/watch?v=${videoId}`;
      });
    });

    startBtn.disabled = false;
  }

  async function getVideoScanStatus(videoId) {
    const key = `volumeData_${videoId}`;
    const result = await chrome.storage.local.get(key);

    if (!result[key] || !result[key].data) {
      return { status: 'not_scanned', progress: 0 };
    }

    const data = result[key].data;
    const filledCount = data.filter(v => v > 0).length;
    const progress = Math.round((filledCount / data.length) * 100);

    if (progress >= 95) {
      return { status: 'completed', progress: 100 };
    } else if (progress > 0) {
      return { status: 'partial', progress };
    }

    return { status: 'not_scanned', progress: 0 };
  }

  async function fetchTimestampStatuses(videoIds) {
    if (!state.ycsApiToken || !state.ycsServerUrl || videoIds.length === 0) {
      return null;
    }
    try {
      const response = await fetch(
        `${state.ycsServerUrl}/api/extension/timestamp-status?video_ids=${videoIds.join(',')}`,
        { headers: { 'Authorization': `Bearer ${state.ycsApiToken}`, 'Accept': 'application/json' } }
      );
      if (!response.ok) return null;
      const data = await response.json();
      return data.statuses || null;
    } catch {
      return null;
    }
  }

  async function restoreListScanState() {
    try {
      const result = await chrome.storage.local.get([
        'listScanVideoIds',
        'listScanCurrentIndex',
        'listScanActive'
      ]);

      if (result.listScanVideoIds && result.listScanVideoIds.length > 0) {
        currentListScanVideoIds = result.listScanVideoIds;
        state.listScanPanel.querySelector('#lsp-video-ids').value = result.listScanVideoIds.join('\n');
        await renderVideoList();

        // スキャン中の場合はUIを更新
        if (result.listScanActive) {
          state.listScanPanel.querySelector('#lsp-start-btn').style.display = 'none';
          state.listScanPanel.querySelector('#lsp-stop-btn').style.display = 'block';
        }
      }
    } catch (error) {
      console.error('リストスキャン状態復元エラー:', error);
    }
  }

  async function startListScanFromPanel() {
    if (currentListScanVideoIds.length === 0) return;

    // スキャン済みでない最初の動画を見つける
    let startIndex = 0;
    for (let i = 0; i < currentListScanVideoIds.length; i++) {
      const status = await getVideoScanStatus(currentListScanVideoIds[i]);
      if (status.status !== 'completed') {
        startIndex = i;
        break;
      }
      if (i === currentListScanVideoIds.length - 1) {
        // 全て完了済み
        console.log('リストスキャン: 全ての動画がスキャン済みです');
        return;
      }
    }

    // ストレージに状態を保存（実行タブのIDも記録し、他タブでの遷移暴走を防ぐ・#580）
    const ownTabId = await getOwnTabId();
    await chrome.storage.local.set({
      listScanVideoIds: currentListScanVideoIds,
      listScanCurrentIndex: startIndex,
      listScanActive: true,
      listScanTabId: ownTabId
    });
    state.listScanProceeding = false;

    // UIを更新
    state.listScanPanel.querySelector('#lsp-start-btn').style.display = 'none';
    state.listScanPanel.querySelector('#lsp-stop-btn').style.display = 'block';

    // 対象動画に移動
    const targetVideoId = currentListScanVideoIds[startIndex];
    const currentVideoId = getVideoId();

    if (currentVideoId !== targetVideoId) {
      window.location.href = `https://www.youtube.com/watch?v=${targetVideoId}`;
    } else {
      // 現在の動画がターゲットなら、スキャンボタンを表示
      state.isListScanMode = true;
      showListScanButton(startIndex, currentListScanVideoIds.length);
    }
  }

  async function stopListScanFromPanel() {
    state.isListScanMode = false;
    hideListScanButton();

    await chrome.storage.local.set({ listScanActive: false });

    // UIを更新
    if (state.listScanPanel) {
      state.listScanPanel.querySelector('#lsp-start-btn').style.display = 'block';
      state.listScanPanel.querySelector('#lsp-stop-btn').style.display = 'none';
    }

    // スキャン中の場合は停止
    stopDirectScan();
    chrome.runtime.sendMessage({ type: 'STOP_SCAN' });
  }

  // === Lines 4520-4893: List scan button and navigation ===

  function showListScanButton(currentIndex, totalCount) {
    // 既存のボタンがあれば更新のみ
    if (listScanButtonContainer) {
      const info = listScanButtonContainer.querySelector('.list-scan-info');
      if (info) {
        info.textContent = `動画 ${currentIndex + 1} / ${totalCount}`;
      }
      listScanButtonContainer.style.display = 'flex';
      return;
    }

    // コンテナを作成
    listScanButtonContainer = document.createElement('div');
    listScanButtonContainer.id = 'list-scan-button-container';
    listScanButtonContainer.innerHTML = `
    <style>
      #list-scan-button-container {
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 9999;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 12px;
        padding: 16px 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        display: flex;
        flex-direction: column;
        gap: 12px;
        font-family: 'Roboto', sans-serif;
        animation: slideIn 0.3s ease-out;
      }
      @keyframes slideIn {
        from { transform: translateX(100px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }
      #list-scan-button-container .list-scan-title {
        color: white;
        font-size: 14px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 8px;
      }
      #list-scan-button-container .list-scan-info {
        color: rgba(255,255,255,0.9);
        font-size: 13px;
      }
      #list-scan-button-container .list-scan-btn {
        background: white;
        color: #667eea;
        border: none;
        border-radius: 8px;
        padding: 12px 24px;
        font-size: 14px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.2s;
      }
      #list-scan-button-container .list-scan-btn:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      }
      #list-scan-button-container .list-scan-btn:active {
        transform: scale(0.98);
      }
      #list-scan-button-container .list-scan-btn.scanning {
        background: #ff5722;
        color: white;
      }
      #list-scan-button-container .list-scan-cancel {
        background: transparent;
        color: rgba(255,255,255,0.8);
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 6px;
        padding: 8px 16px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
      }
      #list-scan-button-container .list-scan-cancel:hover {
        background: rgba(255,255,255,0.1);
        color: white;
      }
      #list-scan-button-container .list-scan-countdown {
        color: #ffeb3b;
        font-size: 12px;
        text-align: center;
        animation: pulse 1s infinite;
      }
      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
      }
    </style>
    <div class="list-scan-title">
      📋 リストスキャン
    </div>
    <div class="list-scan-info">動画 ${currentIndex + 1} / ${totalCount}</div>
    <div class="list-scan-countdown" id="list-scan-countdown">3秒後に自動開始...</div>
    <button class="list-scan-btn" id="list-scan-start-btn">▶ スキャン開始</button>
    <button class="list-scan-cancel" id="list-scan-cancel-btn">キャンセル</button>
  `;

    document.body.appendChild(listScanButtonContainer);

    // イベントリスナー
    const startBtn = listScanButtonContainer.querySelector('#list-scan-start-btn');
    const cancelBtn = listScanButtonContainer.querySelector('#list-scan-cancel-btn');
    const countdown = listScanButtonContainer.querySelector('#list-scan-countdown');

    const startScan = async () => {
      // タイマーをクリア
      clearAutoClickTimer();
      if (countdown) countdown.style.display = 'none';
      startBtn.classList.add('scanning');
      startBtn.textContent = '⏳ スキャン中...';
      // tabCaptureはポップアップ操作（activeTab権限）が必要なため、
      // コンテンツスクリプト内のAudioContextで直接スキャンする
      const success = await startDirectScan();
      if (!success) {
        startBtn.classList.remove('scanning');
        startBtn.textContent = '▶ スキャン開始';
      }
    };

    startBtn.addEventListener('click', startScan);

    cancelBtn.addEventListener('click', async () => {
      clearAutoClickTimer();
      state.isListScanMode = false;
      stopDirectScan();
      await chrome.storage.local.set({ listScanActive: false });
      hideListScanButton();
      console.log('リストスキャン: キャンセルされました');
    });

    // 自動クリックタイマーを開始
    startAutoClickTimer(startScan, countdown);
  }

  function hideListScanButton() {
    clearAutoClickTimer();
    if (listScanButtonContainer) {
      listScanButtonContainer.style.display = 'none';
    }
  }

  function startAutoClickTimer(callback, countdownElement) {
    clearAutoClickTimer();

    let remaining = LIST_SCAN_AUTO_CLICK_DELAY / 1000;

    // カウントダウン表示を更新
    const updateCountdown = () => {
      if (countdownElement) {
        countdownElement.textContent = `${remaining}秒後に自動開始...`;
      }
    };

    updateCountdown();

    // 1秒ごとにカウントダウン
    listScanCountdownInterval = setInterval(() => {
      remaining--;
      if (remaining > 0) {
        updateCountdown();
      } else {
        clearInterval(listScanCountdownInterval);
        listScanCountdownInterval = null;
      }
    }, 1000);

    // 指定時間後に自動クリック
    listScanAutoClickTimer = setTimeout(() => {
      clearInterval(listScanCountdownInterval);
      listScanCountdownInterval = null;
      console.log('リストスキャン: 自動クリック実行');
      callback();
    }, LIST_SCAN_AUTO_CLICK_DELAY);
  }

  function clearAutoClickTimer() {
    if (listScanAutoClickTimer) {
      clearTimeout(listScanAutoClickTimer);
      listScanAutoClickTimer = null;
    }
    if (listScanCountdownInterval) {
      clearInterval(listScanCountdownInterval);
      listScanCountdownInterval = null;
    }
  }

  function updateListScanButtonState(scanning) {
    if (!listScanButtonContainer) return;
    const btn = listScanButtonContainer.querySelector('#list-scan-start-btn');
    if (!btn) return;

    {
      btn.classList.add('scanning');
      btn.textContent = '⏳ スキャン中...';
    }
  }

  async function checkAndStartListScan() {
    try {
      const result = await chrome.storage.local.get([
        'listScanVideoIds',
        'listScanCurrentIndex',
        'listScanActive',
        'listScanTabId'
      ]);

      if (!result.listScanActive || !result.listScanVideoIds) {
        return;
      }

      // 実行タブ以外ではスキャンモードに入らない（#580: 別タブで対象動画を
      // 開くと、そのタブが次々に遷移して共有インデックスを壊してしまう）
      const ownTabId = await getOwnTabId();
      if (result.listScanTabId != null && ownTabId !== result.listScanTabId) {
        return;
      }

      state.listScanProceeding = false;

      const videoIds = result.listScanVideoIds;
      const currentIndex = result.listScanCurrentIndex || 0;
      const currentVideoId = getVideoId();

      // 現在の動画がリストに含まれているか確認
      const expectedVideoId = videoIds[currentIndex];
      if (currentVideoId !== expectedVideoId) {
        console.log('リストスキャン: 想定外のvideoIdです', { expected: expectedVideoId, actual: currentVideoId });
        return;
      }

      console.log(`リストスキャン: 動画 ${currentIndex + 1}/${videoIds.length} の準備中`);
      state.isListScanMode = true;

      // 動画の読み込みを待ってからボタンを表示
      waitForVideoAndShowButton(currentIndex, videoIds.length);
    } catch (error) {
      console.error('リストスキャンチェックエラー:', error);
    }
  }

  function waitForVideoAndShowButton(currentIndex, totalCount) {
    let attempt = 0;
    const checkVideo = () => {
      // 広告中は実動画のdurationが取れないため準備完了と見なさない。
      // ライブ配信中の枠（duration=Infinity）はスキャン不能なのでタイムアウトスキップに落とす（#607）
      if (state.videoElement && state.videoElement.readyState >= 2 && state.videoDuration > 0 && isFinite(state.videoDuration) && !isAdShowing()) {
        // 既にスキャン済みかチェック
        isCurrentVideoScanned().then(scanned => {
          if (scanned) {
            console.log('リストスキャン: 既にスキャン済み、次の動画へ');
            proceedToNextListScanVideo();
          } else {
            console.log('リストスキャン: ボタンを表示');
            // ボタンを表示（ユーザーのクリックでスキャン開始）
            showListScanButton(currentIndex, totalCount);
          }
        });
      } else if (attempt >= 30) {
        // 配信予定の枠・限定公開・削除済みなど、動画が再生可能にならないページで
        // 永久に待ち続けないようスキップして次へ進む（#605）
        console.warn('リストスキャン: 動画が再生可能にならないためスキップします', getVideoId());
        proceedToNextListScanVideo();
      } else {
        attempt++;
        setTimeout(checkVideo, 1000);
      }
    };

    // 少し待ってからチェック開始（ページ読み込み完了を待つ）
    setTimeout(checkVideo, 2000);
  }

  async function proceedToNextListScanVideo() {
    if (!state.isListScanMode) {
      return;
    }

    // 二重実行防止（#607）: 遷移までの待機中に別経路のproceedが走ると
    // インデックスが二重加算され、次の動画が飛ばされてしまう
    if (state.listScanProceeding) {
      console.log('リストスキャン: 遷移処理が既に進行中のためスキップ');
      return;
    }
    state.listScanProceeding = true;

    try {
      const result = await chrome.storage.local.get([
        'listScanVideoIds',
        'listScanCurrentIndex',
        'listScanTabId'
      ]);

      // 実行タブ以外ではインデックスを進めない（#580）
      const ownTabId = await getOwnTabId();
      if (result.listScanTabId != null && ownTabId !== result.listScanTabId) {
        console.log('リストスキャン: 実行タブではないため遷移しません');
        return;
      }

      const videoIds = result.listScanVideoIds || [];
      const currentIndex = result.listScanCurrentIndex || 0;
      const nextIndex = currentIndex + 1;

      if (nextIndex >= videoIds.length) {
        // 全て完了
        console.log('リストスキャン完了: すべての動画をスキャンしました');
        state.isListScanMode = false;
        hideListScanButton();
        await chrome.storage.local.set({
          listScanActive: false
        });
        // 完了メッセージをポップアップに通知
        chrome.runtime.sendMessage({ type: 'LIST_SCAN_COMPLETE' });
        return;
      }

      // インデックスを更新
      await chrome.storage.local.set({
        listScanCurrentIndex: nextIndex
      });

      // 次の動画へ移動
      const nextVideoId = videoIds[nextIndex];
      console.log(`リストスキャン: 次の動画へ移動 (${nextIndex + 1}/${videoIds.length}): ${nextVideoId}`);

      // 少し待ってから移動（安定性向上のため）。待機中に停止されたら遷移しない
      setTimeout(async () => {
        const { listScanActive } = await chrome.storage.local.get(['listScanActive']);
        if (!listScanActive || !state.isListScanMode) {
          state.listScanProceeding = false;
          return;
        }
        window.location.href = `https://www.youtube.com/watch?v=${nextVideoId}`;
      }, 1500);
    } catch (error) {
      console.error('リストスキャン次の動画移動エラー:', error);
      state.isListScanMode = false;
      state.listScanProceeding = false;
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
        toggleListScanPanel();
      });

      // チャット検索ボタンのイベント
      buttonContainer.querySelector('#ycs-chat-btn')?.addEventListener('click', () => {
        toggleChatSearchPanel();
      });

      // 字幕取得ボタンのイベント
      buttonContainer.querySelector('#ycs-subtitle-btn')?.addEventListener('click', () => {
        toggleSubtitlePanel();
      });

      // ハイライト検出ボタンのイベント
      buttonContainer.querySelector('#ycs-highlight-btn')?.addEventListener('click', () => {
        toggleHighlightPanel();
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
        if (isListScanPanelVisible()) {
          listBtn.classList.add('active');
        } else {
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
        updateListScanButtonState();
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
        if (state.isListScanMode) {
          isCurrentVideoScanned().then(async (completed) => {
            if (completed) {
              hideListScanButton();
              proceedToNextListScanVideo();
            } else {
              const storageData = await chrome.storage.local.get(['listScanCurrentIndex', 'listScanVideoIds']);
              if (storageData.listScanVideoIds) {
                showListScanButton(storageData.listScanCurrentIndex || 0, storageData.listScanVideoIds.length);
              }
            }
          });
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
    checkAndStartListScan();
    checkAndStartSubtitleScan();
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
        state.pageBridgeReady = null;
        if (isHighlightPanelVisible()) hideHighlightPanel();
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
        state.pageBridgeReady = null;
        if (isHighlightPanelVisible()) hideHighlightPanel();

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
