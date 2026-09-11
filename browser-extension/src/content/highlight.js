import state from './state.js';
import {
  HIGHLIGHT_DB_NAME, HIGHLIGHT_DB_VERSION, HIGHLIGHT_STORE_NAME,
  HIGHLIGHT_MAX_AGE_DAYS, SAMPLING_INTERVAL_SEC,
  CHAT_STORE_NAME
} from './config.js';
import { getVideoId, escapeHtml, formatSubtitleTime } from './utils.js';
import { loadYcsApiSettings, missingTokenMessage } from './api.js';
import { updateTriggerButtonState } from './ui.js';
import { hideChatSearchPanel, isChatSearchPanelVisible } from './chat-search.js';
import { hideSubtitlePanel, isSubtitlePanelVisible } from './subtitle-panel.js';
import { initChatDB, loadChatDataForVideo } from './chat-search.js';

let highlightPanel = null;
let highlightPanelVisible = false;
let highlightDB = null;
let highlightTooltipHideTimer = null;

export function toggleHighlightPanel() {
  if (highlightPanelVisible) {
    hideHighlightPanel();
  } else {
    showHighlightPanel();
  }
}

export function showHighlightPanel() {
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

export function hideHighlightPanel() {
  if (highlightPanel) {
    highlightPanel.classList.remove('visible');
  }
  hideHighlightSubtitleTooltip();
  highlightPanelVisible = false;
  updateTriggerButtonState();
}

export function isHighlightPanelVisible() {
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
