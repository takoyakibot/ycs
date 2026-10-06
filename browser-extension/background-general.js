(function () {
  'use strict';

  /**
   * 歌枠タイムスタンプ検出 - Background Service Worker
   */

  let timestamps = [];
  let currentTabId = null;

  // 音量グラフ用データ
  let volumeGraphData = [];
  let videoDuration = 0;
  let isScanning = false;

  // デフォルト設定
  const DEFAULT_CONFIG = {
    // 音量のしきい値（0-1）
    volumeThreshold: 0.15,
    // 静かな状態と判定する音量
    quietThreshold: 0.05,
    // 静かな状態が続く最小時間（秒）
    quietMinDuration: 1.0,
    // サンプリング間隔（ミリ秒）
    sampleInterval: 100,
    // 連続検出を防ぐクールダウン（秒）
    cooldown: 3.0
  };

  // 現在の設定（ストレージから読み込む）
  let CONFIG = { ...DEFAULT_CONFIG };

  // 起動時に設定を読み込む
  chrome.storage.local.get('config', (result) => {
    if (result.config) {
      CONFIG = { ...DEFAULT_CONFIG, ...result.config };
    }
  });

  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    switch (message.type) {
      case 'GET_TAB_ID':
        // content scriptは自身のtabIdを直接取得できないためここで返す
        sendResponse({ tabId: sender.tab?.id ?? null });
        return true;

      case 'GET_STATUS':
        sendResponse({
          isScanning: isScanning,
          timestamps,
          volumeGraphData: sender.tab?.id === currentTabId ? volumeGraphData : [],
          config: CONFIG
        });
        return true;

      case 'GET_TIMESTAMPS':
        sendResponse({ timestamps });
        return true;

      case 'CLEAR_TIMESTAMPS':
        timestamps = [];
        sendResponse({ success: true });
        return true;

      case 'UPDATE_CONFIG':
        Object.assign(CONFIG, message.config);
        chrome.storage.local.set({ config: CONFIG });
        sendResponse({ success: true, config: CONFIG });
        return true;

      case 'GET_VOLUME_DATA':
        if (sender.tab?.id !== currentTabId) {
          sendResponse({ data: [], duration: 0 });
        } else {
          sendResponse({ data: volumeGraphData, duration: videoDuration });
        }
        return true;

      case 'CLEAR_VOLUME_DATA':
        volumeGraphData = [];
        videoDuration = 0;
        sendResponse({ success: true });
        return true;

      case 'TOGGLE_VOLUME_GRAPH':
        toggleVolumeGraph();
        return false;

      case 'SHOW_VOLUME_GRAPH':
        showVolumeGraph();
        return false;
    }
  });

  /**
   * 音量データをコンテンツスクリプトに送信
   */
  async function sendVolumeDataToContent() {
  }

  /**
   * 音量グラフの表示をトグル
   */
  async function toggleVolumeGraph() {
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (tab?.id) {
        chrome.tabs.sendMessage(tab.id, { type: 'TOGGLE_VOLUME_GRAPH' });
      }
    } catch (error) {
      console.error('グラフトグルエラー:', error);
    }
  }

  /**
   * 音量グラフを表示
   */
  async function showVolumeGraph() {
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (tab?.id) {
        chrome.tabs.sendMessage(tab.id, { type: 'SHOW_VOLUME_GRAPH' });

        // 既存のデータがあれば送信
        if (volumeGraphData.length > 0) {
          sendVolumeDataToContent();
        }
      }
    } catch (error) {
      console.error('グラフ表示エラー:', error);
    }
  }

})();
