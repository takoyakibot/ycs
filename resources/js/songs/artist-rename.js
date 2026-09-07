import Alpine from 'alpinejs';

Alpine.data('artistRenameApp', () => ({
    _allArtistsData: [],
    filteredArtists: [],
    search: '',
    loading: false,
    selectedArtist: null,
    renameTo: '',
    allArtistNames: [],
    suggestions: [],
    showSuggestions: false,
    highlightIndex: -1,
    previewing: false,
    previewResult: null,
    executing: false,
    message: '',
    messageType: '',

    init() {
        this.fetchArtists();
    },

    async fetchArtists() {
        this.loading = true;
        try {
            const res = await fetch('/api/songs/artists-with-count');
            if (!res.ok) throw new Error();
            this._allArtistsData = await res.json();
            this.allArtistNames = this._allArtistsData.map(a => a.name);
            this.applyFilter();
        } catch {
            this.showMessage('アーティスト一覧の取得に失敗しました', 'error');
        } finally {
            this.loading = false;
        }
    },

    applyFilter() {
        if (!this.search.trim()) {
            this.filteredArtists = this._allArtistsData;
            return;
        }
        const q = this.search.toLowerCase();
        this.filteredArtists = this._allArtistsData.filter(a =>
            a.name.toLowerCase().includes(q)
        );
    },

    selectArtist(name) {
        this.selectedArtist = name;
        this.renameTo = '';
        this.previewResult = null;
        this.message = '';
    },

    updateSuggestions() {
        this.highlightIndex = -1;
        const q = this.renameTo.trim().toLowerCase();
        if (!q) {
            this.suggestions = [];
            this.showSuggestions = false;
            return;
        }
        this.suggestions = this.allArtistNames
            .filter(a => a.toLowerCase().includes(q) && a !== this.selectedArtist)
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
            this.showMessage('変換前と変換後が同じです', 'error');
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
                throw new Error(data.message || 'プレビューの取得に失敗しました');
            }
            const result = await res.json();
            if (result.plan.length === 0) {
                this.showMessage(`「${from}」に一致する楽曲マスタがありません`, 'error');
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
            ? `${rename_count}件をリネーム、${merge_count}件を統合します。統合される側の元マスタは削除されます。よろしいですか？`
            : `${rename_count}件をリネームします。よろしいですか？`;
        if (!confirm(msg)) return;
        this.executing = true;
        try {
            const res = await fetch('/api/songs/cleansing/artist-rename', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify({ from: this.selectedArtist, to: this.renameTo.trim() }),
            });
            if (!res.ok) {
                const data = await res.json();
                throw new Error(data.message || '変換に失敗しました');
            }
            const data = await res.json();
            this.showMessage(data.message || 'アーティスト名を変更しました', 'success');
            this.previewResult = null;
            this.selectedArtist = null;
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
