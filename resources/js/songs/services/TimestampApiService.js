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
     * @param {string} params.song_id - 楽曲IDフィルター（特定の楽曲に紐づくTSのみ取得）
     * @returns {Promise<Object>} タイムスタンプ一覧データ
     */
    async fetchTimestamps({ page = 1, per_page = 50, search = '', filter = 'all', song_id = null }) {
        const params = { page, per_page, search, filter };
        if (song_id) {
            params.song_id = song_id;
        }
        const response = await axios.get('/api/songs/timestamps', { params });
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
     * @param {string} [text] - 元のテキスト（正規化前）
     * @returns {Promise<Object>} レスポンスデータ
     */
    async markAsNotSong(normalizedText, text = null) {
        const data = {};
        if (normalizedText) {
            data.normalized_text = normalizedText;
        }
        if (text) {
            data.text = text;
        }
        const response = await axios.post('/api/songs/mark-not-song', data);
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

    /**
     * タイムスタンプを「保留」状態にする
     * @param {string} normalizedText - 正規化されたテキスト
     * @returns {Promise<Object>} レスポンスデータ
     */
    async markAsPending(normalizedText) {
        const response = await axios.post('/api/songs/mark-pending', {
            normalized_text: normalizedText
        });
        return response.data;
    }

    /**
     * 特定のタイムスタンプに個別で楽曲を紐づける
     * @param {string} tsItemId - タイムスタンプID
     * @param {string} songId - 楽曲ID
     * @returns {Promise<Object>} レスポンスデータ
     */
    async linkTsItemToSong(tsItemId, songId) {
        const response = await axios.post('/api/songs/link-ts-item', {
            ts_item_id: tsItemId,
            song_id: songId
        });
        return response.data;
    }

    /**
     * 特定のタイムスタンプの個別マッピングを解除
     * @param {string} tsItemId - タイムスタンプID
     * @returns {Promise<Object>} レスポンスデータ
     */
    async unlinkTsItem(tsItemId) {
        const response = await axios.delete('/api/songs/unlink-ts-item', {
            data: { ts_item_id: tsItemId }
        });
        return response.data;
    }

    /**
     * 同じnormalized_textを持つタイムスタンプの情報を取得
     * @param {string} normalizedText - 正規化されたテキスト
     * @returns {Promise<Object>} タイムスタンプ情報
     */
    async getTsItemsByNormalizedText(normalizedText) {
        const response = await axios.get('/api/songs/ts-items-by-normalized-text', {
            params: { normalized_text: normalizedText }
        });
        return response.data;
    }
}

// シングルトンインスタンスをエクスポート
export const timestampApiService = new TimestampApiService();
