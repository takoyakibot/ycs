/**
 * content-google.js
 * Google検索結果ページでAI概要（AI Overview / SGE）を非表示にする
 */

(function() {
  'use strict';

  const STORAGE_KEY = 'hideGoogleAI';
  const STYLE_ID = 'ycs-hide-ai-overview';

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
  `;

  let observer = null;

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

  // MutationObserverでAI概要要素を監視して削除
  function setupObserver() {
    if (observer) return;

    observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node.nodeType === Node.ELEMENT_NODE) {
            // AI概要関連の要素が追加されたら非表示にする
            if (isAIOverviewElement(node)) {
              node.style.display = 'none';
            }
            // 子要素も確認
            const aiElements = node.querySelectorAll?.('[data-attrid="SGE"], [aria-label="AI Overview"], [aria-label="AI による概要"], div[jsname="N6jJud"]');
            if (aiElements) {
              aiElements.forEach(el => el.style.display = 'none');
            }
          }
        }
      }
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true
    });
  }

  // Observerを停止
  function stopObserver() {
    if (observer) {
      observer.disconnect();
      observer = null;
    }
  }

  // AI概要関連の要素かどうかを判定
  function isAIOverviewElement(element) {
    if (!element.getAttribute) return false;

    const attrid = element.getAttribute('data-attrid');
    const ariaLabel = element.getAttribute('aria-label');
    const jsname = element.getAttribute('jsname');

    return (
      attrid === 'SGE' ||
      attrid === 'wa:/m/0jbk' ||
      ariaLabel === 'AI Overview' ||
      ariaLabel === 'AI による概要' ||
      jsname === 'N6jJud' ||
      jsname === 'JG9Hqd'
    );
  }

  // AI概要非表示を有効化
  function enableHiding() {
    injectStyles();
    if (document.documentElement) {
      setupObserver();
    } else {
      document.addEventListener('DOMContentLoaded', setupObserver);
    }
    console.log('[YCS] Google AI Overview hider enabled');
  }

  // AI概要非表示を無効化
  function disableHiding() {
    removeStyles();
    stopObserver();
    console.log('[YCS] Google AI Overview hider disabled');
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
    const hide = result[STORAGE_KEY] !== false; // デフォルトはtrue
    if (hide) {
      enableHiding();
    } else {
      console.log('[YCS] Google AI Overview hider loaded (disabled)');
    }
  });
})();
