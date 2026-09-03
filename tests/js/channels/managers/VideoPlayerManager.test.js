import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../../../resources/js/utils/toast.js', () => ({
    default: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
        info: vi.fn(),
    },
}));

vi.mock('../../../../resources/js/utils/youtube.js', () => ({
    getYoutubeUrl: vi.fn(),
    isValidVideoId: vi.fn((id) => typeof id === 'string' && /^[a-zA-Z0-9_-]{11}$/.test(id)),
}));

vi.mock('../../../../resources/js/channels/utils/constants.js', () => ({
    YOUTUBE_PLAYER_CONFIG: {
        height: '180',
        width: '320',
        playerVars: { autoplay: 0, controls: 1 },
        suggestedQuality: 'small',
    },
    PIP_SIZES: {
        small: { width: 240, height: 135, minimizedWidth: 120, label: '小' },
        medium: { width: 320, height: 180, minimizedWidth: 160, label: '中' },
        large: { width: 480, height: 270, minimizedWidth: 240, label: '大' },
    },
}));

let VideoPlayerManager;

beforeEach(async () => {
    globalThis.sessionStorage = {
        store: {},
        getItem(k) { return this.store[k] ?? null; },
        setItem(k, v) { this.store[k] = v; },
        removeItem(k) { delete this.store[k]; },
        clear() { this.store = {}; },
    };

    vi.clearAllMocks();

    const mod = await import('../../../../resources/js/channels/managers/VideoPlayerManager.js');
    VideoPlayerManager = mod.VideoPlayerManager;
});

describe('VideoPlayerManager', () => {
    describe('constructor', () => {
        it('初期状態が正しい', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.player).toBeNull();
            expect(mgr.playerReady).toBe(false);
            expect(mgr.playerInitialized).toBe(false);
            expect(mgr.isPlaying).toBe(false);
            expect(mgr.currentVideoId).toBeNull();
            expect(mgr.showVideoPlayer).toBe(false);
            expect(mgr.playerMinimized).toBe(false);
            expect(mgr.volume).toBe(100);
            expect(mgr.pipSize).toBe('medium');
        });
    });

    describe('loadAndPlay', () => {
        it('不正なvideoIdの場合はfalseを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.loadAndPlay('invalid')).toBe(false);
            expect(mgr.loadAndPlay('')).toBe(false);
        });

        it('正常なvideoIdで状態が更新される', () => {
            const mgr = new VideoPlayerManager();
            const result = mgr.loadAndPlay('dQw4w9WgXcQ', 60);
            expect(result).toBe(true);
            expect(mgr.currentVideoId).toBe('dQw4w9WgXcQ');
            expect(mgr.currentVideoTime).toBe(60);
            expect(mgr.showVideoPlayer).toBe(true);
        });

        it('プレイヤー未初期化時はpendingVideoに保存する', () => {
            const mgr = new VideoPlayerManager();
            mgr.loadAndPlay('dQw4w9WgXcQ', 30);
            expect(mgr.pendingVideo).toEqual({ videoId: 'dQw4w9WgXcQ', time: 30 });
        });

        it('プレイヤー初期化済みならloadVideoByIdを呼ぶ', () => {
            const mgr = new VideoPlayerManager();
            mgr.playerInitialized = true;
            mgr.player = { loadVideoById: vi.fn() };

            mgr.loadAndPlay('dQw4w9WgXcQ', 45);

            expect(mgr.player.loadVideoById).toHaveBeenCalledWith(
                expect.objectContaining({ videoId: 'dQw4w9WgXcQ', startSeconds: 45 })
            );
        });

        it('logUserActionが設定されていれば呼ばれる', () => {
            const mgr = new VideoPlayerManager();
            mgr.logUserAction = vi.fn();
            mgr.loadAndPlay('dQw4w9WgXcQ', 10);
            expect(mgr.logUserAction).toHaveBeenCalledWith('playVideo', { videoId: 'dQw4w9WgXcQ', startTime: 10 });
        });

        it('onShowChangeコールバックが呼ばれる', () => {
            const mgr = new VideoPlayerManager();
            mgr.onShowChange = vi.fn();
            mgr.loadAndPlay('dQw4w9WgXcQ');
            expect(mgr.onShowChange).toHaveBeenCalledWith(true);
        });
    });

    describe('togglePlayPause', () => {
        it('再生中なら一時停止する', () => {
            const mgr = new VideoPlayerManager();
            mgr.isPlaying = true;
            mgr.player = { pauseVideo: vi.fn() };
            mgr.onPlayingChange = vi.fn();

            const result = mgr.togglePlayPause();

            expect(result).toBe(true);
            expect(mgr.isPlaying).toBe(false);
            expect(mgr.player.pauseVideo).toHaveBeenCalled();
            expect(mgr.onPlayingChange).toHaveBeenCalledWith(false);
        });

        it('停止中でvideoIdが指定されていれば再生を開始する', () => {
            const mgr = new VideoPlayerManager();
            mgr.isPlaying = false;
            const result = mgr.togglePlayPause('dQw4w9WgXcQ', 30);
            expect(result).toBe(true);
            expect(mgr.currentVideoId).toBe('dQw4w9WgXcQ');
        });

        it('同じ動画で同じ時間ならプレイヤーのplayVideoを呼ぶ', () => {
            const mgr = new VideoPlayerManager();
            mgr.isPlaying = false;
            mgr.currentVideoId = 'dQw4w9WgXcQ';
            mgr.currentVideoTime = 30;
            mgr.player = { playVideo: vi.fn() };
            mgr.onPlayingChange = vi.fn();

            const result = mgr.togglePlayPause('dQw4w9WgXcQ', 30);

            expect(result).toBe(true);
            expect(mgr.player.playVideo).toHaveBeenCalled();
            expect(mgr.isPlaying).toBe(true);
        });

        it('videoIdなしで停止中ならfalseを返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.isPlaying = false;
            expect(mgr.togglePlayPause()).toBe(false);
        });
    });

    describe('close', () => {
        it('再生を停止してプレイヤーを非表示にする', () => {
            const mgr = new VideoPlayerManager();
            mgr.player = { stopVideo: vi.fn() };
            mgr.isPlaying = true;
            mgr.currentVideoId = 'dQw4w9WgXcQ';
            mgr.showVideoPlayer = true;
            mgr.playerMinimized = true;
            mgr.onPlayingChange = vi.fn();
            mgr.onShowChange = vi.fn();
            mgr.onMinimizedChange = vi.fn();

            mgr.close();

            expect(mgr.player.stopVideo).toHaveBeenCalled();
            expect(mgr.showVideoPlayer).toBe(false);
            expect(mgr.isPlaying).toBe(false);
            expect(mgr.currentVideoId).toBeNull();
            expect(mgr.playerMinimized).toBe(false);
        });

        it('resetPosition関数が渡されていれば呼ぶ', () => {
            const mgr = new VideoPlayerManager();
            mgr.player = { stopVideo: vi.fn() };
            const resetFn = vi.fn();
            mgr.close(resetFn);
            expect(resetFn).toHaveBeenCalled();
        });
    });

    describe('destroy', () => {
        it('プレイヤーを破棄して状態をリセットする', () => {
            const mgr = new VideoPlayerManager();
            mgr.player = { destroy: vi.fn() };
            mgr.playerInitialized = true;
            mgr.pendingVideo = { videoId: 'test', time: 0 };
            mgr.isPlaying = true;
            mgr.currentVideoId = 'test';
            mgr.onPlayingChange = vi.fn();

            mgr.destroy();

            expect(mgr.player).toBeNull();
            expect(mgr.playerInitialized).toBe(false);
            expect(mgr.pendingVideo).toBeNull();
            expect(mgr.showVideoPlayer).toBe(false);
            expect(mgr.isPlaying).toBe(false);
            expect(mgr.currentVideoId).toBeNull();
            expect(mgr.onPlayingChange).toHaveBeenCalledWith(false);
        });
    });

    describe('volume', () => {
        it('有効な音量を設定してsessionStorageに保存する', () => {
            const mgr = new VideoPlayerManager();
            mgr.setVolume(50);
            expect(mgr.volume).toBe(50);
            expect(sessionStorage.getItem('videoVolume')).toBe('50');
        });

        it('プレイヤーがあればsetVolumeを呼ぶ', () => {
            const mgr = new VideoPlayerManager();
            mgr.player = { setVolume: vi.fn() };
            mgr.setVolume(75);
            expect(mgr.player.setVolume).toHaveBeenCalledWith(75);
        });

        it('範囲外の値は無視する', () => {
            const mgr = new VideoPlayerManager();
            mgr.setVolume(-1);
            expect(mgr.volume).toBe(100);
            mgr.setVolume(101);
            expect(mgr.volume).toBe(100);
        });

        it('文字列をパースする', () => {
            const mgr = new VideoPlayerManager();
            mgr.setVolume('30');
            expect(mgr.volume).toBe(30);
        });

        it('NaNは無視する', () => {
            const mgr = new VideoPlayerManager();
            mgr.setVolume('abc');
            expect(mgr.volume).toBe(100);
        });

        it('restoreVolumeでsessionStorageから復元する', () => {
            sessionStorage.setItem('videoVolume', '42');
            const mgr = new VideoPlayerManager();
            mgr.restoreVolume();
            expect(mgr.volume).toBe(42);
        });

        it('sessionStorageに値がなければデフォルト値のまま', () => {
            const mgr = new VideoPlayerManager();
            mgr.restoreVolume();
            expect(mgr.volume).toBe(100);
        });
    });

    describe('pipSize', () => {
        it('有効なサイズを設定してsessionStorageに保存する', () => {
            const mgr = new VideoPlayerManager();
            mgr.setPipSize('small');
            expect(mgr.pipSize).toBe('small');
            expect(sessionStorage.getItem('pipSize')).toBe('small');
        });

        it('無効なサイズは無視する', () => {
            const mgr = new VideoPlayerManager();
            mgr.setPipSize('huge');
            expect(mgr.pipSize).toBe('medium');
        });

        it('restorePipSizeでsessionStorageから復元する', () => {
            sessionStorage.setItem('pipSize', 'large');
            const mgr = new VideoPlayerManager();
            mgr.restorePipSize();
            expect(mgr.pipSize).toBe('large');
        });

        it('getCurrentPipSizeは現在のサイズ設定を返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.pipSize = 'small';
            const size = mgr.getCurrentPipSize();
            expect(size.width).toBe(240);
            expect(size.height).toBe(135);
        });

        it('不明なサイズはmediumにフォールバックする', () => {
            const mgr = new VideoPlayerManager();
            mgr.pipSize = 'unknown';
            const size = mgr.getCurrentPipSize();
            expect(size.width).toBe(320);
        });
    });

    describe('toggleMinimize', () => {
        it('最小化状態をトグルする', () => {
            const mgr = new VideoPlayerManager();
            mgr.onMinimizedChange = vi.fn();

            mgr.toggleMinimize();
            expect(mgr.playerMinimized).toBe(true);
            expect(mgr.onMinimizedChange).toHaveBeenCalledWith(true);

            mgr.toggleMinimize();
            expect(mgr.playerMinimized).toBe(false);
            expect(mgr.onMinimizedChange).toHaveBeenCalledWith(false);
        });
    });

    describe('state getters', () => {
        it('isInitializedはplayerInitializedを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.isInitialized()).toBe(false);
            mgr.playerInitialized = true;
            expect(mgr.isInitialized()).toBe(true);
        });

        it('isAPIReadyはplayerReadyを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.isAPIReady()).toBe(false);
            mgr.playerReady = true;
            expect(mgr.isAPIReady()).toBe(true);
        });

        it('isShowingはshowVideoPlayerを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.isShowing()).toBe(false);
            mgr.showVideoPlayer = true;
            expect(mgr.isShowing()).toBe(true);
        });

        it('isMinimizedはplayerMinimizedを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.isMinimized()).toBe(false);
            mgr.playerMinimized = true;
            expect(mgr.isMinimized()).toBe(true);
        });

        it('getIsPlayingはisPlayingを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.getIsPlaying()).toBe(false);
            mgr.isPlaying = true;
            expect(mgr.getIsPlaying()).toBe(true);
        });
    });

    describe('getCurrentTime / getDuration', () => {
        it('プレイヤーがなければ0を返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.getCurrentTime()).toBe(0);
            expect(mgr.getDuration()).toBe(0);
        });

        it('プレイヤーがあれば値を返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.player = {
                getCurrentTime: vi.fn(() => 42.5),
                getDuration: vi.fn(() => 300),
            };
            expect(mgr.getCurrentTime()).toBe(42.5);
            expect(mgr.getDuration()).toBe(300);
        });

        it('getDurationが無効値なら0を返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.player = { getDuration: vi.fn(() => NaN) };
            expect(mgr.getDuration()).toBe(0);

            mgr.player.getDuration.mockReturnValue(-1);
            expect(mgr.getDuration()).toBe(0);

            mgr.player.getDuration.mockReturnValue(0);
            expect(mgr.getDuration()).toBe(0);
        });
    });

    describe('getPipWidth', () => {
        it('通常時はサイズのwidthを返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.pipSize = 'medium';
            mgr.playerMinimized = false;
            expect(mgr.getPipWidth()).toBe(320);
        });

        it('最小化時はminimizedWidthを返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.pipSize = 'medium';
            mgr.playerMinimized = true;
            expect(mgr.getPipWidth()).toBe(160);
        });
    });

    describe('isCurrentVideoLoaded', () => {
        it('プレイヤーがなければtrueを返す', () => {
            const mgr = new VideoPlayerManager();
            expect(mgr.isCurrentVideoLoaded()).toBe(true);
        });

        it('getVideoDataが一致すればtrueを返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.currentVideoId = 'dQw4w9WgXcQ';
            mgr.player = { getVideoData: vi.fn(() => ({ video_id: 'dQw4w9WgXcQ' })) };
            expect(mgr.isCurrentVideoLoaded()).toBe(true);
        });

        it('getVideoDataが異なればfalseを返す', () => {
            const mgr = new VideoPlayerManager();
            mgr.currentVideoId = 'dQw4w9WgXcQ';
            mgr.player = { getVideoData: vi.fn(() => ({ video_id: 'other12345_' })) };
            expect(mgr.isCurrentVideoLoaded()).toBe(false);
        });
    });
});
