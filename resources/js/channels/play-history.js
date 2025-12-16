import { getYoutubeUrl } from '../utils/youtube.js';

/**
 * 再生履歴表示コンポーネント
 * チャンネル一覧ページでsessionStorageに保存された再生履歴を表示
 */
class PlayHistory {
    constructor() {
        this.STORAGE_KEY = 'playHistory';
        this.init();
    }

    init() {
        this.createHistoryButton();
        this.createHistoryPanel();
        this.updateDisplay();
    }

    /**
     * 再生履歴を取得
     */
    getHistory() {
        try {
            return JSON.parse(sessionStorage.getItem(this.STORAGE_KEY) || '[]');
        } catch {
            return [];
        }
    }

    /**
     * フローティングボタンを作成
     */
    createHistoryButton() {
        const button = document.createElement('button');
        button.id = 'playHistoryButton';
        button.className = 'fixed bottom-6 right-6 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center transition-all z-40';
        button.title = '再生履歴';
        button.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span id="playHistoryBadge" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center hidden">0</span>
        `;
        button.addEventListener('click', () => this.togglePanel());
        document.body.appendChild(button);
    }

    /**
     * 履歴パネルを作成
     */
    createHistoryPanel() {
        const panel = document.createElement('div');
        panel.id = 'playHistoryPanel';
        panel.className = 'fixed bottom-24 right-6 w-80 max-h-96 bg-white dark:bg-gray-800 rounded-lg shadow-xl border border-gray-200 dark:border-gray-700 hidden z-50 flex flex-col';
        panel.innerHTML = `
            <div class="p-3 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center flex-shrink-0">
                <h4 class="font-semibold text-gray-900 dark:text-gray-100">再生履歴</h4>
                <button id="clearPlayHistoryBtn" class="text-xs text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-400">
                    クリア
                </button>
            </div>
            <div id="playHistoryList" class="flex-1 overflow-y-auto p-2 space-y-2">
                <p class="text-gray-500 dark:text-gray-400 text-sm text-center py-4">履歴はありません</p>
            </div>
        `;
        document.body.appendChild(panel);

        // クリアボタンのイベント
        document.getElementById('clearPlayHistoryBtn').addEventListener('click', () => {
            this.clearHistory();
        });

        // パネル外クリックで閉じる
        document.addEventListener('click', (e) => {
            const panel = document.getElementById('playHistoryPanel');
            const button = document.getElementById('playHistoryButton');
            if (!panel.contains(e.target) && !button.contains(e.target) && !panel.classList.contains('hidden')) {
                panel.classList.add('hidden');
            }
        });
    }

    /**
     * パネルの表示/非表示を切り替え
     */
    togglePanel() {
        const panel = document.getElementById('playHistoryPanel');
        panel.classList.toggle('hidden');
    }

    /**
     * 表示を更新
     */
    updateDisplay() {
        const history = this.getHistory();
        const listContainer = document.getElementById('playHistoryList');
        const badge = document.getElementById('playHistoryBadge');

        // バッジ更新
        if (history.length > 0) {
            badge.textContent = history.length;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }

        // リスト更新
        if (history.length === 0) {
            listContainer.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-sm text-center py-4">履歴はありません</p>';
            return;
        }

        listContainer.innerHTML = '';
        history.forEach(entry => {
            const item = this.createHistoryItem(entry);
            listContainer.appendChild(item);
        });
    }

    /**
     * 履歴アイテム要素を作成
     */
    createHistoryItem(entry) {
        const div = document.createElement('div');
        div.className = 'p-2 bg-gray-50 dark:bg-gray-700 rounded text-sm';

        // ヘッダー（チャンネル名と時刻）
        const header = document.createElement('div');
        header.className = 'flex justify-between items-center mb-1';

        const channelSpan = document.createElement('span');
        channelSpan.className = 'text-xs text-gray-500 dark:text-gray-400 truncate max-w-[150px]';
        channelSpan.textContent = entry.channelTitle || entry.channelHandle || '-';
        channelSpan.title = entry.channelTitle || entry.channelHandle || '-';

        const timeSpan = document.createElement('span');
        timeSpan.className = 'text-xs text-gray-400 dark:text-gray-500';
        timeSpan.textContent = this.formatRelativeTime(new Date(entry.playedAt));

        header.appendChild(channelSpan);
        header.appendChild(timeSpan);

        // 楽曲情報
        const content = document.createElement('div');
        content.className = 'text-gray-900 dark:text-gray-100';

        const titleDiv = document.createElement('div');
        titleDiv.className = 'font-medium truncate';
        titleDiv.textContent = entry.title || '-';
        titleDiv.title = entry.title || '-';

        content.appendChild(titleDiv);

        if (entry.artist) {
            const artistDiv = document.createElement('div');
            artistDiv.className = 'text-xs text-gray-500 dark:text-gray-400 truncate';
            artistDiv.textContent = entry.artist;
            artistDiv.title = entry.artist;
            content.appendChild(artistDiv);
        }

        div.appendChild(header);
        div.appendChild(content);

        // YouTubeリンクボタン（videoIdがある場合）
        if (entry.videoId) {
            const linkDiv = document.createElement('div');
            linkDiv.className = 'mt-2 flex gap-2';

            // YouTube視聴リンク
            const youtubeLink = document.createElement('a');
            youtubeLink.href = getYoutubeUrl(entry.videoId, entry.tsNum);
            youtubeLink.target = '_blank';
            youtubeLink.rel = 'noopener noreferrer';
            youtubeLink.className = 'text-xs text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 flex items-center gap-1';
            youtubeLink.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/>
                </svg>
                YouTube
            `;

            // チャンネルページリンク
            if (entry.channelHandle) {
                const channelLink = document.createElement('a');
                channelLink.href = `/channels/${encodeURIComponent(entry.channelHandle)}`;
                channelLink.className = 'text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1';
                channelLink.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    チャンネル
                `;
                linkDiv.appendChild(channelLink);
            }

            linkDiv.appendChild(youtubeLink);
            div.appendChild(linkDiv);
        }

        return div;
    }

    /**
     * 相対時刻をフォーマット
     */
    formatRelativeTime(date) {
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) {
            return 'たった今';
        } else if (diff < 3600000) {
            return `${Math.floor(diff / 60000)}分前`;
        } else {
            return date.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
        }
    }

    /**
     * 履歴をクリア
     */
    clearHistory() {
        try {
            sessionStorage.removeItem(this.STORAGE_KEY);
        } catch {
            // 無視
        }
        this.updateDisplay();
    }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    new PlayHistory();
});
