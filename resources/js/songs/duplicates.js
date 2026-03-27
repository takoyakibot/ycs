document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('duplicates-app');
    if (!app) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    Alpine.data('duplicatesApp', () => ({
        // 左ペイン: 重複グループ
        groups: [],
        groupsLoading: false,

        // 右ペイン: 検索結果
        search: '',
        searchResults: [],
        searchLoading: false,
        selectedIds: [],
        targetId: null,

        // 共通
        merging: false,
        message: null,
        messageType: 'success',

        async init() {
            await this.fetchDuplicates();
        },

        // --- 左ペイン: 重複グループ ---

        async fetchDuplicates() {
            this.groupsLoading = true;
            try {
                const res = await fetch('/api/songs/duplicates');
                if (!res.ok) throw new Error('重複グループの取得に失敗しました');
                this.groups = await res.json();
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.groupsLoading = false;
            }
        },

        selectGroup(group) {
            // グループのタイトルで検索
            const title = group.songs[0]?.title || group.normalized_title;
            this.search = title;
            this.doSearch();
        },

        // --- 右ペイン: 検索・選択・マージ ---

        async doSearch() {
            if (!this.search.trim()) return;
            this.searchLoading = true;
            this.searchResults = [];
            this.selectedIds = [];
            this.targetId = null;
            this.message = null;
            try {
                const params = new URLSearchParams({ search: this.search });
                const res = await fetch(`/api/songs/search-for-merge?${params}`);
                if (!res.ok) throw new Error('検索に失敗しました');
                this.searchResults = await res.json();
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.searchLoading = false;
            }
        },

        toggleSelect(songId) {
            const idx = this.selectedIds.indexOf(songId);
            if (idx === -1) {
                this.selectedIds.push(songId);
            } else {
                this.selectedIds.splice(idx, 1);
                // 選択解除されたものがtargetだったらtargetもクリア
                if (this.targetId === songId) {
                    this.targetId = null;
                }
            }
        },

        isSelected(songId) {
            return this.selectedIds.includes(songId);
        },

        setTarget(songId) {
            this.targetId = songId;
        },

        get canMerge() {
            return this.targetId && this.selectedIds.length >= 2 && this.selectedIds.includes(this.targetId);
        },

        get sourceSongs() {
            return this.selectedIds.filter(id => id !== this.targetId);
        },

        async doMerge() {
            if (!this.canMerge) return;

            const target = this.searchResults.find(s => s.id === this.targetId);
            const sourceCount = this.sourceSongs.length;

            if (!confirm(`「${target.title}」に統合します。${sourceCount}件の楽曲が削除されます。よろしいですか？`)) {
                return;
            }

            this.merging = true;
            this.message = null;

            try {
                for (const sourceId of this.sourceSongs) {
                    const res = await fetch('/api/songs/merge', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            source_song_id: sourceId,
                            target_song_id: this.targetId,
                        }),
                    });
                    if (!res.ok) {
                        const data = await res.json();
                        throw new Error(data.message || 'マージに失敗しました');
                    }
                }
                this.message = `${sourceCount}件の楽曲を統合しました`;
                this.messageType = 'success';
                this.selectedIds = [];
                this.targetId = null;

                // 両方更新
                await Promise.all([this.fetchDuplicates(), this.doSearch()]);
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.merging = false;
            }
        },
    }));
});
