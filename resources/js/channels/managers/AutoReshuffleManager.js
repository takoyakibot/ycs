import toast from '../../utils/toast.js';
import { videoPlayerManager } from './VideoPlayerManager.js';

/**
 * 自動再抽選機能を管理するマネージャークラス
 *
 * 責務:
 * - 再生位置の監視
 * - フェードイン・フェードアウト制御
 * - バッファリングタイムアウト検知
 * - 終了時刻の計算
 * - スタック検知
 */
export class AutoReshuffleManager {
    constructor() {
        // 状態
        this.enabled = false;
        this.currentSongEndTime = null;
        this.originalVolume = 100;
        this.needsFadeIn = false;

        // モニタリング用ID
        this.reshuffleMonitorId = null;
        this.fadeOutIntervalId = null;
        this.fadeInIntervalId = null;
        this.bufferingTimeoutId = null;

        // スタック検知用
        this.lastPlaybackTime = 0;
        this.stallCount = 0;

        // コールバック
        this.onSongEnd = null;          // 動画終了時に呼び出される（自動再抽選用）
        this.onStallDetected = null;    // スタック検知時に呼び出される
        this.onBufferingTimeout = null; // バッファリングタイムアウト時に呼び出される
        this.onNextTimestampReached = null; // 次のタイムスタンプ到達時に呼び出される（表示更新用）
    }

    /**
     * sessionStorageから設定を復元
     */
    restoreSettings() {
        const saved = sessionStorage.getItem('autoReshuffle');
        this.enabled = saved === 'true';
    }

    /**
     * 設定を保存
     */
    saveSettings() {
        sessionStorage.setItem('autoReshuffle', this.enabled.toString());
    }

    /**
     * 自動再抽選のON/OFF切り替え
     * @param {boolean} isPlaying - 現在再生中かどうか
     * @returns {boolean} 新しい状態
     */
    toggle(isPlaying = false) {
        this.enabled = !this.enabled;
        this.saveSettings();

        if (this.enabled) {
            // ONにした場合、現在再生中で終了時刻が設定されていれば監視開始
            if (isPlaying && this.currentSongEndTime !== null) {
                this.startMonitor();
            }
            toast.success('自動再抽選をONにしました');
        } else {
            // OFFにした場合、監視を停止
            this.stopMonitor();
            toast.info('自動再抽選をOFFにしました');
        }

        return this.enabled;
    }

    /**
     * 有効かどうか
     * @returns {boolean}
     */
    isEnabled() {
        return this.enabled;
    }

    /**
     * 有効/無効を設定
     * @param {boolean} enabled
     */
    setEnabled(enabled) {
        this.enabled = enabled;
        this.saveSettings();
    }

    /**
     * 次のタイムスタンプの秒数を取得（表示更新用）
     *
     * 次のタイムスタンプがある場合はその秒数を返す。
     * ない場合はnullを返す。
     *
     * @param {Object} timestamp - タイムスタンプオブジェクト
     * @returns {number|null} 次のタイムスタンプの秒数、またはnull
     */
    calculateEndTime(timestamp) {
        if (timestamp &&
            timestamp.next_ts_num !== null &&
            timestamp.next_ts_num !== undefined &&
            typeof timestamp.next_ts_num === 'number' &&
            timestamp.next_ts_num >= 0) {
            return timestamp.next_ts_num;
        }
        return null;
    }

    /**
     * 終了時刻を設定
     * @param {number|null} endTime
     */
    setEndTime(endTime) {
        this.currentSongEndTime = endTime;
    }

    /**
     * 終了時刻を取得
     * @returns {number|null}
     */
    getEndTime() {
        return this.currentSongEndTime;
    }

    /**
     * 再生位置監視を開始
     */
    startMonitor() {
        // 既存の監視を停止
        this.stopMonitor();

        if (!videoPlayerManager.isInitialized() || this.currentSongEndTime === null) return;

        const CHECK_INTERVAL = 500; // チェック間隔（ミリ秒）
        const MAX_STALL_COUNT = 6; // 3秒間（500ms × 6回）進まなければスタックと判定

        // スタック検知用の初期化
        this.lastPlaybackTime = videoPlayerManager.getCurrentTime();
        this.stallCount = 0;

        this.reshuffleMonitorId = setInterval(() => {
            if (!videoPlayerManager.isInitialized()) {
                this.stopMonitor();
                return;
            }

            const currentTime = videoPlayerManager.getCurrentTime();
            const isPlaying = videoPlayerManager.getIsPlaying();

            // スタック検知: 再生中なのに時間が進まない状態を検知
            if (isPlaying) {
                if (Math.abs(currentTime - this.lastPlaybackTime) < 0.1) {
                    this.stallCount++;
                    if (this.stallCount >= MAX_STALL_COUNT) {
                        console.warn('再生がスタックしています（再生位置が進まない）');
                        toast.warning('読み込みに問題があります。次の曲に進みます...');
                        this.stopMonitor();
                        if (this.onStallDetected) {
                            this.onStallDetected();
                        }
                        return;
                    }
                } else {
                    this.stallCount = 0;
                }
            }
            this.lastPlaybackTime = currentTime;

            // 次のタイムスタンプに到達（表示更新のみ、動画は継続再生）
            // フェードアウトは行わない
            if (currentTime >= this.currentSongEndTime) {
                this.stopMonitor();
                if (this.onNextTimestampReached) {
                    this.onNextTimestampReached();
                }
            }
        }, CHECK_INTERVAL);
    }

    /**
     * 再生位置監視を停止
     */
    stopMonitor() {
        if (this.reshuffleMonitorId) {
            clearInterval(this.reshuffleMonitorId);
            this.reshuffleMonitorId = null;
        }
        this.stopFadeOut();
    }

    /**
     * バッファリングタイムアウトを開始
     */
    startBufferingTimeout() {
        // 既存のタイムアウトをクリア
        this.clearBufferingTimeout();

        const BUFFERING_TIMEOUT = 10000; // 10秒

        this.bufferingTimeoutId = setTimeout(() => {
            console.warn('バッファリングタイムアウト');
            toast.warning('読み込みに時間がかかっています。次の曲に進みます...');
            this.stopMonitor();
            if (this.onBufferingTimeout) {
                this.onBufferingTimeout();
            }
        }, BUFFERING_TIMEOUT);
    }

    /**
     * バッファリングタイムアウトをクリア
     */
    clearBufferingTimeout() {
        if (this.bufferingTimeoutId) {
            clearTimeout(this.bufferingTimeoutId);
            this.bufferingTimeoutId = null;
        }
    }

    /**
     * フェードアウトを開始
     */
    startFadeOut() {
        if (this.fadeOutIntervalId || !videoPlayerManager.isInitialized()) return;

        // 現在の音量を保存
        this.originalVolume = videoPlayerManager.getVolume();

        const FADE_STEPS = 10;
        const FADE_INTERVAL = 300; // 3秒 / 10ステップ = 300ms
        let step = 0;

        this.fadeOutIntervalId = setInterval(() => {
            step++;
            const newVolume = Math.max(0, this.originalVolume * (1 - step / FADE_STEPS));

            videoPlayerManager.setVolume(newVolume);

            if (step >= FADE_STEPS) {
                this.stopFadeOut();
            }
        }, FADE_INTERVAL);
    }

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
    }

    /**
     * フェードインを開始
     */
    startFadeIn() {
        if (this.fadeInIntervalId || !videoPlayerManager.isInitialized()) return;
        if (!this.needsFadeIn) return;

        this.needsFadeIn = false;

        // 音量0から開始
        videoPlayerManager.setVolume(0);

        const FADE_STEPS = 10;
        const FADE_INTERVAL = 200; // 2秒 / 10ステップ = 200ms（フェードアウトより短め）
        let step = 0;

        this.fadeInIntervalId = setInterval(() => {
            step++;
            const newVolume = Math.min(this.originalVolume, this.originalVolume * (step / FADE_STEPS));

            videoPlayerManager.setVolume(newVolume);

            if (step >= FADE_STEPS) {
                this.stopFadeIn();
            }
        }, FADE_INTERVAL);
    }

    /**
     * フェードインを停止
     */
    stopFadeIn() {
        if (this.fadeInIntervalId) {
            clearInterval(this.fadeInIntervalId);
            this.fadeInIntervalId = null;
        }
        // 最終的に元の音量に設定
        videoPlayerManager.setVolume(this.originalVolume);
    }

    /**
     * フェードインが必要かどうか
     * @returns {boolean}
     */
    needsFadeInOnPlay() {
        return this.needsFadeIn;
    }

    /**
     * プレイヤー状態変更時の処理
     * @param {Object} event - YouTube Player State Change Event
     */
    handlePlayerStateChange(event) {
        // 再生開始時の処理
        if (event.data === YT.PlayerState.PLAYING) {
            // バッファリングタイムアウトをクリア
            this.clearBufferingTimeout();
            // スタック検知用の初期化
            this.lastPlaybackTime = videoPlayerManager.getCurrentTime();
            this.stallCount = 0;
            // フェードインが必要な場合は開始
            if (this.needsFadeIn) {
                this.startFadeIn();
            }
            // 一時停止から再開した場合、表示更新用の監視を再開
            if (this.currentSongEndTime !== null && !this.reshuffleMonitorId) {
                this.startMonitor();
            }
        }

        // バッファリング状態の監視（自動再抽選中のみ）
        if (event.data === YT.PlayerState.BUFFERING && this.enabled) {
            this.startBufferingTimeout();
        } else if (event.data !== YT.PlayerState.BUFFERING) {
            this.clearBufferingTimeout();
        }

        // 動画終了時に次のタイムスタンプに遷移（自動再抽選が有効な場合）
        if (event.data === YT.PlayerState.ENDED && this.enabled) {
            this.stopMonitor();
            if (this.onSongEnd) {
                this.onSongEnd();
            }
        } else if (event.data === YT.PlayerState.PAUSED) {
            this.stopMonitor();
        }
    }

    /**
     * すべてのタイマー・インターバルをクリーンアップ
     */
    cleanup() {
        this.stopMonitor();
        this.clearBufferingTimeout();
        this.stopFadeIn();
    }
}

// シングルトンインスタンスをエクスポート
export const autoReshuffleManager = new AutoReshuffleManager();
