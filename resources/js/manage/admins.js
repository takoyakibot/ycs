import toast from '../utils/toast.js';

/**
 * 管理者管理コンポーネント
 * Alpine.jsコンポーネント登録
 */
function registerAdminManagementComponent() {
    if (typeof Alpine !== 'undefined') {
        Alpine.data('adminManagement', function() {
            return {
                admins: [],
                loading: false,
                error: null,
                successMessage: null,
                newAdminEmail: '',
                processing: false,
                removingId: null,

                async init() {
                    await this.fetchAdmins();
                },

                async fetchAdmins() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const response = await fetch('/api/manage/admins');
                        if (!response.ok) {
                            throw new Error('管理者一覧の取得に失敗しました');
                        }

                        const data = await response.json();
                        this.admins = data.data || [];
                    } catch (error) {
                        console.error('管理者一覧の取得に失敗:', error);
                        this.error = '管理者一覧の読み込みに失敗しました。ページを再読み込みしてください。';
                    } finally {
                        this.loading = false;
                    }
                },

                async addAdmin() {
                    if (this.processing || !this.newAdminEmail) return;
                    this.processing = true;
                    this.error = null;
                    this.successMessage = null;

                    try {
                        const response = await fetch('/api/manage/admins', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                email: this.newAdminEmail
                            }),
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || '管理者の追加に失敗しました');
                        }

                        toast.success(data.message || '管理者を追加しました');
                        this.successMessage = data.message;
                        this.newAdminEmail = '';

                        // 管理者一覧を更新
                        await this.fetchAdmins();

                        // 成功メッセージを3秒後に消す
                        setTimeout(() => {
                            this.successMessage = null;
                        }, 3000);
                    } catch (error) {
                        console.error('管理者の追加に失敗:', error);
                        this.error = error.message || '管理者の追加に失敗しました';
                        toast.error(error.message || '管理者の追加に失敗しました');
                    } finally {
                        this.processing = false;
                    }
                },

                async removeAdmin(adminId, adminName) {
                    if (this.removingId) return;

                    if (!confirm(`${adminName} さんの管理者権限を削除してよろしいですか？`)) {
                        return;
                    }

                    this.removingId = adminId;
                    this.error = null;
                    this.successMessage = null;

                    try {
                        const response = await fetch(`/api/manage/admins/${adminId}`, {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });

                        const data = await response.json();

                        if (!response.ok) {
                            throw new Error(data.message || '管理者権限の削除に失敗しました');
                        }

                        toast.success(data.message || '管理者権限を削除しました');
                        this.successMessage = data.message;

                        // 管理者一覧から削除
                        this.admins = this.admins.filter(a => a.id !== adminId);

                        // 成功メッセージを3秒後に消す
                        setTimeout(() => {
                            this.successMessage = null;
                        }, 3000);
                    } catch (error) {
                        console.error('管理者権限の削除に失敗:', error);
                        this.error = error.message || '管理者権限の削除に失敗しました';
                        toast.error(error.message || '管理者権限の削除に失敗しました');
                    } finally {
                        this.removingId = null;
                    }
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
        });
    }
}

// Alpine.jsが既に読み込まれている場合はすぐに登録
if (typeof Alpine !== 'undefined') {
    registerAdminManagementComponent();
} else {
    // Alpine.jsの初期化を待つ
    window.addEventListener('alpine:init', registerAdminManagementComponent);
}
