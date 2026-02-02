/**
 * content-google.js
 * Google検索結果ページでAI概要（AI Overview / SGE）を非表示にする
 */

(function() {
  'use strict';

  const STORAGE_KEY = 'hideGoogleAI';
  const STYLE_ID = 'ycs-hide-ai-overview';
  const INDICATOR_ID = 'ycs-ai-hidden-indicator';

  // CSSを早期に注入してAI概要を非表示にする
  const hideAIOverviewCSS = `
    /* AI Overview / SGE 関連要素を非表示 */
    [data-attrid="SGE"],
    [data-attrid="wa:/m/0jbk"],
    div[jsname="N6jJud"],
    div[jsname="JG9Hqd"],
    #m-x-content,
    [aria-label="AI Overview"],
    [aria-label="AI による概要"],
    .M8OgIe,
    .yp1CPe,
    .wDYxhc[data-md],
    div[data-async-type="editableDirectAnswer"],
    div[data-hveid][data-ved] > div[jsname="yEBWhe"],
    .kno-rdesc[data-attrid^="description"] ~ div[data-attrid="SGE"] {
      display: none !important;
    }

    /* 非表示インジケーター */
    #${INDICATOR_ID} {
      background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 12px 16px;
      margin: 8px 0 16px 0;
      font-size: 13px;
      color: #64748b;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    #${INDICATOR_ID} .icon {
      font-size: 16px;
    }
  `;

  let observer = null;
  let observerTimeout = null;
  let domReadyListener = null;
  let aiOverviewDetected = false;

  // スタイル要素を作成して注入
  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;

    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = hideAIOverviewCSS;

    // document.headがまだない場合はdocumentElementに追加
    const target = document.head || document.documentElement;
    if (target) {
      target.appendChild(style);
    }
  }

  // スタイルを削除
  function removeStyles() {
    const style = document.getElementById(STYLE_ID);
    if (style) {
      style.remove();
    }
  }

  // インジケーターを表示
  function showIndicator() {
    if (document.getElementById(INDICATOR_ID)) return;

    const indicator = document.createElement('div');
    indicator.id = INDICATOR_ID;
    indicator.innerHTML = '<span class="icon">🤖</span><span>AI概要は拡張機能により非表示になっています</span>';

    // 検索結果の上部に挿入
    const insertTarget = document.querySelector('#search') || document.querySelector('#rso') || document.querySelector('#main');
    if (insertTarget) {
      insertTarget.insertBefore(indicator, insertTarget.firstChild);
    } else {
      // 挿入先がまだない場合は少し待ってリトライ
      setTimeout(() => {
        if (document.getElementById(INDICATOR_ID)) return;
        const retryTarget = document.querySelector('#search') || document.querySelector('#rso') || document.querySelector('#main');
        if (retryTarget) {
          retryTarget.insertBefore(indicator, retryTarget.firstChild);
        }
      }, 500);
    }
  }

  // インジケーターを削除
  function removeIndicator() {
    const indicator = document.getElementById(INDICATOR_ID);
    if (indicator) {
      indicator.remove();
    }
  }

  // MutationObserverでAI概要要素を監視して削除
  function setupObserver() {
    if (observer) return;

    observer = new MutationObserver((mutations) => {
      // デバウンス: 連続した変更を100msごとにまとめて処理
      if (observerTimeout) {
        clearTimeout(observerTimeout);
      }

      observerTimeout = setTimeout(() => {
        for (const mutation of mutations) {
          for (const node of mutation.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) {
              // AI概要関連の要素が追加されたら非表示にする
              if (isAIOverviewElement(node)) {
                node.style.display = 'none';
                if (!aiOverviewDetected) {
                  aiOverviewDetected = true;
                  showIndicator();
                }
              }
              // 子要素も確認
              if (node.querySelectorAll) {
                const aiElements = node.querySelectorAll('[data-attrid="SGE"], [aria-label="AI Overview"], [aria-label="AI による概要"], div[jsname="N6jJud"], #m-x-content');
                if (aiElements.length > 0) {
                  aiElements.forEach(el => el.style.display = 'none');
                  if (!aiOverviewDetected) {
                    aiOverviewDetected = true;
                    showIndicator();
                  }
                }
              }
            }
          }
        }
        observerTimeout = null;
      }, 100);
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true
    });

    // 既存のAI概要要素をチェック
    checkExistingAIOverview();
  }

  // 既存のAI概要要素をチェック
  function checkExistingAIOverview() {
    const check = () => {
      const aiElements = document.querySelectorAll('[data-attrid="SGE"], [aria-label="AI Overview"], [aria-label="AI による概要"], div[jsname="N6jJud"], #m-x-content');
      if (aiElements.length > 0 && !aiOverviewDetected) {
        aiOverviewDetected = true;
        showIndicator();
      }
    };

    // 即時チェック
    check();

    // DOMが完全に読み込まれた後にも再チェック
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', check);
    }

    // 少し遅延してからも再チェック（動的に読み込まれる場合に対応）
    setTimeout(check, 1000);
  }

  // Observerを停止
  function stopObserver() {
    if (observerTimeout) {
      clearTimeout(observerTimeout);
      observerTimeout = null;
    }
    if (observer) {
      observer.disconnect();
      observer = null;
    }
  }

  // AI概要関連の要素かどうかを判定
  function isAIOverviewElement(element) {
    if (!element?.getAttribute) return false;

    const id = element.id;
    const attrid = element.getAttribute('data-attrid');
    const ariaLabel = element.getAttribute('aria-label');
    const jsname = element.getAttribute('jsname');

    return (
      id === 'm-x-content' ||
      attrid === 'SGE' ||
      attrid === 'wa:/m/0jbk' ||
      ariaLabel === 'AI Overview' ||
      ariaLabel === 'AI による概要' ||
      jsname === 'N6jJud' ||
      jsname === 'JG9Hqd'
    );
  }

  // DOMContentLoadedリスナーのクリーンアップ
  function cleanupDomReadyListener() {
    if (domReadyListener) {
      document.removeEventListener('DOMContentLoaded', domReadyListener);
      domReadyListener = null;
    }
  }

  // AI概要非表示を有効化
  function enableHiding() {
    injectStyles();

    // 既存のリスナーをクリーンアップ
    cleanupDomReadyListener();

    if (document.documentElement) {
      setupObserver();
    } else {
      domReadyListener = () => {
        setupObserver();
        cleanupDomReadyListener();
      };
      document.addEventListener('DOMContentLoaded', domReadyListener);
    }
  }

  // AI概要非表示を無効化
  function disableHiding() {
    removeStyles();
    stopObserver();
    cleanupDomReadyListener();
    removeIndicator();
    aiOverviewDetected = false;
  }

  // ポップアップからのメッセージを受信
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.type === 'HIDE_GOOGLE_AI') {
      enableHiding();
      sendResponse({ success: true });
    } else if (message.type === 'SHOW_GOOGLE_AI') {
      disableHiding();
      sendResponse({ success: true });
    }
    return true;
  });

  // 初期化: 保存された設定を読み込み
  chrome.storage.local.get(STORAGE_KEY, (result) => {
    // エラーハンドリング
    if (chrome.runtime.lastError) {
      console.error('[YCS] Failed to load settings:', chrome.runtime.lastError);
      // デフォルト動作として非表示を有効化
      enableHiding();
      return;
    }

    const hide = result[STORAGE_KEY] !== false; // デフォルトはtrue
    if (hide) {
      enableHiding();
    }
  });
})();
