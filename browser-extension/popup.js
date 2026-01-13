/**
 * 歌枠タイムスタンプ検出 - Popup Script (最小版)
 * 埋め込みUIの表示/非表示を切り替えるだけのシンプルなポップアップ
 */

const STORAGE_KEY = 'showEmbeddedUI';

// DOM要素
const elements = {
  showEmbeddedUI: document.getElementById('show-embedded-ui'),
  infoContainer: document.getElementById('info-container'),
  helpLink: document.getElementById('help-link')
};

/**
 * 初期化
 */
async function init() {
  // 現在のタブを取得
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });

  // YouTubeかチェック
  if (!tab?.url?.includes('youtube.com/watch')) {
    showInfo('YouTubeの動画ページで使用してください');
    elements.showEmbeddedUI.disabled = true;
    return;
  }

  // 保存された設定を読み込み
  const result = await chrome.storage.local.get(STORAGE_KEY);
  const showUI = result[STORAGE_KEY] !== false; // デフォルトはtrue
  elements.showEmbeddedUI.checked = showUI;

  // イベントリスナーを設定
  elements.showEmbeddedUI.addEventListener('change', toggleEmbeddedUI);
  elements.helpLink.addEventListener('click', showHelp);

  // 初期状態をコンテンツスクリプトに通知
  notifyContentScript(showUI);
}

/**
 * 埋め込みUI表示を切り替え
 */
async function toggleEmbeddedUI() {
  const show = elements.showEmbeddedUI.checked;

  // 設定を保存
  await chrome.storage.local.set({ [STORAGE_KEY]: show });

  // コンテンツスクリプトに通知
  notifyContentScript(show);
}

/**
 * コンテンツスクリプトに通知
 */
async function notifyContentScript(show) {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id) {
      await chrome.tabs.sendMessage(tab.id, {
        type: show ? 'SHOW_EMBEDDED_UI' : 'HIDE_EMBEDDED_UI'
      });
    }
  } catch (error) {
    console.log('Content script not ready:', error.message);
  }
}

/**
 * ヘルプを表示
 */
function showHelp(e) {
  e.preventDefault();
  showInfo(`
    <strong>使い方:</strong><br>
    1. チェックを入れるとYouTube画面にUIが表示されます<br>
    2. UIから「スキャン」を開始すると音量グラフが生成されます<br>
    3. グラフをクリックすると該当位置にシークできます
  `);
}

/**
 * 情報メッセージを表示
 */
function showInfo(message) {
  elements.infoContainer.innerHTML = `<div class="info-message">${message}</div>`;
}

// 初期化実行
init();
