import axios from 'axios';

/**
 * 楽曲API通信サービス
 */
export class SongApiService {
    /**
     * 楽曲マスタ一覧を取得
     * @param {string} search - 検索クエリ
     * @returns {Promise<Object>} 楽曲一覧データ
     */
    async fetchSongs(search = '') {
        const response = await axios.get('/api/songs', {
            params: { search }
        });
        return response.data;
    }

    /**
     * 楽曲マスタを登録
     * @param {Object} songData - 楽曲データ
     * @param {string} songData.title - 楽曲名
     * @param {string} songData.artist - アーティスト名
     * @param {string} songData.spotify_track_id - Spotify Track ID
     * @param {Object} songData.spotify_data - Spotifyデータ
     * @param {Object} options - オプション
     * @param {boolean} options.force_create - 強制新規登録
     * @param {string} options.use_existing_id - 既存楽曲ID
     * @returns {Promise<Object>} 登録結果
     */
    async createSong(songData, options = {}) {
        const response = await axios.post('/api/songs', {
            ...songData,
            ...options
        });
        return response.data;
    }

    /**
     * 楽曲マスタを削除
     * @param {string} songId - 楽曲ID
     * @returns {Promise<Object>} 削除結果
     */
    async deleteSong(songId) {
        const response = await axios.delete(`/api/songs/${songId}`);
        return response.data;
    }

    /**
     * Spotify APIで楽曲を検索
     * @param {string} query - 検索クエリ
     * @param {number} limit - 取得件数
     * @returns {Promise<Array>} 検索結果
     */
    async searchSpotify(query, limit = 10) {
        const response = await axios.get('/api/songs/search-spotify', {
            params: { query, limit }
        });
        return response.data;
    }
}

// シングルトンインスタンスをエクスポート
export const songApiService = new SongApiService();
