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
import { REPORT_TYPES, MOBILE_REGEX, YOUTUBE_PLAYER_CONFIG } from './utils/constants.js';
import { ChannelApiService } from './services/ChannelApiService.js';
import { ReportService } from './services/ReportService.js';

/**
 * ユーザー操作ログを出力
 * @param {string} action - 操作名
 * @param {Object} data - ログデータ
 */
const logUserAction = (action, data) => {
    console.log(`[UserAction] ${action}:`, data);
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
                currentVideoId: null,
                currentVideoTime: 0,
                isPlaying: false,
                playerReady: false,
                youtubePlayer: null,
                playerInitialized: false,
                pendingVideo: null,
                // ランダム再生機能
                isRandomPlaying: false,

                // ドラッグ機能用
                isDragging: false,
                playerPosition: { x: null, y: null },
                dragOffset: { x: 0, y: 0 },
                boundOnDrag: null,
                boundStopDrag: null,

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
                    this.fetchTimestamps(1, this.searchQuery, this.selectedIndex);
                },

                clearIndexFilter() {
                    this.selectedIndex = '';
                    this.currentTimestampPage = 1;
                    this.fetchTimestamps(1, this.searchQuery, '');
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

                    // YouTube IFrame APIの読み込み
                    this.loadYouTubeAPI();

                    // ページ離脱時のクリーンアップ
                    window.addEventListener('beforeunload', () => {
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
                        ts_item_id: this.reportTarget.id,
                        video_id: this.reportTarget.video_id,
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

                    const pseudoSong = {
                        title: text.trim(),
                        artist: '',
                        spotify_track_id: null
                    };
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

                    this.youtubePlayer = new YT.Player('youtube-player', {
                        height: YOUTUBE_PLAYER_CONFIG.height,
                        width: YOUTUBE_PLAYER_CONFIG.width,
                        playerVars: {
                            ...YOUTUBE_PLAYER_CONFIG.playerVars,
                            origin: window.location.origin
                        },
                        events: {
                            'onReady': () => {
                                this.playerInitialized = true;
                                // 待機中の動画があれば再生
                                // isPlayingはonStateChangeで更新されるため、ここでは設定しない
                                if (this.pendingVideo) {
                                    this.youtubePlayer.loadVideoById({
                                        videoId: this.pendingVideo.videoId,
                                        startSeconds: this.pendingVideo.time
                                    });
                                    this.pendingVideo = null;
                                }
                            },
                            'onStateChange': (event) => {
                                this.isPlaying = event.data === YT.PlayerState.PLAYING;
                            },
                            'onError': (event) => {
                                console.error('YouTube Player Error:', event.data);
                                this.isPlaying = false;
                                this.pendingVideo = null;
                                const errorMessages = {
                                    2: '無効なパラメータです',
                                    5: 'HTML5プレイヤーエラーが発生しました',
                                    100: '動画が見つかりません',
                                    101: '動画の埋め込みが許可されていません',
                                    150: '動画の埋め込みが許可されていません'
                                };
                                const message = errorMessages[event.data] || '動画の読み込みに失敗しました';
                                toast.error(message);
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
                            startSeconds: time
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
                    this.resetPlayerPosition();
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

                        // 動画を再生
                        if (timestamp.video_id) {
                            this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
                        }

                        // 該当ページに切り替え
                        if (timestamp.page && timestamp.page !== this.currentTimestampPage) {
                            await this.fetchTimestamps(timestamp.page, this.searchQuery, this.selectedIndex);
                        }

                        toast.success('ランダムで楽曲を選びました！');
                    } catch (error) {
                        console.error('ランダム再生に失敗しました:', error);
                        toast.error(error.message || 'ランダム再生に失敗しました');
                    } finally {
                        this.isRandomPlaying = false;
                    }
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
