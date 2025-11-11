import { escapeHTML, formatDate } from '../utils.js';
import toast from '../utils/toast.js';

// 報告タイプ定数
const REPORT_TYPES = {
    WRONG_SONG: 'wrong_song',
    NOT_SONG: 'not_song',
    NOT_TIMESTAMP: 'not_timestamp',
    PROBLEM: 'problem',
    OTHER: 'other'
};

/**
 * アーカイブ一覧とタイムスタンプ管理コンポーネント
 * Alpine.jsコンポーネント登録
 */
function registerArchiveListComponent() {
    if (typeof Alpine !== 'undefined') {
        Alpine.data('archiveListComponent', function() {
            return {
                // 状態管理
                channel: window.channel || {},
                archives: window.archives || {},
                timestamps: {},
                activeTab: 'timestamps',
                searchQuery: '',
                archiveQuery: '',
                tsFlg: '',
                searchTimeout: null,
                currentTimestampPage: 1,
                timestampSort: 'song_asc',
                loading: false,
                error: null,
                isFiltered: false,

                // 報告機能の状態管理
                showReportModal: false,
                reportTarget: null,
                reportType: '',
                reportComment: '',

                // computed property
                get maxPage() {
                    if (!this.archives.total || !this.archives.per_page) return 1;
                    return Math.ceil(this.archives.total / this.archives.per_page);
                },

                // メソッド
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
                },

                firstUrl(params) {
                    return `/api/channels/${this.channel.handle}?page=1` + (params ? `&${params}` : '');
                },

                getYoutubeUrl(videoId, tsNum) {
                    const safeVideoId = encodeURIComponent(videoId || '');
                    const safeTsNum = parseInt(tsNum) || 0;
                    return `https://youtube.com/watch?v=${safeVideoId}&t=${safeTsNum}s`;
                },

                getArchiveUrl(videoId, tsNum) {
                    return this.getYoutubeUrl(videoId, tsNum);
                },

                escapeHTML(str) {
                    return escapeHTML(str);
                },

                formatPublishedDate(dateStr) {
                    return formatDate(dateStr);
                },

                archiveSearch() {
                    const params = new URLSearchParams();
                    params.append('baramutsu', this.archiveQuery);
                    params.append('visible', '');
                    params.append('ts', this.tsFlg);

                    const hasQuery = this.archiveQuery.length > 0;
                    this.$dispatch('filter-changed', hasQuery);

                    this.fetchData(this.firstUrl(params.toString()));
                    this.updateURL();
                },

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

                        const parsedPage = parseInt(data.current_page, 10);
                        data.current_page = Number.isNaN(parsedPage) ? 1 : parsedPage;

                        const parsedLastPage = parseInt(data.last_page, 10);
                        data.last_page = Number.isNaN(parsedLastPage) ? 1 : parsedLastPage;

                        this.timestamps = data;
                        this.currentTimestampPage = page;
                        this.updateURL();
                    } catch (error) {
                        console.error('タイムスタンプの取得に失敗しました:', error);
                        this.error = 'タイムスタンプの読み込み中にエラーが発生しました。ページを再読み込みしてください。';
                    } finally {
                        this.loading = false;
                    }
                },

                searchTimestamps() {
                    this.currentTimestampPage = 1;
                    this.fetchTimestamps(1, this.searchQuery);
                },

                jumpToIndex(letter) {
                    if (!this.timestamps.index_map || !this.timestamps.index_map[letter]) {
                        return;
                    }

                    const targetPage = this.timestamps.index_map[letter];
                    this.fetchTimestamps(targetPage, this.searchQuery);

                    // スムーズスクロール + オフセット
                    setTimeout(() => {
                        const tabElement = document.querySelector('#archives');
                        if (tabElement) {
                            const offset = 100; // 100pxの余白
                            const elementPosition = tabElement.getBoundingClientRect().top;
                            const offsetPosition = elementPosition + window.pageYOffset - offset;

                            window.scrollTo({
                                top: offsetPosition,
                                behavior: 'smooth'
                            });
                        }
                    }, 100); // データ取得待ち
                },

                updateURL() {
                    const params = new URLSearchParams();

                    // Only add 'view' parameter when not on default tab (timestamps)
                    if (this.activeTab !== 'timestamps') {
                        params.set('view', this.activeTab);
                    }

                    // タイムスタンプタブのパラメータ
                    if (this.activeTab === 'timestamps') {
                        if (this.searchQuery) {
                            params.set('search', this.searchQuery);
                        }

                        if (this.timestampSort && this.timestampSort !== 'song_asc') {
                            params.set('sort', this.timestampSort);
                        }

                        if (this.currentTimestampPage && this.currentTimestampPage > 1) {
                            params.set('page', this.currentTimestampPage);
                        }
                    } else {
                        // アーカイブタブのパラメータ
                        if (this.archiveQuery) {
                            params.set('baramutsu', this.archiveQuery);
                        }

                        if (this.tsFlg) {
                            params.set('ts', this.tsFlg);
                        }

                        if (this.archives.current_page && this.archives.current_page > 1) {
                            params.set('page', this.archives.current_page);
                        }
                    }

                    const paramString = params.toString();
                    const newURL = paramString ? `${window.location.pathname}?${paramString}` : window.location.pathname;
                    window.history.pushState({}, '', newURL);
                },

                handlePaginationClick(event) {
                    const button = event.target;
                    const isNext = button.classList.contains('next');
                    const url = isNext ? this.archives.next_page_url : this.archives.prev_page_url;

                    if (!url) return;
                    this.fetchData(url);
                    window.scroll({top: 0, behavior: 'auto'});
                },

                restoreStateFromURL(params) {
                    const view = params.get('view');
                    const search = params.get('search');
                    const sort = params.get('sort');
                    // ページパラメータのバリデーション: 1以上の整数に制限
                    const page = Math.max(1, parseInt(params.get('page')) || 1);

                    if (view === 'archives') {
                        this.activeTab = 'archives';
                        const archiveQuery = params.get('baramutsu') || '';
                        const tsFlg = params.get('ts') || '';
                        this.archiveQuery = archiveQuery;
                        this.tsFlg = tsFlg;

                        if (archiveQuery || tsFlg) {
                            this.archiveSearch();
                        } else {
                            this.fetchData(this.firstUrl());
                        }
                    } else {
                        // タイムスタンプタブの状態を復元（デフォルト）
                        this.activeTab = 'timestamps';
                        this.searchQuery = search || '';
                        this.timestampSort = sort || 'song_asc';
                        this.currentTimestampPage = page;
                        this.fetchTimestamps(page, this.searchQuery);
                    }
                },

                init() {
                    const params = new URLSearchParams(window.location.search);
                    this.restoreStateFromURL(params);

                    const paginationButtons = document.querySelectorAll('#paginationButtons button');
                    paginationButtons.forEach(button => {
                        button.addEventListener('click', this.handlePaginationClick.bind(this));
                    });

                    this.$watch('activeTab', (newTab) => {
                        if (newTab === 'timestamps' && !this.timestamps.data) {
                            this.fetchTimestamps(1, this.searchQuery);
                        } else if (newTab === 'archives' && !this.archives.data) {
                            this.fetchData(this.firstUrl());
                        }
                        this.updateURL();
                    });

                    this.$watch('searchQuery', () => {
                        clearTimeout(this.searchTimeout);
                        this.searchTimeout = setTimeout(() => {
                            this.searchTimestamps();
                        }, 300);
                    });

                    window.addEventListener('popstate', () => {
                        const params = new URLSearchParams(window.location.search);
                        this.restoreStateFromURL(params);
                    });
                },

                // 報告モーダルを開く
                openReportModal(timestamp) {
                    this.reportTarget = timestamp;
                    this.reportType = '';
                    this.reportComment = '';
                    this.showReportModal = true;
                },

                // 報告を送信（暫定実装：コンソールに出力）
                submitReport() {
                    // reportTargetの存在確認
                    if (!this.reportTarget) {
                        console.error('No report target set');
                        toast.error('報告対象が見つかりません');
                        return;
                    }

                    // 報告タイプの検証
                    if (!this.reportType) {
                        toast.error('報告の種類を選択してください');
                        return;
                    }

                    const reportData = {
                        timestamp_id: this.reportTarget.id,
                        video_id: this.reportTarget.video_id,
                        timestamp_text: this.reportTarget.text,
                        timestamp_time: this.reportTarget.ts_text,
                        report_type: this.reportType,
                        comment: this.reportComment,
                        reported_at: new Date().toISOString(),
                    };

                    // 暫定実装：コンソールに出力
                    console.log('=== タイムスタンプ報告 ===', reportData);

                    // TODO: 将来的にはAPIエンドポイントに送信
                    // Expected endpoint: POST /api/timestamp-reports
                    // Expected response: { success: boolean, message: string }
                    // Required headers: CSRF token
                    // await fetch('/api/timestamp-reports', {
                    //     method: 'POST',
                    //     headers: {
                    //         'Content-Type': 'application/json',
                    //         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    //     },
                    //     body: JSON.stringify(reportData),
                    // });

                    toast.success('報告を受け付けました。（現在は暫定実装のため、コンソールに出力されています）');
                    this.showReportModal = false;
                }
            };
        });
    }
}

// Alpine.jsが既に読み込まれている場合はすぐに登録
if (typeof Alpine !== 'undefined') {
    registerArchiveListComponent();
} else {
    // Alpine.jsの初期化を待つ
    window.addEventListener('alpine:init', registerArchiveListComponent);
}

// グローバル関数（ページネーション用）
window.togglePaginationButtonDisabled = function(button, newPage, maxPage) {
    const isNext = button.classList.contains('next');
    if (!isNext && 1 < newPage || isNext && newPage < maxPage) {
        button.classList.remove('pagination-button-disabled');
    } else {
        button.classList.add('pagination-button-disabled');
    }
};
