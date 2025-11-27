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
                    const safeVideoId = encodeURIComponent(videoId || '');
                    const safeTsNum = parseInt(tsNum) || 0;
                    return `https://youtu.be/${safeVideoId}?t=${safeTsNum}s`;
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

                async fetchTimestamps(page = 1, search = '', index = '') {
                    try {
                        this.loading = true;
                        this.error = null;

                        const params = new URLSearchParams({
                            page: page,
                            per_page: 50
                        });

                        if (search) {
                            params.set('search', search);
                        }

                        if (index) {
                            params.set('index', index);
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
                        // ダウンロード用URLを生成
                        const url = `/api/channels/${this.channel.handle}/timestamps/download`;

                        // リンクを作成してクリック（サーバー側のファイル名を使用）
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

                    // Only add 'view' parameter when not on default tab (timestamps)
                    if (this.activeTab !== 'timestamps') {
                        params.set('view', this.activeTab);
                    }

                    // タイムスタンプタブのパラメータ
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
                    this.isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

                    // 自動再生は常にOFF（ページ読み込み時にリセット）
                    this.autoPlay = false;

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
                        ts_item_id: this.reportTarget.id,
                        video_id: this.reportTarget.video_id,
                        report_type: this.reportType,
                        comment: this.reportComment || null,
                    };

                    try {
                        const response = await fetch('/api/timestamp-reports', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify(reportData),
                        });

                        const data = await response.json();

                        if (response.ok) {
                            toast.success(data.message || '報告を受け付けました。ご協力ありがとうございます。');
                            this.showReportModal = false;

                            // フロントエンドで報告済みフラグを即座に反映
                            if (this.reportTarget) {
                                // selectedTimestamp を更新（配信リンクパネル用）
                                if (this.selectedTimestamp && this.selectedTimestamp.id === this.reportTarget.id) {
                                    this.selectedTimestamp.has_pending_report = true;
                                }
                                // timestamps.data 内の該当アイテムを更新（一覧表示用）
                                if (this.timestamps.data) {
                                    const index = this.timestamps.data.findIndex(ts => ts.id === this.reportTarget.id);
                                    if (index !== -1) {
                                        this.timestamps.data[index].has_pending_report = true;
                                    }
                                }
                            }
                        } else if (response.status === 429) {
                            toast.error(data.message || '報告の送信制限中です。しばらくしてから再度お試しください。');
                        } else {
                            toast.error(data.message || '報告の送信に失敗しました。');
                        }
                    } catch (error) {
                        console.error('報告の送信に失敗しました:', error);
                        toast.error('報告の送信に失敗しました。時間をおいて再度お試しください。');
                    }
                },

                // 配信リンクパネル関連メソッド
                selectSong(song, timestamp = null) {
                    if (!song) return;
                    this.selectedSong = song;
                    this.selectedTimestamp = timestamp;
                    if (!this.panelDismissed) {
                        this.showDistributionPanel = true;
                    }

                    // 自動再生がONの場合、動画を読み込んで再生
                    if (this.autoPlay && timestamp && timestamp.video_id) {
                        this.loadAndPlayVideo(timestamp.video_id, timestamp.ts_num || 0);
                    }
                },

                // テキストから検索用の疑似songオブジェクトを作成して選択
                selectText(text, timestamp = null) {
                    if (!text || text.trim() === '') return;
                    // テキストをそのまま検索クエリとして使用
                    // title にテキスト全体を設定し、artist は空にする
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

                    // 自動再生がONの場合、動画を読み込んで再生
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
                    if (!song) return '';
                    if (song.spotify_track_id) {
                        return `https://open.spotify.com/track/${encodeURIComponent(song.spotify_track_id)}`;
                    }
                    const query = encodeURIComponent(`${song.title} ${song.artist}`);
                    return `https://open.spotify.com/search/${query}`;
                },

                getAppleMusicUrl(song) {
                    if (!song) return '';
                    const query = encodeURIComponent(`${song.title} ${song.artist}`);
                    return `https://music.apple.com/jp/search?term=${query}`;
                },

                getYouTubeMusicUrl(song) {
                    if (!song) return '';
                    const query = encodeURIComponent(`${song.title} ${song.artist}`);
                    return `https://music.youtube.com/search?q=${query}`;
                },

                getAmazonMusicUrl(song) {
                    if (!song) return '';
                    // URLに使えない特殊文字を除去し、スペースを+に変換
                    const searchText = `${song.title} ${song.artist}`
                        .replace(/[/\\?#%&=:@!$'()*+,;[\]{}|^`<>"]/g, ' ')  // 特殊文字をスペースに
                        .trim()
                        .replace(/\s+/g, '+');  // 連続スペースを+に
                    return `https://music.amazon.co.jp/search/${searchText}`;
                },

                getLineMusicUrl(song) {
                    if (!song) return '';
                    // URLに使えない特殊文字を除去してからエンコード
                    const searchText = `${song.title} ${song.artist}`
                        .replace(/[/\\?#%&=:@!$'()*+,;[\]{}|^`<>"]/g, ' ')
                        .trim()
                        .replace(/\s+/g, ' ');  // 連続スペースを1つに
                    const query = encodeURIComponent(searchText);
                    // LINE MUSICのwebappは /webapp/ パスを使用
                    return `https://music.line.me/webapp/search/tracks?query=${query}`;
                },

                // YouTube IFrame APIの読み込み
                loadYouTubeAPI() {
                    if (window.YT && window.YT.Player) {
                        this.playerReady = true;
                        return;
                    }

                    // コールバック配列を初期化（複数コンポーネント対応）
                    if (!window.youtubeAPIReadyCallbacks) {
                        window.youtubeAPIReadyCallbacks = [];
                        window.onYouTubeIframeAPIReady = () => {
                            window.youtubeAPIReadyCallbacks.forEach(cb => cb());
                        };
                    }

                    // コールバックを登録
                    window.youtubeAPIReadyCallbacks.push(() => {
                        this.playerReady = true;
                    });

                    // APIがまだ読み込まれていない場合
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
                        height: '180',
                        width: '320',
                        playerVars: {
                            'autoplay': 0,
                            'controls': 1,
                            'rel': 0,
                            'modestbranding': 1,
                            'origin': window.location.origin
                        },
                        events: {
                            'onReady': () => {
                                // プレイヤー準備完了
                            },
                            'onStateChange': (event) => {
                                this.isPlaying = event.data === YT.PlayerState.PLAYING;
                            }
                        }
                    });
                },

                // 動画を読み込んで再生
                loadAndPlayVideo(videoId, time = 0) {
                    // YouTubeのvideoIDは11文字の英数字とハイフン、アンダースコア
                    if (!videoId || !/^[a-zA-Z0-9_-]{11}$/.test(videoId)) {
                        console.error('Invalid video ID:', videoId);
                        return;
                    }

                    this.currentVideoId = videoId;
                    this.currentVideoTime = time;
                    this.showVideoPlayer = true;

                    // プレイヤーが既に初期化されている場合
                    if (this.youtubePlayer && this.youtubePlayer.loadVideoById) {
                        this.youtubePlayer.loadVideoById({
                            videoId: videoId,
                            startSeconds: time
                        });
                        this.isPlaying = true;
                    } else {
                        // プレイヤーがまだ初期化されていない場合、次のtickで初期化を試みる
                        this.$nextTick(() => {
                            if (!this.youtubePlayer && this.playerReady) {
                                this.initPlayer();
                                // 少し待ってから動画を読み込む
                                setTimeout(() => {
                                    if (this.youtubePlayer && this.youtubePlayer.loadVideoById) {
                                        this.youtubePlayer.loadVideoById({
                                            videoId: videoId,
                                            startSeconds: time
                                        });
                                        this.isPlaying = true;
                                    }
                                }, 500);
                            }
                        });
                    }
                },

                // 再生/一時停止の切り替え
                togglePlayPause() {
                    // 再生中の場合は一時停止
                    if (this.isPlaying) {
                        if (this.youtubePlayer) {
                            this.youtubePlayer.pauseVideo();
                        }
                        this.isPlaying = false;
                        return;
                    }

                    // 停止中の場合
                    if (this.selectedTimestamp && this.selectedTimestamp.video_id) {
                        const selectedVideoId = this.selectedTimestamp.video_id;
                        const selectedTime = this.selectedTimestamp.ts_num || 0;

                        // 選択中のタイムスタンプが現在の動画と異なる場合は新しい動画を読み込み
                        if (this.currentVideoId !== selectedVideoId || this.currentVideoTime !== selectedTime) {
                            this.loadAndPlayVideo(selectedVideoId, selectedTime);
                        } else {
                            // 同じ動画の場合は再生を再開
                            if (this.youtubePlayer) {
                                this.youtubePlayer.playVideo();
                                this.isPlaying = true;
                            } else {
                                // プレイヤーがない場合は読み込み・再生
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

                // プレイヤーの破棄（メモリリーク防止）
                destroyPlayer() {
                    if (this.youtubePlayer) {
                        this.youtubePlayer.destroy();
                        this.youtubePlayer = null;
                    }
                    this.showVideoPlayer = false;
                    this.isPlaying = false;
                    this.currentVideoId = null;
                },

                // ドラッグ開始
                startDrag(event) {
                    // タッチイベントとマウスイベントの両方に対応
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

                    // バインドした関数を保存（removeEventListenerで同じ参照を使うため）
                    this.boundOnDrag = this.onDrag.bind(this);
                    this.boundStopDrag = this.stopDrag.bind(this);

                    // イベントリスナーを追加
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

                    // 画面内に収まるように位置を計算
                    let newX = clientX - this.dragOffset.x;
                    let newY = clientY - this.dragOffset.y;

                    // 画面外にはみ出さないように制限
                    newX = Math.max(0, Math.min(newX, window.innerWidth - playerWidth));
                    newY = Math.max(0, Math.min(newY, window.innerHeight - playerHeight));

                    this.playerPosition = { x: newX, y: newY };

                    event.preventDefault();
                },

                // ドラッグ終了
                stopDrag() {
                    this.isDragging = false;

                    // 保存した関数参照を使ってイベントリスナーを削除
                    if (this.boundOnDrag) {
                        document.removeEventListener('mousemove', this.boundOnDrag);
                        document.removeEventListener('touchmove', this.boundOnDrag);
                    }
                    if (this.boundStopDrag) {
                        document.removeEventListener('mouseup', this.boundStopDrag);
                        document.removeEventListener('touchend', this.boundStopDrag);
                    }

                    // 参照をクリア
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
