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
        this.onSongEnd = null;          // 曲の終了時に呼び出される
        this.onStallDetected = null;    // スタック検知時に呼び出される
        this.onBufferingTimeout = null; // バッファリングタイムアウト時に呼び出される
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
     * 終了時刻を計算
     *
     * 優先順位:
     * 1. 楽曲長さあり & 次のTSあり → min(開始 + 楽曲長さ + 10秒, 次のTS)
     * 2. 楽曲長さのみあり → 開始 + 楽曲長さ + 10秒
     * 3. 次のTSのみあり → 次のTS - 10秒
     * 4. どちらもなし → デフォルト5分
     *
     * @param {Object} timestamp - タイムスタンプオブジェクト
     * @returns {number} 終了時刻（秒）
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

        const FADE_OUT_DURATION = 3; // フェードアウト秒数
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
            const fadeOutStartTime = this.currentSongEndTime - FADE_OUT_DURATION;
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

            // フェードアウト開始時刻に到達
            if (currentTime >= fadeOutStartTime && !this.fadeOutIntervalId) {
                this.startFadeOut();
            }

            // 終了時刻に到達
            if (currentTime >= this.currentSongEndTime) {
                this.stopMonitor();
                if (this.onSongEnd) {
                    this.onSongEnd();
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
        }

        // バッファリング状態の監視（自動再抽選中のみ）
        if (event.data === YT.PlayerState.BUFFERING && this.enabled) {
            this.startBufferingTimeout();
        } else if (event.data !== YT.PlayerState.BUFFERING) {
            this.clearBufferingTimeout();
        }

        // 自動再抽選: 再生状態に応じて監視を開始/停止
        if (this.enabled && this.currentSongEndTime !== null) {
            if (event.data === YT.PlayerState.PLAYING) {
                this.startMonitor();
            } else if (event.data === YT.PlayerState.PAUSED ||
                       event.data === YT.PlayerState.ENDED) {
                this.stopMonitor();
            }
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
