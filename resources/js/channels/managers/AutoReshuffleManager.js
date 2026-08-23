import toast from '../../utils/toast.js';
import { videoPlayerManager } from './VideoPlayerManager.js';

/**
 * 自動再抽選機能を管理するマネージャークラス
 *
 * 責務:
 * - 再生位置の監視
 * - バッファリングタイムアウト検知
 * - 終了時刻の計算
 * - スタック検知
 */
export class AutoReshuffleManager {
    constructor() {
        // 状態
        this.enabled = false;
        this.currentSongEndTime = null;

        // モニタリング用ID
        this.reshuffleMonitorId = null;
        this.bufferingTimeoutId = null;

        // スタック検知用
        this.lastPlaybackTime = 0;
        this.stallCount = 0;

        // アーカイブ末尾の多重処理防止フラグ
        // 動画終端の検知はENDEDイベント・監視・PAUSEDの3経路があるため、
        // 1回の再生につき1度だけ末尾処理を実行する
        this.archiveEndHandled = false;

        // 連続再生（ガチャ・曲送り）由来の再生かどうか
        // 末尾に到達したときの案内を出すかの判定に使う。一覧クリックや
        // シェアリンク（?play=）単独の再生では案内しても続ける手段がないため、
        // 再生の起点側で明示的に設定する（起点が分かるのは呼び出し側だけ）
        this.continuousPlayback = false;

        // コールバック
        this.onSongEnd = null;          // 動画終了時に呼び出される（自動再抽選用）
        this.onStallDetected = null;    // スタック検知時に呼び出される
        this.onBufferingTimeout = null; // バッファリングタイムアウト時に呼び出される
        this.onNextTimestampReached = null; // 次のタイムスタンプ到達時に呼び出される（表示更新用）
        this.onArchiveEndWithoutReshuffle = null; // 自動再抽選OFFで末尾に到達したときに呼び出される
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
            // ONにした場合、再生中であれば監視開始。
            // 終了時刻がない（＝アーカイブ内の最後の楽曲）場合も
            // 末尾検知のために監視する必要があるため条件に含めない
            if (isPlaying) {
                // 末尾処理済みフラグを解除する。終端付近で末尾処理が走った後は
                // 監視ループの解除条件（終端から離れたら解除）が成立しないため、
                // 解除しないと監視を再開しても handleArchiveEnd() が空振りする。
                //
                // この代入は必ず isPlaying の内側に置くこと。外に出すと、
                // プレイヤーを閉じたあと（suspend() がフラグを立てて stopVideo() 由来の
                // ENDED を抑止している状態）にトグルを操作しただけで抑止が解け、
                // 閉じた動画の ENDED で再抽選が走る。
                this.archiveEndHandled = false;
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
        console.debug('[Monitor] setEndTime:', endTime);
        this.currentSongEndTime = endTime;
        // 新しい再生位置に移ったので末尾処理フラグをリセット
        this.archiveEndHandled = false;
    }

    /**
     * この再生が連続再生（ガチャ・曲送り）由来かどうかを設定する
     *
     * 末尾に到達したときに案内を出すかの判定に使う。起点が分かるのは
     * 呼び出し側だけなので、再生を開始する各経路で明示的に設定する。
     *
     * @param {boolean} isContinuous
     */
    setContinuousPlayback(isContinuous) {
        this.continuousPlayback = isContinuous;
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
     *
     * 次のタイムスタンプがない（＝アーカイブ内の最後の楽曲）場合も監視を続ける。
     * ENDEDイベントが届かないアーカイブでも末尾を検知できるようにするため。
     */
    startMonitor() {
        // 既存の監視を停止
        this.stopMonitor();

        if (!videoPlayerManager.isInitialized()) {
            console.debug('[Monitor] startMonitor aborted: player not initialized');
            return;
        }

        const CHECK_INTERVAL = 500; // チェック間隔（ミリ秒）
        const MAX_STALL_COUNT = 6; // 3秒間（500ms × 6回）進まなければスタックと判定

        // スタック検知用の初期化
        this.lastPlaybackTime = videoPlayerManager.getCurrentTime();
        this.stallCount = 0;

        console.debug('[Monitor] startMonitor: currentTime=%s, endTime=%s',
            this.lastPlaybackTime, this.currentSongEndTime);

        this.reshuffleMonitorId = setInterval(() => {
            if (!videoPlayerManager.isInitialized()) {
                this.stopMonitor();
                return;
            }

            const currentTime = videoPlayerManager.getCurrentTime();
            const isPlaying = videoPlayerManager.getIsPlaying();
            const nearVideoEnd = this.isNearVideoEnd(currentTime);

            // 動画終端に到達（ENDEDイベントが届かないケースの保険）
            // 再生中のみ判定する。停止中は動画の切り替え待ちの可能性があり、
            // 前の動画の終端値を拾って再抽選が連鎖するおそれがあるため
            if (isPlaying && nearVideoEnd) {
                console.debug('[Monitor] videoEndReached: currentTime=%s, duration=%s',
                    currentTime, videoPlayerManager.getDuration());
                this.handleArchiveEnd();
                return;
            }

            // 終端から離れた位置を再生している（シーク戻しなど）場合は
            // 末尾処理フラグを戻し、再度末尾に到達したときに処理できるようにする
            if (this.archiveEndHandled && !nearVideoEnd) {
                this.archiveEndHandled = false;
            }

            // スタック検知: 再生中なのに時間が進まない状態を検知
            // 自動再抽選がOFFのときは勝手に次の曲へ進めない（バッファリング検知と同じ扱い）
            if (isPlaying && this.enabled) {
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
            if (this.currentSongEndTime !== null && currentTime >= this.currentSongEndTime) {
                console.debug('[Monitor] nextTimestampReached: currentTime=%s, endTime=%s',
                    currentTime, this.currentSongEndTime);
                this.stopMonitor();
                if (this.onNextTimestampReached) {
                    this.onNextTimestampReached();
                }
            }
        }, CHECK_INTERVAL);
    }

    /**
     * 動画の終端付近かどうか
     *
     * 動画長が取得できない（ライブアーカイブのメタデータ未確定など）場合や、
     * 動画の切り替えが完了していない場合は判定できないためfalseを返す。
     *
     * @param {number} currentTime - 現在の再生位置（秒）
     * @returns {boolean}
     */
    isNearVideoEnd(currentTime) {
        const VIDEO_END_THRESHOLD = 1.5; // 終端とみなす残り秒数

        if (!videoPlayerManager.isCurrentVideoLoaded()) {
            return false;
        }

        const duration = videoPlayerManager.getDuration();

        return duration > 0 && currentTime >= duration - VIDEO_END_THRESHOLD;
    }

    /**
     * アーカイブ末尾到達時の処理
     *
     * ENDEDイベント・再生位置監視・終端での一時停止のいずれから呼ばれても
     * 1回の再生につき1度だけ処理する。
     */
    handleArchiveEnd() {
        if (this.archiveEndHandled) {
            return;
        }
        this.archiveEndHandled = true;
        this.stopMonitor();

        if (!this.enabled) {
            // 自動再抽選OFFのときは何も選ばずに停止する（仕様）
            // ただし無言で止まると不具合に見えるため呼び出し元に通知する。
            // 通知するのは連続再生（ガチャ・曲送り）由来のときだけ。一覧クリックや
            // シェアリンク単独の再生では selectedTimestamp が無く「次の曲」も押せないため、
            // 案内しても実行できる操作がない
            console.debug('[Monitor] archiveEnd (auto reshuffle off)');
            if (this.continuousPlayback && this.onArchiveEndWithoutReshuffle) {
                this.onArchiveEndWithoutReshuffle();
            }
            return;
        }

        console.debug('[Monitor] archiveEnd: reshuffling');
        if (this.onSongEnd) {
            this.onSongEnd();
        }
    }

    /**
     * 監視を中断し、以降の末尾処理を抑止する
     *
     * プレイヤーを閉じたときなど、stopVideo()由来のENDEDで
     * 意図しない再抽選が走らないようにするために使う。
     */
    suspend() {
        this.stopMonitor();
        this.clearBufferingTimeout();
        // 終了時刻は保持する。再度再生したときに曲送り表示を継続するため
        this.archiveEndHandled = true;
    }

    /**
     * 再生位置監視を停止
     */
    stopMonitor() {
        if (this.reshuffleMonitorId) {
            console.debug('[Monitor] stopMonitor: clearing interval');
            clearInterval(this.reshuffleMonitorId);
            this.reshuffleMonitorId = null;
        }
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
     * プレイヤー状態変更時の処理
     * @param {Object} event - YouTube Player State Change Event
     */
    handlePlayerStateChange(event) {
        const stateNames = { [-1]: 'UNSTARTED', 0: 'ENDED', 1: 'PLAYING', 2: 'PAUSED', 3: 'BUFFERING', 5: 'CUED' };
        console.debug('[Monitor] playerStateChange: %s (%d), endTime=%s, monitorActive=%s',
            stateNames[event.data] || 'UNKNOWN', event.data, this.currentSongEndTime, !!this.reshuffleMonitorId);

        // 再生開始時の処理
        if (event.data === YT.PlayerState.PLAYING) {
            // バッファリングタイムアウトをクリア
            this.clearBufferingTimeout();
            // スタック検知用の初期化
            this.lastPlaybackTime = videoPlayerManager.getCurrentTime();
            this.stallCount = 0;
            // 一時停止から再開した場合、監視を再開
            // 次のタイムスタンプがない（最後の楽曲）場合も末尾検知のため監視する
            if (!this.reshuffleMonitorId) {
                this.startMonitor();
            }
        }

        // バッファリング状態の監視（自動再抽選中のみ）
        if (event.data === YT.PlayerState.BUFFERING && this.enabled) {
            this.startBufferingTimeout();
        } else if (event.data !== YT.PlayerState.BUFFERING) {
            this.clearBufferingTimeout();
        }

        // 動画終了時はアーカイブ末尾として扱う
        if (event.data === YT.PlayerState.ENDED) {
            this.handleArchiveEnd();
        } else if (event.data === YT.PlayerState.PAUSED) {
            // 終端で一時停止扱いになるプレイヤーもあるため、末尾かどうかを確認する
            if (this.isNearVideoEnd(videoPlayerManager.getCurrentTime())) {
                this.handleArchiveEnd();
            } else {
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
    }
}

// シングルトンインスタンスをエクスポート
export const autoReshuffleManager = new AutoReshuffleManager();
