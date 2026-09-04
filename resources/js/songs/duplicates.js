const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function registerDuplicatesComponent() {
    Alpine.data('duplicatesApp', () => ({
        // 左ペイン: 重複グループ
        groups: [],
        groupsLoading: false,
        groupFilter: 'active',
        groupSearch: '',
        groupSelectedIds: {},
        groupTargetId: {},
        groupMerging: {},
        activeGroupHash: null,

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
                const params = new URLSearchParams({ filter: this.groupFilter });
                if (this.groupSearch.trim()) params.set('search', this.groupSearch);
                const res = await fetch(`/api/songs/duplicates?${params}`);
                if (!res.ok) throw new Error('重複グループの取得に失敗しました');
                this.groups = await res.json();
                this.groupSelectedIds = {};
                this.groupTargetId = {};
                this.activeGroupHash = null;
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.groupsLoading = false;
            }
        },

        setGroupFilter(filter) {
            this.groupFilter = filter;
            this.fetchDuplicates();
        },

        selectGroup(group) {
            this.activeGroupHash = group.song_ids_hash;
            const title = group.songs[0]?.title || group.normalized_title;
            this.search = title;
            this.doSearch();
        },

        toggleGroupSelect(hash, songId) {
            if (!this.groupSelectedIds[hash]) this.groupSelectedIds[hash] = [];
            const idx = this.groupSelectedIds[hash].indexOf(songId);
            if (idx === -1) {
                this.groupSelectedIds[hash].push(songId);
            } else {
                this.groupSelectedIds[hash].splice(idx, 1);
                if (this.groupTargetId[hash] === songId) {
                    delete this.groupTargetId[hash];
                }
            }
        },

        isGroupSelected(hash, songId) {
            return (this.groupSelectedIds[hash] || []).includes(songId);
        },

        setGroupTarget(hash, songId) {
            this.groupTargetId[hash] = songId;
        },

        canMergeGroup(hash) {
            const selected = this.groupSelectedIds[hash] || [];
            return selected.length >= 2 && this.groupTargetId[hash] && selected.includes(this.groupTargetId[hash]);
        },

        async mergeGroup(group) {
            const hash = group.song_ids_hash;
            if (!this.canMergeGroup(hash)) return;

            const target = group.songs.find(s => s.id === this.groupTargetId[hash]);
            const sources = this.groupSelectedIds[hash].filter(id => id !== this.groupTargetId[hash]);

            if (!confirm(`「${target.title} / ${target.artist || '(アーティスト未設定)'}」に統合します。${sources.length}件の楽曲が削除されます。よろしいですか？`)) {
                return;
            }

            this.groupMerging[hash] = true;
            this.message = null;

            try {
                let completed = 0;
                for (const sourceId of sources) {
                    const res = await fetch('/api/songs/merge', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            source_song_id: sourceId,
                            target_song_id: this.groupTargetId[hash],
                        }),
                    });
                    if (!res.ok) {
                        const data = await res.json();
                        const base = data.message || 'マージに失敗しました';
                        throw new Error(completed > 0
                            ? `${base}（${completed}/${sources.length}件は統合済み）`
                            : base);
                    }
                    completed++;
                }
                this.message = `${sources.length}件の楽曲を統合しました`;
                this.messageType = 'success';
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.groupMerging[hash] = false;
                await this.fetchDuplicates();
            }
        },

        async reviewGroup(group, decision) {
            const label = decision === 'pending' ? '保留' : '別の曲';
            if (!confirm(`このグループを「${label}」として記録します。よろしいですか？`)) return;

            this.message = null;
            try {
                const res = await fetch('/api/songs/cleansing/title-groups/review', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        normalized_title: group.normalized_title,
                        song_ids: group.songs.map(s => s.id),
                        decision,
                    }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || '記録に失敗しました');
                }
                const data = await res.json();
                this.message = data.message;
                this.messageType = 'success';
                this.groups = this.groups.filter(g => g.song_ids_hash !== group.song_ids_hash);
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            }
        },

        // --- 右ペイン: 検索・選択・マージ ---

        clearSearch() {
            this.search = '';
            this.searchResults = [];
            this.selectedIds = [];
            this.targetId = null;
            this.message = null;
        },

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
                this.selectedIds = this.searchResults.map(s => s.id);
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

                await Promise.all([this.fetchDuplicates(), this.doSearch()]);
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.merging = false;
            }
        },
    }));
}

if (typeof Alpine !== 'undefined') {
    registerDuplicatesComponent();
} else {
    document.addEventListener('alpine:init', registerDuplicatesComponent);
}
