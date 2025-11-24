import toast from '../utils/toast.js';

// 報告タイプのラベルマッピング
const REPORT_TYPE_LABELS = {
    wrong_song: '曲が違う',
    not_song: '楽曲ではない',
    not_timestamp: 'タイムスタンプが間違っている',
    problem: '問題がある',
    other: 'その他'
};

/**
 * 報告管理コンポーネント
 */
function reportManagement() {
    return {
        reports: [],
        loading: false,
        error: null,
        statusFilter: 'pending',
        currentPage: 1,
        lastPage: 1,
        total: 0,
        processingId: null,

        async init() {
            await this.fetchReports(1);
        },

        async fetchReports(page = 1) {
            this.loading = true;
            this.error = null;

            try {
                const params = new URLSearchParams({ page });
                if (this.statusFilter) {
                    params.set('status', this.statusFilter);
                }

                const response = await fetch(`/api/manage/timestamp-reports?${params}`);
                if (!response.ok) {
                    throw new Error('報告の取得に失敗しました');
                }

                const data = await response.json();
                this.reports = data.data || [];
                this.currentPage = data.current_page || 1;
                this.lastPage = data.last_page || 1;
                this.total = data.total || 0;
            } catch (error) {
                console.error('報告の取得に失敗:', error);
                this.error = '報告の読み込みに失敗しました。ページを再読み込みしてください。';
            } finally {
                this.loading = false;
            }
        },

        async resolveReport(reportId) {
            if (this.processingId) return;
            this.processingId = reportId;

            try {
                const response = await fetch(`/api/manage/timestamp-reports/${reportId}/resolve`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                if (!response.ok) {
                    throw new Error('対応済みマークに失敗しました');
                }

                const data = await response.json();
                toast.success(data.message || '対応済みにしました');

                // リストを更新
                const index = this.reports.findIndex(r => r.id === reportId);
                if (index !== -1) {
                    this.reports[index].status = 'resolved';
                    this.reports[index].resolved_at = new Date().toISOString();
                }
            } catch (error) {
                console.error('対応済みマークに失敗:', error);
                toast.error('対応済みマークに失敗しました');
            } finally {
                this.processingId = null;
            }
        },

        async markAsNotSong(report) {
            if (this.processingId || !report.ts_item) return;
            this.processingId = report.id;

            try {
                // 楽曲ではない判定API
                const response = await fetch('/api/songs/mark-not-song', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        text: report.ts_item.text
                    }),
                });

                if (!response.ok) {
                    throw new Error('「楽曲ではない」判定に失敗しました');
                }

                toast.success('「楽曲ではない」に設定しました');

                // 報告も対応済みにする
                await this.resolveReport(report.id);
            } catch (error) {
                console.error('「楽曲ではない」判定に失敗:', error);
                toast.error('「楽曲ではない」判定に失敗しました');
                this.processingId = null;
            }
        },

        getReportTypeLabel(type) {
            return REPORT_TYPE_LABELS[type] || type;
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleString('ja-JP', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    };
}

// グローバルに登録
window.reportManagement = reportManagement;
