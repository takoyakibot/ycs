/**
 * content-google.js
 * Google検索結果ページでAI概要（AI Overview / SGE）を非表示にする
 */

(function() {
  'use strict';

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

  // スタイル要素を作成して注入
  function injectStyles() {
    const style = document.createElement('style');
    style.id = 'ycs-hide-ai-overview';
    style.textContent = hideAIOverviewCSS;

    // document.headがまだない場合はdocumentElementに追加
    const target = document.head || document.documentElement;
    if (target) {
      target.appendChild(style);
    }
  }

  // MutationObserverでAI概要要素を監視して削除
  function setupObserver() {
    const observer = new MutationObserver((mutations) => {
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

  // 初期化
  injectStyles();

  // DOMContentLoadedを待たずにObserverを開始
  if (document.documentElement) {
    setupObserver();
  } else {
    document.addEventListener('DOMContentLoaded', setupObserver);
  }

  console.log('[YCS] Google AI Overview hider loaded');
})();
