import {
    getSpotifyUrl,
    getAppleMusicUrl,
    getYouTubeMusicUrl,
    getAmazonMusicUrl,
    getLineMusicUrl,
} from '@/utils/music-services.js';

const song = { title: '夜に駆ける', artist: 'YOASOBI' };
const songWithSpotifyId = { title: '夜に駆ける', artist: 'YOASOBI', spotify_track_id: '2yLa0DPMexLBMBKgNg4bqK' };

describe('getSpotifyUrl', () => {
    it('spotify_track_idがある場合はトラックURLを返す', () => {
        expect(getSpotifyUrl(songWithSpotifyId)).toBe(
            'https://open.spotify.com/track/2yLa0DPMexLBMBKgNg4bqK'
        );
    });

    it('spotify_track_idがない場合は検索URLを返す', () => {
        const url = getSpotifyUrl(song);
        expect(url).toContain('https://open.spotify.com/search/');
        expect(url).toContain(encodeURIComponent('夜に駆ける YOASOBI'));
    });

    it('nullで空文字列を返す', () => {
        expect(getSpotifyUrl(null)).toBe('');
    });
});

describe('getAppleMusicUrl', () => {
    it('検索URLを生成する', () => {
        const url = getAppleMusicUrl(song);
        expect(url).toContain('https://music.apple.com/jp/search?term=');
        expect(url).toContain(encodeURIComponent('夜に駆ける YOASOBI'));
    });

    it('nullで空文字列を返す', () => {
        expect(getAppleMusicUrl(null)).toBe('');
    });
});

describe('getYouTubeMusicUrl', () => {
    it('検索URLを生成する', () => {
        const url = getYouTubeMusicUrl(song);
        expect(url).toContain('https://music.youtube.com/search?q=');
        expect(url).toContain(encodeURIComponent('夜に駆ける YOASOBI'));
    });

    it('nullで空文字列を返す', () => {
        expect(getYouTubeMusicUrl(null)).toBe('');
    });
});

describe('getAmazonMusicUrl', () => {
    it('検索URLを生成する', () => {
        const url = getAmazonMusicUrl(song);
        expect(url).toContain('https://music.amazon.co.jp/search/');
    });

    it('特殊文字を除去してスペースを+に変換する', () => {
        const url = getAmazonMusicUrl({ title: 'test/song', artist: 'art&ist' });
        expect(url).not.toContain('/song');
        expect(url).not.toContain('&ist');
        expect(url).toContain('+');
    });

    it('nullで空文字列を返す', () => {
        expect(getAmazonMusicUrl(null)).toBe('');
    });
});

describe('getLineMusicUrl', () => {
    it('検索URLを生成する', () => {
        const url = getLineMusicUrl(song);
        expect(url).toContain('https://music.line.me/webapp/search/tracks?query=');
    });

    it('特殊文字を除去する', () => {
        const url = getLineMusicUrl({ title: 'test/song', artist: 'art&ist' });
        expect(url).not.toContain('/song');
        expect(url).not.toContain('&ist');
    });

    it('nullで空文字列を返す', () => {
        expect(getLineMusicUrl(null)).toBe('');
    });
});
