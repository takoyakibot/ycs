/**
 * YouTube Live チャット遅延送信 - Content Script
 *
 * YouTube Liveのチャット送信を一定秒数プールし、
 * その間にキャンセルできる機能を提供する。
 * live_chat iframe内で動作する。
 */

(function() {
  'use strict';

  // リプレイモードでは無効
  if (window.location.pathname.includes('live_chat_replay')) {
    console.log('チャット遅延送信: リプレイモードでは無効です');
    return;
  }

  const STORAGE_KEY_CHAT_DELAY_ENABLED = 'chatDelayEnabled';
  const STORAGE_KEY_CHAT_DELAY_SECONDS = 'chatDelaySeconds';
  const DEFAULT_DELAY_SECONDS = 10;
  const MIN_DELAY_SECONDS = 3;

  let isEnabled = false;
  let delaySeconds = DEFAULT_DELAY_SECONDS;
  let pendingMessage = null; // { text, timer, countdownInterval }
  let indicatorFadeTimeout = null;

  /**
   * スタイルを注入（1回だけ）
   */
  function injectStyles() {
    if (document.getElementById('ycs-chat-delay-style')) return;

    const style = document.createElement('style');
    style.id = 'ycs-chat-delay-style';
    style.textContent = `
      #ycs-chat-delay-overlay {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.85);
        color: #fff;
        z-index: 99999;
        animation: ycs-slide-up 0.2s ease-out;
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
      }
      @keyframes ycs-slide-up {
        from { transform: translateY(100%); }
        to { transform: translateY(0); }
      }
      .ycs-delay-content {
        padding: 12px 16px;
        max-width: 100%;
      }
      .ycs-delay-message-preview {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 6px;
        padding: 8px 12px;
        font-size: 13px;
        margin-bottom: 8px;
        word-break: break-all;
        max-height: 60px;
        overflow-y: auto;
      }
      .ycs-delay-timer {
        font-size: 14px;
        font-weight: 600;
        text-align: center;
        margin-bottom: 6px;
      }
      .ycs-delay-countdown {
        font-size: 20px;
        color: #ff9800;
      }
      .ycs-delay-bar-container {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 3px;
        height: 4px;
        margin-bottom: 10px;
        overflow: hidden;
      }
      .ycs-delay-bar {
        height: 100%;
        background: #ff9800;
        border-radius: 3px;
        width: 100%;
      }
      .ycs-delay-actions {
        display: flex;
        gap: 8px;
        justify-content: center;
      }
      .ycs-delay-actions button {
        border: none;
        border-radius: 6px;
        padding: 8px 20px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity 0.2s;
      }
      .ycs-delay-actions button:hover {
        opacity: 0.85;
      }
      .ycs-delay-cancel {
        background: #f44336;
        color: #fff;
      }
      .ycs-delay-send-now {
        background: #4caf50;
        color: #fff;
      }
      #ycs-chat-delay-indicator {
        position: fixed;
        top: 8px;
        right: 8px;
        background: rgba(76, 175, 80, 0.9);
        color: #fff;
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 4px;
        z-index: 99998;
        font-family: 'Segoe UI', 'Hiragino Sans', sans-serif;
        transition: opacity 0.5s;
      }
      #ycs-chat-delay-indicator.disabled {
        background: rgba(158, 158, 158, 0.7);
      }
    `;
    document.head.appendChild(style);
  }

  /**
   * 設定を読み込む
   */
  async function loadSettings() {
    try {
      const result = await chrome.storage.local.get([
        STORAGE_KEY_CHAT_DELAY_ENABLED,
        STORAGE_KEY_CHAT_DELAY_SECONDS
      ]);
      isEnabled = result[STORAGE_KEY_CHAT_DELAY_ENABLED] === true;
      delaySeconds = Math.max(MIN_DELAY_SECONDS, result[STORAGE_KEY_CHAT_DELAY_SECONDS] || DEFAULT_DELAY_SECONDS);
    } catch (e) {
      console.log('チャット遅延: 設定読み込みエラー', e);
    }
  }

  /**
   * 設定変更をリッスン
   */
  chrome.storage.onChanged.addListener((changes) => {
    if (changes[STORAGE_KEY_CHAT_DELAY_ENABLED]) {
      const wasEnabled = isEnabled;
      isEnabled = changes[STORAGE_KEY_CHAT_DELAY_ENABLED].newValue === true;
      updateIndicator();
      // 無効化された場合、保留中のメッセージをキャンセル
      if (wasEnabled && !isEnabled && pendingMessage) {
        cancelPending();
      }
    }
    if (changes[STORAGE_KEY_CHAT_DELAY_SECONDS]) {
      delaySeconds = Math.max(MIN_DELAY_SECONDS, changes[STORAGE_KEY_CHAT_DELAY_SECONDS].newValue || DEFAULT_DELAY_SECONDS);
    }
  });

  /**
   * メッセージリスナー（popup等からの通知）
   */
  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message.type === 'CHAT_DELAY_STATUS') {
      sendResponse({ enabled: isEnabled, delaySeconds });
      return true;
    }
  });

  /**
   * チャット入力エリアとボタンの要素を取得
   */
  function getChatElements() {
    const chatInput = document.querySelector(
      '#input.yt-live-chat-text-input-field-renderer, ' +
      'div#input[contenteditable="true"]'
    );
    const sendButton = document.querySelector(
      '#send-button button, ' +
      'yt-button-renderer#send-button button'
    );
    return { chatInput, sendButton };
  }

  /**
   * チャット入力のテキストを取得
   */
  function getChatText() {
    const { chatInput } = getChatElements();
    if (!chatInput) return '';
    return chatInput.textContent?.trim() || '';
  }

  /**
   * チャット入力のテキストをセット
   */
  function setChatText(text) {
    const { chatInput } = getChatElements();
    if (!chatInput) return false;
    chatInput.textContent = text;
    chatInput.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  }

  /**
   * 実際にメッセージを送信する
   */
  function doSend() {
    const { sendButton } = getChatElements();
    if (sendButton) {
      sendButton.click();
      return true;
    }
    return false;
  }

  /**
   * 遅延送信オーバーレイを作成（DOM APIで安全に構築）
   */
  function createDelayOverlay(text, remaining) {
    const overlay = document.createElement('div');
    overlay.id = 'ycs-chat-delay-overlay';

    const content = document.createElement('div');
    content.className = 'ycs-delay-content';

    // メッセージプレビュー（textContentで安全に設定）
    const preview = document.createElement('div');
    preview.className = 'ycs-delay-message-preview';
    preview.textContent = text;

    // タイマー表示
    const timerDiv = document.createElement('div');
    timerDiv.className = 'ycs-delay-timer';
    const countdown = document.createElement('span');
    countdown.className = 'ycs-delay-countdown';
    countdown.textContent = remaining;
    timerDiv.appendChild(countdown);
    timerDiv.appendChild(document.createTextNode('秒後に送信'));

    // プログレスバー
    const barContainer = document.createElement('div');
    barContainer.className = 'ycs-delay-bar-container';
    const bar = document.createElement('div');
    bar.className = 'ycs-delay-bar';
    bar.style.width = '100%';
    bar.style.transition = `width ${remaining}s linear`;
    barContainer.appendChild(bar);

    // アクションボタン
    const actions = document.createElement('div');
    actions.className = 'ycs-delay-actions';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'ycs-delay-cancel';
    cancelBtn.textContent = 'キャンセル';
    cancelBtn.addEventListener('click', () => cancelPending());

    const sendNowBtn = document.createElement('button');
    sendNowBtn.className = 'ycs-delay-send-now';
    sendNowBtn.textContent = '今すぐ送信';
    sendNowBtn.addEventListener('click', () => sendPendingNow());

    actions.appendChild(cancelBtn);
    actions.appendChild(sendNowBtn);

    content.appendChild(preview);
    content.appendChild(timerDiv);
    content.appendChild(barContainer);
    content.appendChild(actions);
    overlay.appendChild(content);

    document.body.appendChild(overlay);

    // プログレスバーのアニメーションを開始
    requestAnimationFrame(() => {
      bar.style.width = '0%';
    });

    return overlay;
  }

  /**
   * 送信をインターセプトして遅延させる
   */
  function interceptSend(e) {
    if (!isEnabled) return;

    const text = getChatText();
    if (!text) return;

    // 既にプール中なら何もしない
    if (pendingMessage) return;

    // 送信を阻止
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    // 入力欄をクリア（送信されたように見せる）
    setChatText('');

    // 遅延送信を開始
    startDelay(text);
  }

  /**
   * Enterキーによる送信をインターセプト
   */
  function interceptEnterKey(e) {
    if (!isEnabled) return;
    if (e.key !== 'Enter' || e.shiftKey || e.isComposing) return;

    const text = getChatText();
    if (!text) return;
    if (pendingMessage) return;

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    setChatText('');
    startDelay(text);
  }

  /**
   * 遅延送信を開始
   */
  function startDelay(text) {
    let remaining = delaySeconds;

    const overlay = createDelayOverlay(text, remaining);
    const countdownEl = overlay.querySelector('.ycs-delay-countdown');

    const countdownInterval = setInterval(() => {
      remaining--;
      if (countdownEl) {
        countdownEl.textContent = remaining;
      }
      if (remaining <= 0) {
        clearInterval(countdownInterval);
      }
    }, 1000);

    const timer = setTimeout(() => {
      executeSend(text);
    }, delaySeconds * 1000);

    pendingMessage = { text, timer, countdownInterval };
  }

  /**
   * 送信を実行（遅延完了後）
   */
  function executeSend(text) {
    removeOverlay();

    const { chatInput, sendButton } = getChatElements();
    if (!chatInput || !sendButton) {
      console.error('チャット遅延: 送信要素が見つかりません');
      pendingMessage = null;
      return;
    }

    setChatText(text);

    // DOMの反映を待ってから送信
    setTimeout(() => {
      doSend();
      pendingMessage = null;
    }, 200);
  }

  /**
   * 今すぐ送信
   */
  function sendPendingNow() {
    if (!pendingMessage) return;

    clearTimeout(pendingMessage.timer);
    clearInterval(pendingMessage.countdownInterval);

    const text = pendingMessage.text;
    executeSend(text);
  }

  /**
   * 送信をキャンセル
   */
  function cancelPending() {
    if (!pendingMessage) return;

    clearTimeout(pendingMessage.timer);
    clearInterval(pendingMessage.countdownInterval);

    removeOverlay();
    pendingMessage = null;
  }

  /**
   * オーバーレイを削除
   */
  function removeOverlay() {
    const overlay = document.getElementById('ycs-chat-delay-overlay');
    if (overlay) {
      overlay.remove();
    }
  }

  /**
   * 有効/無効インジケーターを表示
   */
  function updateIndicator() {
    // 前回のフェードタイマーをクリア
    if (indicatorFadeTimeout) {
      clearTimeout(indicatorFadeTimeout);
      indicatorFadeTimeout = null;
    }

    let indicator = document.getElementById('ycs-chat-delay-indicator');

    if (!indicator) {
      indicator = document.createElement('div');
      indicator.id = 'ycs-chat-delay-indicator';
      document.body.appendChild(indicator);
    }

    // フェード解除
    indicator.style.opacity = '1';

    if (isEnabled) {
      indicator.textContent = `遅延送信 ON (${delaySeconds}秒)`;
      indicator.className = '';
    } else {
      indicator.textContent = '遅延送信 OFF';
      indicator.className = 'disabled';
    }

    // OFFの場合は3秒後にフェードアウトして削除
    if (!isEnabled) {
      indicatorFadeTimeout = setTimeout(() => {
        const el = document.getElementById('ycs-chat-delay-indicator');
        if (el && !isEnabled) {
          el.style.opacity = '0';
          setTimeout(() => {
            const el2 = document.getElementById('ycs-chat-delay-indicator');
            if (el2 && !isEnabled) {
              el2.remove();
            }
          }, 500);
        }
        indicatorFadeTimeout = null;
      }, 3000);
    }
  }

  /**
   * クリーンアップ
   */
  function cleanup() {
    cancelPending();
    removeOverlay();
    const indicator = document.getElementById('ycs-chat-delay-indicator');
    if (indicator) indicator.remove();
  }

  /**
   * イベントリスナーをセットアップ
   */
  function setupInterception() {
    // 送信ボタンのクリックをインターセプト（キャプチャフェーズで捕捉）
    document.addEventListener('click', (e) => {
      if (!isEnabled) return;

      const sendButton = e.target.closest(
        '#send-button button, ' +
        'yt-button-renderer#send-button button, ' +
        '#send-button'
      );
      if (sendButton) {
        interceptSend(e);
      }
    }, true);

    // Enterキーの送信をインターセプト（キャプチャフェーズ）
    document.addEventListener('keydown', (e) => {
      if (!isEnabled) return;

      const chatInput = e.target.closest(
        '#input.yt-live-chat-text-input-field-renderer, ' +
        'div#input[contenteditable="true"]'
      );
      if (chatInput) {
        interceptEnterKey(e);
      }
    }, true);

    // ページ離脱時にクリーンアップ
    window.addEventListener('beforeunload', cleanup);
  }

  /**
   * 初期化
   */
  async function initChatDelay() {
    await loadSettings();
    injectStyles();
    setupInterception();
    updateIndicator();
    console.log('チャット遅延送信: 初期化完了', { isEnabled, delaySeconds });
  }

  // チャットが読み込まれたら初期化
  // live_chatページはチャット要素が動的に読み込まれるため少し待つ
  const INIT_DELAY_MS = 1000;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(initChatDelay, INIT_DELAY_MS);
    });
  } else {
    setTimeout(initChatDelay, INIT_DELAY_MS);
  }
})();
