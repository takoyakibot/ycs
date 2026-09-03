import { getYoutubeUrl, isValidVideoId } from '@/utils/youtube.js';

describe('getYoutubeUrl', () => {
    it('videoIdとタイムスタンプからURLを生成する', () => {
        expect(getYoutubeUrl('dQw4w9WgXcQ', 120)).toBe(
            'https://youtu.be/dQw4w9WgXcQ?t=120s'
        );
    });

    it('タイムスタンプ省略時は0sを付与する', () => {
        expect(getYoutubeUrl('dQw4w9WgXcQ')).toBe(
            'https://youtu.be/dQw4w9WgXcQ?t=0s'
        );
    });

    it('videoIdが空の場合も安全にURLを返す', () => {
        expect(getYoutubeUrl('')).toBe('https://youtu.be/?t=0s');
    });

    it('videoIdがnullの場合も安全にURLを返す', () => {
        expect(getYoutubeUrl(null)).toBe('https://youtu.be/?t=0s');
    });

    it('特殊文字を含むvideoIdをエンコードする', () => {
        const url = getYoutubeUrl('a&b=c');
        expect(url).toContain('a%26b%3Dc');
    });

    it('タイムスタンプが文字列でもパースする', () => {
        expect(getYoutubeUrl('dQw4w9WgXcQ', '60')).toBe(
            'https://youtu.be/dQw4w9WgXcQ?t=60s'
        );
    });

    it('タイムスタンプが不正値の場合は0sになる', () => {
        expect(getYoutubeUrl('dQw4w9WgXcQ', 'abc')).toBe(
            'https://youtu.be/dQw4w9WgXcQ?t=0s'
        );
    });
});

describe('isValidVideoId', () => {
    it('正しい11文字のIDを受け付ける', () => {
        expect(isValidVideoId('dQw4w9WgXcQ')).toBe(true);
    });

    it('ハイフンを含むIDを受け付ける', () => {
        expect(isValidVideoId('abc-def_123')).toBe(true);
    });

    it('10文字のIDを拒否する', () => {
        expect(isValidVideoId('dQw4w9WgXc')).toBe(false);
    });

    it('12文字のIDを拒否する', () => {
        expect(isValidVideoId('dQw4w9WgXcQQ')).toBe(false);
    });

    it('空文字列を拒否する', () => {
        expect(isValidVideoId('')).toBe(false);
    });

    it('nullを拒否する', () => {
        expect(isValidVideoId(null)).toBe(false);
    });

    it('undefinedを拒否する', () => {
        expect(isValidVideoId(undefined)).toBe(false);
    });

    it('特殊文字を含むIDを拒否する', () => {
        expect(isValidVideoId('dQw4w9WgX!Q')).toBe(false);
    });
});
