import state from './state.js';
import { CHAT_DB_NAME, CHAT_DB_VERSION, CHAT_STORE_NAME, CHAT_MAX_AGE_DAYS } from './config.js';
import { getVideoId, escapeHtml, formatTimestampMsec } from './utils.js';
import { updateTriggerButtonState } from './ui.js';
import { hideSubtitlePanel, isSubtitlePanelVisible } from './subtitle-panel.js';
import { hideHighlightPanel, isHighlightPanelVisible } from './highlight.js';
import { ensurePageBridge } from './subtitle-panel.js';

let chatSearchPanel = null;
let chatSearchPanelVisible = false;
let chatSearchDB = null;

export function toggleChatSearchPanel() {
  if (chatSearchPanelVisible) {
    hideChatSearchPanel();
  } else {
    showChatSearchPanel();
  }
}

export async function showChatSearchPanel() {
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

export function hideChatSearchPanel() {
  if (chatSearchPanel) {
    chatSearchPanel.classList.remove('visible');
  }
  chatSearchPanelVisible = false;
  updateTriggerButtonState();
}

export function isChatSearchPanelVisible() {
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
export function initChatDB() {
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

export async function loadChatDataForVideo(videoId) {
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
export async function getChatContinuation() {
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

export async function fetchAllChatReplays(initialContinuation, onProgress) {
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

export async function saveChatsToDB(videoId, chats) {
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
  const resultsEl = chatSearchPanel?.querySelector('#csp-results');
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
