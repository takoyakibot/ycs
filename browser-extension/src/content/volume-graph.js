import state from './state.js';
import { ZOOM_LEVELS, MARKER_SNAP_THRESHOLD_SEC, MARKER_SNAP_THRESHOLD_PX } from './config.js';
import { formatTimeDisplay, updateTimeMarker } from './utils.js';
import { updatePlaylistUI, startAutoScan, stopAutoScan } from './playlist.js';
import { setAudioGain, discardVolumeDataAndReset, loadVolumeData, startDirectScan } from './audio.js';
import {
  updateTimestampList, pushMarkerHistory, snapshotMarkers,
  undoMarkers, redoMarkers, deleteSelectedMarker, moveSelectedMarker,
  deselectMarker, blurMarkerTextInput, updateTimestampListSelection,
  scrollSelectedRowIntoView,
} from './timestamp-editor.js';
import { autoDetectSongStarts } from './auto-detect.js';
import { copyTimestamps, importTimestamps, saveMarkersToStorage, loadMarkersFromStorage } from './timestamp-io.js';
import { closeLyricsPastePopup, closeSongCandidatePopup, isLyricsPastePopupOpen } from './song-candidates.js';

export function createVolumeGraph() {
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

export function insertVolumeGraph() {
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

export function resizeCanvas() {
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

export function getZoomLevel() {
  return ZOOM_LEVELS[state.zoomIndex];
}

export function changeZoomLevel(delta) {
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

export function updateZoomButtons() {
  const zoomInBtn = state.volumeGraphContainer?.querySelector('#vdg-zoom-in-btn');
  const zoomOutBtn = state.volumeGraphContainer?.querySelector('#vdg-zoom-out-btn');
  if (zoomInBtn) zoomInBtn.disabled = state.zoomIndex >= ZOOM_LEVELS.length - 1;
  if (zoomOutBtn) zoomOutBtn.disabled = state.zoomIndex <= 0;
}

export function getMarkerSnapThresholdSec(totalWidth) {
  if (!state.videoDuration || !totalWidth) return MARKER_SNAP_THRESHOLD_SEC;
  const pxPerSec = totalWidth / state.videoDuration;
  return Math.max(MARKER_SNAP_THRESHOLD_SEC, MARKER_SNAP_THRESHOLD_PX / pxPerSec);
}

export function setupVolumeGraphEvents() {
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
    const canvasWrapper = container.querySelector('#vdg-canvas-wrapper');

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
      if (draggingMarkerId !== null) {
        // ドラッグ中はgrabbing（documentのmousemove側で設定）を維持
      } else if (state.tsEditorMode === 'marker') {
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

export function isEditableTarget(target) {
  if (!(target instanceof Element)) return false;
  if (target.isContentEditable) return true;
  return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

export function isTsEditorKeyScope() {
  const active = document.activeElement;
  if (!active || active === document.body || active === document.documentElement) return true;
  if (state.volumeGraphContainer && state.volumeGraphContainer.contains(active)) return true;
  if (active === state.videoElement) return true;
  if (active.id === 'movie_player') return true;
  return false;
}

export function drawSingingOverlay(height, barWidth, maxVolume) {
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

export function drawVolumeGraph() {
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

export function drawTimestampMarkers(width, height) {
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
