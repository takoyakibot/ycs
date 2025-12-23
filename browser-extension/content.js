/**
 * 歌枠タイムスタンプ検出 - Content Script
 *
 * YouTubeページに注入され、動画の再生時刻を取得してbackground.jsに送信する
 * また、検出されたタイムスタンプをオーバーレイ表示する
 */

let videoElement = null;
let timeUpdateInterval = null;
let overlay = null;

// 初期化
function init() {
  // 動画要素を取得
  findVideoElement();

  // オーバーレイを作成
  createOverlay();

  // メッセージリスナーを設定
  chrome.runtime.onMessage.addListener(handleMessage);
}

/**
 * YouTube動画要素を見つける
 */
function findVideoElement() {
  const checkVideo = () => {
    videoElement = document.querySelector('video.html5-main-video');
    if (videoElement) {
      console.log('動画要素を検出しました');
      startTimeSync();
    } else {
      setTimeout(checkVideo, 1000);
    }
  };
  checkVideo();
}

/**
 * 再生時刻の同期を開始
 */
function startTimeSync() {
  if (timeUpdateInterval) {
    clearInterval(timeUpdateInterval);
  }

  timeUpdateInterval = setInterval(() => {
    if (videoElement && !videoElement.paused) {
      chrome.runtime.sendMessage({
        type: 'UPDATE_VIDEO_TIME',
        time: videoElement.currentTime
      });
    }
  }, 100);
}

/**
 * オーバーレイUIを作成
 */
function createOverlay() {
  overlay = document.createElement('div');
  overlay.id = 'timestamp-detector-overlay';
  overlay.innerHTML = `
    <style>
      #timestamp-detector-overlay {
        position: fixed;
        top: 80px;
        right: 20px;
        width: 320px;
        max-height: 400px;
        background: rgba(0, 0, 0, 0.9);
        border: 1px solid #444;
        border-radius: 8px;
        color: white;
        font-family: 'Segoe UI', sans-serif;
        font-size: 13px;
        z-index: 9999;
        display: none;
        flex-direction: column;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
      }

      #timestamp-detector-overlay.visible {
        display: flex;
      }

      .tsd-header {
        padding: 12px;
        background: #1a1a1a;
        border-bottom: 1px solid #444;
        border-radius: 8px 8px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .tsd-header h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
      }

      .tsd-status {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
      }

      .tsd-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #666;
      }

      .tsd-status-dot.active {
        background: #4caf50;
        animation: pulse 1.5s infinite;
      }

      @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
      }

      .tsd-content {
        padding: 12px;
        overflow-y: auto;
        flex: 1;
      }

      .tsd-timestamps {
        list-style: none;
        padding: 0;
        margin: 0;
      }

      .tsd-timestamp-item {
        display: flex;
        align-items: center;
        padding: 8px;
        margin-bottom: 6px;
        background: #2a2a2a;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.2s;
      }

      .tsd-timestamp-item:hover {
        background: #3a3a3a;
      }

      .tsd-timestamp-item.new {
        animation: highlight 1s ease-out;
      }

      @keyframes highlight {
        0% { background: #4a6a4a; }
        100% { background: #2a2a2a; }
      }

      .tsd-time {
        font-family: monospace;
        font-size: 14px;
        font-weight: 600;
        color: #4fc3f7;
        margin-right: 12px;
        min-width: 60px;
      }

      .tsd-label {
        flex: 1;
        color: #999;
        font-size: 12px;
      }

      .tsd-remove {
        background: none;
        border: none;
        color: #666;
        cursor: pointer;
        padding: 4px;
        font-size: 16px;
      }

      .tsd-remove:hover {
        color: #f44336;
      }

      .tsd-empty {
        color: #666;
        text-align: center;
        padding: 20px;
      }

      .tsd-footer {
        padding: 12px;
        border-top: 1px solid #444;
        display: flex;
        gap: 8px;
      }

      .tsd-btn {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        transition: background 0.2s;
      }

      .tsd-btn-primary {
        background: #1976d2;
        color: white;
      }

      .tsd-btn-primary:hover {
        background: #1565c0;
      }

      .tsd-btn-secondary {
        background: #333;
        color: white;
      }

      .tsd-btn-secondary:hover {
        background: #444;
      }

      .tsd-btn-danger {
        background: #c62828;
        color: white;
      }

      .tsd-btn-danger:hover {
        background: #b71c1c;
      }
    </style>
    <div class="tsd-header">
      <h3>タイムスタンプ検出</h3>
      <div class="tsd-status">
        <span class="tsd-status-dot" id="tsd-status-dot"></span>
        <span id="tsd-status-text">停止中</span>
      </div>
    </div>
    <div class="tsd-content">
      <ul class="tsd-timestamps" id="tsd-timestamp-list">
        <li class="tsd-empty">検出されたタイムスタンプがありません</li>
      </ul>
    </div>
    <div class="tsd-footer">
      <button class="tsd-btn tsd-btn-secondary" id="tsd-copy-btn">コピー</button>
      <button class="tsd-btn tsd-btn-danger" id="tsd-clear-btn">クリア</button>
    </div>
  `;

  document.body.appendChild(overlay);

  // イベントリスナーを設定
  document.getElementById('tsd-copy-btn').addEventListener('click', copyTimestamps);
  document.getElementById('tsd-clear-btn').addEventListener('click', clearTimestamps);
}

/**
 * タイムスタンプを追加
 */
function addTimestamp(timestamp) {
  const list = document.getElementById('tsd-timestamp-list');
  const empty = list.querySelector('.tsd-empty');
  if (empty) {
    empty.remove();
  }

  const item = document.createElement('li');
  item.className = 'tsd-timestamp-item new';
  item.dataset.time = timestamp.time;
  item.innerHTML = `
    <span class="tsd-time">${timestamp.formattedTime}</span>
    <span class="tsd-label">楽曲開始候補</span>
    <button class="tsd-remove" title="削除">&times;</button>
  `;

  // クリックで動画をシーク
  item.addEventListener('click', (e) => {
    if (e.target.classList.contains('tsd-remove')) {
      item.remove();
      if (list.children.length === 0) {
        list.innerHTML = '<li class="tsd-empty">検出されたタイムスタンプがありません</li>';
      }
      return;
    }
    if (videoElement) {
      videoElement.currentTime = parseFloat(item.dataset.time);
    }
  });

  list.appendChild(item);

  // newクラスを削除
  setTimeout(() => item.classList.remove('new'), 1000);
}

/**
 * タイムスタンプをクリップボードにコピー
 */
async function copyTimestamps() {
  const items = document.querySelectorAll('.tsd-timestamp-item');
  if (items.length === 0) return;

  const lines = Array.from(items).map(item => {
    const time = item.querySelector('.tsd-time').textContent;
    return time;
  });

  try {
    await navigator.clipboard.writeText(lines.join('\n'));
    const btn = document.getElementById('tsd-copy-btn');
    const originalText = btn.textContent;
    btn.textContent = 'コピーしました!';
    setTimeout(() => btn.textContent = originalText, 1500);
  } catch (err) {
    console.error('コピーに失敗しました:', err);
  }
}

/**
 * タイムスタンプをクリア
 */
function clearTimestamps() {
  chrome.runtime.sendMessage({ type: 'CLEAR_TIMESTAMPS' });
  const list = document.getElementById('tsd-timestamp-list');
  list.innerHTML = '<li class="tsd-empty">検出されたタイムスタンプがありません</li>';
}

/**
 * ステータス表示を更新
 */
function updateStatus(isCapturing) {
  const dot = document.getElementById('tsd-status-dot');
  const text = document.getElementById('tsd-status-text');

  if (isCapturing) {
    dot.classList.add('active');
    text.textContent = '検出中';
  } else {
    dot.classList.remove('active');
    text.textContent = '停止中';
  }
}

/**
 * メッセージハンドラ
 */
function handleMessage(message, sender, sendResponse) {
  switch (message.type) {
    case 'TIMESTAMP_DETECTED':
      addTimestamp(message.timestamp);
      break;

    case 'SHOW_OVERLAY':
      overlay.classList.add('visible');
      break;

    case 'HIDE_OVERLAY':
      overlay.classList.remove('visible');
      break;

    case 'TOGGLE_OVERLAY':
      overlay.classList.toggle('visible');
      break;

    case 'CAPTURE_STARTED':
      updateStatus(true);
      overlay.classList.add('visible');
      break;

    case 'CAPTURE_STOPPED':
      updateStatus(false);
      break;
  }
}

// ページ遷移時のクリーンアップ
window.addEventListener('beforeunload', () => {
  if (timeUpdateInterval) {
    clearInterval(timeUpdateInterval);
  }
});

// 初期化実行
init();
