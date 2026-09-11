import state from './state.js';
import { toggleListScanPanel } from './list-scan.js';
import { toggleChatSearchPanel } from './chat-search.js';
import { toggleSubtitlePanel } from './subtitle-panel.js';
import { toggleHighlightPanel } from './highlight.js';
import { isListScanPanelVisible } from './list-scan.js';
import { isChatSearchPanelVisible } from './chat-search.js';
import { isSubtitlePanelVisible } from './subtitle-panel.js';
import { isHighlightPanelVisible } from './highlight.js';
import { closeLyricsPastePopup, closeSongCandidatePopup } from './song-candidates.js';
import { updateVideoDuration } from './utils.js';
import { resizeCanvas } from './volume-graph.js';

export function createEmbeddedTriggerButton() {
  if (state.embeddedTriggerButton) return;

  // ボタンコンテナを作成
  const buttonContainer = document.createElement('div');
  buttonContainer.id = 'ycs-button-container';
  buttonContainer.innerHTML = `
    <style>
      #ycs-button-container {
        position: fixed;
        bottom: 80px;
        right: 16px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 8px;
      }
      #ycs-button-container.hidden {
        display: none !important;
      }
      .ycs-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: 12px;
        font-weight: bold;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .ycs-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 4px 16px rgba(0,0,0,0.4);
      }
      .ycs-btn.active {
        background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
      }
      #ycs-list-btn {
        font-size: 16px;
      }
      #ycs-chat-btn {
        font-size: 14px;
      }
      #ycs-subtitle-btn {
        font-size: 14px;
      }
      #ycs-highlight-btn {
        font-size: 14px;
      }
    </style>
    <button class="ycs-btn" id="ycs-trigger-btn" title="タイムスタンプ検出グラフを表示/非表示">YCS</button>
    <button class="ycs-btn" id="ycs-list-btn" title="リストスキャンパネルを開く">☰</button>
    <button class="ycs-btn" id="ycs-chat-btn" title="チャット検索パネルを開く">💬</button>
    <button class="ycs-btn" id="ycs-subtitle-btn" title="字幕取得パネルを開く">📝</button>
    <button class="ycs-btn" id="ycs-highlight-btn" title="ハイライト検出パネルを開く">✨</button>
  `;

  document.body.appendChild(buttonContainer);
  state.embeddedTriggerButton = buttonContainer;

  // YCSボタンのイベント
  buttonContainer.querySelector('#ycs-trigger-btn').addEventListener('click', () => {
    toggleEmbeddedUI();
  });

  // リストボタンのイベント
  buttonContainer.querySelector('#ycs-list-btn').addEventListener('click', () => {
    toggleListScanPanel();
  });

  // チャット検索ボタンのイベント
  buttonContainer.querySelector('#ycs-chat-btn').addEventListener('click', () => {
    toggleChatSearchPanel();
  });

  // 字幕取得ボタンのイベント
  buttonContainer.querySelector('#ycs-subtitle-btn').addEventListener('click', () => {
    toggleSubtitlePanel();
  });

  // ハイライト検出ボタンのイベント
  buttonContainer.querySelector('#ycs-highlight-btn').addEventListener('click', () => {
    toggleHighlightPanel();
  });

  updateTriggerButtonState();
}

export function toggleEmbeddedUI() {
  if (state.volumeGraphContainer) {
    const isVisible = state.volumeGraphContainer.classList.contains('visible');
    if (isVisible) {
      hideVolumeGraphPanel();
    } else {
      state.volumeGraphContainer.classList.add('visible');
      state.isGraphVisible = true;
      updateVideoDuration();
      resizeCanvas();
    }
    updateTriggerButtonState();
  }
}

export function updateTriggerButtonState() {
  if (!state.embeddedTriggerButton) return;

  const ycsBtn = state.embeddedTriggerButton.querySelector('#ycs-trigger-btn');
  if (ycsBtn) {
    if (state.isGraphVisible) {
      ycsBtn.classList.add('active');
    } else {
      ycsBtn.classList.remove('active');
    }
  }

  const listBtn = state.embeddedTriggerButton.querySelector('#ycs-list-btn');
  if (listBtn) {
    if (isListScanPanelVisible()) {
      listBtn.classList.add('active');
    } else {
      listBtn.classList.remove('active');
    }
  }

  const chatBtn = state.embeddedTriggerButton.querySelector('#ycs-chat-btn');
  if (chatBtn) {
    chatBtn.classList.toggle('active', isChatSearchPanelVisible());
  }

  const subtitleBtn = state.embeddedTriggerButton.querySelector('#ycs-subtitle-btn');
  if (subtitleBtn) {
    subtitleBtn.classList.toggle('active', isSubtitlePanelVisible());
  }

  const highlightBtn = state.embeddedTriggerButton.querySelector('#ycs-highlight-btn');
  if (highlightBtn) {
    highlightBtn.classList.toggle('active', isHighlightPanelVisible());
  }
}

export function showEmbeddedUI() {
  state.embeddedUIVisible = true;
  if (state.embeddedTriggerButton) {
    state.embeddedTriggerButton.classList.remove('hidden');
  }
}

// ペースト変換ポップアップのリスナー残留を防ぐため、非表示化は必ずここを経由すること
export function hideVolumeGraphPanel() {
  closeLyricsPastePopup();
  closeSongCandidatePopup();
  if (state.volumeGraphContainer) {
    state.volumeGraphContainer.classList.remove('visible');
  }
  state.isGraphVisible = false;
}

export function hideEmbeddedUI() {
  state.embeddedUIVisible = false;
  if (state.embeddedTriggerButton) {
    state.embeddedTriggerButton.classList.add('hidden');
  }
  // グラフも非表示
  hideVolumeGraphPanel();
}
