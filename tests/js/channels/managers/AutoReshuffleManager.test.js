import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../../../resources/js/utils/toast.js', () => ({
    default: {
        success: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
}));

vi.mock('../../../../resources/js/channels/managers/VideoPlayerManager.js', () => {
    const manager = {
        isInitialized: vi.fn(() => true),
        getCurrentTime: vi.fn(() => 0),
        getIsPlaying: vi.fn(() => false),
        getDuration: vi.fn(() => 300),
        isCurrentVideoLoaded: vi.fn(() => true),
    };
    return {
        VideoPlayerManager: vi.fn(() => manager),
        videoPlayerManager: manager,
    };
});

let AutoReshuffleManager;
let videoPlayerManager;

beforeEach(async () => {
    vi.useFakeTimers();

    globalThis.YT = { PlayerState: { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3, CUED: 5 } };
    globalThis.sessionStorage = { store: {}, getItem(k) { return this.store[k] ?? null; }, setItem(k, v) { this.store[k] = v; }, removeItem(k) { delete this.store[k]; } };

    const mod = await import('../../../../resources/js/channels/managers/AutoReshuffleManager.js');
    AutoReshuffleManager = mod.AutoReshuffleManager;

    const vpm = await import('../../../../resources/js/channels/managers/VideoPlayerManager.js');
    videoPlayerManager = vpm.videoPlayerManager;

    vi.clearAllMocks();
    videoPlayerManager.isInitialized.mockReturnValue(true);
    videoPlayerManager.getCurrentTime.mockReturnValue(0);
    videoPlayerManager.getIsPlaying.mockReturnValue(false);
    videoPlayerManager.getDuration.mockReturnValue(300);
    videoPlayerManager.isCurrentVideoLoaded.mockReturnValue(true);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('AutoReshuffleManager', () => {
    describe('toggle', () => {
        it('OFF→ONに切り替わる', () => {
            const mgr = new AutoReshuffleManager();
            expect(mgr.isEnabled()).toBe(false);

            const result = mgr.toggle();

            expect(result).toBe(true);
            expect(mgr.isEnabled()).toBe(true);
        });

        it('ON→OFFに切り替わる', () => {
            const mgr = new AutoReshuffleManager();
            mgr.toggle();

            const result = mgr.toggle();

            expect(result).toBe(false);
            expect(mgr.isEnabled()).toBe(false);
        });

        it('再生中にONにするとarchiveEndHandledがリセットされ監視が開始される', () => {
            const mgr = new AutoReshuffleManager();
            mgr.archiveEndHandled = true;

            mgr.toggle(true);

            expect(mgr.archiveEndHandled).toBe(false);
            expect(mgr.reshuffleMonitorId).not.toBeNull();
            mgr.cleanup();
        });

        it('再生中でないときにONにしてもarchiveEndHandledはリセットされない', () => {
            const mgr = new AutoReshuffleManager();
            mgr.archiveEndHandled = true;

            mgr.toggle(false);

            expect(mgr.archiveEndHandled).toBe(true);
            expect(mgr.reshuffleMonitorId).toBeNull();
        });

        it('sessionStorageに状態が保存される', () => {
            const mgr = new AutoReshuffleManager();

            mgr.toggle();
            expect(sessionStorage.getItem('autoReshuffle')).toBe('true');

            mgr.toggle();
            expect(sessionStorage.getItem('autoReshuffle')).toBe('false');
        });
    });

    describe('calculateEndTime', () => {
        it('next_ts_numがある場合はその値を返す', () => {
            const mgr = new AutoReshuffleManager();
            expect(mgr.calculateEndTime({ next_ts_num: 120 })).toBe(120);
        });

        it('next_ts_numが0の場合も返す', () => {
            const mgr = new AutoReshuffleManager();
            expect(mgr.calculateEndTime({ next_ts_num: 0 })).toBe(0);
        });

        it('next_ts_numがnullの場合はnullを返す', () => {
            const mgr = new AutoReshuffleManager();
            expect(mgr.calculateEndTime({ next_ts_num: null })).toBeNull();
        });

        it('next_ts_numがundefinedの場合はnullを返す', () => {
            const mgr = new AutoReshuffleManager();
            expect(mgr.calculateEndTime({ next_ts_num: undefined })).toBeNull();
        });

        it('timestampがnullの場合はnullを返す', () => {
            const mgr = new AutoReshuffleManager();
            expect(mgr.calculateEndTime(null)).toBeNull();
        });
    });

    describe('handleArchiveEnd', () => {
        it('archiveEndHandledがtrueの場合は何もしない（多重実行防止）', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.archiveEndHandled = true;
            mgr.onSongEnd = vi.fn();

            mgr.handleArchiveEnd();

            expect(mgr.onSongEnd).not.toHaveBeenCalled();
        });

        it('自動再抽選ON時はonSongEndが呼ばれる', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.handleArchiveEnd();

            expect(mgr.archiveEndHandled).toBe(true);
            expect(mgr.onSongEnd).toHaveBeenCalledOnce();
        });

        it('自動再抽選OFF＋連続再生時はonArchiveEndWithoutReshuffleが呼ばれる', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = false;
            mgr.continuousPlayback = true;
            mgr.onArchiveEndWithoutReshuffle = vi.fn();

            mgr.handleArchiveEnd();

            expect(mgr.onArchiveEndWithoutReshuffle).toHaveBeenCalledOnce();
        });

        it('自動再抽選OFF＋非連続再生時はコールバックなし', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = false;
            mgr.continuousPlayback = false;
            mgr.onArchiveEndWithoutReshuffle = vi.fn();

            mgr.handleArchiveEnd();

            expect(mgr.onArchiveEndWithoutReshuffle).not.toHaveBeenCalled();
        });

        it('ENDEDイベント・監視・PAUSEDの3経路から呼ばれても1回だけ実行される', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.handleArchiveEnd();
            mgr.handleArchiveEnd();
            mgr.handleArchiveEnd();

            expect(mgr.onSongEnd).toHaveBeenCalledOnce();
        });
    });

    describe('suspend', () => {
        it('archiveEndHandledをtrueにしてENDED抑止する', () => {
            const mgr = new AutoReshuffleManager();
            mgr.archiveEndHandled = false;

            mgr.suspend();

            expect(mgr.archiveEndHandled).toBe(true);
        });

        it('suspend後にhandleArchiveEndを呼んでもコールバックは実行されない', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.suspend();
            mgr.handleArchiveEnd();

            expect(mgr.onSongEnd).not.toHaveBeenCalled();
        });
    });

    describe('setEndTime', () => {
        it('終了時刻を設定しarchiveEndHandledをリセットする', () => {
            const mgr = new AutoReshuffleManager();
            mgr.archiveEndHandled = true;

            mgr.setEndTime(120);

            expect(mgr.getEndTime()).toBe(120);
            expect(mgr.archiveEndHandled).toBe(false);
        });
    });

    describe('startMonitor', () => {
        it('プレイヤー未初期化時は監視を開始しない', () => {
            videoPlayerManager.isInitialized.mockReturnValue(false);
            const mgr = new AutoReshuffleManager();

            mgr.startMonitor();

            expect(mgr.reshuffleMonitorId).toBeNull();
        });

        it('次のタイムスタンプに到達するとonNextTimestampReachedが呼ばれる', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(0);
            const mgr = new AutoReshuffleManager();
            mgr.currentSongEndTime = 60;
            mgr.onNextTimestampReached = vi.fn();

            mgr.startMonitor();

            videoPlayerManager.getCurrentTime.mockReturnValue(61);
            vi.advanceTimersByTime(500);

            expect(mgr.onNextTimestampReached).toHaveBeenCalledOnce();
            expect(mgr.reshuffleMonitorId).toBeNull();
        });

        it('再生中に動画終端に到達するとhandleArchiveEndが呼ばれる', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(0);
            videoPlayerManager.getDuration.mockReturnValue(300);
            videoPlayerManager.getIsPlaying.mockReturnValue(true);
            videoPlayerManager.isCurrentVideoLoaded.mockReturnValue(true);

            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.startMonitor();

            videoPlayerManager.getCurrentTime.mockReturnValue(299);
            vi.advanceTimersByTime(500);

            expect(mgr.onSongEnd).toHaveBeenCalledOnce();
        });

        it('再生中に位置が進まない場合3秒後にスタック検知される', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(50);
            videoPlayerManager.getIsPlaying.mockReturnValue(true);
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onStallDetected = vi.fn();

            mgr.startMonitor();

            // 6回×500ms = 3秒スタック
            for (let i = 0; i < 6; i++) {
                vi.advanceTimersByTime(500);
            }

            expect(mgr.onStallDetected).toHaveBeenCalledOnce();
        });

        it('終端から離れた位置を再生するとarchiveEndHandledがリセットされる', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(100);
            videoPlayerManager.getIsPlaying.mockReturnValue(false);
            videoPlayerManager.getDuration.mockReturnValue(300);
            videoPlayerManager.isCurrentVideoLoaded.mockReturnValue(true);

            const mgr = new AutoReshuffleManager();
            mgr.archiveEndHandled = true;

            mgr.startMonitor();
            vi.advanceTimersByTime(500);

            expect(mgr.archiveEndHandled).toBe(false);
            mgr.cleanup();
        });
    });

    describe('handlePlayerStateChange', () => {
        it('ENDED時にhandleArchiveEndが呼ばれる', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.handlePlayerStateChange({ data: YT.PlayerState.ENDED });

            expect(mgr.onSongEnd).toHaveBeenCalledOnce();
        });

        it('PLAYING時にバッファリングタイムアウトがクリアされる', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(50);
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onBufferingTimeout = vi.fn();

            mgr.handlePlayerStateChange({ data: YT.PlayerState.BUFFERING });
            expect(mgr.bufferingTimeoutId).not.toBeNull();

            mgr.handlePlayerStateChange({ data: YT.PlayerState.PLAYING });
            expect(mgr.bufferingTimeoutId).toBeNull();
            mgr.cleanup();
        });

        it('BUFFERING中に自動再抽選ONだと10秒後にタイムアウト', () => {
            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onBufferingTimeout = vi.fn();

            mgr.handlePlayerStateChange({ data: YT.PlayerState.BUFFERING });

            vi.advanceTimersByTime(10000);

            expect(mgr.onBufferingTimeout).toHaveBeenCalledOnce();
        });

        it('終端でPAUSEDになると2秒後にarchiveEnd処理が走る', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(299);
            videoPlayerManager.getDuration.mockReturnValue(300);
            videoPlayerManager.isCurrentVideoLoaded.mockReturnValue(true);

            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.handlePlayerStateChange({ data: YT.PlayerState.PAUSED });

            expect(mgr.onSongEnd).not.toHaveBeenCalled();
            vi.advanceTimersByTime(2000);
            expect(mgr.onSongEnd).toHaveBeenCalledOnce();
        });

        it('終端でPAUSED後にPLAYINGになるとPAUSEタイマーがキャンセルされる', () => {
            videoPlayerManager.getCurrentTime.mockReturnValue(299);
            videoPlayerManager.getDuration.mockReturnValue(300);
            videoPlayerManager.isCurrentVideoLoaded.mockReturnValue(true);

            const mgr = new AutoReshuffleManager();
            mgr.enabled = true;
            mgr.onSongEnd = vi.fn();

            mgr.handlePlayerStateChange({ data: YT.PlayerState.PAUSED });
            mgr.handlePlayerStateChange({ data: YT.PlayerState.PLAYING });

            vi.advanceTimersByTime(2000);
            expect(mgr.onSongEnd).not.toHaveBeenCalled();
            mgr.cleanup();
        });
    });

    describe('Issue #665で検知されたバグのリグレッションテスト', () => {
        it('toggle()でON→OFF→ONするとarchiveEndHandledが正しくリセットされる', () => {
            const mgr = new AutoReshuffleManager();
            mgr.onSongEnd = vi.fn();

            // ON にして末尾処理を実行
            mgr.toggle(true);
            mgr.handleArchiveEnd();
            expect(mgr.archiveEndHandled).toBe(true);
            expect(mgr.onSongEnd).toHaveBeenCalledOnce();

            // OFF にする
            mgr.toggle();
            expect(mgr.isEnabled()).toBe(false);

            // 再度 ON（再生中）→ archiveEndHandled がリセットされ、次の曲に進める
            mgr.toggle(true);
            expect(mgr.archiveEndHandled).toBe(false);

            mgr.handleArchiveEnd();
            expect(mgr.onSongEnd).toHaveBeenCalledTimes(2);
            mgr.cleanup();
        });
    });
});
