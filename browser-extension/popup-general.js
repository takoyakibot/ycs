(function () {
  'use strict';

  /**
   * 歌枠タイムスタンプ検出 - Popup Script
   * 埋め込みUIの表示/非表示・音量グラフの高さ設定・スキャン済み動画一覧の表示と管理
   */


  const STORAGE_KEY_EMBEDDED_UI = 'showEmbeddedUI';
  const STORAGE_KEY_GRAPH_BASE_HEIGHT = 'graphBaseHeight';
  const STORAGE_KEY_GRAPH_HEIGHT_STEP = 'graphHeightStep';

  // 音量グラフの高さ（content.js側の既定値と揃えること）
  const DEFAULT_GRAPH_BASE_HEIGHT = 60;
  const DEFAULT_GRAPH_HEIGHT_STEP = 20;
  const GRAPH_BASE_HEIGHT_RANGE = { min: 40, max: 400 };
  const GRAPH_HEIGHT_STEP_RANGE = { min: 0, max: 100 };

  // DOM要素
  const elements = {
    showEmbeddedUI: document.getElementById('show-embedded-ui'),
    infoContainer: document.getElementById('info-container'),
    helpLink: document.getElementById('help-link'),
    scannedList: document.getElementById('scanned-list'),
    clearAllBtn: document.getElementById('clear-all-btn'),
    graphBaseHeight: document.getElementById('graph-base-height'),
    graphHeightStep: document.getElementById('graph-height-step'),
    graphHeightStatus: document.getElementById('graph-height-status')
  };

  /**
   * 初期化
   */
  async function init() {
    // 現在のタブを取得
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    const isYouTube = tab?.url?.includes('youtube.com/watch');

    // YouTube埋め込みUI設定
    if (!isYouTube) {
      elements.showEmbeddedUI.disabled = true;
      elements.showEmbeddedUI.parentElement.style.opacity = '0.5';
    }

    // 保存された設定を読み込み
    const storageKeys = [
      STORAGE_KEY_EMBEDDED_UI,
      STORAGE_KEY_GRAPH_BASE_HEIGHT,
      STORAGE_KEY_GRAPH_HEIGHT_STEP
    ];
    const result = await chrome.storage.local.get(storageKeys);
    const showUI = result[STORAGE_KEY_EMBEDDED_UI] !== false; // デフォルトはtrue

    elements.showEmbeddedUI.checked = showUI;

    // 音量グラフの高さ設定を読み込み
    elements.graphBaseHeight.value = clampNumber(
      result[STORAGE_KEY_GRAPH_BASE_HEIGHT], DEFAULT_GRAPH_BASE_HEIGHT, GRAPH_BASE_HEIGHT_RANGE);
    elements.graphHeightStep.value = clampNumber(
      result[STORAGE_KEY_GRAPH_HEIGHT_STEP], DEFAULT_GRAPH_HEIGHT_STEP, GRAPH_HEIGHT_STEP_RANGE);
    updateGraphHeightStatus();

    // イベントリスナーを設定
    elements.showEmbeddedUI.addEventListener('change', toggleEmbeddedUI);
    elements.graphBaseHeight.addEventListener('change', saveGraphHeightSettings);
    elements.graphHeightStep.addEventListener('change', saveGraphHeightSettings);
    elements.helpLink.addEventListener('click', showHelp);
    elements.clearAllBtn.addEventListener('click', clearAllScannedData);

    // YouTube埋め込みUIの初期状態をコンテンツスクリプトに通知
    if (isYouTube) {
      notifyYouTubeContentScript(showUI);
    }

    // スキャン済み一覧を読み込み
    await loadScannedList();
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
   * 数値を範囲内に丸める（不正値は既定値を返す）
   * @param {*} value - 対象の値
   * @param {number} defaultValue - 既定値
   * @param {{min: number, max: number}} range - 許容範囲
   * @returns {number}
   */
  function clampNumber(value, defaultValue, range) {
    // 空欄・未設定は「既定値を使う」として扱う（Number('')が0になり最小値へ丸められるのを防ぐ）
    if (value === null || value === undefined || value === '') return defaultValue;
    const num = Number(value);
    if (!Number.isFinite(num)) return defaultValue;
    return Math.max(range.min, Math.min(range.max, Math.round(num)));
  }

  /**
   * 高さ設定の説明文を更新
   */
  function updateGraphHeightStatus() {
    const base = Number(elements.graphBaseHeight.value);
    const step = Number(elements.graphHeightStep.value);
    // ズームは9段階（1x〜8x）
    const maxHeight = base + step * 8;
    elements.graphHeightStatus.textContent = `等倍 ${base}px 〜 最大ズーム ${maxHeight}px`;
  }

  /**
   * 音量グラフの高さ設定を保存（開いているYouTubeタブに即時反映される）
   */
  async function saveGraphHeightSettings() {
    const base = clampNumber(elements.graphBaseHeight.value, DEFAULT_GRAPH_BASE_HEIGHT, GRAPH_BASE_HEIGHT_RANGE);
    const step = clampNumber(elements.graphHeightStep.value, DEFAULT_GRAPH_HEIGHT_STEP, GRAPH_HEIGHT_STEP_RANGE);

    // 丸めた結果を入力欄に反映
    elements.graphBaseHeight.value = base;
    elements.graphHeightStep.value = step;
    updateGraphHeightStatus();

    await chrome.storage.local.set({
      [STORAGE_KEY_GRAPH_BASE_HEIGHT]: base,
      [STORAGE_KEY_GRAPH_HEIGHT_STEP]: step
    });
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
   * スキャン済み動画一覧を取得
   */
  async function getScannedVideosList() {
    const allData = await chrome.storage.local.get(null);
    const videos = [];

    for (const key in allData) {
      if (key.startsWith('volumeData_')) {
        const videoId = key.replace('volumeData_', '');
        const data = allData[key];
        videos.push({
          videoId,
          savedAt: data.savedAt,
          duration: data.duration
        });
      }
    }

    return videos.sort((a, b) => new Date(b.savedAt) - new Date(a.savedAt));
  }

  /**
   * スキャン済み一覧を読み込み・表示
   */
  async function loadScannedList() {
    const videos = await getScannedVideosList();

    // ストレージ使用量を表示
    chrome.storage.local.getBytesInUse(null, (bytes) => {
      const usageEl = document.getElementById('storage-usage');
      if (usageEl) {
        const mb = (bytes / 1024 / 1024).toFixed(1);
        usageEl.textContent = `(${mb} MB / 10 MB)`;
      }
    });

    if (videos.length === 0) {
      elements.scannedList.innerHTML = '<div class="empty-message">スキャン済みの動画はありません</div>';
      elements.clearAllBtn.style.display = 'none';
      return;
    }

    elements.clearAllBtn.style.display = 'block';

    const html = videos.map(video => {
      const date = video.savedAt ? formatDate(video.savedAt) : '不明';
      return `
      <div class="scanned-item" data-video-id="${video.videoId}">
        <div class="scanned-info">
          <a href="#" class="scanned-video-id" data-video-id="${video.videoId}">${video.videoId}</a>
          <span class="scanned-date">${date}</span>
        </div>
        <div class="scanned-actions">
          <button class="btn-small btn-open" data-video-id="${video.videoId}">開く</button>
          <button class="btn-small btn-delete" data-video-id="${video.videoId}">削除</button>
        </div>
      </div>
    `;
    }).join('');

    elements.scannedList.innerHTML = html;

    // イベントリスナーを設定
    elements.scannedList.querySelectorAll('.scanned-video-id, .btn-open').forEach(el => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        openVideo(el.dataset.videoId);
      });
    });

    elements.scannedList.querySelectorAll('.btn-delete').forEach(el => {
      el.addEventListener('click', () => deleteScannedData(el.dataset.videoId));
    });
  }

  /**
   * 日付をフォーマット
   */
  function formatDate(dateString) {
    try {
      const date = new Date(dateString);
      const month = (date.getMonth() + 1).toString().padStart(2, '0');
      const day = date.getDate().toString().padStart(2, '0');
      const hours = date.getHours().toString().padStart(2, '0');
      const minutes = date.getMinutes().toString().padStart(2, '0');
      return `${month}/${day} ${hours}:${minutes}`;
    } catch {
      return '不明';
    }
  }

  /**
   * 動画を開く
   */
  function openVideo(videoId) {
    chrome.tabs.create({
      url: `https://www.youtube.com/watch?v=${videoId}`
    });
  }

  /**
   * スキャンデータを削除
   */
  async function deleteScannedData(videoId) {
    await chrome.storage.local.remove(`volumeData_${videoId}`);
    await loadScannedList();
  }

  /**
   * 全てのスキャンデータをクリア
   */
  async function clearAllScannedData() {
    if (!confirm('全てのスキャンデータを削除しますか？')) {
      return;
    }

    const allData = await chrome.storage.local.get(null);
    const keysToRemove = Object.keys(allData).filter(key => key.startsWith('volumeData_'));

    if (keysToRemove.length > 0) {
      await chrome.storage.local.remove(keysToRemove);
    }

    await loadScannedList();
  }

  /**
   * ヘルプを表示
   */
  function showHelp(e) {
    e.preventDefault();
    {
      showInfo(`
      <strong>使い方:</strong><br>
      ・YouTube画面にUI: チェックでYouTube動画画面に音量検出UIを表示<br>
      ・スキャン: YouTube動画画面のYCSボタンからダイレクトスキャンを開始<br>
      ・楽曲推測: タイムスタンプマーカーをクリックして楽曲候補を表示<br>
      ・スキャン済み一覧: スキャン済みの動画を確認・開く・削除
    `);
    }
  }

  /**
   * 情報メッセージを表示
   */
  function showInfo(message) {
    elements.infoContainer.innerHTML = `<div class="info-message">${message}</div>`;
  }

  // 初期化実行
  init();

})();
