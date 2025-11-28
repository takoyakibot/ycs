/**
 * タイムスタンプ報告サービス
 */
export class ReportService {
    /**
     * タイムスタンプの問題を報告
     * @param {Object} reportData - 報告データ
     * @param {string} reportData.ts_item_id - タイムスタンプアイテムID
     * @param {string} reportData.video_id - 動画ID
     * @param {string} reportData.report_type - 報告タイプ
     * @param {string|null} reportData.comment - コメント
     * @returns {Promise<{success: boolean, message: string}>} 送信結果
     */
    static async submitReport(reportData) {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            if (!csrfToken) {
                throw new Error('CSRFトークンが見つかりません');
            }

            const response = await fetch('/api/timestamp-reports', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(reportData),
            });

            const data = await response.json();

            if (response.ok) {
                return {
                    success: true,
                    message: data.message || '報告を受け付けました。ご協力ありがとうございます。'
                };
            }

            if (response.status === 429) {
                return {
                    success: false,
                    message: data.message || '報告の送信制限中です。しばらくしてから再度お試しください。'
                };
            }

            return {
                success: false,
                message: data.message || '報告の送信に失敗しました。'
            };
        } catch (error) {
            console.error('報告の送信に失敗しました:', error);
            return {
                success: false,
                message: '報告の送信に失敗しました。時間をおいて再度お試しください。'
            };
        }
    }
}
