import { isValidVideoId } from '../../utils/youtube.js';
import { YOUTUBE_PLAYER_CONFIG } from '../utils/constants.js';
import toast from '../../utils/toast.js';

/**
 * YouTubeプレイヤー管理サービス
 */
export class YouTubePlayerService {
    constructor() {
        this.player = null;
        this.playerReady = false;
        this.onReadyCallback = null;
        this.onStateChangeCallback = null;
    }

    /**
     * YouTube IFrame APIを読み込み
     * @returns {Promise<void>}
     */
    loadAPI() {
        return new Promise((resolve) => {
            if (window.YT && window.YT.Player) {
                this.playerReady = true;
                resolve();
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
                resolve();
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
        });
    }

    /**
     * プレイヤーを初期化
     * @param {string} elementId - プレイヤー要素のID
     * @param {Object} callbacks - コールバック関数
     * @param {Function} callbacks.onReady - 準備完了時のコールバック
     * @param {Function} callbacks.onStateChange - 状態変更時のコールバック
     * @returns {boolean} 初期化成功/失敗
     */
    initPlayer(elementId, callbacks = {}) {
        if (!this.playerReady || this.player) {
            return false;
        }

        const playerElement = document.getElementById(elementId);
        if (!playerElement) {
            return false;
        }

        this.onReadyCallback = callbacks.onReady;
        this.onStateChangeCallback = callbacks.onStateChange;

        this.player = new YT.Player(elementId, {
            height: YOUTUBE_PLAYER_CONFIG.height,
            width: YOUTUBE_PLAYER_CONFIG.width,
            playerVars: {
                ...YOUTUBE_PLAYER_CONFIG.playerVars,
                origin: window.location.origin
            },
            events: {
                onReady: () => {
                    if (this.onReadyCallback) {
                        this.onReadyCallback();
                    }
                },
                onStateChange: (event) => {
                    if (this.onStateChangeCallback) {
                        this.onStateChangeCallback(event);
                    }
                }
            }
        });

        return true;
    }

    /**
     * 動画を読み込んで再生
     * @param {string} videoId - 動画ID
     * @param {number} startSeconds - 開始秒数
     * @returns {boolean} 読み込み成功/失敗
     */
    loadAndPlay(videoId, startSeconds = 0) {
        if (!isValidVideoId(videoId)) {
            console.error('Invalid video ID:', videoId);
            return false;
        }

        if (this.player && this.player.loadVideoById) {
            this.player.loadVideoById({
                videoId: videoId,
                startSeconds: startSeconds
            });
            return true;
        }

        return false;
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
     * プレイヤーを破棄
     */
    destroy() {
        if (this.player) {
            this.player.destroy();
            this.player = null;
        }
    }

    /**
     * プレイヤーが初期化済みかどうか
     * @returns {boolean}
     */
    isInitialized() {
        return this.player !== null;
    }

    /**
     * APIが読み込み済みかどうか
     * @returns {boolean}
     */
    isAPIReady() {
        return this.playerReady;
    }
}
