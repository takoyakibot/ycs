const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function registerArtistRenameComponent() {
    Alpine.data('artistRenameApp', () => ({
        artists: [],
        filteredArtists: [],
        search: '',
        loading: false,

        selectedArtist: null,
        selectedSongs: [],
        songsLoading: false,

        renameTo: '',
        suggestions: [],
        showSuggestions: false,
        highlightIndex: -1,

        previewing: false,
        previewResult: null,
        executing: false,
        message: '',
        messageType: '',

        async init() {
            await this.fetchArtists();
        },

        async fetchArtists() {
            this.loading = true;
            try {
                const res = await fetch('/api/songs/artists-with-count');
                if (!res.ok) throw new Error();
                this.artists = await res.json();
                this.applyFilter();
            } catch {
                this.showMessage('アーティスト一覧の取得に失敗しました', 'error');
            } finally {
                this.loading = false;
            }
        },

        applyFilter() {
            if (!this.search.trim()) {
                this.filteredArtists = this.artists;
                return;
            }
            const q = this.search.toLowerCase();
            this.filteredArtists = this.artists.filter(a =>
                a.name && a.name.toLowerCase().includes(q)
            );
        },

        async selectArtist(name) {
            if (this.selectedArtist === name) return;
            this.selectedArtist = name;
            this.renameTo = '';
            this.previewResult = null;
            this.message = '';
            this.showSuggestions = false;
            await this.fetchSongsForArtist(name);
        },

        async fetchSongsForArtist(artist) {
            this.songsLoading = true;
            this.selectedSongs = [];
            try {
                const params = new URLSearchParams({ artist });
                const res = await fetch(`/api/songs/by-artist?${params}`);
                if (!res.ok) throw new Error();
                this.selectedSongs = await res.json();
            } catch {
                this.showMessage('楽曲一覧の取得に失敗しました', 'error');
            } finally {
                this.songsLoading = false;
            }
        },

        updateSuggestions() {
            this.highlightIndex = -1;
            const q = this.renameTo.trim().toLowerCase();
            if (!q) {
                this.suggestions = [];
                this.showSuggestions = false;
                return;
            }
            this.suggestions = this.artists
                .map(a => a.name)
                .filter(a => a && a.toLowerCase().includes(q) && a !== this.selectedArtist)
                .slice(0, 20);
            this.showSuggestions = this.suggestions.length > 0;
        },

        selectSuggestion(name) {
            this.renameTo = name;
            this.showSuggestions = false;
        },

        async preview() {
            const from = this.selectedArtist;
            const to = this.renameTo.trim();
            if (!from || !to) return;
            if (from === to) {
                this.showMessage('変更前と変更後が同じです', 'error');
                return;
            }
            this.previewing = true;
            this.previewResult = null;
            this.message = '';
            try {
                const params = new URLSearchParams({ from, to });
                const res = await fetch(`/api/songs/cleansing/artist-rename-preview?${params}`);
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || 'プレビューに失敗しました');
                }
                const result = await res.json();
                if (result.plan.length === 0) {
                    this.showMessage(`「${from}」に一致する楽曲がありません`, 'error');
                } else {
                    this.previewResult = result;
                }
            } catch (e) {
                this.showMessage(e.message, 'error');
            } finally {
                this.previewing = false;
            }
        },

        async execute() {
            if (!this.previewResult) return;
            const { rename_count, merge_count } = this.previewResult;
            const msg = merge_count > 0
                ? `${rename_count}件のリネームと${merge_count}件の統合を実行します。よろしいですか？`
                : `${rename_count}件のアーティスト名を変更します。よろしいですか？`;
            if (!confirm(msg)) return;
            this.executing = true;
            try {
                const res = await fetch('/api/songs/cleansing/artist-rename', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ from: this.selectedArtist, to: this.renameTo.trim() }),
                });
                if (!res.ok) {
                    const data = await res.json();
                    throw new Error(data.message || '変更に失敗しました');
                }
                const data = await res.json();
                this.showMessage(data.message || 'アーティスト名を変更しました', 'success');
                this.previewResult = null;
                this.selectedArtist = null;
                this.selectedSongs = [];
                this.renameTo = '';
                await this.fetchArtists();
            } catch (e) {
                this.showMessage(e.message, 'error');
            } finally {
                this.executing = false;
            }
        },

        showMessage(text, type) {
            this.message = text;
            this.messageType = type;
        },
    }));
}

if (typeof Alpine !== 'undefined') {
    registerArtistRenameComponent();
} else {
    document.addEventListener('alpine:init', registerArtistRenameComponent);
}
