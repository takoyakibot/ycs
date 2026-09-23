import state from './state.js';

export function ensurePageBridge() {
  if (state.pageBridgeReady) return state.pageBridgeReady;
  state.pageBridgeReady = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = chrome.runtime.getURL('page-bridge.js');
    script.onload = () => { script.remove(); resolve(); };
    script.onerror = () => {
      script.remove();
      state.pageBridgeReady = null;
      reject(new Error('page-bridge.jsのロードに失敗しました'));
    };
    document.documentElement.appendChild(script);
  });
  return state.pageBridgeReady;
}

export async function getCaptionTracksFromPage() {
  await ensurePageBridge();

  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      window.removeEventListener('message', handler);
      reject(new Error('字幕データの取得がタイムアウトしました'));
    }, 5000);

    function handler(event) {
      if (event.source !== window || event.data?.type !== 'YCS_CAPTION_TRACKS_RESPONSE') return;
      window.removeEventListener('message', handler);
      clearTimeout(timeout);

      if (event.data.playabilityStatus !== 'OK') {
        reject(new Error('動画を取得できません。動画が非公開・削除済み、または年齢制限がある可能性があります'));
        return;
      }
      resolve(event.data.tracks);
    }

    window.addEventListener('message', handler);
    window.postMessage({ type: 'YCS_GET_CAPTION_TRACKS' }, '*');
  });
}

export function fetchTimedText(videoId, lang) {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      window.removeEventListener('message', handler);
      reject(new Error('字幕の取得がタイムアウトしました'));
    }, 15000);

    function handler(event) {
      if (event.source !== window || event.data?.type !== 'YCS_TIMEDTEXT_RESPONSE') return;
      window.removeEventListener('message', handler);
      clearTimeout(timeout);
      if (event.data.error) {
        reject(new Error(event.data.error));
      } else {
        resolve(event.data.segments);
      }
    }

    window.addEventListener('message', handler);
    window.postMessage({ type: 'YCS_FETCH_TIMEDTEXT', videoId, lang }, '*');
  });
}
