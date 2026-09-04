import { isValidVideoId } from '../../utils/youtube.js';
import toast from '../../utils/toast.js';
import { YOUTUBE_PLAYER_CONFIG, PIP_SIZES } from '../utils/constants.js';

/**
 * YouTube動画プレイヤーを管理するマネージャークラス
 *
 * 責務:
 * - YouTube IFrame APIの読み込みと管理
 * - プレイヤーの初期化・破棄
 * - 動画の読み込みと再生制御
 * - 音量・サイズの管理
 * - プレイヤー表示状態の管理
 */
export class VideoPlayerManager {
    constructor() {
        // プレイヤーインスタンス
        this.player = null;
        this.playerReady = false;
        this.playerInitialized = false;

        // 再生状態
        this.isPlaying = false;
        this.currentVideoId = null;
        this.currentVideoTime = 0;
        this.pendingVideo = null;

        // UI状態
        this.showVideoPlayer = false;
        this.playerMinimized = false;

        // 設定
        this.volume = 100;
        this.pipSize = 'medium';

        // コールバック
        this.onReady = null;
        this.onStateChange = null;
        this.onError = null;
        this.onPlayingChange = null;
        this.onShowChange = null;
        this.onMinimizedChange = null;

        // ユーザーアクションログ関数（外部から設定）
        this.logUserAction = null;
    }

    /**
     * YouTube IFrame APIを読み込み
     * @param {Function} onAPIReady - API読み込み完了時のコールバック
     * @returns {void}
     */
    loadAPI(onAPIReady = null) {
        if (window.YT && window.YT.Player) {
            this.playerReady = true;
            if (onAPIReady) onAPIReady();
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
            if (onAPIReady) onAPIReady();
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
    }

    /**
     * プレイヤーの事前初期化（モバイルの自動再生制限対策）
     * @param {string} elementId - プレイヤー要素のID
     */
    preInitialize(elementId = 'youtube-player') {
        if (this.player) return;

        const playerElement = document.getElementById(elementId);
        if (!playerElement) {
            setTimeout(() => this.preInitialize(elementId), 100);
            return;
        }

        this.initPlayer(elementId);
    }

    /**
     * 動画プレイヤーを初期化
     * @param {string} elementId - プレイヤー要素のID
     * @returns {boolean} 初期化成功/失敗
     */
    initPlayer(elementId = 'youtube-player') {
        if (!this.playerReady || this.player) return false;

        const playerElement = document.getElementById(elementId);
        if (!playerElement) return false;

        const currentSize = this.getCurrentPipSize();
        this.player = new YT.Player(elementId, {
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
                    if (this.player.setPlaybackQuality) {
                        this.player.setPlaybackQuality(YOUTUBE_PLAYER_CONFIG.suggestedQuality);
                    }
                    // 保存された音量を適用
                    if (typeof this.player.setVolume === 'function') {
                        this.player.setVolume(this.volume);
                    }
                    // 待機中の動画があれば再生
                    if (this.pendingVideo) {
                        this.player.loadVideoById({
                            videoId: this.pendingVideo.videoId,
                            startSeconds: this.pendingVideo.time,
                            suggestedQuality: YOUTUBE_PLAYER_CONFIG.suggestedQuality
                        });
                        this.pendingVideo = null;
                    }
                    if (this.onReady) this.onReady();
                },
                'onStateChange': (event) => {
                    const wasPlaying = this.isPlaying;
                    this.isPlaying = event.data === YT.PlayerState.PLAYING;

                    if (wasPlaying !== this.isPlaying && this.onPlayingChange) {
                        this.onPlayingChange(this.isPlaying);
                    }

                    if (this.onStateChange) {
                        this.onStateChange(event);
                    }
                },
                'onError': (event) => {
                    console.error('YouTube Player Error:', event.data);
                    this.isPlaying = false;
                    this.pendingVideo = null;

                    if (this.onPlayingChange) {
                        this.onPlayingChange(false);
                    }

                    if (this.onError) {
                        this.onError(event);
                    }
                }
            }
        });

        return true;
    }

    /**
     * 動画を読み込んで再生
     * @param {string} videoId - 動画ID
     * @param {number} time - 開始秒数
     * @returns {boolean} 読み込み成功/失敗
     */
    loadAndPlay(videoId, time = 0) {
        if (!isValidVideoId(videoId)) {
            console.error('Invalid video ID:', videoId);
            return false;
        }

        if (this.logUserAction) {
            this.logUserAction('playVideo', {
                videoId,
                startTime: time
            });
        }

        this.currentVideoId = videoId;
        this.currentVideoTime = time;
        this._setShowVideoPlayer(true);

        if (this.player && this.playerInitialized) {
            this.player.loadVideoById({
                videoId: videoId,
                startSeconds: time,
                suggestedQuality: YOUTUBE_PLAYER_CONFIG.suggestedQuality
            });
            return true;
        }

        // 初期化が完了していない場合、待機動画として保存
        this.pendingVideo = { videoId, time };
        return true;
    }

    /**
     * 再生
     */
    play() {
        if (this.player && this.player.playVideo) {
            this.player.playVideo();
        }
    }

    /**
     * 一時停止
     */
    pause() {
        if (this.player && this.player.pauseVideo) {
            this.player.pauseVideo();
        }
    }

    /**
     * 停止
     */
    stop() {
        if (this.player && this.player.stopVideo) {
            this.player.stopVideo();
        }
    }

    /**
     * 再生/一時停止の切り替え
     * @param {string|null} videoId - 動画ID
     * @param {number} time - 開始秒数
     * @returns {boolean} 操作成功/失敗
     */
    togglePlayPause(videoId = null, time = 0) {
        if (this.isPlaying) {
            this.pause();
            this.isPlaying = false;
            if (this.onPlayingChange) this.onPlayingChange(false);
            return true;
        }

        if (videoId) {
            if (this.currentVideoId !== videoId || this.currentVideoTime !== time) {
                return this.loadAndPlay(videoId, time);
            }

            if (this.player) {
                this.play();
                this.isPlaying = true;
                if (this.onPlayingChange) this.onPlayingChange(true);
                return true;
            }

            return this.loadAndPlay(videoId, time);
        }

        return false;
    }

    /**
     * 動画プレイヤーを閉じる
     * @param {Function} resetPosition - プレイヤー位置リセット関数
     */
    close(resetPosition = null) {
        this.stop();
        this._setShowVideoPlayer(false);
        this.isPlaying = false;
        this.currentVideoId = null;
        this._setPlayerMinimized(false);
        if (resetPosition) resetPosition();
        if (this.onPlayingChange) this.onPlayingChange(false);
    }

    /**
     * プレイヤーの最小化トグル
     */
    toggleMinimize() {
        this._setPlayerMinimized(!this.playerMinimized);
    }

    /**
     * プレイヤーを破棄
     */
    destroy() {
        if (this.player) {
            this.player.destroy();
            this.player = null;
        }
        this.playerInitialized = false;
        this.pendingVideo = null;
        this._setShowVideoPlayer(false);
        this.isPlaying = false;
        this.currentVideoId = null;
        if (this.onPlayingChange) this.onPlayingChange(false);
    }

    /**
     * 音量を設定
     * @param {number|string} value - 音量（0-100）
     */
    setVolume(value) {
        const vol = parseInt(value, 10);
        if (isNaN(vol) || vol < 0 || vol > 100) return;

        this.volume = vol;
        sessionStorage.setItem('videoVolume', vol.toString());

        if (this.player && typeof this.player.setVolume === 'function') {
            this.player.setVolume(vol);
        }
    }

    /**
     * 音量を取得
     * @returns {number}
     */
    getVolume() {
        return this.volume;
    }

    /**
     * sessionStorageから音量を復元
     */
    restoreVolume() {
        const saved = sessionStorage.getItem('videoVolume');
        if (saved !== null) {
            const parsed = parseInt(saved, 10);
            this.volume = Number.isNaN(parsed) ? 100 : parsed;
        }
    }

    /**
     * PiPサイズを設定
     * @param {string} size - サイズ（small/medium/large）
     */
    setPipSize(size) {
        if (PIP_SIZES[size]) {
            this.pipSize = size;
            sessionStorage.setItem('pipSize', size);
            this.updatePlayerSize();
        }
    }

    /**
     * PiPサイズを取得
     * @returns {string}
     */
    getPipSize() {
        return this.pipSize;
    }

    /**
     * sessionStorageからPiPサイズを復元
     */
    restorePipSize() {
        const saved = sessionStorage.getItem('pipSize');
        if (saved && PIP_SIZES[saved]) {
            this.pipSize = saved;
        }
    }

    /**
     * YouTube Playerのサイズを更新
     */
    updatePlayerSize() {
        if (this.player && typeof this.player.setSize === 'function') {
            const currentSize = this.getCurrentPipSize();
            this.player.setSize(currentSize.width, currentSize.height);
        }
    }

    /**
     * 現在のPiPサイズ設定を取得
     * @returns {Object}
     */
    getCurrentPipSize() {
        return PIP_SIZES[this.pipSize] || PIP_SIZES.medium;
    }

    /**
     * PiPの幅を取得（CSSで使用）
     * @returns {number}
     */
    getPipWidth() {
        const size = this.getCurrentPipSize();
        return this.playerMinimized ? size.minimizedWidth : size.width;
    }

    /**
     * 現在の再生時間を取得
     * @returns {number}
     */
    getCurrentTime() {
        if (this.player && typeof this.player.getCurrentTime === 'function') {
            return this.player.getCurrentTime();
        }
        return 0;
    }

    /**
     * 動画の長さ（秒）を取得
     *
     * メタデータ未読み込み時や取得できない場合は0を返す。
     *
     * @returns {number}
     */
    getDuration() {
        if (this.player && typeof this.player.getDuration === 'function') {
            const duration = this.player.getDuration();
            return typeof duration === 'number' && isFinite(duration) && duration > 0 ? duration : 0;
        }
        return 0;
    }

    /**
     * 実際にプレイヤーに読み込まれている動画IDを取得
     *
     * loadAndPlay()直後は切り替えが完了しておらず、前の動画のIDが返る。
     * 取得手段がない場合はnullを返す。
     *
     * @returns {string|null}
     */
    getPlayingVideoId() {
        if (!this.player || typeof this.player.getVideoData !== 'function') {
            return null;
        }

        try {
            return this.player.getVideoData()?.video_id || null;
        } catch (error) {
            // getVideoDataは非公開APIのため、取得できない場合は判定を諦める
            return null;
        }
    }

    /**
     * 再生指示した動画の読み込みが完了しているかどうか
     *
     * 動画の切り替え中に前の動画の再生位置・長さを参照してしまうのを防ぐために使う。
     * 判定手段がない場合は処理を止めないようtrueを返す。
     *
     * @returns {boolean}
     */
    isCurrentVideoLoaded() {
        const playingVideoId = this.getPlayingVideoId();
        if (playingVideoId === null || this.currentVideoId === null) {
            return true;
        }
        return playingVideoId === this.currentVideoId;
    }

    /**
     * プレイヤーが初期化済みかどうか
     * @returns {boolean}
     */
    isInitialized() {
        return this.playerInitialized;
    }

    /**
     * APIが読み込み済みかどうか
     * @returns {boolean}
     */
    isAPIReady() {
        return this.playerReady;
    }

    /**
     * プレイヤーが表示中かどうか
     * @returns {boolean}
     */
    isShowing() {
        return this.showVideoPlayer;
    }

    /**
     * プレイヤーが最小化中かどうか
     * @returns {boolean}
     */
    isMinimized() {
        return this.playerMinimized;
    }

    /**
     * 再生中かどうか
     * @returns {boolean}
     */
    getIsPlaying() {
        return this.isPlaying;
    }

    // ========== プライベートメソッド ==========

    /**
     * プレイヤー表示状態を設定
     * @private
     */
    _setShowVideoPlayer(show) {
        this.showVideoPlayer = show;
        if (this.onShowChange) this.onShowChange(show);
    }

    /**
     * プレイヤー最小化状態を設定
     * @private
     */
    _setPlayerMinimized(minimized) {
        this.playerMinimized = minimized;
        if (this.onMinimizedChange) this.onMinimizedChange(minimized);
    }
}

// シングルトンインスタンスをエクスポート
export const videoPlayerManager = new VideoPlayerManager();
