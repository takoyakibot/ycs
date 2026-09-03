import { CONSTANTS } from '@/songs/utils/constants.js';

describe('CONSTANTS', () => {
    it('YouTube URLが定義されている', () => {
        expect(CONSTANTS.YOUTUBE_BASE_URL).toBe('https://youtu.be/');
    });

    it('検索モードが定義されている', () => {
        expect(CONSTANTS.SONG_SEARCH_MODE_FUZZY).toBe('fuzzy');
        expect(CONSTANTS.SONG_SEARCH_MODE_EXACT).toBe('exact');
    });

    it('表示制限値が数値である', () => {
        expect(typeof CONSTANTS.MAX_STATUS_LENGTH).toBe('number');
        expect(typeof CONSTANTS.MAX_SELECTION_TEXT_LENGTH).toBe('number');
    });
});
