/**
 * YouTube関連のユーティリティ
 */

/**
 * YouTube動画のURLを生成
 * @param {string} videoId - YouTube動画ID
 * @param {number} [tsNum=0] - タイムスタンプ（秒）
 * @returns {string} YouTube URL
 */
export function getYoutubeUrl(videoId, tsNum = 0) {
    const safeVideoId = encodeURIComponent(videoId || '');
    const safeTsNum = parseInt(tsNum) || 0;
    return `https://youtu.be/${safeVideoId}?t=${safeTsNum}s`;
}

/**
 * YouTube動画IDの妥当性を検証
 * @param {string} videoId - YouTube動画ID
 * @returns {boolean} 妥当な場合true
 */
export function isValidVideoId(videoId) {
    if (!videoId) return false;
    // YouTubeのvideoIDは11文字の英数字とハイフン、アンダースコア
    return /^[a-zA-Z0-9_-]{11}$/.test(videoId);
}
