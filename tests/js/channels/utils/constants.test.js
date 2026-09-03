import { REPORT_TYPES, MOBILE_REGEX, YOUTUBE_PLAYER_CONFIG, PIP_SIZES } from '@/channels/utils/constants.js';

describe('REPORT_TYPES', () => {
    it('全ての報告タイプが定義されている', () => {
        expect(REPORT_TYPES.WRONG_SONG).toBe('wrong_song');
        expect(REPORT_TYPES.NOT_SONG).toBe('not_song');
        expect(REPORT_TYPES.NOT_TIMESTAMP).toBe('not_timestamp');
        expect(REPORT_TYPES.PROBLEM).toBe('problem');
        expect(REPORT_TYPES.OTHER).toBe('other');
    });
});

describe('MOBILE_REGEX', () => {
    it('モバイルUserAgentにマッチする', () => {
        expect(MOBILE_REGEX.test('Mozilla/5.0 (iPhone; CPU iPhone OS 14_0)')).toBe(true);
        expect(MOBILE_REGEX.test('Mozilla/5.0 (Linux; Android 11)')).toBe(true);
    });

    it('デスクトップUserAgentにマッチしない', () => {
        expect(MOBILE_REGEX.test('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')).toBe(false);
    });
});

describe('YOUTUBE_PLAYER_CONFIG', () => {
    it('プレイヤーサイズが設定されている', () => {
        expect(YOUTUBE_PLAYER_CONFIG.height).toBe('180');
        expect(YOUTUBE_PLAYER_CONFIG.width).toBe('320');
    });

    it('自動再生がオフ', () => {
        expect(YOUTUBE_PLAYER_CONFIG.playerVars.autoplay).toBe(0);
    });
});

describe('PIP_SIZES', () => {
    it('3つのサイズが定義されている', () => {
        expect(Object.keys(PIP_SIZES)).toEqual(['small', 'medium', 'large']);
    });

    it('各サイズにwidth/height/labelがある', () => {
        for (const size of Object.values(PIP_SIZES)) {
            expect(size).toHaveProperty('width');
            expect(size).toHaveProperty('height');
            expect(size).toHaveProperty('label');
        }
    });
});
