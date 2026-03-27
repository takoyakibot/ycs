document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('duplicates-app');
    if (!app) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    const state = Alpine.reactive({
        groups: [],
        search: '',
        loading: false,
        merging: false,
        message: null,
        messageType: 'success',
    });

    Alpine.data('duplicatesApp', () => ({
        ...state,

        async init() {
            await this.fetchDuplicates();
        },

        async fetchDuplicates() {
            this.loading = true;
            this.message = null;
            try {
                const params = new URLSearchParams();
                if (this.search) params.set('search', this.search);
                const res = await fetch(`/api/songs/duplicates?${params}`);
                if (!res.ok) throw new Error('取得に失敗しました');
                this.groups = await res.json();
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.loading = false;
            }
        },

        async merge(group, targetSong) {
            const sourceSongs = group.songs.filter(s => s.id !== targetSong.id);
            if (sourceSongs.length === 0) return;

            const confirmed = confirm(
                `「${targetSong.title}」に統合します。${sourceSongs.length}件の楽曲が削除されます。よろしいですか？`
            );
            if (!confirmed) return;

            this.merging = true;
            this.message = null;

            try {
                for (const source of sourceSongs) {
                    const res = await fetch('/api/songs/merge', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            source_song_id: source.id,
                            target_song_id: targetSong.id,
                        }),
                    });
                    if (!res.ok) {
                        const data = await res.json();
                        throw new Error(data.message || 'マージに失敗しました');
                    }
                }
                this.message = 'マージが完了しました';
                this.messageType = 'success';
                await this.fetchDuplicates();
            } catch (e) {
                this.message = e.message;
                this.messageType = 'error';
            } finally {
                this.merging = false;
            }
        },

        totalRefs(song) {
            return song.mappings_count + song.ts_items_count;
        },
    }));
});
