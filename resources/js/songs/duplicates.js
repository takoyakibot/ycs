const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function registerDuplicatesComponent() {
    Alpine.data('duplicatesApp', () => ({
        groups: [],
        groupsLoading: false,
        groupFilter: 'active',
        groupSearch: '',
        groupSelectedIds: {},
        groupTargetId: {},
        groupMerging: {},
        activeGroupHash: null,

        message: null,
        messageType: 'success',

        async init() {
            await this.fetchDuplicates();
        },

        async fetchDuplicates({ preserveState = false } = {}) {
            this.groupsLoading = true;

            let prevByTitle = {};
            let prevActiveTitle = null;
            if (preserveState) {
                for (const group of this.groups) {
                    prevByTitle[group.normalized_title] = {
                        selectedIds: this.groupSelectedIds[group.song_ids_hash] || [],
                        targetId: this.groupTargetId[group.song_ids_hash],
                    };
                    if (group.song_ids_hash === this.activeGroupHash) {
                        prevActiveTitle = group.normalized_title;
                    }
                }
            }

            const scrollContainer = this.$root?.querySelector('.overflow-y-auto');
            const scrollTop = preserveState && scrollContainer ? scrollContainer.scrollTop : 0;

            try {
                const params = new URLSearchParams({ filter: this.groupFilter });
                if (this.groupSearch.trim()) params.set('search', this.groupSearch);
                const res = await fetch(`/api/songs/duplicates?${params}`);
                if (!res.ok) throw new Error('重複グループの取得に失敗しました');
                this.groups = await res.json();
                this.groupSelectedIds = {};
                this.groupTargetId = {};
                this.activeGroupHash = null;
                for (const group of this.groups) {
                    const songIds = group.songs.map(s => s.id);
                    const prev = prevByTitle[group.normalized_title];
                    if (preserveState && prev) {
                        this.groupSelectedIds[group.song_ids_hash] = prev.selectedIds.filter(id => songIds.includes(id));
                        if (prev.targetId && songIds.includes(prev.targetId)) {
                            this.groupTargetId[group.song_ids_hash] = prev.targetId;
                        }
                    } else {
                        this.groupSelectedIds[group.song_ids_hash] = songIds;
                    }
                    if (prevActiveTitle && group.normalized_title === prevActiveTitle) {
                        this.activeGroupHash = group.song_ids_hash;
                    }
                }

                if (preserveState && scrollTop > 0) {
                    this.$nextTick(() => {
                        const container = this.$root?.querySelector('.overflow-y-auto');
                        if (container) container.scrollTop = scrollTop;
                    });
                }
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

        isAllSelected(group) {
            const hash = group.song_ids_hash;
            const selected = this.groupSelectedIds[hash] || [];
            return selected.length === group.songs.length;
        },

        toggleSelectAll(group) {
            const hash = group.song_ids_hash;
            if (this.isAllSelected(group)) {
                this.groupSelectedIds[hash] = [];
                delete this.groupTargetId[hash];
            } else {
                this.groupSelectedIds[hash] = group.songs.map(s => s.id);
            }
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
                await this.fetchDuplicates({ preserveState: true });
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

    }));
}

if (typeof Alpine !== 'undefined') {
    registerDuplicatesComponent();
} else {
    document.addEventListener('alpine:init', registerDuplicatesComponent);
}
