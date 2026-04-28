import { escapeHTML, formatDate } from '../utils.js';
import toast from '../utils/toast.js';
import { getYoutubeUrl, isValidVideoId } from '../utils/youtube.js';
import {
    getSpotifyUrl,
    getAppleMusicUrl,
    getYouTubeMusicUrl,
    getAmazonMusicUrl,
    getLineMusicUrl
} from '../utils/music-services.js';
import { REPORT_TYPES, MOBILE_REGEX, PIP_SIZES } from './utils/constants.js';
import { ChannelApiService } from './services/ChannelApiService.js';
import { ReportService } from './services/ReportService.js';
import { videoPlayerManager } from './managers/VideoPlayerManager.js';
import { autoReshuffleManager } from './managers/AutoReshuffleManager.js';

/**
 * ユーザー操作ログをサーバーに送信
 * @param {string} action - 操作名
 * @param {Object} data - ログデータ
 */
const logUserAction = async (action, data) => {
    try {
        await fetch('/api/user-actions/log', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ action, data }),
        });
    } catch (error) {
        // ログ送信失敗は無視（ユーザー体験に影響を与えない）
    }
};

/**
 * 再生履歴をsessionStorageに保存
 * @param {Object} song - 楽曲情報
 * @param {Object} timestamp - タイムスタンプ情報
 * @param {Object} channel - チャンネル情報
 */
const savePlayHistory = (song, timestamp, channel) => {
    if (!song) return;

    const STORAGE_KEY = 'playHistory';
    const MAX_HISTORY = 5;

    try {
        const history = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');

        const entry = {
            title: song.title || '',
            artist: song.artist || '',
            videoId: timestamp?.video_id || null,
            tsNum: timestamp?.ts_num || 0,
            channelHandle: channel?.handle || '',
            channelTitle: channel?.title || '',
            playedAt: new Date().toISOString()
        };

        // 重複チェック（同じ曲を連続で追加しない）
        if (history.length > 0) {
            const last = history[0];
            if (last.title === entry.title &&
                last.artist === entry.artist &&
                last.videoId === entry.videoId &&
                last.tsNum === entry.tsNum) {
                return; // 直前と同じなら追加しない
            }
        }

        // 先頭に追加し、最大件数を超えたら古いものを削除
        history.unshift(entry);
        if (history.length > MAX_HISTORY) {
            history.splice(MAX_HISTORY);
        }

        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
    } catch (error) {
        // sessionStorage操作失敗は無視
    }
};

// PiPタイトル更新用: 元のページタイトルを保持
const originalDocumentTitle = document.title;

/**
 * 再生中の楽曲名でdocument.titleを更新（PiPタイトルに反映される）
 * @param {Object|null} song - 楽曲情報（nullの場合は元のタイトルに戻す）
 */
const updateDocumentTitle = (song) => {
    if (song && song.title) {
        const parts = [song.title];
        if (song.artist) parts.push(song.artist);
        document.title = `♪ ${parts.join(' / ')}`;
    } else {
        document.title = originalDocumentTitle;
    }
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
                selectedIndex: '',
                loading: false,
                error: null,
                isFiltered: false,
                filterExpanded: false,
                recentFilters: JSON.parse(localStorage.getItem('recentFilters') || '[]'),

                // 検索サジェスト機能
                suggestionTexts: [],
                showSuggestions: false,
                suggestionsLoaded: false,
                filteredSuggestionsList: [],

                // 報告機能の状態管理
                showReportModal: false,
                reportTarget: null,
                reportType: '',
                reportComment: '',

                // 配信リンクパネルの状態管理
                selectedSong: null,
                selectedTimestamp: null,
                showDistributionPanel: false,
                panelDismissed: false,

                // 動画プレイヤーの状態管理
                autoPlay: false,
                isMobile: false,
                showVideoPlayer: false,
                playerMinimized: false,
                currentVideoId: null,
                currentVideoTime: 0,
                isPlaying: false,
                playerReady: false,
                playerInitialized: false,
                // 再生遷移中フラグ（ランダム再生・次の曲再生など）
                isPlaybackTransitioning: false,
                // 次の曲スキップ中フラグ
                isSkippingToNext: false,

                // 自動再抽選機能
                autoReshuffle: false,

                // ドラッグ機能用
                isDragging: false,
                playerPosition: { x: null, y: null },
                dragOffset: { x: 0, y: 0 },
                boundOnDrag: null,
                boundStopDrag: null,

                // ワイプサイズ設定
                pipSize: 'medium',
                pipSizes: PIP_SIZES,

                // 音量設定
                volume: 100,

                // ガチャシェアポップアップ
                showGachaShare: false,
                gachaShareData: null,
                gachaShareTimer: null,
                gachaShareHovered: false,

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
                    return getYoutubeUrl(videoId, tsNum);
                },

                getArchiveUrl(videoId, tsNum) {
                    return getYoutubeUrl(videoId, tsNum);
                },

                escapeHTML(str) {
                    return escapeHTML(str);
                },

                formatPublishedDate(dateStr) {
                    return formatDate(dateStr);
                },

                archiveSearch() {
                    const params = new URLSearchParams();
                    params.append('search', this.archiveQuery);
                    params.append('visible', '');
                    params.append('ts', this.tsFlg);

                    logUserAction('archiveSearch', {
                        query: this.archiveQuery,
                        tsFilter: this.tsFlg
                    });

                    const hasQuery = this.archiveQuery.length > 0;
                    this.$dispatch('filter-changed', hasQuery);

                    this.fetchData(this.firstUrl(params.toString()));
                    this.updateURL();
                },

                async fetchTimestamps(page = 1, search = '', index = '') {
                    logUserAction('fetchTimestamps', {
                        page,
                        search,
                        index
                    });

                    try {
                        this.loading = true;
                        this.error = null;

                        const data = await ChannelApiService.fetchTimestamps(
                            this.channel.handle,
                            { page, per_page: 50, search, index }
                        );

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
                    this.fetchTimestamps(1, this.searchQuery, this.selectedIndex);
                },

                filterByIndex(index) {
                    this.selectedIndex = index;
                    this.currentTimestampPage = 1;
                    this.saveRecentFilter(index);
                    this.fetchTimestamps(1, this.searchQuery, this.selectedIndex);
                },

                clearIndexFilter() {
                    this.selectedIndex = '';
                    this.currentTimestampPage = 1;
                    this.fetchTimestamps(1, this.searchQuery, '');
                },

                toggleFilterExpanded() {
                    this.filterExpanded = !this.filterExpanded;
                },

                saveRecentFilter(filter) {
                    let recent = this.recentFilters.filter(f => f !== filter);
                    recent.unshift(filter);
                    this.recentFilters = recent.slice(0, 3);
                    localStorage.setItem('recentFilters', JSON.stringify(this.recentFilters));
                },

                // 検索サジェスト用テキスト一覧を読み込み
                async loadSuggestionTexts() {
                    if (this.suggestionsLoaded) return;
                    try {
                        const url = `/api/channels/${this.channel.handle}/timestamps/texts`;
                        const response = await fetch(url);
                        if (response.ok) {
                            this.suggestionTexts = await response.json();
                            this.suggestionsLoaded = true;
                            // 読み込み完了後にフィルタリストを更新
                            this.updateFilteredSuggestions();
                        }
                    } catch (error) {
                        console.error('Failed to load suggestion texts:', error);
                    }
                },

                // フィルタされたサジェスト一覧を更新（リアクティブプロパティ用）
                updateFilteredSuggestions() {
                    if (!this.searchQuery || this.searchQuery.length < 2) {
                        this.filteredSuggestionsList = [];
                        return;
                    }
                    const query = this.searchQuery.toLowerCase();
                    this.filteredSuggestionsList = this.suggestionTexts
                        .filter(text => text.toLowerCase().includes(query))
                        .slice(0, 10);
                },

                // 後方互換性のためのgetter（テンプレートでfilteredSuggestionsを使用可能）
                get filteredSuggestions() {
                    return this.filteredSuggestionsList;
                },

                // サジェストを選択
                selectSuggestion(text) {
                    this.searchQuery = text;
                    this.showSuggestions = false;
                    this.searchTimestamps();
                },

                // サジェストを閉じる
                closeSuggestions() {
                    // 少し遅延させてクリックイベントを先に処理
                    setTimeout(() => {
                        this.showSuggestions = false;
                    }, 200);
                },

                downloadTimestamps() {
                    try {
                        const url = ChannelApiService.getDownloadUrl(this.channel.handle);
                        const a = document.createElement('a');
                        a.href = url;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);

                        toast.success('ダウンロードを開始しました');
                    } catch (error) {
                        console.error('ダウンロードに失敗しました:', error);
                        toast.error('ダウンロードに失敗しました。時間をおいて再度お試しください。');
                    }
                },

                async copyVideoIdList() {
                    try {
                        const videoIds = (this.archives.data || [])
                            .map(archive => archive.video_id)
                            .filter(id => id);

                        if (videoIds.length === 0) {
                            toast.error('コピーするvideoIdがありません');
                            return;
                        }

                        const text = videoIds.join('\n');
                        await navigator.clipboard.writeText(text);

                        toast.success(`${videoIds.length}件のvideoIdをコピーしました`);

                        logUserAction('copyVideoIdList', {
                            count: videoIds.length,
                            tsFilter: this.tsFlg
                        });
                    } catch (error) {
                        console.error('コピーに失敗しました:', error);
                        toast.error('コピーに失敗しました');
                    }
                },

                updateURL() {
                    const params = new URLSearchParams();

                    if (this.activeTab !== 'timestamps') {
                        params.set('view', this.activeTab);
                    }

                    if (this.activeTab === 'timestamps') {
                        if (this.searchQuery) {
                            params.set('search', this.searchQuery);
                        }
                        if (this.selectedIndex) {
                            params.set('index', this.selectedIndex);
                        }
                        if (this.currentTimestampPage && this.currentTimestampPage > 1) {
                            params.set('page', this.currentTimestampPage);
                        }
                    } else {
                        if (this.archiveQuery) {
                            params.set('search', this.archiveQuery);
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

                    const targetPage = isNext
                        ? this.archives.current_page + 1
                        : this.archives.current_page - 1;

                    logUserAction('archivePagination', {
                        direction: isNext ? 'next' : 'prev',
                        fromPage: this.archives.current_page,
                        toPage: targetPage
                    });

                    this.fetchData(url);
                    window.scroll({top: 0, behavior: 'auto'});
                },

                restoreStateFromURL(params) {
                    const view = params.get('view');
                    const search = params.get('search');
                    const page = Math.max(1, parseInt(params.get('page')) || 1);

                    if (view === 'archives') {
                        this.activeTab = 'archives';
                        const archiveQuery = params.get('search') || '';
                        const tsFlg = params.get('ts') || '';
                        this.archiveQuery = archiveQuery;
                        this.tsFlg = tsFlg;

                        if (archiveQuery || tsFlg) {
                            this.archiveSearch();
                        } else {
                            this.fetchData(this.firstUrl());
                        }
                    } else {
                        this.activeTab = 'timestamps';
                        this.searchQuery = search || '';
                        this.selectedIndex = params.get('index') || '';
                        this.currentTimestampPage = page;
                        this.fetchTimestamps(page, this.searchQuery, this.selectedIndex);
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
                        // サジェストリストを即時更新
                        this.updateFilteredSuggestions();
                        // 検索は遅延実行
                        clearTimeout(this.searchTimeout);
                        this.searchTimeout = setTimeout(() => {
                            this.searchTimestamps();
                        }, 300);
                    });

                    window.addEventListener('popstate', () => {
                        const params = new URLSearchParams(window.location.search);
                        this.restoreStateFromURL(params);
                    });

                    // 配信リンクパネルの設定を読み込み
                    const dismissed = localStorage.getItem('distributionPanelDismissed');
                    this.panelDismissed = dismissed === 'true';

                    // モバイル判定
                    this.isMobile = MOBILE_REGEX.test(navigator.userAgent);

                    // 自動再生設定を読み込み（sessionStorageから、デフォルトOFF）
                    if (!this.isMobile) {
                        const autoPlaySaved = sessionStorage.getItem('videoAutoPlay');
                        this.autoPlay = autoPlaySaved === 'true';
                    }

                    // マネージャーの初期化
                    this._initVideoPlayerManager();
                    this._initAutoReshuffleManager();

                    // YouTube IFrame APIの読み込み
                    this.loadYouTubeAPI();

                    // 検索サジェスト用テキスト一覧を読み込み
                    this.loadSuggestionTexts();

                    // ページ離脱時のクリーンアップ
                    window.addEventListener('beforeunload', () => {
                        autoReshuffleManager.cleanup();
                        this.destroyPlayer();
                    });
                },

                // 報告モーダルを開く
                openReportModal(timestamp) {
                    this.reportTarget = timestamp;
                    this.reportType = '';
                    this.reportComment = '';
                    this.showReportModal = true;
                },

                // 報告を送信
                async submitReport() {
                    if (!this.reportTarget) {
                        console.error('No report target set');
                        toast.error('報告対象が見つかりません');
                        return;
                    }

                    if (!this.reportType) {
                        toast.error('報告の種類を選択してください');
                        return;
                    }

                    const result = await ReportService.submitReport({
                        video_id: this.reportTarget.video_id,
                        ts_text: this.reportTarget.ts_text,
                        ts_num: this.reportTarget.ts_num,
                        report_type: this.reportType,
                        comment: this.reportComment || null,
                    });

                    if (result.success) {
                        toast.success(result.message);
                        this.showReportModal = false;

                        // フロントエンドで報告済みフラグを即座に反映
                        if (this.reportTarget) {
                            if (this.selectedTimestamp && this.selectedTimestamp.id === this.reportTarget.id) {
                                this.selectedTimestamp.has_pending_report = true;
                            }
                            if (this.timestamps.data) {
                                const index = this.timestamps.data.findIndex(ts => ts.id === this.reportTarget.id);
                                if (index !== -1) {
                                    this.timestamps.data[index].has_pending_report = true;
                                }
                            }
                        }
                    } else {
                        toast.error(result.message);
                    }
                },

                // 配信リンクパネル関連メソッド
                selectSong(song, timestamp = null) {
                    if (!song) return;

                    logUserAction('selectTimestamp', {
                        type: 'song',
                        songTitle: song.title,
                        songArtist: song.artist,
                        timestampId: timestamp?.id,
                        videoId: timestamp?.video_id,
                        tsNum: timestamp?.ts_num
                    });

                    // 再生履歴に保存・PiPタイトル更新
                    savePlayHistory(song, timestamp, this.channel);
                    updateDocumentTitle(song);

                    // 自動ガチャ中に手動で楽曲を選択した場合、自動再抽選をOFFにする
                    if (this.autoReshuffle) {
                        this.autoReshuffle = false;
                        autoReshuffleManager.setEnabled(false);
                        autoReshuffleManager.stopMonitor();
                        toast.info('自動再抽選をOFFにしました');
                    }

                    this.selectedSong = song;
                    this.selectedTimestamp = timestamp;
                    if (!this.panelDismissed) {
                        this.showDistributionPanel = true;
                    }

                    if (this.autoPlay && timestamp && timestamp.video_id) {
                        this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
                    }
                },

                selectText(text, timestamp = null) {
                    if (!text || text.trim() === '') return;

                    logUserAction('selectTimestamp', {
                        type: 'text',
                        text: text.trim(),
                        timestampId: timestamp?.id,
                        videoId: timestamp?.video_id,
                        tsNum: timestamp?.ts_num
                    });

                    // 自動ガチャ中に手動で楽曲を選択した場合、自動再抽選をOFFにする
                    if (this.autoReshuffle) {
                        this.autoReshuffle = false;
                        autoReshuffleManager.setEnabled(false);
                        autoReshuffleManager.stopMonitor();
                        toast.info('自動再抽選をOFFにしました');
                    }

                    const pseudoSong = {
                        title: text.trim(),
                        artist: '',
                        spotify_track_id: null
                    };

                    // 再生履歴に保存・PiPタイトル更新
                    savePlayHistory(pseudoSong, timestamp, this.channel);
                    updateDocumentTitle(pseudoSong);

                    this.selectedSong = pseudoSong;
                    this.selectedTimestamp = timestamp;
                    if (!this.panelDismissed) {
                        this.showDistributionPanel = true;
                    }

                    if (this.autoPlay && timestamp && timestamp.video_id) {
                        this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
                    }
                },

                closePanel() {
                    this.showDistributionPanel = false;
                    this.panelDismissed = true;
                    localStorage.setItem('distributionPanelDismissed', 'true');
                },

                openPanel() {
                    this.panelDismissed = false;
                    localStorage.setItem('distributionPanelDismissed', 'false');
                    if (this.selectedSong) {
                        this.showDistributionPanel = true;
                    }
                },

                // 配信サービスURL生成メソッド
                getSpotifyUrl(song) {
                    return getSpotifyUrl(song);
                },

                getAppleMusicUrl(song) {
                    return getAppleMusicUrl(song);
                },

                getYouTubeMusicUrl(song) {
                    return getYouTubeMusicUrl(song);
                },

                getAmazonMusicUrl(song) {
                    return getAmazonMusicUrl(song);
                },

                getLineMusicUrl(song) {
                    return getLineMusicUrl(song);
                },

                // 配信サービスリンククリックのログ出力
                logDistributionLinkClick(service) {
                    logUserAction('clickDistributionLink', {
                        service,
                        songTitle: this.selectedSong?.title,
                        songArtist: this.selectedSong?.artist
                    });
                },

                // VideoPlayerManagerの初期化
                _initVideoPlayerManager() {
                    // 設定を復元
                    videoPlayerManager.restoreVolume();
                    videoPlayerManager.restorePipSize();

                    // コンポーネントの状態と同期
                    this.volume = videoPlayerManager.getVolume();
                    this.pipSize = videoPlayerManager.getPipSize();

                    // ログ関数を設定
                    videoPlayerManager.logUserAction = logUserAction;

                    // コールバックを設定
                    videoPlayerManager.onPlayingChange = (isPlaying) => {
                        this.isPlaying = isPlaying;
                    };

                    videoPlayerManager.onShowChange = (show) => {
                        this.showVideoPlayer = show;
                    };

                    videoPlayerManager.onMinimizedChange = (minimized) => {
                        this.playerMinimized = minimized;
                    };

                    videoPlayerManager.onStateChange = (event) => {
                        // AutoReshuffleManagerに状態変更を通知
                        autoReshuffleManager.handlePlayerStateChange(event);
                    };

                    videoPlayerManager.onError = (event) => {
                        autoReshuffleManager.clearBufferingTimeout();

                        const errorMessages = {
                            2: '無効なパラメータです',
                            5: 'HTML5プレイヤーエラーが発生しました',
                            100: '動画が見つかりません',
                            101: '動画の埋め込みが許可されていません',
                            150: '動画の埋め込みが許可されていません'
                        };
                        const message = errorMessages[event.data] || '動画の読み込みに失敗しました';

                        // 自動再抽選中ならエラーをスキップして次の曲へ
                        if (this.autoReshuffle) {
                            toast.warning(`${message} - 次の曲に進みます...`);
                            autoReshuffleManager.stopMonitor();
                            setTimeout(() => {
                                this.playRandomTimestamp();
                            }, 1000);
                        } else {
                            toast.error(message);
                        }
                    };
                },

                // AutoReshuffleManagerの初期化
                _initAutoReshuffleManager() {
                    // 設定を復元
                    autoReshuffleManager.restoreSettings();
                    this.autoReshuffle = autoReshuffleManager.isEnabled();

                    // コールバックを設定: 曲終了時は同じアーカイブ内の次の曲を優先
                    autoReshuffleManager.onSongEnd = () => {
                        this.playNextOrRandom();
                    };

                    autoReshuffleManager.onStallDetected = () => {
                        this.playNextOrRandom();
                    };

                    autoReshuffleManager.onBufferingTimeout = () => {
                        this.playNextOrRandom();
                    };

                    // 次のタイムスタンプ到達時の表示更新
                    autoReshuffleManager.onNextTimestampReached = () => {
                        this.updateDisplayForNextTimestamp();
                    };
                },

                // YouTube IFrame APIの読み込み
                loadYouTubeAPI() {
                    videoPlayerManager.loadAPI(() => {
                        this.playerReady = true;
                        this.$nextTick(() => this.preInitializePlayer());
                    });
                },

                // プレイヤーの事前初期化（モバイルの自動再生制限対策）
                preInitializePlayer() {
                    videoPlayerManager.preInitialize('youtube-player');
                },

                // 動画プレイヤーの初期化
                initPlayer() {
                    const result = videoPlayerManager.initPlayer('youtube-player');
                    if (result) {
                        this.playerInitialized = true;
                    }
                },

                // 動画を読み込んで再生
                loadAndPlayVideo(videoId, time = 0) {
                    const result = videoPlayerManager.loadAndPlay(videoId, time);
                    if (result) {
                        this.currentVideoId = videoId;
                        this.currentVideoTime = time;
                    }
                },

                // 再生/一時停止の切り替え
                togglePlayPause() {
                    if (this.selectedTimestamp && this.selectedTimestamp.video_id) {
                        const selectedVideoId = this.selectedTimestamp.video_id;
                        const selectedTime = this.selectedTimestamp.ts_num || 0;
                        videoPlayerManager.togglePlayPause(selectedVideoId, selectedTime);
                        this.currentVideoId = selectedVideoId;
                        this.currentVideoTime = selectedTime;
                    } else {
                        videoPlayerManager.togglePlayPause();
                    }
                },

                // 動画プレイヤーを閉じる
                closeVideoPlayer() {
                    videoPlayerManager.close(() => this.resetPlayerPosition());
                    updateDocumentTitle(null);
                },

                // プレイヤーの最小化トグル
                togglePlayerMinimize() {
                    videoPlayerManager.toggleMinimize();
                },

                // プレイヤーの破棄
                destroyPlayer() {
                    videoPlayerManager.destroy();
                    this.playerInitialized = false;
                    this.currentVideoId = null;
                },

                // 自動再生設定の保存
                saveAutoPlay() {
                    sessionStorage.setItem('videoAutoPlay', this.autoPlay.toString());
                },

                // 音量変更
                changeVolume(value) {
                    videoPlayerManager.setVolume(value);
                    this.volume = videoPlayerManager.getVolume();
                },

                // ワイプサイズの変更
                changePipSize(size) {
                    videoPlayerManager.setPipSize(size);
                    this.pipSize = videoPlayerManager.getPipSize();
                },

                // YouTube Playerのサイズを更新
                updatePlayerSize() {
                    videoPlayerManager.updatePlayerSize();
                },

                // 現在のワイプサイズ設定を取得
                getCurrentPipSize() {
                    return videoPlayerManager.getCurrentPipSize();
                },

                // ワイプの幅を取得（CSSで使用）
                getPipWidth() {
                    return videoPlayerManager.getPipWidth();
                },

                // ランダム再生
                async playRandomTimestamp() {
                    if (this.isPlaybackTransitioning) return;

                    try {
                        this.isPlaybackTransitioning = true;

                        const excludeVideoId = this.selectedTimestamp?.video_id || null;
                        const timestamp = await ChannelApiService.fetchRandomTimestamp(this.channel.handle, excludeVideoId);

                        if (excludeVideoId && timestamp.video_id === excludeVideoId) {
                            console.warn('同じアーカイブが再選択されました', { excludeVideoId, selectedVideoId: timestamp.video_id });
                        }

                        logUserAction('playRandomTimestamp', {
                            excludeVideoId: excludeVideoId,
                            timestampId: timestamp.id,
                            videoId: timestamp.video_id,
                            tsNum: timestamp.ts_num,
                            songTitle: timestamp.mapping?.song?.title,
                            text: timestamp.text,
                            page: timestamp.page
                        });

                        // 楽曲情報または疑似楽曲オブジェクトを設定
                        if (timestamp.mapping?.song) {
                            this.selectedSong = timestamp.mapping.song;
                        } else {
                            this.selectedSong = {
                                title: timestamp.text || '-',
                                artist: '',
                                spotify_track_id: null
                            };
                        }
                        this.selectedTimestamp = timestamp;

                        // 配信パネルを表示
                        if (!this.panelDismissed) {
                            this.showDistributionPanel = true;
                        }

                        // 自動再抽選用: 終了時刻を計算・設定
                        const endTime = autoReshuffleManager.calculateEndTime(timestamp);
                        autoReshuffleManager.setEndTime(endTime);

                        // 動画を再生
                        if (timestamp.video_id) {
                            this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
                        }

                        // 該当ページに切り替え
                        if (timestamp.page && timestamp.page !== this.currentTimestampPage) {
                            await this.fetchTimestamps(timestamp.page, this.searchQuery, this.selectedIndex);
                        }

                        // 選択された楽曲の位置までスクロール
                        this.$nextTick(() => {
                            const selectedElement = document.querySelector(`[data-timestamp-id="${timestamp.id}"]`);
                            if (selectedElement) {
                                selectedElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        });

                        // 再生履歴に保存・PiPタイトル更新
                        savePlayHistory(this.selectedSong, timestamp, this.channel);
                        updateDocumentTitle(this.selectedSong);

                        toast.success('ランダムで楽曲を選びました！');

                        // ガチャシェアポップアップを表示
                        this.gachaShareData = {
                            songTitle: this.selectedSong.title,
                            channelTitle: this.channel.title,
                            channelHandle: this.channel.handle,
                            publishedAt: timestamp.archive?.published_at,
                        };
                        this.showGachaShare = true;
                        this.startGachaShareTimer();

                        // 次のタイムスタンプがある場合は表示更新用の監視を開始
                        if (endTime !== null) {
                            autoReshuffleManager.startMonitor();
                        }
                    } catch (error) {
                        console.error('ランダム再生に失敗しました:', error);
                        toast.error(error.message || 'ランダム再生に失敗しました');
                    } finally {
                        this.isPlaybackTransitioning = false;
                    }
                },

                // --- ガチャシェアポップアップ ---
                startGachaShareTimer() {
                    clearTimeout(this.gachaShareTimer);
                    this.gachaShareTimer = setTimeout(() => {
                        if (!this.gachaShareHovered) {
                            this.showGachaShare = false;
                        }
                    }, 5500);
                },
                pauseGachaShareTimer() {
                    this.gachaShareHovered = true;
                    clearTimeout(this.gachaShareTimer);
                },
                resumeGachaShareTimer() {
                    this.gachaShareHovered = false;
                    this.startGachaShareTimer();
                },
                closeGachaShare() {
                    this.showGachaShare = false;
                    clearTimeout(this.gachaShareTimer);
                },
                getGachaShareUrl() {
                    const data = this.gachaShareData;
                    if (!data) return '';
                    const date = data.publishedAt
                        ? new Date(data.publishedAt).toLocaleDateString('ja-JP')
                        : '';
                    const siteUrl = window.location.origin + '/channels/' + encodeURIComponent(data.channelHandle);
                    const dateText = date ? data.channelTitle + 'が' + date + 'に歌った' : data.channelTitle + 'が歌った';
                    const text = '🎵 ' + dateText + data.songTitle + '！\n\n歌枠履歴er:D で探す👇';
                    return 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(siteUrl);
                },
                getGachaShareText() {
                    const data = this.gachaShareData;
                    if (!data) return '';
                    const date = data.publishedAt
                        ? new Date(data.publishedAt).toLocaleDateString('ja-JP')
                        : '';
                    const dateText = date ? date + 'に歌った' : '';
                    return data.channelTitle + 'が' + dateText + data.songTitle + '！';
                },

                /**
                 * 同じアーカイブ内の次の曲を再生、なければランダム再生
                 */
                async playNextOrRandom() {
                    // 現在再生中のタイムスタンプ情報がなければランダム再生
                    if (!this.selectedTimestamp?.video_id || this.selectedTimestamp?.ts_num === undefined) {
                        this.playRandomTimestamp();
                        return;
                    }

                    try {
                        // 同じアーカイブ内の次の楽曲を取得
                        const nextTimestamp = await ChannelApiService.fetchNextTimestampInArchive(
                            this.channel.handle,
                            this.selectedTimestamp.video_id,
                            this.selectedTimestamp.ts_num
                        );

                        if (nextTimestamp) {
                            // 同じアーカイブ内の次の曲を再生
                            await this.playTimestamp(nextTimestamp, false);
                        } else {
                            // アーカイブ内に次の曲がない場合、別のアーカイブからランダム選択
                            toast.info('このアーカイブの再生が終わりました');
                            this.playRandomTimestamp();
                        }
                    } catch (error) {
                        console.error('次の楽曲の取得に失敗しました:', error);
                        // エラー時はランダム再生にフォールバック
                        this.playRandomTimestamp();
                    }
                },

                /**
                 * ユーザー操作で次の曲にスキップ
                 * 同じアーカイブ内の次の曲に飛ばす。次の曲がなければアーカイブ末尾と同じ挙動。
                 */
                async skipToNextSong() {
                    if (this.isSkippingToNext || this.isPlaybackTransitioning) return;

                    // 現在再生中のタイムスタンプ情報がなければ何もしない
                    if (!this.channel?.handle ||
                        !this.selectedTimestamp?.video_id ||
                        this.selectedTimestamp?.ts_num === undefined) {
                        return;
                    }

                    try {
                        this.isSkippingToNext = true;

                        logUserAction('skipToNextSong', {
                            videoId: this.selectedTimestamp.video_id,
                            tsNum: this.selectedTimestamp.ts_num,
                        });

                        // 同じアーカイブ内の次の楽曲を取得
                        const nextTimestamp = await ChannelApiService.fetchNextTimestampInArchive(
                            this.channel.handle,
                            this.selectedTimestamp.video_id,
                            this.selectedTimestamp.ts_num
                        );

                        if (nextTimestamp) {
                            // 同じアーカイブ内の次の曲を再生
                            await this.playTimestamp(nextTimestamp, true);
                        } else {
                            // アーカイブ内に次の曲がない場合、アーカイブ末尾到達と同じ挙動
                            toast.info('このアーカイブの再生が終わりました');
                            this.playRandomTimestamp();
                        }
                    } catch (error) {
                        console.error('次の曲へのスキップに失敗しました:', error);
                        toast.error('次の曲へのスキップに失敗しました');
                    } finally {
                        this.isSkippingToNext = false;
                    }
                },

                /**
                 * 次のタイムスタンプの表示を更新（再生位置は変更しない）
                 * 動画が自然に再生されて次のタイムスタンプに到達した際に呼ばれる
                 */
                async updateDisplayForNextTimestamp() {
                    // 必要な情報がなければ何もしない
                    if (!this.channel?.handle ||
                        !this.selectedTimestamp?.video_id ||
                        this.selectedTimestamp?.ts_num === undefined) {
                        return;
                    }

                    try {
                        // 同じアーカイブ内の次の楽曲情報を取得
                        const nextTimestamp = await ChannelApiService.fetchNextTimestampInArchive(
                            this.channel.handle,
                            this.selectedTimestamp.video_id,
                            this.selectedTimestamp.ts_num
                        );

                        if (nextTimestamp) {
                            logUserAction('autoDisplayUpdate', {
                                timestampId: nextTimestamp.id,
                                videoId: nextTimestamp.video_id,
                                tsNum: nextTimestamp.ts_num,
                                songTitle: nextTimestamp.mapping?.song?.title,
                                text: nextTimestamp.text
                            });

                            // 楽曲情報を更新（表示のみ、再生位置は変更しない）
                            if (nextTimestamp.mapping?.song) {
                                this.selectedSong = nextTimestamp.mapping.song;
                            } else {
                                this.selectedSong = {
                                    title: nextTimestamp.text || '-',
                                    artist: '',
                                    spotify_track_id: null
                                };
                            }
                            this.selectedTimestamp = nextTimestamp;

                            // PiPタイトル更新（表示更新のみで再生位置は変わらないため履歴は追加しない）
                            updateDocumentTitle(this.selectedSong);

                            // 次のタイムスタンプまでの監視を再設定
                            const endTime = autoReshuffleManager.calculateEndTime(nextTimestamp);
                            autoReshuffleManager.setEndTime(endTime);
                            if (endTime !== null) {
                                autoReshuffleManager.startMonitor();
                            }
                        }
                        // 次のタイムスタンプがない場合は何もしない（動画終了まで現在の表示を維持）
                    } catch (error) {
                        console.error('次の楽曲情報の取得に失敗しました:', error);
                    }
                },

                /**
                 * 指定したタイムスタンプを再生（内部用）
                 * @param {Object} timestamp - タイムスタンプデータ
                 * @param {boolean} showToast - トースト表示するか
                 */
                async playTimestamp(timestamp, showToast = true) {
                    if (this.isPlaybackTransitioning) return;

                    try {
                        this.isPlaybackTransitioning = true;

                        logUserAction('playNextInArchive', {
                            timestampId: timestamp.id,
                            videoId: timestamp.video_id,
                            tsNum: timestamp.ts_num,
                            songTitle: timestamp.mapping?.song?.title,
                            text: timestamp.text,
                            isLastInArchive: timestamp.is_last_in_archive
                        });

                        // 楽曲情報または疑似楽曲オブジェクトを設定
                        if (timestamp.mapping?.song) {
                            this.selectedSong = timestamp.mapping.song;
                        } else {
                            this.selectedSong = {
                                title: timestamp.text || '-',
                                artist: '',
                                spotify_track_id: null
                            };
                        }
                        this.selectedTimestamp = timestamp;

                        // 配信パネルを表示
                        if (!this.panelDismissed) {
                            this.showDistributionPanel = true;
                        }

                        // 自動再抽選用: 終了時刻を計算・設定
                        const endTime = autoReshuffleManager.calculateEndTime(timestamp);
                        autoReshuffleManager.setEndTime(endTime);

                        // 動画を再生（同じアーカイブ内なのでシーク）
                        if (timestamp.video_id) {
                            this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
                        }

                        // 再生履歴に保存・PiPタイトル更新
                        savePlayHistory(this.selectedSong, timestamp, this.channel);
                        updateDocumentTitle(this.selectedSong);

                        if (showToast) {
                            toast.success('次の曲を再生します');
                        }

                        // 次のタイムスタンプがある場合は表示更新用の監視を開始
                        if (endTime !== null) {
                            autoReshuffleManager.startMonitor();
                        }
                    } finally {
                        this.isPlaybackTransitioning = false;
                    }
                },

                /**
                 * 自動再抽選のON/OFF切り替え
                 */
                toggleAutoReshuffle() {
                    this.autoReshuffle = autoReshuffleManager.toggle(this.isPlaying);
                },

                // ドラッグ開始
                startDrag(event) {
                    const clientX = event.touches ? event.touches[0].clientX : event.clientX;
                    const clientY = event.touches ? event.touches[0].clientY : event.clientY;

                    const playerEl = this.$refs.videoPlayer;
                    if (!playerEl) return;

                    const rect = playerEl.getBoundingClientRect();
                    this.isDragging = true;
                    this.dragOffset = {
                        x: clientX - rect.left,
                        y: clientY - rect.top
                    };

                    this.boundOnDrag = this.onDrag.bind(this);
                    this.boundStopDrag = this.stopDrag.bind(this);

                    document.addEventListener('mousemove', this.boundOnDrag);
                    document.addEventListener('mouseup', this.boundStopDrag);
                    document.addEventListener('touchmove', this.boundOnDrag, { passive: false });
                    document.addEventListener('touchend', this.boundStopDrag);

                    event.preventDefault();
                },

                // ドラッグ中
                onDrag(event) {
                    if (!this.isDragging) return;

                    const clientX = event.touches ? event.touches[0].clientX : event.clientX;
                    const clientY = event.touches ? event.touches[0].clientY : event.clientY;

                    const playerEl = this.$refs.videoPlayer;
                    if (!playerEl) return;

                    const playerWidth = playerEl.offsetWidth;
                    const playerHeight = playerEl.offsetHeight;

                    let newX = clientX - this.dragOffset.x;
                    let newY = clientY - this.dragOffset.y;

                    newX = Math.max(0, Math.min(newX, window.innerWidth - playerWidth));
                    newY = Math.max(0, Math.min(newY, window.innerHeight - playerHeight));

                    this.playerPosition = { x: newX, y: newY };

                    event.preventDefault();
                },

                // ドラッグ終了
                stopDrag() {
                    this.isDragging = false;

                    if (this.boundOnDrag) {
                        document.removeEventListener('mousemove', this.boundOnDrag);
                        document.removeEventListener('touchmove', this.boundOnDrag);
                    }
                    if (this.boundStopDrag) {
                        document.removeEventListener('mouseup', this.boundStopDrag);
                        document.removeEventListener('touchend', this.boundStopDrag);
                    }

                    this.boundOnDrag = null;
                    this.boundStopDrag = null;
                },

                // プレイヤーの位置スタイルを取得
                getPlayerStyle() {
                    if (this.playerPosition.x !== null && this.playerPosition.y !== null) {
                        return {
                            left: `${this.playerPosition.x}px`,
                            top: `${this.playerPosition.y}px`,
                            right: 'auto',
                            bottom: 'auto'
                        };
                    }
                    return {};
                },

                // プレイヤー位置をリセット
                resetPlayerPosition() {
                    this.playerPosition = { x: null, y: null };
                }
            };
        });
    }
}

// Alpine.jsが既に読み込まれている場合はすぐに登録
if (typeof Alpine !== 'undefined') {
    registerArchiveListComponent();
} else {
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
