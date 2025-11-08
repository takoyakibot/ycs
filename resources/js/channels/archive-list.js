/**
 * アーカイブ一覧とタイムスタンプ管理
 */
class ArchiveListComponent {
    constructor() {
        // 状態管理
        this.channel = window.channel || {};
        this.archives = window.archives || {};
        this.timestamps = {};
        this.activeTab = 'archives';
        this.searchQuery = '';
        this.searchTimeout = null;
        this.currentTimestampPage = 1;
        this.timestampSort = 'time_desc';
        this.loading = false;
        this.error = null;
        this.isFiltered = false;
    }

    /**
     * ページのデータを取得
     */
    async fetchData(url) {
        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('データ取得エラー');
            this.archives = await response.json();

            const paginationButtons = document.querySelectorAll('#paginationButtons button');
            paginationButtons.forEach(button => {
                window.togglePaginationButtonDisabled(button, this.archives.current_page, this.maxPage);
            });
        } catch (error) {
            console.error('データの取得に失敗しました:', error);
        }
    }

    /**
     * 最大ページ数を計算
     */
    get maxPage() {
        if (!this.archives.total || !this.archives.per_page) return 1;
        return Math.ceil(this.archives.total / this.archives.per_page);
    }

    /**
     * 初回URL生成
     */
    firstUrl(params) {
        return `/api/channels/${this.channel.handle}?page=1` + (params ? `&${params}` : '');
    }

    /**
     * YouTube URLを安全に構築
     */
    getYoutubeUrl(videoId, tsNum) {
        const safeVideoId = encodeURIComponent(videoId || '');
        const safeTsNum = parseInt(tsNum) || 0;
        return `https://youtube.com/watch?v=${safeVideoId}&t=${safeTsNum}s`;
    }

    /**
     * タイムスタンプデータを取得（検索・ソート対応）
     */
    async fetchTimestamps(page = 1, search = '') {
        try {
            this.loading = true;
            this.error = null;

            const params = new URLSearchParams({
                page: page,
                per_page: 50,
                sort: this.timestampSort
            });

            if (search) {
                params.set('search', search);
            }

            const response = await fetch(`/api/channels/${this.channel.handle}/timestamps?${params}`);
            if (!response.ok) throw new Error('タイムスタンプの取得に失敗しました');

            const data = await response.json();

            // ページ番号を数値として保存（文字列連結バグの防止）
            const parsedPage = parseInt(data.current_page, 10);
            data.current_page = Number.isNaN(parsedPage) ? 1 : parsedPage;

            const parsedLastPage = parseInt(data.last_page, 10);
            data.last_page = Number.isNaN(parsedLastPage) ? 1 : parsedLastPage;

            this.timestamps = data;
            this.currentTimestampPage = page;
            this.updateURL();
        } catch (error) {
            console.error('タイムスタンプの取得に失敗しました:', error);
            this.error = error.message;
        } finally {
            this.loading = false;
        }
    }

    /**
     * 検索実行
     */
    searchTimestamps() {
        this.currentTimestampPage = 1;
        this.fetchTimestamps(1, this.searchQuery);
    }

    /**
     * URLパラメータを更新
     */
    updateURL() {
        const params = new URLSearchParams();

        // タブ
        if (this.activeTab !== 'archives') {
            params.set('view', this.activeTab);
        }

        // 検索キーワード
        if (this.searchQuery) {
            params.set('search', this.searchQuery);
        }

        // ソート（デフォルト以外の場合のみ）
        if (this.timestampSort && this.timestampSort !== 'time_desc') {
            params.set('sort', this.timestampSort);
        }

        // ページ番号
        const page = this.activeTab === 'timestamps' ? this.currentTimestampPage : this.archives.current_page;
        if (page && page > 1) {
            params.set('page', page);
        }

        const paramString = params.toString();
        const newURL = paramString ? `${window.location.pathname}?${paramString}` : window.location.pathname;
        window.history.pushState({}, '', newURL);
    }

    /**
     * ページネーションクリック処理
     */
    handlePaginationClick(event) {
        const button = event.target;
        const isNext = button.classList.contains('next');
        const url = isNext ? this.archives.next_page_url : this.archives.prev_page_url;

        if (!url) return;
        this.fetchData(url);
        window.scroll({top: 0, behavior: 'auto'});
    }

    /**
     * 初期化処理
     */
    init() {
        // URLパラメータから状態を復元
        const params = new URLSearchParams(window.location.search);
        const view = params.get('view');
        const search = params.get('search');
        const sort = params.get('sort');
        const page = parseInt(params.get('page')) || 1;

        if (view === 'timestamps') {
            this.activeTab = 'timestamps';
            this.searchQuery = search || '';
            this.timestampSort = sort || 'time_desc';
            this.currentTimestampPage = page;
            this.fetchTimestamps(page, this.searchQuery);
        } else {
            this.fetchData(this.firstUrl());
        }

        // 検索コンポーネントのイベントリスナー
        this.$el.addEventListener('search-results', (e) => {
            this.fetchData(this.firstUrl(e.detail));
        });

        // ページネーションボタンのイベント設定
        const paginationButtons = document.querySelectorAll('#paginationButtons button');
        paginationButtons.forEach(button => {
            button.addEventListener('click', this.handlePaginationClick.bind(this));
        });

        // タブ切り替え監視
        this.$watch('activeTab', (newTab) => {
            if (newTab === 'timestamps' && !this.timestamps.data) {
                this.fetchTimestamps(1, this.searchQuery);
            }
            this.updateURL();
        });

        // 検索キーワード監視（debounce）
        this.$watch('searchQuery', () => {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.searchTimestamps();
            }, 300);
        });

        // ブラウザバック/フォワード対応
        window.addEventListener('popstate', () => {
            const params = new URLSearchParams(window.location.search);
            const view = params.get('view');
            const search = params.get('search');
            const sort = params.get('sort');
            const page = parseInt(params.get('page')) || 1;

            this.activeTab = view || 'archives';

            if (view === 'timestamps') {
                this.searchQuery = search || '';
                this.timestampSort = sort || 'time_desc';
                this.currentTimestampPage = page;
                this.fetchTimestamps(page, search || '');
            }
        });
    }
}

// Alpine.jsコンポーネント登録
window.addEventListener('alpine:init', () => {
    Alpine.data('archiveListComponent', () => {
        const component = new ArchiveListComponent();
        return new Proxy(component, {
            get(target, prop) {
                if (typeof target[prop] === 'function') {
                    return target[prop].bind(target);
                }
                return target[prop];
            }
        });
    });
});

// グローバル関数（ページネーション用）
window.togglePaginationButtonDisabled = function(button, newPage, maxPage) {
    const isNext = button.classList.contains('next');
    if (!isNext && 1 < newPage || isNext && newPage < maxPage) {
        button.classList.remove('pagination-button-disabled');
    } else {
        button.classList.add('pagination-button-disabled');
    }
};
