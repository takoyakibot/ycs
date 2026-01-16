/**
 * 歌枠タイムスタンプ検出 - Popup Script
 * 埋め込みUIの表示/非表示とGoogle AI概要の表示/非表示を切り替える
 */

const STORAGE_KEY_EMBEDDED_UI = 'showEmbeddedUI';
const STORAGE_KEY_HIDE_GOOGLE_AI = 'hideGoogleAI';

// DOM要素
const elements = {
  showEmbeddedUI: document.getElementById('show-embedded-ui'),
  hideGoogleAI: document.getElementById('hide-google-ai'),
  infoContainer: document.getElementById('info-container'),
  helpLink: document.getElementById('help-link')
};

/**
 * 初期化
 */
async function init() {
  // 現在のタブを取得
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  const isYouTube = tab?.url?.includes('youtube.com/watch');
  const isGoogle = tab?.url?.includes('google.com/search') || tab?.url?.includes('google.co.jp/search');

  // YouTube埋め込みUI設定
  if (!isYouTube) {
    elements.showEmbeddedUI.disabled = true;
    elements.showEmbeddedUI.parentElement.style.opacity = '0.5';
  }

  // 保存された設定を読み込み
  const result = await chrome.storage.local.get([STORAGE_KEY_EMBEDDED_UI, STORAGE_KEY_HIDE_GOOGLE_AI]);
  const showUI = result[STORAGE_KEY_EMBEDDED_UI] !== false; // デフォルトはtrue
  const hideGoogleAI = result[STORAGE_KEY_HIDE_GOOGLE_AI] !== false; // デフォルトはtrue

  elements.showEmbeddedUI.checked = showUI;
  elements.hideGoogleAI.checked = hideGoogleAI;

  // イベントリスナーを設定
  elements.showEmbeddedUI.addEventListener('change', toggleEmbeddedUI);
  elements.hideGoogleAI.addEventListener('change', toggleHideGoogleAI);
  elements.helpLink.addEventListener('click', showHelp);

  // YouTube埋め込みUIの初期状態をコンテンツスクリプトに通知
  if (isYouTube) {
    notifyYouTubeContentScript(showUI);
  }

  // Googleの場合は設定変更を通知
  if (isGoogle) {
    notifyGoogleContentScript(hideGoogleAI);
  }
}

/**
 * 埋め込みUI表示を切り替え
 */
async function toggleEmbeddedUI() {
  const show = elements.showEmbeddedUI.checked;

  // 設定を保存
  await chrome.storage.local.set({ [STORAGE_KEY_EMBEDDED_UI]: show });

  // コンテンツスクリプトに通知
  notifyYouTubeContentScript(show);
}

/**
 * Google AI概要の非表示を切り替え
 */
async function toggleHideGoogleAI() {
  const hide = elements.hideGoogleAI.checked;

  // 設定を保存
  await chrome.storage.local.set({ [STORAGE_KEY_HIDE_GOOGLE_AI]: hide });

  // Google検索ページに通知
  notifyGoogleContentScript(hide);
}

/**
 * YouTubeコンテンツスクリプトに通知
 */
async function notifyYouTubeContentScript(show) {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id && tab.url?.includes('youtube.com/watch')) {
      await chrome.tabs.sendMessage(tab.id, {
        type: show ? 'SHOW_EMBEDDED_UI' : 'HIDE_EMBEDDED_UI'
      });
    }
  } catch (error) {
    console.log('Content script not ready:', error.message);
  }
}

/**
 * Googleコンテンツスクリプトに通知
 */
async function notifyGoogleContentScript(hide) {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.id && (tab.url?.includes('google.com/search') || tab.url?.includes('google.co.jp/search'))) {
      await chrome.tabs.sendMessage(tab.id, {
        type: hide ? 'HIDE_GOOGLE_AI' : 'SHOW_GOOGLE_AI'
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
    ・YouTube画面にUI: チェックでYouTube動画画面に音量検出UIを表示<br>
    ・AI概要を非表示: チェックでGoogle検索のAI概要を非表示
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
