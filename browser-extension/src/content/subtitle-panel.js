import state from './state.js';
import { getVideoId, escapeHtml, formatSubtitleTime } from './utils.js';
import { loadYcsApiSettings, sendSubtitlesToServer, isExtensionContextValid, missingTokenMessage } from './api.js';
import { updateTriggerButtonState } from './ui.js';
import { hideChatSearchPanel, isChatSearchPanelVisible } from './chat-search.js';
import { hideHighlightPanel, isHighlightPanelVisible } from './highlight.js';

let subtitlePanel = null;
let subtitlePanelVisible = false;

export function toggleSubtitlePanel() {
  if (subtitlePanelVisible) {
    hideSubtitlePanel();
  } else {
    showSubtitlePanel();
  }
}

export async function showSubtitlePanel() {
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

export function hideSubtitlePanel() {
  if (subtitlePanel) {
    subtitlePanel.classList.remove('visible');
  }
  subtitlePanelVisible = false;
  updateTriggerButtonState();
}

export function isSubtitlePanelVisible() {
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

export function ensurePageBridge() {
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

export async function getCaptionTracksFromPage() {
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

export async function fetchSubtitleTracks(videoId) {
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
export function fetchTimedText(videoId, lang) {
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
