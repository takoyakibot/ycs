const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function registerTitleGroupsComponent() {
    Alpine.data('titleGroupsApp', () => ({
        groupFilter: 'active',
        groups: [],
        groupsLoading: false,
        groupSearch: '',
        selectedIds: {},
        targetId: {},
        merging: {},

        message: null,
        messageType: 'success',

        async init() {
            await this.fetchTitleGroups();
        },

        async fetchTitleGroups() {
            this.groupsLoading = true;
            try {
                const params = new URLSearchParams({ filter: this.groupFilter });
                if (this.groupSearch.trim()) params.set('search', this.groupSearch);
                const res = await fetch(`/api/songs/cleansing/title-groups?${params}`);
                if (!res.ok) throw new Error('グループの取得に失敗しました');
                this.groups = await res.json();
                this.selectedIds = {};
                this.targetId = {};
                for (const group of this.groups) {
                    const key = this.groupKey(group);
                    this.selectedIds[key] = group.songs.map(s => s.id);
                }
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.groupsLoading = false;
            }
        },

        setFilter(filter) {
            this.groupFilter = filter;
            this.fetchTitleGroups();
        },

        groupKey(group) {
            return group.song_ids_hash;
        },

        toggleSelect(groupKey, songId) {
            if (!this.selectedIds[groupKey]) this.selectedIds[groupKey] = [];
            const idx = this.selectedIds[groupKey].indexOf(songId);
            if (idx === -1) {
                this.selectedIds[groupKey].push(songId);
            } else {
                this.selectedIds[groupKey].splice(idx, 1);
                if (this.targetId[groupKey] === songId) {
                    delete this.targetId[groupKey];
                }
            }
        },

        isSelected(groupKey, songId) {
            return (this.selectedIds[groupKey] || []).includes(songId);
        },

        setTarget(groupKey, songId) {
            this.targetId[groupKey] = songId;
        },

        canMerge(groupKey) {
            const selected = this.selectedIds[groupKey] || [];
            return selected.length >= 2 && this.targetId[groupKey] && selected.includes(this.targetId[groupKey]);
        },

        async mergeGroup(group) {
            const groupKey = this.groupKey(group);
            if (!this.canMerge(groupKey)) return;

            const target = group.songs.find(s => s.id === this.targetId[groupKey]);
            const sources = this.selectedIds[groupKey].filter(id => id !== this.targetId[groupKey]);

            if (!confirm(`「${target.artist || '(アーティスト未設定)'}」に統合します。${sources.length}件の楽曲が削除されます。よろしいですか？`)) {
                return;
            }

            this.merging[groupKey] = true;
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
                            target_song_id: this.targetId[groupKey],
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
                this.merging[groupKey] = false;
                await this.fetchTitleGroups();
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
                this.groups = this.groups.filter(g => this.groupKey(g) !== this.groupKey(group));
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            }
        },
    }));
}

if (typeof Alpine !== 'undefined') {
    registerTitleGroupsComponent();
} else {
    document.addEventListener('alpine:init', registerTitleGroupsComponent);
}
