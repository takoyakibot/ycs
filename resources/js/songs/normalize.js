import axios from 'axios';
import toast from '../utils/toast.js';
import { CONSTANTS } from './utils/constants.js';
import { timestampApiService } from './services/TimestampApiService.js';
import { songApiService } from './services/SongApiService.js';
import { SimilarSongsDialog } from './components/SimilarSongsDialog.js';
import { Pagination } from '../shared/components/Pagination.js';

// axiosの設定: クロスオリジンリクエストでクッキーを送信
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * タイムスタンプ正規化機能
 */
class TimestampNormalization {
    constructor() {
        this.selectedTimestamps = []; // 複数選択対応
        this.selectedSong = null;
        this.selectedSpotifyTrack = null; // Spotify選択楽曲情報
        this.currentPage = 1;
        this.currentSearchQuery = ''; // 検索条件を保持
        this.searchTimeout = null;
        this.currentFilter = 'all'; // all, unlinked, linked, not_song

        this.init();
    }

    init() {
        this.bindEvents();
        this.loadTimestamps();
        this.showTab('spotifyTab');
        this.updateSelectionDisplay();
    }

    bindEvents() {
        // タイムスタンプ検索
        document.getElementById('timestampSearch').addEventListener('input', (e) => {
            clearTimeout(this.searchTimeout);
            this.currentSearchQuery = e.target.value;
            this.searchTimeout = setTimeout(() => {
                this.loadTimestamps(1, this.currentSearchQuery);
            }, 500);
        });

        // フィルターボタン
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.setFilter(e.target.dataset.filter);
            });
        });

        // 全選択・全選択解除
        document.getElementById('selectAllBtn').addEventListener('click', () => this.selectAll());
        document.getElementById('deselectAllBtn').addEventListener('click', () => this.deselectAll());

        // Spotify検索
        document.getElementById('searchSpotifyBtn').addEventListener('click', () => this.searchSpotify());
        document.getElementById('spotifySearch').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.searchSpotify();
            }
        });

        // Spotify検索クリアボタン
        document.getElementById('clearSpotifySearchBtn').addEventListener('click', () => {
            document.getElementById('spotifySearch').value = '';
            document.getElementById('spotifyTracks').innerHTML = '';
        });

        // 更新ボタン
        document.getElementById('refreshTimestampsBtn').addEventListener('click', () => {
            this.loadTimestamps(this.currentPage, this.currentSearchQuery);
        });

        // 楽曲マスタ一覧表示
        document.getElementById('showSongsBtn').addEventListener('click', () => {
            this.showTab('songsTab');
            this.loadSongs();
        });

        // 手動登録フォーム
        document.getElementById('createSongForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.createSong();
        });

        // 手動登録フォームクリアボタン
        document.getElementById('clearManualFormBtn').addEventListener('click', () => {
            document.getElementById('createSongForm').reset();
        });

        // 楽曲マスタ検索
        document.getElementById('songsSearch').addEventListener('input', (e) => {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.loadSongs(e.target.value);
            }, 500);
        });

        // タブ切り替え
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', (e) => this.showTab(e.target.id));
        });

        // アクション
        document.getElementById('linkSongBtn').addEventListener('click', () => this.linkTimestamps());
        document.getElementById('markAsNotSongBtn').addEventListener('click', () => this.markAsNotSong());
        document.getElementById('unmarkAsNotSongBtn').addEventListener('click', () => this.unmarkAsNotSong());
        document.getElementById('unlinkBtn').addEventListener('click', () => this.unlinkTimestamps());
        document.getElementById('clearSelectionBtn').addEventListener('click', () => this.clearSelection());
    }

    setFilter(filter) {
        this.currentFilter = filter;
        this.updateFilterButtons();
        this.loadTimestamps(1, this.currentSearchQuery);
    }

    updateFilterButtons() {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            const isActive = btn.dataset.filter === this.currentFilter;
            if (isActive) {
                btn.classList.add('bg-blue-600', 'text-white');
                btn.classList.remove('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            } else {
                btn.classList.remove('bg-blue-600', 'text-white');
                btn.classList.add('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            }
        });
    }

    async loadTimestamps(page = 1, search = '') {
        try {
            this.showLoading();
            const data = await timestampApiService.fetchTimestamps({
                page,
                per_page: 50,
                search,
                filter: this.currentFilter
            });

            const parsedPage = parseInt(data.current_page, 10);
            this.currentPage = Number.isNaN(parsedPage) ? 1 : parsedPage;
            this.displayTimestamps(data.data);
            this.displayPagination(data);
        } catch (error) {
            console.error('タイムスタンプの取得に失敗しました:', error);
            toast.error('タイムスタンプの取得に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    displayTimestamps(timestamps) {
        const container = document.getElementById('timestampsList');
        container.innerHTML = '';

        if (timestamps.length === 0) {
            container.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-sm">タイムスタンプがありません。</p>';
            return;
        }

        // DBでソート済みなのでそのまま表示
        timestamps.forEach(ts => {
            container.appendChild(this.createTimestampElement(ts));
        });
    }

    createTimestampElement(ts) {
        const div = document.createElement('div');
        const isSelected = this.selectedTimestamps.some(t => t.id === ts.id);

        div.className = `p-2 border rounded flex items-center gap-2 ${
            isSelected ? 'bg-blue-100 dark:bg-blue-900 border-blue-500' : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
        }`;

        // チェックボックス
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = isSelected;
        checkbox.className = 'flex-shrink-0';
        checkbox.addEventListener('change', (e) => {
            e.stopPropagation();
            this.toggleTimestampSelection(ts);
        });

        const contentDiv = document.createElement('div');
        contentDiv.className = 'flex-1 cursor-pointer min-w-0 flex items-center gap-2 overflow-hidden';
        contentDiv.addEventListener('click', () => this.toggleTimestampSelection(ts));

        // タイムスタンプテキスト
        const textDiv = document.createElement('div');
        textDiv.className = 'font-medium text-sm truncate flex-shrink-0';
        textDiv.style.maxWidth = CONSTANTS.MAX_TIMESTAMP_WIDTH;
        textDiv.textContent = ts.text;
        textDiv.title = ts.text;
        contentDiv.appendChild(textDiv);

        // 動画タイトル
        const archiveTitle = document.createElement('span');
        archiveTitle.textContent = ts.archive?.title || '';
        archiveTitle.className = 'text-xs text-gray-500 dark:text-gray-400 truncate';
        archiveTitle.style.maxWidth = CONSTANTS.MAX_ARCHIVE_TITLE_WIDTH;
        archiveTitle.title = ts.archive?.title || '';
        contentDiv.appendChild(archiveTitle);

        // ステータス
        const statusDiv = this.createStatusElement(ts);
        contentDiv.appendChild(statusDiv);

        // コピーボタン
        const copyBtn = this.createCopyButton(ts.text);

        div.appendChild(checkbox);
        div.appendChild(contentDiv);
        div.appendChild(copyBtn);

        return div;
    }

    createStatusElement(ts) {
        const statusDiv = document.createElement('div');
        statusDiv.className = 'text-xs flex-shrink-0';

        if (ts.is_not_song) {
            statusDiv.className += ' text-red-600 dark:text-red-400';
            statusDiv.textContent = '楽曲ではない';
        } else if (ts.song) {
            statusDiv.className += ' text-green-600 dark:text-green-400';
            const statusText = `${ts.song.title} / ${ts.song.artist}`;
            statusDiv.textContent = statusText.length > CONSTANTS.MAX_STATUS_LENGTH
                ? statusText.substring(0, CONSTANTS.MAX_STATUS_LENGTH) + '...'
                : statusText;
            statusDiv.title = `${ts.song.title} / ${ts.song.artist}`;
        } else {
            statusDiv.className += ' text-gray-400';
            statusDiv.textContent = '未紐づけ';
        }

        return statusDiv;
    }

    createCopyButton(text) {
        const copyBtn = document.createElement('button');
        copyBtn.className = 'p-1.5 text-gray-600 dark:text-gray-400 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 flex-shrink-0 transition-colors';
        copyBtn.title = 'コピー';
        copyBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        `;

        const originalIcon = copyBtn.innerHTML;
        const checkIcon = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        `;

        copyBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            navigator.clipboard.writeText(text);
            copyBtn.innerHTML = checkIcon;
            copyBtn.title = 'コピー済';
            setTimeout(() => {
                copyBtn.innerHTML = originalIcon;
                copyBtn.title = 'コピー';
            }, 1000);
        });

        return copyBtn;
    }

    displayPagination(data) {
        const container = document.getElementById('timestampPagination');
        Pagination.render(container, data, (page) => {
            this.loadTimestamps(page, this.currentSearchQuery);
            document.getElementById('timestampsList').scrollTop = 0;
        });
    }

    toggleTimestampSelection(timestamp) {
        const index = this.selectedTimestamps.findIndex(t => t.id === timestamp.id);

        if (index >= 0) {
            this.selectedTimestamps.splice(index, 1);
        } else {
            this.selectedTimestamps.push(timestamp);
        }

        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage, this.currentSearchQuery);

        // 最初のタイムスタンプが選択された時、Spotify検索窓に反映
        if (this.selectedTimestamps.length === 1) {
            document.getElementById('spotifySearch').value = this.selectedTimestamps[0].text;
        }
    }

    selectAll() {
        const timestampItems = document.querySelectorAll('#timestampsList > div');
        timestampItems.forEach((item) => {
            const checkbox = item.querySelector('input[type="checkbox"]');
            if (checkbox && !checkbox.checked) {
                checkbox.click();
            }
        });
    }

    deselectAll() {
        this.selectedTimestamps = [];
        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage, this.currentSearchQuery);
    }

    updateSelectionDisplay() {
        const container = document.getElementById('selectedTimestamp');
        const countSpan = document.getElementById('selectedCount');
        const textSpan = document.getElementById('selectedText');
        const normalizedSpan = document.getElementById('selectedNormalized');

        container.classList.remove('hidden');

        if (this.selectedTimestamps.length === 0) {
            this.displayNoSelection(countSpan, textSpan, normalizedSpan);
        } else if (this.selectedTimestamps.length === 1) {
            this.displaySingleSelection(countSpan, textSpan, normalizedSpan);
        } else {
            this.displayMultipleSelection(countSpan, textSpan, normalizedSpan);
        }

        this.updateSpotifySelectedDisplay();
        document.getElementById('linkSongBtn').disabled = !(this.selectedTimestamps.length > 0 && this.selectedSong);
    }

    displayNoSelection(countSpan, textSpan, normalizedSpan) {
        countSpan.textContent = '未選択';
        textSpan.textContent = 'タイムスタンプを選択してください';
        normalizedSpan.textContent = '';
        document.getElementById('linkSongBtn').disabled = true;
        document.getElementById('markAsNotSongBtn').disabled = true;
        document.getElementById('unmarkAsNotSongBtn').disabled = true;
        document.getElementById('unlinkBtn').disabled = true;
        this.updateVideoButton(false);
    }

    displaySingleSelection(countSpan, textSpan, normalizedSpan) {
        const ts = this.selectedTimestamps[0];
        countSpan.textContent = '1件選択中';
        textSpan.textContent = ts.text;
        textSpan.title = ts.text;
        normalizedSpan.textContent = `正規化: ${ts.normalized_text}`;
        document.getElementById('markAsNotSongBtn').disabled = false;
        document.getElementById('unmarkAsNotSongBtn').disabled = !ts.is_not_song;
        document.getElementById('unlinkBtn').disabled = !ts.mapping;

        if (ts.archive?.video_id) {
            this.updateVideoButton(true, ts.archive.video_id, ts.ts_num, ts.archive.title || '');
        } else {
            this.updateVideoButton(false, null, null, '動画情報なし');
        }
    }

    displayMultipleSelection(countSpan, textSpan, normalizedSpan) {
        countSpan.textContent = `${this.selectedTimestamps.length}件選択中`;
        const joinedText = this.selectedTimestamps.map(t => t.text).join(', ');

        if (joinedText.length > CONSTANTS.MAX_SELECTION_TEXT_LENGTH) {
            textSpan.textContent = joinedText.substring(0, CONSTANTS.MAX_SELECTION_TEXT_LENGTH) + '...';
        } else {
            textSpan.textContent = joinedText;
        }
        textSpan.title = joinedText;
        normalizedSpan.textContent = '';
        document.getElementById('markAsNotSongBtn').disabled = false;

        const hasNotSong = this.selectedTimestamps.some(ts => ts.is_not_song);
        document.getElementById('unmarkAsNotSongBtn').disabled = !hasNotSong;
        document.getElementById('unlinkBtn').disabled = false;
        this.updateVideoButton(false);
    }

    updateSpotifySelectedDisplay() {
        const spotifySelectedDiv = document.getElementById('spotifySelected');
        if (this.selectedSpotifyTrack) {
            spotifySelectedDiv.classList.remove('hidden');
            const spotifyInfoDiv = document.getElementById('spotifySelectedInfo');
            spotifyInfoDiv.textContent = '';

            const titleSpan = document.createElement('span');
            titleSpan.className = 'font-medium';
            titleSpan.textContent = this.selectedSpotifyTrack.name;

            const artistNames = this.selectedSpotifyTrack.artists.map(a => a.name).join(', ');
            const separatorSpan = document.createElement('span');
            separatorSpan.className = 'text-gray-500 dark:text-gray-400';
            separatorSpan.textContent = ' / ' + artistNames;

            spotifyInfoDiv.appendChild(titleSpan);
            spotifyInfoDiv.appendChild(separatorSpan);
            spotifyInfoDiv.title = `${this.selectedSpotifyTrack.name} / ${artistNames}`;
        } else {
            spotifySelectedDiv.classList.add('hidden');
        }
    }

    clearSelection() {
        this.selectedTimestamps = [];
        this.selectedSong = null;
        this.selectedSpotifyTrack = null;
        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage, this.currentSearchQuery);
    }

    async searchSpotify() {
        const query = document.getElementById('spotifySearch').value.trim();
        if (!query) {
            toast.warning('検索キーワードを入力してください。');
            return;
        }

        const container = document.getElementById('spotifyTracks');
        container.innerHTML = '<p class="text-gray-500 text-sm">検索中...</p>';

        try {
            const tracks = await songApiService.searchSpotify(query, 10);

            if (tracks.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm">検索結果がありません。</p>';
                return;
            }

            this.displaySpotifyTracks(container, tracks);
        } catch (error) {
            console.error('Spotify検索に失敗しました:', error);
            container.innerHTML = '<p class="text-red-500 text-sm">検索に失敗しました。</p>';
        }
    }

    displaySpotifyTracks(container, tracks) {
        container.innerHTML = '';
        tracks.forEach(track => {
            const div = document.createElement('div');
            const isSelected = this.selectedSpotifyTrack?.id === track.id;
            div.className = `p-2 border rounded cursor-pointer ${
                isSelected
                    ? 'bg-blue-100 dark:bg-blue-900 border-blue-500'
                    : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
            }`;

            const songInfo = document.createElement('div');
            songInfo.className = 'text-sm truncate';

            const titleSpan = document.createElement('span');
            titleSpan.className = 'font-medium';
            titleSpan.textContent = track.name;

            const artistNames = track.artists.map(a => a.name).join(', ');
            const separatorSpan = document.createElement('span');
            separatorSpan.className = 'text-gray-500 dark:text-gray-400';
            separatorSpan.textContent = ' / ' + artistNames;

            songInfo.appendChild(titleSpan);
            songInfo.appendChild(separatorSpan);
            songInfo.title = `${track.name} / ${artistNames}`;

            div.appendChild(songInfo);
            div.addEventListener('click', () => this.selectSpotifyTrack(track));

            container.appendChild(div);
        });
    }

    async selectSpotifyTrack(track) {
        this.selectedSpotifyTrack = track;

        await this.registerSong({
            title: track.name,
            artist: track.artists.map(a => a.name).join(', '),
            spotify_track_id: track.id,
            spotify_data: track
        });
    }

    async createSong() {
        const title = document.getElementById('songTitle').value.trim();
        const artist = document.getElementById('songArtist').value.trim();

        if (!title || !artist) {
            toast.warning('楽曲名とアーティスト名を入力してください。');
            return;
        }

        await this.registerSong({ title, artist });
    }

    async registerSong(songData, options = {}) {
        try {
            this.showLoading();
            const response = await songApiService.createSong(songData, options);

            const { status, song, similar_songs, input } = response;

            if (status === 'exact_match' || status === 'existing_used') {
                await this.handleExactMatch(song, songData);
            } else if (status === 'similar_found') {
                this.hideLoading();
                await this.handleSimilarFound(similar_songs, input, songData);
            } else if (status === 'created') {
                await this.handleCreated(song, songData);
            }
        } catch (error) {
            console.error('楽曲マスタの登録に失敗しました:', error);
            toast.error('登録に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async handleExactMatch(song, songData) {
        this.selectedSong = song;
        this.updateSelectionDisplay();
        toast.info(`既存の楽曲マスタを使用します: ${song.title} / ${song.artist}`);

        if (document.getElementById('createSongForm')) {
            document.getElementById('createSongForm').reset();
        }

        if (this.selectedTimestamps.length > 0) {
            await this.linkTimestamps();
        }

        if (songData.spotify_track_id) {
            this.searchSpotify();
        }
    }

    async handleSimilarFound(similarSongs, input, songData) {
        const result = await SimilarSongsDialog.show(similarSongs, input);

        if (result.action === 'use_existing') {
            this.showLoading();
            await this.registerSong(input, { use_existing_id: result.songId });
        } else if (result.action === 'force_create') {
            this.showLoading();
            await this.registerSong(input, { force_create: true });
        }
    }

    async handleCreated(song, songData) {
        this.selectedSong = song;
        this.updateSelectionDisplay();
        toast.success(`楽曲マスタに登録しました: ${song.title} / ${song.artist}`);

        if (document.getElementById('createSongForm')) {
            document.getElementById('createSongForm').reset();
        }

        if (this.selectedTimestamps.length > 0) {
            await this.linkTimestamps();
        }

        if (songData.spotify_track_id) {
            this.searchSpotify();
        }
    }

    async loadSongs(search = '') {
        try {
            const response = await songApiService.fetchSongs(search);

            if (response.data) {
                this.displaySongs(response.data, response.total);
            } else {
                this.displaySongs(response, response.length);
            }
        } catch (error) {
            console.error('楽曲マスタの取得に失敗しました:', error);
            toast.error('楽曲マスタの取得に失敗しました。');
        }
    }

    displaySongs(songs, total = null) {
        const container = document.getElementById('songsResults');
        if (!container) {
            console.error('songsResults element not found');
            return;
        }

        container.innerHTML = '';
        this.updateSongsCount(total !== null ? total : songs.length);

        if (!Array.isArray(songs)) {
            console.error('songs is not an array:', songs);
            container.innerHTML = '<p class="text-red-500 dark:text-red-400 text-sm">データの形式が正しくありません。</p>';
            return;
        }

        if (songs.length === 0) {
            container.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-sm">楽曲マスタがありません。</p>';
            return;
        }

        songs.forEach(song => {
            container.appendChild(this.createSongElement(song, songs, total));
        });
    }

    createSongElement(song, songs, total) {
        const div = document.createElement('div');
        const isSelected = this.selectedSong?.id === song.id;
        div.className = `p-2 border rounded cursor-pointer flex items-center justify-between ${
            isSelected
                ? 'bg-blue-100 dark:bg-blue-900 border-blue-500'
                : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
        }`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'flex-1';

        const songInfo = document.createElement('div');
        songInfo.className = 'text-sm truncate';

        const titleSpan = document.createElement('span');
        titleSpan.className = 'font-medium';
        titleSpan.textContent = song.title;

        const separatorSpan = document.createElement('span');
        separatorSpan.className = 'text-gray-500 dark:text-gray-400';
        separatorSpan.textContent = ' / ' + song.artist;

        songInfo.appendChild(titleSpan);
        songInfo.appendChild(separatorSpan);
        songInfo.title = `${song.title} / ${song.artist}`;

        contentDiv.appendChild(songInfo);

        // 削除ボタン
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'px-2 py-1 text-xs bg-red-600 text-white rounded hover:bg-red-700';
        deleteBtn.textContent = '削除';
        deleteBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (confirm(`楽曲マスタを削除しますか?\n${song.title} / ${song.artist}`)) {
                await this.deleteSong(song.id);
            }
        });

        div.appendChild(contentDiv);
        div.appendChild(deleteBtn);

        div.addEventListener('click', () => {
            this.selectedSong = song;
            this.displaySongs(songs, total);
            this.updateSelectionDisplay();
        });

        return div;
    }

    updateSongsCount(count) {
        const countDiv = document.getElementById('songsCount');
        if (countDiv) {
            countDiv.textContent = `${count}件`;
        }
    }

    async deleteSong(songId) {
        try {
            this.showLoading();
            await songApiService.deleteSong(songId);
            toast.success('楽曲マスタを削除しました。');
            await this.loadSongs();
            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);

            if (this.selectedSong?.id === songId) {
                this.selectedSong = null;
                this.updateSelectionDisplay();
            }
        } catch (error) {
            console.error('削除に失敗しました:', error);
            toast.error('削除に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async linkTimestamps() {
        if (this.selectedTimestamps.length === 0 || !this.selectedSong) {
            toast.warning('タイムスタンプと楽曲を選択してください。');
            return;
        }

        try {
            this.showLoading();

            for (const ts of this.selectedTimestamps) {
                await timestampApiService.linkTimestamp(ts.normalized_text, this.selectedSong.id);
            }

            toast.success(`${this.selectedTimestamps.length}件のタイムスタンプを紐づけました。`);

            this.selectedTimestamps = [];
            this.selectedSpotifyTrack = null;

            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('紐づけに失敗しました:', error);
            toast.error('紐づけに失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async markAsNotSong() {
        if (this.selectedTimestamps.length === 0) {
            toast.warning('タイムスタンプを選択してください。');
            return;
        }

        if (!confirm(`${this.selectedTimestamps.length}件のタイムスタンプを「楽曲ではない」とマークしますか?`)) {
            return;
        }

        try {
            this.showLoading();

            for (const ts of this.selectedTimestamps) {
                await timestampApiService.markAsNotSong(ts.normalized_text);
            }

            toast.success('楽曲ではないとマークしました。');
            this.selectedTimestamps = [];

            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('マークに失敗しました:', error);
            toast.error('マークに失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async unmarkAsNotSong() {
        if (this.selectedTimestamps.length === 0) {
            toast.warning('タイムスタンプを選択してください。');
            return;
        }

        const notSongTimestamps = this.selectedTimestamps.filter(ts => ts.is_not_song);

        if (notSongTimestamps.length === 0) {
            toast.warning('選択されたタイムスタンプに「楽曲ではない」マークがありません。');
            return;
        }

        if (!confirm(`${notSongTimestamps.length}件の「楽曲ではない」マークを解除しますか?`)) {
            return;
        }

        try {
            this.showLoading();

            for (const ts of notSongTimestamps) {
                await timestampApiService.unmarkAsNotSong(ts.normalized_text);
            }

            toast.success('「楽曲ではない」マークを解除しました。');
            this.selectedTimestamps = [];

            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('解除に失敗しました:', error);
            toast.error('解除に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async unlinkTimestamps() {
        if (this.selectedTimestamps.length === 0) {
            toast.warning('タイムスタンプを選択してください。');
            return;
        }

        if (!confirm(`${this.selectedTimestamps.length}件の紐づけを解除しますか?`)) {
            return;
        }

        try {
            this.showLoading();

            for (const ts of this.selectedTimestamps) {
                await timestampApiService.unlinkTimestamp(ts.normalized_text);
            }

            toast.success('紐づけを解除しました。');
            this.selectedTimestamps = [];

            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('解除に失敗しました:', error);
            toast.error('解除に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    showTab(tabId) {
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('border-green-500', 'text-green-600', 'border-blue-500', 'text-blue-600', 'border-purple-500', 'text-purple-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });

        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });

        const activeTab = document.getElementById(tabId);
        if (tabId === 'spotifyTab') {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-green-500', 'text-green-600');
            document.getElementById('spotifyResults').classList.remove('hidden');
        } else if (tabId === 'manualTab') {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-blue-500', 'text-blue-600');
            document.getElementById('manualForm').classList.remove('hidden');
        } else if (tabId === 'songsTab') {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-purple-500', 'text-purple-600');
            document.getElementById('songsList').classList.remove('hidden');
        }
    }

    showLoading() {
        document.getElementById('loadingModal').classList.remove('hidden');
    }

    hideLoading() {
        document.getElementById('loadingModal').classList.add('hidden');
    }

    generateVideoUrl(videoId, tsNum) {
        if (!videoId) return null;
        const timeParam = tsNum ? `?t=${tsNum}s` : '';
        return `${CONSTANTS.YOUTUBE_BASE_URL}${videoId}${timeParam}`;
    }

    updateVideoButton(enabled, videoId = null, tsNum = null, title = '') {
        const videoTitle = document.getElementById('videoTitle');
        const videoLinkBtn = document.getElementById('videoLinkBtn');

        videoTitle.textContent = title;
        videoTitle.title = title;
        videoTitle.classList.add('truncate');
        videoTitle.style.maxWidth = '300px';
        videoLinkBtn.disabled = !enabled;
        videoLinkBtn.setAttribute('aria-disabled', enabled ? 'false' : 'true');

        videoLinkBtn.onclick = null;

        if (enabled && videoId) {
            videoLinkBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
            videoLinkBtn.classList.add('bg-red-600', 'hover:bg-red-700', 'cursor-pointer');

            videoLinkBtn.onclick = () => {
                const videoUrl = this.generateVideoUrl(videoId, tsNum);
                if (!videoUrl) {
                    console.error('Failed to generate video URL');
                    return;
                }

                const newWindow = window.open(videoUrl, '_blank');

                if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                    toast.warning('ポップアップがブロックされました。ブラウザの設定を確認してください。');
                }
            };
        } else {
            videoLinkBtn.classList.add('bg-gray-400', 'cursor-not-allowed');
            videoLinkBtn.classList.remove('bg-red-600', 'hover:bg-red-700', 'cursor-pointer');
        }
    }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    new TimestampNormalization();
});
