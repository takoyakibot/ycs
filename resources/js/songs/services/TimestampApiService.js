import axios from 'axios';

/**
 * タイムスタンプAPI通信サービス
 */
export class TimestampApiService {
    /**
     * タイムスタンプ一覧を取得
     * @param {Object} params - 取得パラメータ
     * @param {number} params.page - ページ番号
     * @param {number} params.per_page - 1ページあたりの件数
     * @param {string} params.search - 検索クエリ
     * @param {string} params.filter - フィルター (all, unlinked, linked, not_song)
     * @returns {Promise<Object>} タイムスタンプ一覧データ
     */
    async fetchTimestamps({ page = 1, per_page = 50, search = '', filter = 'all' }) {
        const response = await axios.get('/api/songs/timestamps', {
            params: { page, per_page, search, filter }
        });
        return response.data;
    }

    /**
     * タイムスタンプと楽曲を紐づける
     * @param {string} normalizedText - 正規化されたテキスト
     * @param {string} songId - 楽曲ID
     * @returns {Promise<Object>} レスポンスデータ
     */
    async linkTimestamp(normalizedText, songId) {
        const response = await axios.post('/api/songs/link', {
            normalized_text: normalizedText,
            song_id: songId
        });
        return response.data;
    }

    /**
     * タイムスタンプを「楽曲ではない」とマーク
     * @param {string} normalizedText - 正規化されたテキスト
     * @returns {Promise<Object>} レスポンスデータ
     */
    async markAsNotSong(normalizedText) {
        const response = await axios.post('/api/songs/mark-not-song', {
            normalized_text: normalizedText
        });
        return response.data;
    }

    /**
     * 「楽曲ではない」マークを解除
     * @param {string} normalizedText - 正規化されたテキスト
     * @returns {Promise<Object>} レスポンスデータ
     */
    async unmarkAsNotSong(normalizedText) {
        const response = await axios.post('/api/songs/unmark-not-song', {
            normalized_text: normalizedText
        });
        return response.data;
    }

    /**
     * タイムスタンプの紐づけを解除
     * @param {string} normalizedText - 正規化されたテキスト
     * @returns {Promise<Object>} レスポンスデータ
     */
    async unlinkTimestamp(normalizedText) {
        const response = await axios.delete('/api/songs/unlink', {
            data: { normalized_text: normalizedText }
        });
        return response.data;
    }
}

// シングルトンインスタンスをエクスポート
export const timestampApiService = new TimestampApiService();
