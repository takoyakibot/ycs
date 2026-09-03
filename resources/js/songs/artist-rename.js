const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function registerArtistRenameComponent() {
    Alpine.data('artistRenameApp', () => ({
        renameFrom: '',
        renameTo: '',
        renamePlan: null,
        renamePreviewing: false,
        renameExecuting: false,

        message: null,
        messageType: 'success',

        async previewRename() {
            if (!this.renameFrom.trim() || !this.renameTo.trim()) return;
            this.renamePreviewing = true;
            this.renamePlan = null;
            this.message = null;
            try {
                const params = new URLSearchParams({ from: this.renameFrom, to: this.renameTo });
                const res = await fetch(`/api/songs/cleansing/artist-rename-preview?${params}`);
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || 'プレビューの取得に失敗しました');
                }
                this.renamePlan = await res.json();
                if (this.renamePlan.plan.length === 0) {
                    this.message = `「${this.renameFrom}」に一致する楽曲マスタがありません`;
                    this.messageType = 'error';
                }
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.renamePreviewing = false;
            }
        },

        async executeRename() {
            if (!this.renamePlan || this.renamePlan.plan.length === 0) return;

            const { rename_count, merge_count } = this.renamePlan;
            const confirmMessage = merge_count > 0
                ? `${rename_count}件をリネーム、${merge_count}件を統合します。統合される側の元マスタは削除されます。よろしいですか？`
                : `${rename_count}件をリネームします。よろしいですか？`;

            if (!confirm(confirmMessage)) return;

            this.renameExecuting = true;
            this.message = null;

            try {
                const res = await fetch('/api/songs/cleansing/artist-rename', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ from: this.renameFrom, to: this.renameTo }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || '変換に失敗しました');
                }
                const data = await res.json();
                this.message = data.message;
                this.messageType = 'success';
                this.renamePlan = null;
                this.renameFrom = '';
                this.renameTo = '';
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.renameExecuting = false;
            }
        },
    }));
}

if (typeof Alpine !== 'undefined') {
    registerArtistRenameComponent();
} else {
    document.addEventListener('alpine:init', registerArtistRenameComponent);
}
