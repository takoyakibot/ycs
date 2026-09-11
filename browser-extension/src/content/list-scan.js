import state from './state.js';
import { LIST_SCAN_AUTO_CLICK_DELAY } from './config.js';
import { getVideoId, escapeHtml } from './utils.js';
import { getOwnTabId } from './subtitle-scan.js';
import { loadYcsApiSettings, isExtensionContextValid, missingTokenMessage } from './api.js';
import { updateTriggerButtonState } from './ui.js';
// Functions from modules not yet extracted -- these imports will resolve
// once the remaining content.js sections are modularised
import { startDirectScan, stopDirectScan } from './audio.js';
import { isCurrentVideoScanned, isAdShowing } from './utils.js';
import {
  clearAllScannedVideos, loadSubtitleScanTargets,
  startSubtitleScan, stopSubtitleScan,
  restoreSubtitleScanPanelState
} from './subtitle-scan.js';

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
    import('./subtitle-scan.js').then(m => m.loadScannedVideosList?.());
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

export function toggleListScanPanel() {
  if (state.listScanPanelVisible) {
    hideListScanPanel();
  } else {
    showListScanPanel();
  }
}

export function showListScanPanel() {
  if (!state.listScanPanel) {
    createListScanPanel();
  }
  state.listScanPanel.classList.add('visible');
  state.listScanPanelVisible = true;
  updateTriggerButtonState();

  // 既存のリストスキャン状態を復元
  restoreListScanState();
}

export function hideListScanPanel() {
  if (state.listScanPanel) {
    state.listScanPanel.classList.remove('visible');
  }
  state.listScanPanelVisible = false;
  updateTriggerButtonState();
}

export function isListScanPanelVisible() {
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

export async function restoreListScanState() {
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

export async function startListScanFromPanel() {
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

export async function stopListScanFromPanel() {
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

export function showListScanButton(currentIndex, totalCount) {
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

export function hideListScanButton() {
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

export function updateListScanButtonState(scanning) {
  if (!listScanButtonContainer) return;
  const btn = listScanButtonContainer.querySelector('#list-scan-start-btn');
  if (!btn) return;

  if (scanning) {
    btn.classList.add('scanning');
    btn.textContent = '⏳ スキャン中...';
  } else {
    btn.classList.remove('scanning');
    btn.textContent = '▶ スキャン開始';
  }
}

export async function checkAndStartListScan() {
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

export async function proceedToNextListScanVideo() {
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
