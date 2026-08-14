/**
 * 歌枠タイムスタンプ検出 - Background Service Worker
 *
 * Offscreen Documentを使用してYouTubeタブの音声をキャプチャし、
 * 音量変化を検出してタイムスタンプ候補を生成する
 * 音量ダイナミクスグラフ用のデータを蓄積する
 */

// 状態管理
let isCapturing = false;
let timestamps = [];
let currentTabId = null;

// 音量グラフ用データ
let volumeGraphData = [];
let videoDuration = 0;
let isScanning = false;
const SAMPLING_INTERVAL_SEC = 2; // サンプリング間隔（秒）
const LEGACY_GRAPH_RESOLUTION = 500; // 旧形式との互換用
let currentGraphResolution = LEGACY_GRAPH_RESOLUTION;
let lastVolumeUpdateTime = 0; // 最後にUI更新した時刻

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

// 旧既定値（ローカル開発サーバー）を保存していた場合のみ、一度だけ新既定値へ移行する
// APIトークン保存時にサーバーURLも一緒に保存されるため、URLを変更していないユーザーでも
// 旧既定値が明示的に保存済みになっており、既定値の変更だけでは本番に向かないため
const LEGACY_YCS_SERVER_URL = 'http://localhost:8000';
const YCS_SERVER_URL_MIGRATION_KEY = 'ycsServerUrlMigratedToProd';
(async () => {
  try {
    const result = await chrome.storage.local.get(['ycsServerUrl', YCS_SERVER_URL_MIGRATION_KEY]);
    if (result[YCS_SERVER_URL_MIGRATION_KEY]) return;

    // フラグとURLは1回の書き込みでまとめて更新する
    // （別々に書くと「フラグだけ立ってURLが未移行」の状態が残りうる。また移行済みフラグを
    //   立てることで、以降ユーザーが意図的にlocalhostを設定しても上書きしない）
    const update = { [YCS_SERVER_URL_MIGRATION_KEY]: true };
    const isLegacyUrl = result.ycsServerUrl
      && result.ycsServerUrl.replace(/\/+$/, '') === LEGACY_YCS_SERVER_URL;
    if (isLegacyUrl) {
      update.ycsServerUrl = 'https://ycs.alpacasandbag.jp';
    }
    await chrome.storage.local.set(update);
    if (isLegacyUrl) {
      console.log('[YCS] サーバーURLの保存値を旧既定値から本番URLへ移行しました');
    }
  } catch (error) {
    console.warn('[YCS] サーバーURL移行エラー:', error.message);
  }
})();

// Service Worker起動時にクリーンアップ
// tabCaptureの「つかみっぱなし」を防ぐため、既存のOffscreen Documentを閉じる
(async () => {
  try {
    const contexts = await chrome.runtime.getContexts({
      contextTypes: ['OFFSCREEN_DOCUMENT'],
      documentUrls: [chrome.runtime.getURL('offscreen.html')]
    });
    if (contexts.length > 0) {
      console.log('起動時: 既存のOffscreen Documentを閉じます');
      await chrome.offscreen.closeDocument();
    }
    isCapturing = false;
    isScanning = false;
  } catch (error) {
    console.log('起動時クリーンアップ（エラーは無視）:', error.message);
  }
})();

// メッセージリスナー
// 各スキャン: 実行タブが閉じられたら状態を落とす（タブID残留で他タブが遷移するのを防ぐ）
chrome.tabs.onRemoved.addListener(async (tabId) => {
  try {
    const result = await chrome.storage.local.get([
      'subtitleScanActive', 'subtitleScanTabId',
      'listScanActive', 'listScanTabId',
    ]);
    const updates = {};
    if (result.subtitleScanActive && result.subtitleScanTabId === tabId) {
      updates.subtitleScanActive = false;
    }
    if (result.listScanActive && result.listScanTabId === tabId) {
      updates.listScanActive = false;
    }
    if (Object.keys(updates).length > 0) {
      await chrome.storage.local.set(updates);
    }
  } catch (e) { /* サービスワーカー停止間際などは無視 */ }
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  switch (message.type) {
    case 'GET_TAB_ID':
      // content scriptは自身のtabIdを直接取得できないためここで返す
      sendResponse({ tabId: sender.tab?.id ?? null });
      return true;

    case 'GET_STATUS':
      sendResponse({
        isScanning,
        timestamps,
        volumeGraphData,
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

    case 'UPDATE_VIDEO_TIME':
      // Offscreen Documentに転送
      chrome.runtime.sendMessage({
        type: 'UPDATE_VIDEO_TIME',
        time: message.time
      }).catch(() => {});
      return false;

    case 'UPDATE_CONFIG':
      Object.assign(CONFIG, message.config);
      // ストレージに保存
      chrome.storage.local.set({ config: CONFIG });
      // Offscreen Documentにも転送
      chrome.runtime.sendMessage({
        type: 'UPDATE_CONFIG',
        config: CONFIG
      }).catch(() => {});
      sendResponse({ success: true, config: CONFIG });
      return true;

    case 'TIMESTAMP_DETECTED_FROM_OFFSCREEN':
      // Offscreen Documentからのタイムスタンプ検出通知
      timestamps.push(message.timestamp);
      notifyContentScript(message.timestamp);
      return false;

    case 'VOLUME_DATA_FROM_OFFSCREEN':
      // Offscreen Documentからの音量データ
      console.log('音量データ受信(raw)', { index: message.index, volume: message.volume });
      if (message.index >= 0 && message.index < currentGraphResolution) {
        const oldValue = volumeGraphData[message.index] || 0;
        if (message.volume > oldValue) {
          volumeGraphData[message.index] = message.volume;
        }
      }
      // 200msごとにUIを更新
      const now = Date.now();
      if (now - lastVolumeUpdateTime >= 200) {
        lastVolumeUpdateTime = now;
        sendVolumeDataToContent();
      }
      return false;

    // 音量グラフ関連
    case 'START_SCAN':
      console.log('START_SCAN受信', { isScanning });
      if (isScanning) {
        stopScan();
        sendResponse({ success: true, isScanning: false });
      } else {
        // 非同期で開始し、結果を返す
        startScan(message.muted).then(result => {
          sendResponse({ success: result.success, isScanning, error: result.error });
        });
      }
      return true;

    case 'STOP_SCAN':
      stopScan();
      sendResponse({ success: true });
      return true;

    case 'GET_VOLUME_DATA':
      sendResponse({ data: volumeGraphData, duration: videoDuration });
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

    case 'CHECK_TOXICITY':
      checkToxicity(message.text, message.recentMessages)
        .then(result => sendResponse(result))
        .catch(error => sendResponse({ error: error.message }));
      return true;
  }
});

/**
 * Offscreen Documentが存在するか確認
 */
async function hasOffscreenDocument() {
  const contexts = await chrome.runtime.getContexts({
    contextTypes: ['OFFSCREEN_DOCUMENT'],
    documentUrls: [chrome.runtime.getURL('offscreen.html')]
  });
  return contexts.length > 0;
}

/**
 * Offscreen Documentを作成（存在しない場合のみ）
 */
async function ensureOffscreenDocument() {
  if (await hasOffscreenDocument()) {
    return;
  }

  await chrome.offscreen.createDocument({
    url: 'offscreen.html',
    reasons: ['USER_MEDIA'],
    justification: 'Audio capture and volume analysis for timestamp detection'
  });
}

/**
 * Offscreen Documentを閉じる
 */
async function closeOffscreenDocument() {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument();
  }
}

/**
 * タブの音声キャプチャを開始（内部使用のみ）
 */
async function startCapture(tabId) {
  if (isCapturing) {
    return { success: false, error: '既にキャプチャ中です' };
  }

  try {
    // Offscreen Documentを作成
    await ensureOffscreenDocument();

    // Stream IDを取得
    const streamId = await chrome.tabCapture.getMediaStreamId({
      targetTabId: tabId
    });

    if (!streamId) {
      return { success: false, error: 'Stream IDを取得できませんでした' };
    }

    // Offscreen Documentで音声処理を開始
    console.log('START_AUDIO_PROCESSING送信中...');
    const response = await chrome.runtime.sendMessage({
      type: 'START_AUDIO_PROCESSING',
      streamId: streamId,
      config: CONFIG,
      graphResolution: currentGraphResolution
    });

    console.log('START_AUDIO_PROCESSING応答:', response);

    if (!response || !response.success) {
      await closeOffscreenDocument();
      return { success: false, error: response?.error || '音声処理の開始に失敗しました' };
    }

    isCapturing = true;
    currentTabId = tabId;
    timestamps = [];

    return { success: true };
  } catch (error) {
    console.error('キャプチャ開始エラー:', error);
    await closeOffscreenDocument();
    return { success: false, error: error.message };
  }
}

/**
 * キャプチャを停止（内部使用のみ）
 */
async function stopCapture() {
  try {
    // Offscreen Documentで音声処理を停止
    await chrome.runtime.sendMessage({ type: 'STOP_AUDIO_PROCESSING' }).catch(() => {});

    // Offscreen Documentを閉じる
    await closeOffscreenDocument();

    isCapturing = false;
    currentTabId = null;

    return { success: true };
  } catch (error) {
    console.error('キャプチャ停止エラー:', error);
    isCapturing = false;
    currentTabId = null;
    return { success: false, error: error.message };
  }
}

/**
 * コンテンツスクリプトに通知
 */
async function notifyContentScript(timestamp) {
  if (!currentTabId) return;

  try {
    await chrome.tabs.sendMessage(currentTabId, {
      type: 'TIMESTAMP_DETECTED',
      timestamp
    });
  } catch (error) {
    console.error('通知エラー:', error);
  }
}

/**
 * 音量データをコンテンツスクリプトに送信
 */
async function sendVolumeDataToContent() {
  try {
    // スキャン中のタブ（キャプチャ対象）に送る。アクティブタブ宛てにすると、
    // スキャン中に別タブでアーカイブを開いたとき無関係な動画にグラフが
    // 表示・誤保存されてしまう（#614）
    const tab = currentTabId ? { id: currentTabId } : null;
    if (tab?.id) {
      // スパース配列を埋める
      const filledData = [];
      for (let i = 0; i < currentGraphResolution; i++) {
        filledData[i] = volumeGraphData[i] || 0;
      }

      const progress = (filledData.filter(v => v > 0).length / currentGraphResolution) * 100;

      // デバッグ: 送信データを確認（毎回出力）
      console.log('音量データ送信', {
        progress: progress.toFixed(1) + '%',
        nonZeroCount: filledData.filter(v => v > 0).length
      });

      chrome.tabs.sendMessage(tab.id, {
        type: 'VOLUME_DATA_UPDATE',
        data: filledData,
        progress
      });
    }
  } catch (error) {
    console.error('音量データ送信エラー:', error);
  }
}

/**
 * 高速スキャンを開始
 * スキャン中は常にミュート（tabCaptureの仕様により音声出力は不可）
 */
async function startScan() {
  console.log('startScan開始');
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    console.log('アクティブタブ:', tab?.id, tab?.url);
    if (!tab?.id) {
      console.error('アクティブタブが見つかりません');
      return { success: false, error: 'NO_ACTIVE_TAB' };
    }

    // スキャン状況を確認
    console.log('GET_SCAN_STATUS送信中...');
    const scanStatus = await chrome.tabs.sendMessage(tab.id, { type: 'GET_SCAN_STATUS' });
    console.log('スキャン状況:', scanStatus);

    // 既にスキャン完了済みなら実行しない
    if (scanStatus.isComplete) {
      console.log('この動画は既にスキャン完了済みです（進捗: ' + scanStatus.progress.toFixed(1) + '%）');
      // グラフを表示
      chrome.tabs.sendMessage(tab.id, { type: 'SHOW_VOLUME_GRAPH' });
      return { success: true, alreadyComplete: true };
    }

    // 動画情報を取得
    const videoInfo = await chrome.tabs.sendMessage(tab.id, { type: 'GET_VIDEO_INFO' });
    videoDuration = videoInfo.duration;

    if (!videoDuration) {
      console.error('動画の長さを取得できません');
      return { success: false, error: 'NO_VIDEO_DURATION' };
    }

    // 動画の長さに応じてグラフ解像度を計算
    currentGraphResolution = Math.ceil(videoDuration / SAMPLING_INTERVAL_SEC);

    // キャプチャを開始（既存のキャプチャは停止してから再開始）
    if (isCapturing) {
      await stopCapture();
    }
    const result = await startCapture(tab.id);
    if (!result.success) {
      console.error('キャプチャ開始失敗:', result.error);
      // activeTab権限エラーの場合、コンテンツスクリプトに通知
      if (result.error?.includes('activeTab') || result.error?.includes('invoked')) {
        chrome.tabs.sendMessage(tab.id, { type: 'SCAN_PERMISSION_ERROR' });
      }
      return { success: false, error: 'CAPTURE_FAILED', message: result.error };
    }

    isScanning = true;

    // 既存データがあれば引き継ぐ、なければ新規作成
    if (scanStatus.hasData && scanStatus.data) {
      volumeGraphData = [...scanStatus.data];
      // 配列の長さが足りなければ埋める
      while (volumeGraphData.length < currentGraphResolution) {
        volumeGraphData.push(0);
      }
      // デバッグ: データの状態を確認
      const nonZeroCount = volumeGraphData.filter(v => v > 0).length;
      console.log('既存データから再開', {
        progress: scanStatus.progress.toFixed(1) + '%',
        resumeTime: scanStatus.resumeTime,
        dataLength: volumeGraphData.length,
        nonZeroCount: nonZeroCount,
        first10: volumeGraphData.slice(0, 10),
        last10: volumeGraphData.slice(-10)
      });
    } else {
      volumeGraphData = new Array(currentGraphResolution).fill(0);
      console.log('新規スキャン開始');
    }

    // Offscreen Documentにスキャン開始を通知
    console.log('START_VOLUME_SCAN送信中...', { duration: videoDuration, graphResolution: currentGraphResolution });
    chrome.runtime.sendMessage({
      type: 'START_VOLUME_SCAN',
      duration: videoDuration,
      graphResolution: currentGraphResolution
    }).then(res => {
      console.log('START_VOLUME_SCAN応答:', res);
    }).catch(err => {
      console.error('START_VOLUME_SCAN失敗:', err);
    });

    // 動画を高速再生
    // 注意: tabCaptureはタブの音声出力をキャプチャするため、ミュートすると音声データが取得できない
    // そのため、スキャン中は音声が出力される
    await chrome.tabs.sendMessage(tab.id, { type: 'SET_MUTED', muted: false });
    await chrome.tabs.sendMessage(tab.id, { type: 'SET_PLAYBACK_RATE', rate: 4 });

    // 途中から再開する場合は、その時点からシーク
    const startTime = scanStatus.hasData && scanStatus.resumeTime > 0
      ? scanStatus.resumeTime
      : 0;
    await chrome.tabs.sendMessage(tab.id, { type: 'SEEK_VIDEO', time: startTime });
    await chrome.tabs.sendMessage(tab.id, { type: 'PLAY_VIDEO' });

    // UIに通知
    chrome.tabs.sendMessage(tab.id, { type: 'SCAN_STARTED' });
    chrome.tabs.sendMessage(tab.id, { type: 'SHOW_VOLUME_GRAPH' });

    // スキャン完了を監視
    monitorScanProgress(tab.id);

    console.log('高速スキャン開始' + (startTime > 0 ? `（${startTime.toFixed(0)}秒から再開）` : ''));
    return { success: true };
  } catch (error) {
    console.error('スキャン開始エラー:', error);
    isScanning = false;
    return { success: false, error: 'UNKNOWN_ERROR', message: error.message };
  }
}

/**
 * スキャン進捗を監視
 */
function monitorScanProgress(tabId) {
  const checkProgress = setInterval(async () => {
    if (!isScanning) {
      clearInterval(checkProgress);
      return;
    }

    try {
      const videoInfo = await chrome.tabs.sendMessage(tabId, { type: 'GET_VIDEO_INFO' });

      // 動画の終わりに近づいたらスキャン完了
      if (videoInfo.currentTime >= videoDuration - 1) {
        clearInterval(checkProgress);
        stopScan();
      }

      // 進捗を送信
      sendVolumeDataToContent();
    } catch (error) {
      clearInterval(checkProgress);
      stopScan();
    }
  }, 200);
}

/**
 * 高速スキャンを停止
 */
async function stopScan() {
  if (!isScanning) return;

  isScanning = false;

  // Offscreen Documentにスキャン停止を通知
  chrome.runtime.sendMessage({ type: 'STOP_VOLUME_SCAN' }).catch(() => {});

  try {
    // 後始末は必ずスキャン中のタブに対して行う。アクティブタブ宛てだと、
    // 別タブを見ている間に停止した場合にスキャン中のタブが4倍速ミュートの
    // まま残り、見ていた無関係なタブが一時停止・先頭シークされてしまう（#614）
    const tabId = currentTabId;
    if (tabId) {
      // 再生速度を元に戻し、ミュートを解除
      await chrome.tabs.sendMessage(tabId, { type: 'SET_MUTED', muted: false });
      await chrome.tabs.sendMessage(tabId, { type: 'SET_PLAYBACK_RATE', rate: 1 });
      await chrome.tabs.sendMessage(tabId, { type: 'PAUSE_VIDEO' });
      await chrome.tabs.sendMessage(tabId, { type: 'SEEK_VIDEO', time: 0 });

      // UIに通知
      chrome.tabs.sendMessage(tabId, { type: 'SCAN_STOPPED' });

      // 最終データを送信
      sendVolumeDataToContent();
    }
  } catch (error) {
    console.error('スキャン停止エラー:', error);
  }

  console.log('高速スキャン停止');
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

/**
 * Claude APIを使ってメッセージの毒性をチェック
 * @param {string} text - チェック対象のメッセージ
 * @param {string[]} recentMessages - 直近のチャットメッセージ（文脈用）
 * @returns {Promise<{toxic: boolean, reason: string}>}
 */
const TOXICITY_MODEL = 'claude-haiku-4-5-20251001';
const TOXICITY_TIMEOUT_MS = 10000;
const TOXICITY_MIN_INTERVAL_MS = 1000;
let lastToxicityCheckTime = 0;

async function checkToxicity(text, recentMessages = []) {
  try {
    // レート制限
    const now = Date.now();
    if (now - lastToxicityCheckTime < TOXICITY_MIN_INTERVAL_MS) {
      return { toxic: false, reason: '', skipped: true };
    }
    lastToxicityCheckTime = now;

    const result = await chrome.storage.local.get(['claudeApiKey', 'toxicityCheckEnabled']);
    if (!result.toxicityCheckEnabled || !result.claudeApiKey) {
      return { toxic: false, reason: '', skipped: true };
    }

    let contextPart = '';
    if (recentMessages && recentMessages.length > 0) {
      const recent = recentMessages.slice(-10).join('\n');
      contextPart = `\n\n参考: 直近のチャット:\n${recent}`;
    }

    // タイムアウト付きfetch
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), TOXICITY_TIMEOUT_MS);

    let response;
    try {
      response = await fetch('https://api.anthropic.com/v1/messages', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': result.claudeApiKey,
          'anthropic-version': '2023-06-01',
          'anthropic-dangerous-direct-browser-access': 'true'
        },
        body: JSON.stringify({
          model: TOXICITY_MODEL,
          max_tokens: 150,
          messages: [{
            role: 'user',
            content: `あなたはYouTubeライブチャットの投稿内容を確認するモデレーターです。
以下のメッセージが攻撃的・侮辱的・不適切でないか判定してください。

判定基準:
- 誹謗中傷、人格攻撃、差別的表現
- 過度な煽り、嫌がらせ
- 配信者や他の視聴者への攻撃

以下のJSON形式のみで回答してください（他のテキストは不要）:
{"toxic": true/false, "reason": "理由（問題がない場合は空文字）"}

送信しようとしているメッセージ: ${text}${contextPart}`
          }]
        }),
        signal: controller.signal
      });
    } finally {
      clearTimeout(timeoutId);
    }

    if (!response.ok) {
      const errorBody = await response.text();
      console.error('Claude API エラー:', response.status, errorBody);
      return { toxic: false, reason: '', error: `API error: ${response.status}` };
    }

    const data = await response.json();
    const content = data.content?.[0]?.text || '';

    // JSONを抽出（"toxic"キーを含むオブジェクトを検索）
    try {
      const jsonMatch = content.match(/\{[^{}]*"toxic"[^{}]*\}/);
      if (jsonMatch) {
        const parsed = JSON.parse(jsonMatch[0]);
        if (typeof parsed.toxic === 'boolean') {
          return { toxic: parsed.toxic, reason: parsed.reason || '' };
        }
      }
      // フォールバック: 全体をパース
      const parsed = JSON.parse(content);
      if (typeof parsed.toxic === 'boolean') {
        return { toxic: parsed.toxic, reason: parsed.reason || '' };
      }
    } catch (parseError) {
      console.error('JSON parse error:', parseError, 'Content:', content);
    }

    return { toxic: false, reason: '', error: 'Failed to parse response' };
  } catch (error) {
    if (error.name === 'AbortError') {
      console.error('毒性チェック: タイムアウト');
      return { toxic: false, reason: '', error: 'タイムアウト' };
    }
    console.error('毒性チェックエラー:', error);
    return { toxic: false, reason: '', error: error.message };
  }
}
