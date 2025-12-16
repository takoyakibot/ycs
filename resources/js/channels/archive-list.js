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
import { REPORT_TYPES, MOBILE_REGEX, YOUTUBE_PLAYER_CONFIG, PIP_SIZES } from './utils/constants.js';
import { ChannelApiService } from './services/ChannelApiService.js';
import { ReportService } from './services/ReportService.js';

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
                youtubePlayer: null,
                playerInitialized: false,
                pendingVideo: null,
                // ランダム再生機能
                isRandomPlaying: false,

                // 自動再抽選機能
                autoReshuffle: false,
                reshuffleMonitorId: null,
                currentSongEndTime: null,
                fadeOutIntervalId: null,
                fadeInIntervalId: null,
                originalVolume: 100,
                needsFadeIn: false,

                // 再生失敗検知機能
                bufferingTimeoutId: null,
                lastPlaybackTime: 0,
                stallCount: 0,

                // ドラッグ機能用
                isDragging: false,
                playerPosition: { x: null, y: null },
                dragOffset: { x: 0, y: 0 },
                boundOnDrag: null,
                boundStopDrag: null,

                // ワイプサイズ設定
                pipSize: 'medium',
                pipSizes: PIP_SIZES,

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

                    // 自動再抽選設定を読み込み（sessionStorageから、デフォルトOFF）
                    const autoReshuffleSaved = sessionStorage.getItem('autoReshuffle');
                    this.autoReshuffle = autoReshuffleSaved === 'true';

                    // ワイプサイズ設定を読み込み（sessionStorageから、デフォルトmedium）
                    const pipSizeSaved = sessionStorage.getItem('pipSize');
                    if (pipSizeSaved && PIP_SIZES[pipSizeSaved]) {
                        this.pipSize = pipSizeSaved;
                    }

                    // YouTube IFrame APIの読み込み
                    this.loadYouTubeAPI();

                    // 検索サジェスト用テキスト一覧を読み込み
                    this.loadSuggestionTexts();

                    // ページ離脱時のクリーンアップ
                    window.addEventListener('beforeunload', () => {
                        this.stopReshuffleMonitor();
                        this.clearBufferingTimeout();
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

                    // 再生履歴に保存
                    savePlayHistory(song, timestamp, this.channel);

                    // 自動ガチャ中に手動で楽曲を選択した場合、自動再抽選をOFFにする
                    if (this.autoReshuffle) {
                        this.autoReshuffle = false;
                        this.saveAutoReshuffle();
                        this.stopReshuffleMonitor();
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
                        this.saveAutoReshuffle();
                        this.stopReshuffleMonitor();
                        toast.info('自動再抽選をOFFにしました');
                    }

                    const pseudoSong = {
                        title: text.trim(),
                        artist: '',
                        spotify_track_id: null
                    };

                    // 再生履歴に保存
                    savePlayHistory(pseudoSong, timestamp, this.channel);

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

                // YouTube IFrame APIの読み込み
                loadYouTubeAPI() {
                    if (window.YT && window.YT.Player) {
                        this.playerReady = true;
                        return;
                    }

                    if (!window.youtubeAPIReadyCallbacks) {
                        window.youtubeAPIReadyCallbacks = [];
                        window.onYouTubeIframeAPIReady = () => {
                            window.youtubeAPIReadyCallbacks.forEach(cb => cb());
                        };
                    }

                    window.youtubeAPIReadyCallbacks.push(() => {
                        this.playerReady = true;
                    });

                    if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
                        const tag = document.createElement('script');
                        tag.src = 'https://www.youtube.com/iframe_api';
                        tag.onerror = () => {
                            console.error('YouTube APIの読み込みに失敗しました');
                            toast.error('動画プレイヤーの読み込みに失敗しました');
                        };
                        const firstScriptTag = document.getElementsByTagName('script')[0];
                        firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);
                    }
                },

                // 動画プレイヤーの初期化
                initPlayer() {
                    if (!this.playerReady || this.youtubePlayer) return;

                    const playerElement = document.getElementById('youtube-player');
                    if (!playerElement) return;

                    const currentSize = this.getCurrentPipSize();
                    this.youtubePlayer = new YT.Player('youtube-player', {
                        height: currentSize.height.toString(),
                        width: currentSize.width.toString(),
                        playerVars: {
                            ...YOUTUBE_PLAYER_CONFIG.playerVars,
                            origin: window.location.origin
                        },
                        events: {
                            'onReady': () => {
                                this.playerInitialized = true;
                                // 画質を最低に設定（推奨のみ、強制は不可）
                                if (this.youtubePlayer.setPlaybackQuality) {
                                    this.youtubePlayer.setPlaybackQuality(YOUTUBE_PLAYER_CONFIG.suggestedQuality);
                                }
                                // 待機中の動画があれば再生
                                // isPlayingはonStateChangeで更新されるため、ここでは設定しない
                                if (this.pendingVideo) {
                                    this.youtubePlayer.loadVideoById({
                                        videoId: this.pendingVideo.videoId,
                                        startSeconds: this.pendingVideo.time,
                                        suggestedQuality: YOUTUBE_PLAYER_CONFIG.suggestedQuality
                                    });
                                    this.pendingVideo = null;
                                }
                            },
                            'onStateChange': (event) => {
                                this.isPlaying = event.data === YT.PlayerState.PLAYING;

                                // 再生開始時の処理
                                if (event.data === YT.PlayerState.PLAYING) {
                                    // バッファリングタイムアウトをクリア
                                    this.clearBufferingTimeout();
                                    // スタック検知用の初期化
                                    this.lastPlaybackTime = this.youtubePlayer?.getCurrentTime() || 0;
                                    this.stallCount = 0;
                                    // フェードインが必要な場合は開始
                                    if (this.needsFadeIn) {
                                        this.startFadeIn();
                                    }
                                }

                                // バッファリング状態の監視（自動再抽選中のみ）
                                if (event.data === YT.PlayerState.BUFFERING && this.autoReshuffle) {
                                    this.startBufferingTimeout();
                                } else if (event.data !== YT.PlayerState.BUFFERING) {
                                    this.clearBufferingTimeout();
                                }

                                // 自動再抽選: 再生状態に応じて監視を開始/停止
                                if (this.autoReshuffle && this.currentSongEndTime !== null) {
                                    if (event.data === YT.PlayerState.PLAYING) {
                                        this.startReshuffleMonitor();
                                    } else if (event.data === YT.PlayerState.PAUSED ||
                                               event.data === YT.PlayerState.ENDED) {
                                        this.stopReshuffleMonitor();
                                    }
                                }
                            },
                            'onError': (event) => {
                                console.error('YouTube Player Error:', event.data);
                                this.isPlaying = false;
                                this.pendingVideo = null;
                                this.clearBufferingTimeout();

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
                                    this.stopReshuffleMonitor();
                                    setTimeout(() => {
                                        this.playRandomTimestamp();
                                    }, 1000);
                                } else {
                                    toast.error(message);
                                }
                            }
                        }
                    });
                },

                // 動画を読み込んで再生
                loadAndPlayVideo(videoId, time = 0) {
                    if (!isValidVideoId(videoId)) {
                        console.error('Invalid video ID:', videoId);
                        return;
                    }

                    logUserAction('playVideo', {
                        videoId,
                        startTime: time
                    });

                    this.currentVideoId = videoId;
                    this.currentVideoTime = time;
                    this.showVideoPlayer = true;

                    // 既にプレイヤーが初期化完了している場合
                    // isPlayingはonStateChangeで更新されるため、ここでは設定しない
                    if (this.youtubePlayer && this.playerInitialized) {
                        this.youtubePlayer.loadVideoById({
                            videoId: videoId,
                            startSeconds: time,
                            suggestedQuality: YOUTUBE_PLAYER_CONFIG.suggestedQuality
                        });
                    } else {
                        // 初期化が完了していない場合、待機動画として保存
                        this.pendingVideo = { videoId, time };

                        this.$nextTick(() => {
                            if (!this.youtubePlayer && this.playerReady) {
                                this.initPlayer();
                            }
                        });
                    }
                },

                // 再生/一時停止の切り替え
                togglePlayPause() {
                    if (this.isPlaying) {
                        if (this.youtubePlayer) {
                            this.youtubePlayer.pauseVideo();
                        }
                        this.isPlaying = false;
                        return;
                    }

                    if (this.selectedTimestamp && this.selectedTimestamp.video_id) {
                        const selectedVideoId = this.selectedTimestamp.video_id;
                        const selectedTime = this.selectedTimestamp.ts_num || 0;

                        if (this.currentVideoId !== selectedVideoId || this.currentVideoTime !== selectedTime) {
                            this.loadAndPlayVideo(selectedVideoId, selectedTime);
                        } else {
                            if (this.youtubePlayer) {
                                this.youtubePlayer.playVideo();
                                this.isPlaying = true;
                            } else {
                                this.loadAndPlayVideo(selectedVideoId, selectedTime);
                            }
                        }
                    }
                },

                // 動画プレイヤーを閉じる
                closeVideoPlayer() {
                    if (this.youtubePlayer) {
                        this.youtubePlayer.stopVideo();
                    }
                    this.showVideoPlayer = false;
                    this.isPlaying = false;
                    this.currentVideoId = null;
                    this.playerMinimized = false;
                    this.resetPlayerPosition();
                },

                // プレイヤーの最小化トグル
                togglePlayerMinimize() {
                    this.playerMinimized = !this.playerMinimized;
                },

                // プレイヤーの破棄
                destroyPlayer() {
                    if (this.youtubePlayer) {
                        this.youtubePlayer.destroy();
                        this.youtubePlayer = null;
                    }
                    this.playerInitialized = false;
                    this.pendingVideo = null;
                    this.showVideoPlayer = false;
                    this.isPlaying = false;
                    this.currentVideoId = null;
                },

                // 自動再生設定の保存
                saveAutoPlay() {
                    sessionStorage.setItem('videoAutoPlay', this.autoPlay.toString());
                },

                // ワイプサイズの変更
                changePipSize(size) {
                    if (PIP_SIZES[size]) {
                        this.pipSize = size;
                        sessionStorage.setItem('pipSize', size);
                    }
                },

                // 現在のワイプサイズ設定を取得
                getCurrentPipSize() {
                    return PIP_SIZES[this.pipSize] || PIP_SIZES.medium;
                },

                // ワイプの幅を取得（CSSで使用）
                getPipWidth() {
                    const size = this.getCurrentPipSize();
                    return this.playerMinimized ? size.minimizedWidth : size.width;
                },

                // ランダム再生
                async playRandomTimestamp() {
                    if (this.isRandomPlaying) return;

                    try {
                        this.isRandomPlaying = true;

                        const timestamp = await ChannelApiService.fetchRandomTimestamp(this.channel.handle);

                        logUserAction('playRandomTimestamp', {
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

                        // 自動再抽選用: 終了時刻を計算
                        this.currentSongEndTime = this.calculateEndTime(timestamp);

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

                        toast.success('ランダムで楽曲を選びました！');

                        // 自動再抽選: 有効な場合は監視を開始
                        if (this.autoReshuffle && this.currentSongEndTime !== null) {
                            this.startReshuffleMonitor();
                        }
                    } catch (error) {
                        console.error('ランダム再生に失敗しました:', error);
                        toast.error(error.message || 'ランダム再生に失敗しました');
                    } finally {
                        this.isRandomPlaying = false;
                    }
                },

                /**
                 * 終了時刻を計算（自動再抽選用）
                 *
                 * 優先順位:
                 * 1. 楽曲長さあり & 次のTSあり → min(開始 + 楽曲長さ + 10秒, 次のTS)
                 * 2. 楽曲長さのみあり → 開始 + 楽曲長さ + 10秒
                 * 3. 次のTSのみあり → 次のTS - 10秒
                 * 4. どちらもなし → デフォルト5分
                 */
                calculateEndTime(timestamp) {
                    const startTime = timestamp.ts_num || 0;
                    const nextTsNum = timestamp.next_ts_num;
                    const durationMs = timestamp.mapping?.song?.duration_ms;
                    const durationSec = durationMs ? Math.ceil(durationMs / 1000) : null;
                    const defaultDuration = 5 * 60; // 5分

                    if (durationSec !== null && nextTsNum !== null) {
                        // 楽曲長さあり & 次のTSあり
                        return Math.min(startTime + durationSec + 10, nextTsNum);
                    } else if (durationSec !== null) {
                        // 楽曲長さのみあり
                        return startTime + durationSec + 10;
                    } else if (nextTsNum !== null) {
                        // 次のTSのみあり
                        return Math.max(startTime, nextTsNum - 10);
                    } else {
                        // どちらもなし（デフォルト5分）
                        return startTime + defaultDuration;
                    }
                },

                /**
                 * 再生位置監視を開始（自動再抽選用）
                 */
                startReshuffleMonitor() {
                    // 既存の監視を停止
                    this.stopReshuffleMonitor();

                    if (!this.youtubePlayer || this.currentSongEndTime === null) return;

                    const FADE_OUT_DURATION = 3; // フェードアウト秒数
                    const CHECK_INTERVAL = 500; // チェック間隔（ミリ秒）
                    const MAX_STALL_COUNT = 6; // 3秒間（500ms × 6回）進まなければスタックと判定

                    // スタック検知用の初期化
                    this.lastPlaybackTime = this.youtubePlayer.getCurrentTime();
                    this.stallCount = 0;

                    this.reshuffleMonitorId = setInterval(() => {
                        if (!this.youtubePlayer || typeof this.youtubePlayer.getCurrentTime !== 'function') {
                            this.stopReshuffleMonitor();
                            return;
                        }

                        const currentTime = this.youtubePlayer.getCurrentTime();
                        const fadeOutStartTime = this.currentSongEndTime - FADE_OUT_DURATION;

                        // スタック検知: 再生中なのに時間が進まない状態を検知
                        if (this.isPlaying) {
                            if (Math.abs(currentTime - this.lastPlaybackTime) < 0.1) {
                                this.stallCount++;
                                if (this.stallCount >= MAX_STALL_COUNT) {
                                    console.warn('再生がスタックしています（再生位置が進まない）');
                                    toast.warning('読み込みに問題があります。次の曲に進みます...');
                                    this.stopReshuffleMonitor();
                                    this.playRandomTimestamp();
                                    return;
                                }
                            } else {
                                this.stallCount = 0;
                            }
                        }
                        this.lastPlaybackTime = currentTime;

                        // フェードアウト開始時刻に到達
                        if (currentTime >= fadeOutStartTime && !this.fadeOutIntervalId) {
                            this.startFadeOut();
                        }

                        // 終了時刻に到達
                        if (currentTime >= this.currentSongEndTime) {
                            this.stopReshuffleMonitor();
                            // 次の曲を抽選
                            this.playRandomTimestamp();
                        }
                    }, CHECK_INTERVAL);
                },

                /**
                 * 再生位置監視を停止
                 */
                stopReshuffleMonitor() {
                    if (this.reshuffleMonitorId) {
                        clearInterval(this.reshuffleMonitorId);
                        this.reshuffleMonitorId = null;
                    }
                    this.stopFadeOut();
                },

                /**
                 * バッファリングタイムアウトを開始
                 * バッファリング状態が10秒続いたら次の曲にスキップ
                 */
                startBufferingTimeout() {
                    // 既存のタイムアウトをクリア
                    this.clearBufferingTimeout();

                    const BUFFERING_TIMEOUT = 10000; // 10秒

                    this.bufferingTimeoutId = setTimeout(() => {
                        console.warn('バッファリングタイムアウト');
                        toast.warning('読み込みに時間がかかっています。次の曲に進みます...');
                        this.stopReshuffleMonitor();
                        this.playRandomTimestamp();
                    }, BUFFERING_TIMEOUT);
                },

                /**
                 * バッファリングタイムアウトをクリア
                 */
                clearBufferingTimeout() {
                    if (this.bufferingTimeoutId) {
                        clearTimeout(this.bufferingTimeoutId);
                        this.bufferingTimeoutId = null;
                    }
                },

                /**
                 * フェードアウトを開始
                 */
                startFadeOut() {
                    if (this.fadeOutIntervalId || !this.youtubePlayer) return;

                    // 現在の音量を保存
                    if (typeof this.youtubePlayer.getVolume === 'function') {
                        this.originalVolume = this.youtubePlayer.getVolume();
                    }

                    const FADE_STEPS = 10;
                    const FADE_INTERVAL = 300; // 3秒 / 10ステップ = 300ms
                    let step = 0;

                    this.fadeOutIntervalId = setInterval(() => {
                        step++;
                        const newVolume = Math.max(0, this.originalVolume * (1 - step / FADE_STEPS));

                        if (this.youtubePlayer && typeof this.youtubePlayer.setVolume === 'function') {
                            this.youtubePlayer.setVolume(newVolume);
                        }

                        if (step >= FADE_STEPS) {
                            this.stopFadeOut();
                        }
                    }, FADE_INTERVAL);
                },

                /**
                 * フェードアウトを停止（音量は復元しない）
                 */
                stopFadeOut() {
                    if (this.fadeOutIntervalId) {
                        clearInterval(this.fadeOutIntervalId);
                        this.fadeOutIntervalId = null;
                        // フェードアウト中だった場合、次の曲でフェードインが必要
                        this.needsFadeIn = true;
                    }
                },

                /**
                 * フェードインを開始
                 */
                startFadeIn() {
                    if (this.fadeInIntervalId || !this.youtubePlayer) return;
                    if (!this.needsFadeIn) return;

                    this.needsFadeIn = false;

                    // 音量0から開始
                    if (typeof this.youtubePlayer.setVolume === 'function') {
                        this.youtubePlayer.setVolume(0);
                    }

                    const FADE_STEPS = 10;
                    const FADE_INTERVAL = 200; // 2秒 / 10ステップ = 200ms（フェードアウトより短め）
                    let step = 0;

                    this.fadeInIntervalId = setInterval(() => {
                        step++;
                        const newVolume = Math.min(this.originalVolume, this.originalVolume * (step / FADE_STEPS));

                        if (this.youtubePlayer && typeof this.youtubePlayer.setVolume === 'function') {
                            this.youtubePlayer.setVolume(newVolume);
                        }

                        if (step >= FADE_STEPS) {
                            this.stopFadeIn();
                        }
                    }, FADE_INTERVAL);
                },

                /**
                 * フェードインを停止
                 */
                stopFadeIn() {
                    if (this.fadeInIntervalId) {
                        clearInterval(this.fadeInIntervalId);
                        this.fadeInIntervalId = null;
                    }
                    // 最終的に元の音量に設定
                    if (this.youtubePlayer && typeof this.youtubePlayer.setVolume === 'function') {
                        this.youtubePlayer.setVolume(this.originalVolume);
                    }
                },

                /**
                 * 自動再抽選のON/OFF切り替え
                 */
                toggleAutoReshuffle() {
                    this.autoReshuffle = !this.autoReshuffle;
                    this.saveAutoReshuffle();

                    if (this.autoReshuffle) {
                        // ONにした場合、現在再生中で終了時刻が設定されていれば監視開始
                        if (this.isPlaying && this.currentSongEndTime !== null) {
                            this.startReshuffleMonitor();
                        }
                        toast.success('自動再抽選をONにしました');
                    } else {
                        // OFFにした場合、監視を停止
                        this.stopReshuffleMonitor();
                        toast.info('自動再抽選をOFFにしました');
                    }
                },

                /**
                 * 自動再抽選設定を保存
                 */
                saveAutoReshuffle() {
                    sessionStorage.setItem('autoReshuffle', this.autoReshuffle.toString());
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
