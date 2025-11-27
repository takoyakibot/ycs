/**
 * 音楽配信サービスのURL生成ユーティリティ
 */

/**
 * Spotify URLを生成
 * @param {Object} song - 楽曲情報 {title, artist, spotify_track_id}
 * @returns {string} Spotify URL
 */
export function getSpotifyUrl(song) {
    if (!song) return '';
    if (song.spotify_track_id) {
        return `https://open.spotify.com/track/${encodeURIComponent(song.spotify_track_id)}`;
    }
    const query = encodeURIComponent(`${song.title} ${song.artist}`);
    return `https://open.spotify.com/search/${query}`;
}

/**
 * Apple Music URLを生成
 * @param {Object} song - 楽曲情報 {title, artist}
 * @returns {string} Apple Music URL
 */
export function getAppleMusicUrl(song) {
    if (!song) return '';
    const query = encodeURIComponent(`${song.title} ${song.artist}`);
    return `https://music.apple.com/jp/search?term=${query}`;
}

/**
 * YouTube Music URLを生成
 * @param {Object} song - 楽曲情報 {title, artist}
 * @returns {string} YouTube Music URL
 */
export function getYouTubeMusicUrl(song) {
    if (!song) return '';
    const query = encodeURIComponent(`${song.title} ${song.artist}`);
    return `https://music.youtube.com/search?q=${query}`;
}

/**
 * Amazon Music URLを生成
 * @param {Object} song - 楽曲情報 {title, artist}
 * @returns {string} Amazon Music URL
 */
export function getAmazonMusicUrl(song) {
    if (!song) return '';
    // URLに使えない特殊文字を除去し、スペースを+に変換
    const searchText = `${song.title} ${song.artist}`
        .replace(/[/\\?#%&=:@!$'()*+,;[\]{}|^`<>"]/g, ' ')
        .trim()
        .replace(/\s+/g, '+');
    return `https://music.amazon.co.jp/search/${searchText}`;
}

/**
 * LINE MUSIC URLを生成
 * @param {Object} song - 楽曲情報 {title, artist}
 * @returns {string} LINE MUSIC URL
 */
export function getLineMusicUrl(song) {
    if (!song) return '';
    // URLに使えない特殊文字を除去してからエンコード
    const searchText = `${song.title} ${song.artist}`
        .replace(/[/\\?#%&=:@!$'()*+,;[\]{}|^`<>"]/g, ' ')
        .trim()
        .replace(/\s+/g, ' ');
    const query = encodeURIComponent(searchText);
    return `https://music.line.me/webapp/search/tracks?query=${query}`;
}
