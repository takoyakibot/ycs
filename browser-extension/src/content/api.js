import state from './state.js';
import { DEFAULT_YCS_SERVER_URL } from './config.js';
import { getVideoId } from './utils.js';

const subtitleSentCache = new Set();
const subtitleSendInFlight = new Map();

export async function loadYcsApiSettings() {
  try {
    const result = await chrome.storage.local.get(['ycsApiToken', 'ycsServerUrl']);
    state.ycsApiToken = result.ycsApiToken || null;
    // 末尾のスラッシュは除去する（APIパス連結時に「//」になるのを防ぐ）
    state.ycsServerUrl = (result.ycsServerUrl || DEFAULT_YCS_SERVER_URL).replace(/\/+$/, '');
  } catch (error) {
    console.warn('[YCS] API設定読み込みエラー:', error);
  }
}

export function isExtensionContextValid() {
  try {
    return !!chrome.runtime?.id;
  } catch (e) {
    return false;
  }
}

export function missingTokenMessage() {
  return isExtensionContextValid()
    ? 'APIトークンが未設定です。プロフィール画面で発行し、拡張の設定に登録してください'
    : '拡張機能が更新されました。ページを再読み込みしてください';
}

export async function sendSubtitlesToServer(videoId, lang, subtitles) {
  // 選択中のトラックからkindを判定
  const selectEl = document.querySelector('#stp-lang-select');
  const selectedOption = selectEl?.selectedOptions?.[0];
  const selectedLang = selectedOption?.dataset?.lang || lang;
  const selectedTrack = state.currentCaptionTracks.find(t => t.languageCode === selectedLang);
  const kind = selectedTrack?.kind === 'asr' ? 'asr' : '';

  try {
    await postSubtitlesToServer(videoId, selectedLang, kind, subtitles);
  } catch (error) {
    console.warn('[YCS] 字幕データ送信エラー:', error.message);
  }
}

const chatReplaySentCache = new Set();

export async function sendChatReplayDataToServer(videoId, chats, duration) {
  if (!videoId || !chats || chats.length === 0) return;

  if (chatReplaySentCache.has(videoId)) return;

  if (!state.ycsApiToken) {
    await loadYcsApiSettings();
  }
  if (!state.ycsApiToken) return;

  try {
    const chatData = chats.map(c => ({
      message: c.message,
      timestamp: c.timestamp,
      type: c.type || 'normal',
    }));

    const response = await fetch(`${state.ycsServerUrl}/api/extension/chat-replay-data`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${state.ycsApiToken}`,
      },
      body: JSON.stringify({
        video_id: videoId,
        duration: duration,
        chat_data: chatData,
      }),
    });

    if (response.ok) {
      chatReplaySentCache.add(videoId);
      console.log(`[YCS] チャットリプレイデータをサーバーに送信しました: ${videoId} (${chatData.length}件)`);
    } else {
      console.warn(`[YCS] チャットリプレイデータ送信エラー: ${response.status}`);
    }
  } catch (error) {
    console.warn('[YCS] チャットリプレイデータ送信エラー:', error.message);
  }
}

export async function postSubtitlesToServer(videoId, languageCode, kind, subtitles) {
  if (!videoId || !subtitles || subtitles.length === 0) return;

  // 重複送信防止
  const cacheKey = `${videoId}_${languageCode}_${kind}`;
  if (subtitleSentCache.has(cacheKey)) return;

  // 送信中に再トリガーされた場合は実行中のPromiseを返す
  const inFlight = subtitleSendInFlight.get(cacheKey);
  if (inFlight) return inFlight;

  const sendPromise = (async () => {
    if (!state.ycsApiToken) {
      await loadYcsApiSettings();
    }
    if (!state.ycsApiToken) {
      throw new Error(missingTokenMessage());
    }

    const response = await fetch(`${state.ycsServerUrl}/api/manage/archives/subtitles/store`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${state.ycsApiToken}`,
      },
      body: JSON.stringify({
        video_id: videoId,
        language_code: languageCode,
        kind: kind,
        subtitles: subtitles.map(s => ({
          start: s.start,
          duration: s.duration,
          text: s.text,
        })),
      }),
    });

    if (!response.ok) {
      console.warn(`[YCS] 字幕データ送信失敗: ${response.status}`);
      throw new Error(`字幕データの送信に失敗しました (${response.status})`);
    }

    subtitleSentCache.add(cacheKey);
    const data = await response.json();
    console.log(`[YCS] 字幕データ送信成功: ${videoId} (${data.segment_count}セグメント, FP: ${data.fingerprints_generated}件)`);
  })();

  subtitleSendInFlight.set(cacheKey, sendPromise);
  try {
    return await sendPromise;
  } finally {
    subtitleSendInFlight.delete(cacheKey);
  }
}
