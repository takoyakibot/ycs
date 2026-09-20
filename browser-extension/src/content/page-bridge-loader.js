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
