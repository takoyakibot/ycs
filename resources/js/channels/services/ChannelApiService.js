/**
 * チャンネルAPI通信サービス
 */
export class ChannelApiService {
    /**
     * アーカイブ一覧を取得
     * @param {string} channelHandle - チャンネルハンドル
     * @param {Object} params - クエリパラメータ
     * @returns {Promise<Object>} アーカイブ一覧データ
     */
    static async fetchArchives(channelHandle, params = {}) {
        const urlParams = new URLSearchParams(params);
        const response = await fetch(`/api/channels/${channelHandle}?${urlParams}`);
        if (!response.ok) {
            throw new Error('アーカイブの取得に失敗しました');
        }
        return response.json();
    }

    /**
     * タイムスタンプ一覧を取得
     * @param {string} channelHandle - チャンネルハンドル
     * @param {Object} options - 取得オプション
     * @param {number} options.page - ページ番号
     * @param {number} options.per_page - 1ページあたりの件数
     * @param {string} options.search - 検索クエリ
     * @param {string} options.index - インデックスフィルター
     * @returns {Promise<Object>} タイムスタンプ一覧データ
     */
    static async fetchTimestamps(channelHandle, { page = 1, per_page = 50, search = '', index = '' } = {}) {
        const params = new URLSearchParams({ page, per_page });

        if (search) {
            params.set('search', search);
        }

        if (index) {
            params.set('index', index);
        }

        const response = await fetch(`/api/channels/${channelHandle}/timestamps?${params}`);
        if (!response.ok) {
            throw new Error('タイムスタンプの取得に失敗しました');
        }

        const data = await response.json();

        // ページ番号のバリデーション
        const parsedPage = parseInt(data.current_page, 10);
        data.current_page = Number.isNaN(parsedPage) ? 1 : parsedPage;

        const parsedLastPage = parseInt(data.last_page, 10);
        data.last_page = Number.isNaN(parsedLastPage) ? 1 : parsedLastPage;

        return data;
    }

    /**
     * タイムスタンプをダウンロード用URLを取得
     * @param {string} channelHandle - チャンネルハンドル
     * @returns {string} ダウンロードURL
     */
    static getDownloadUrl(channelHandle) {
        return `/api/channels/${channelHandle}/timestamps/download`;
    }
}
