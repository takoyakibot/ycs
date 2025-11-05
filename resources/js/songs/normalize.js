import axios from 'axios';

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
        this.searchTimeout = null;
        this.unlinkedOnly = false;

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
            this.searchTimeout = setTimeout(() => {
                this.loadTimestamps(1, e.target.value);
            }, 500);
        });

        // 未連携フィルター
        document.getElementById('unlinkedOnlyBtn').addEventListener('click', () => {
            this.unlinkedOnly = !this.unlinkedOnly;
            const btn = document.getElementById('unlinkedOnlyBtn');
            if (this.unlinkedOnly) {
                btn.classList.add('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'dark:hover:bg-blue-700');
                btn.classList.remove('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            } else {
                btn.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'dark:hover:bg-blue-700');
                btn.classList.add('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            }
            this.loadTimestamps(1);
        });

        // 全選択・全選択解除
        document.getElementById('selectAllBtn').addEventListener('click', () => {
            this.selectAll();
        });

        document.getElementById('deselectAllBtn').addEventListener('click', () => {
            this.deselectAll();
        });

        // Spotify検索
        document.getElementById('searchSpotifyBtn').addEventListener('click', () => {
            this.searchSpotify();
        });

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
            this.loadTimestamps(this.currentPage);
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
            button.addEventListener('click', (e) => {
                this.showTab(e.target.id);
            });
        });

        // アクション
        document.getElementById('linkSongBtn').addEventListener('click', () => {
            this.linkTimestamps();
        });

        document.getElementById('markAsNotSongBtn').addEventListener('click', () => {
            this.markAsNotSong();
        });

        document.getElementById('unlinkBtn').addEventListener('click', () => {
            this.unlinkTimestamps();
        });

        document.getElementById('clearSelectionBtn').addEventListener('click', () => {
            this.clearSelection();
        });
    }

    async loadTimestamps(page = 1, search = '') {
        try {
            this.showLoading();
            const response = await axios.get('/api/songs/timestamps', {
                params: {
                    page,
                    per_page: 50,
                    search,
                    unlinked_only: this.unlinkedOnly
                }
            });

            this.currentPage = response.data.current_page;
            this.displayTimestamps(response.data.data);
            this.displayPagination(response.data);
        } catch (error) {
            console.error('タイムスタンプの取得に失敗しました:', error);
            alert('タイムスタンプの取得に失敗しました。');
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

        timestamps.forEach(ts => {
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
            contentDiv.addEventListener('click', () => {
                this.toggleTimestampSelection(ts);
            });

            // タイムスタンプテキスト
            const textDiv = document.createElement('div');
            textDiv.className = 'font-medium text-sm truncate flex-shrink-0';
            textDiv.style.maxWidth = '200px';
            textDiv.textContent = ts.text;
            textDiv.title = ts.text; // ホバーで全文表示

            contentDiv.appendChild(textDiv);

            // 動画タイトル
            const archiveTitle = document.createElement('span');
            archiveTitle.textContent = ts.archive?.title || '';
            archiveTitle.className = 'text-xs text-gray-500 dark:text-gray-400 truncate';
            archiveTitle.style.maxWidth = '150px';
            archiveTitle.title = ts.archive?.title || '';
            contentDiv.appendChild(archiveTitle);

            // ステータス
            const statusDiv = document.createElement('div');
            statusDiv.className = 'text-xs flex-shrink-0';

            if (ts.is_not_song) {
                statusDiv.className += ' text-red-600 dark:text-red-400';
                statusDiv.textContent = '楽曲ではない';
            } else if (ts.song) {
                statusDiv.className += ' text-green-600 dark:text-green-400';
                const statusText = `${ts.song.title} / ${ts.song.artist}`;
                statusDiv.textContent = statusText.length > 30 ? statusText.substring(0, 30) + '...' : statusText;
                statusDiv.title = `${ts.song.title} / ${ts.song.artist}`;
            } else {
                statusDiv.className += ' text-gray-400';
                statusDiv.textContent = '未紐づけ';
            }

            contentDiv.appendChild(statusDiv);

            // コピーボタン
            const copyBtn = document.createElement('button');
            copyBtn.className = 'px-2 py-1 text-xs bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 flex-shrink-0';
            copyBtn.textContent = 'コピー';
            copyBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                navigator.clipboard.writeText(ts.text);
                copyBtn.textContent = 'コピー済';
                setTimeout(() => {
                    copyBtn.textContent = 'コピー';
                }, 1000);
            });

            div.appendChild(checkbox);
            div.appendChild(contentDiv);
            div.appendChild(copyBtn);

            container.appendChild(div);
        });
    }

    displayPagination(data) {
        const container = document.getElementById('timestampPagination');
        container.innerHTML = '';

        if (data.last_page <= 1) return;

        // 前へボタン
        if (data.current_page > 1) {
            const prevBtn = document.createElement('button');
            prevBtn.textContent = '前へ';
            prevBtn.className = 'px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-gray-300 dark:hover:bg-gray-600';
            prevBtn.addEventListener('click', () => this.loadTimestamps(data.current_page - 1));
            container.appendChild(prevBtn);
        }

        // ページ情報
        const pageInfo = document.createElement('span');
        pageInfo.textContent = `${data.current_page} / ${data.last_page}`;
        pageInfo.className = 'px-3 py-1 text-sm';
        container.appendChild(pageInfo);

        // 次へボタン
        if (data.current_page < data.last_page) {
            const nextBtn = document.createElement('button');
            nextBtn.textContent = '次へ';
            nextBtn.className = 'px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-gray-300 dark:hover:bg-gray-600';
            nextBtn.addEventListener('click', () => this.loadTimestamps(data.current_page + 1));
            container.appendChild(nextBtn);
        }
    }

    toggleTimestampSelection(timestamp) {
        const index = this.selectedTimestamps.findIndex(t => t.id === timestamp.id);

        if (index >= 0) {
            this.selectedTimestamps.splice(index, 1);
        } else {
            this.selectedTimestamps.push(timestamp);
        }

        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage);

        // 最初のタイムスタンプが選択された時、Spotify検索窓に反映
        if (this.selectedTimestamps.length === 1) {
            document.getElementById('spotifySearch').value = this.selectedTimestamps[0].text;
        }
    }

    selectAll() {
        // 現在表示中のタイムスタンプを全て選択
        const timestampItems = document.querySelectorAll('#timestampsList > div');
        timestampItems.forEach((item, index) => {
            const checkbox = item.querySelector('input[type="checkbox"]');
            if (checkbox && !checkbox.checked) {
                checkbox.click();
            }
        });
    }

    deselectAll() {
        this.selectedTimestamps = [];
        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage);
    }

    updateSelectionDisplay() {
        const container = document.getElementById('selectedTimestamp');
        const countSpan = document.getElementById('selectedCount');
        const textSpan = document.getElementById('selectedText');
        const normalizedSpan = document.getElementById('selectedNormalized');
        const videoInfoArea = document.getElementById('videoInfoArea');
        const videoTitle = document.getElementById('videoTitle');
        const videoLinkBtn = document.getElementById('videoLinkBtn');

        // 常に表示
        container.classList.remove('hidden');

        if (this.selectedTimestamps.length === 0) {
            countSpan.textContent = '未選択';
            textSpan.textContent = 'タイムスタンプを選択してください';
            normalizedSpan.textContent = '';
            document.getElementById('linkSongBtn').disabled = true;
            document.getElementById('markAsNotSongBtn').disabled = true;
            document.getElementById('unlinkBtn').disabled = true;
            videoInfoArea.classList.add('hidden');
        } else if (this.selectedTimestamps.length === 1) {
            const ts = this.selectedTimestamps[0];
            countSpan.textContent = '1件選択中';
            textSpan.textContent = ts.text;
            textSpan.title = ts.text; // ホバーで全文表示
            normalizedSpan.textContent = `正規化: ${ts.normalized_text}`;
            document.getElementById('markAsNotSongBtn').disabled = false;
            document.getElementById('unlinkBtn').disabled = !ts.mapping;

            // 動画情報の表示
            if (ts.archive?.video_id) {
                videoInfoArea.classList.remove('hidden');
                videoTitle.textContent = ts.archive.title || '';
                videoTitle.title = ts.archive.title || '';
                const videoUrl = `https://www.youtube.com/live/${ts.archive.video_id}?t=${ts.ts_num}`;
                videoLinkBtn.href = videoUrl;
            } else {
                videoInfoArea.classList.add('hidden');
            }
        } else {
            countSpan.textContent = `${this.selectedTimestamps.length}件選択中`;
            const joinedText = this.selectedTimestamps.map(t => t.text).join(', ');
            // 長い文字列は切り詰める
            if (joinedText.length > 100) {
                textSpan.textContent = joinedText.substring(0, 100) + '...';
                textSpan.title = joinedText; // ホバーで全文表示
            } else {
                textSpan.textContent = joinedText;
                textSpan.title = joinedText;
            }
            normalizedSpan.textContent = '';
            document.getElementById('markAsNotSongBtn').disabled = false;
            document.getElementById('unlinkBtn').disabled = false;
            videoInfoArea.classList.add('hidden');
        }

        // Spotify選択楽曲情報の表示
        const spotifySelectedDiv = document.getElementById('spotifySelected');
        if (this.selectedSpotifyTrack) {
            spotifySelectedDiv.classList.remove('hidden');
            document.getElementById('spotifySelectedTitle').textContent = this.selectedSpotifyTrack.name;
            document.getElementById('spotifySelectedArtist').textContent = this.selectedSpotifyTrack.artists.map(a => a.name).join(', ');
        } else {
            spotifySelectedDiv.classList.add('hidden');
        }

        // 楽曲と紐づけボタンの有効化
        document.getElementById('linkSongBtn').disabled = !(this.selectedTimestamps.length > 0 && this.selectedSong);
    }

    clearSelection() {
        this.selectedTimestamps = [];
        this.selectedSong = null;
        this.selectedSpotifyTrack = null;
        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage);
    }

    async searchSpotify() {
        const query = document.getElementById('spotifySearch').value.trim();
        if (!query) {
            alert('検索キーワードを入力してください。');
            return;
        }

        const container = document.getElementById('spotifyTracks');
        container.innerHTML = '<p class="text-gray-500 text-sm">検索中...</p>';

        try {
            const response = await axios.get('/api/songs/search-spotify', {
                params: { query, limit: 10 }
            });

            const tracks = response.data;

            if (tracks.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm">検索結果がありません。</p>';
                return;
            }

            container.innerHTML = '';
            tracks.forEach(track => {
                const div = document.createElement('div');
                const isSelected = this.selectedSpotifyTrack?.id === track.id;
                div.className = `p-2 border rounded cursor-pointer ${
                    isSelected
                        ? 'bg-blue-100 dark:bg-blue-900 border-blue-500'
                        : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
                }`;

                const title = document.createElement('div');
                title.className = 'font-medium text-sm';
                title.textContent = track.name;

                const artist = document.createElement('div');
                artist.className = 'text-xs text-gray-500 dark:text-gray-400';
                artist.textContent = track.artists.map(a => a.name).join(', ');

                div.appendChild(title);
                div.appendChild(artist);

                div.addEventListener('click', () => {
                    this.selectSpotifyTrack(track);
                });

                container.appendChild(div);
            });
        } catch (error) {
            console.error('Spotify検索に失敗しました:', error);
            container.innerHTML = '<p class="text-red-500 text-sm">検索に失敗しました。</p>';
        }
    }

    async selectSpotifyTrack(track) {
        this.selectedSpotifyTrack = track;

        // 楽曲マスタに登録
        const title = track.name;
        const artist = track.artists.map(a => a.name).join(', ');
        const spotifyTrackId = track.id;

        try {
            this.showLoading();
            const response = await axios.post('/api/songs', {
                title,
                artist,
                spotify_track_id: spotifyTrackId,
                spotify_data: track
            });

            this.selectedSong = response.data;
            this.updateSelectionDisplay();
            alert(`楽曲マスタに登録しました: ${title} / ${artist}`);

            // タイムスタンプが選択されていれば紐づける
            if (this.selectedTimestamps.length > 0) {
                await this.linkTimestamps();
            }

            // Spotify検索結果を再描画して選択状態を反映
            this.searchSpotify();
        } catch (error) {
            console.error('楽曲マスタの登録に失敗しました:', error);
            alert('登録に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async createSong() {
        const title = document.getElementById('songTitle').value.trim();
        const artist = document.getElementById('songArtist').value.trim();

        if (!title || !artist) {
            alert('楽曲名とアーティスト名を入力してください。');
            return;
        }

        try {
            this.showLoading();
            const response = await axios.post('/api/songs', {
                title,
                artist
            });

            this.selectedSong = response.data;
            this.updateSelectionDisplay();
            alert(`楽曲マスタに登録しました: ${title} / ${artist}`);

            document.getElementById('createSongForm').reset();

            // タイムスタンプが選択されていれば紐づける
            if (this.selectedTimestamps.length > 0) {
                await this.linkTimestamps();
            }
        } catch (error) {
            console.error('楽曲マスタの登録に失敗しました:', error);
            alert('登録に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async loadSongs(search = '') {
        try {
            const response = await axios.get('/api/songs', {
                params: { search }
            });

            this.displaySongs(response.data);
        } catch (error) {
            console.error('楽曲マスタの取得に失敗しました:', error);
            alert('楽曲マスタの取得に失敗しました。');
        }
    }

    displaySongs(songs) {
        const container = document.getElementById('songsResults');
        if (!container) {
            console.error('songsResults element not found');
            return;
        }

        container.innerHTML = '';

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
            const div = document.createElement('div');
            const isSelected = this.selectedSong?.id === song.id;
            div.className = `p-2 border rounded cursor-pointer flex items-center justify-between ${
                isSelected
                    ? 'bg-blue-100 dark:bg-blue-900 border-blue-500'
                    : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
            }`;

            const contentDiv = document.createElement('div');
            contentDiv.className = 'flex-1';

            const title = document.createElement('div');
            title.className = 'font-medium text-sm';
            title.textContent = song.title;

            const artist = document.createElement('div');
            artist.className = 'text-xs text-gray-500 dark:text-gray-400';
            artist.textContent = song.artist;

            contentDiv.appendChild(title);
            contentDiv.appendChild(artist);

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
                this.displaySongs(songs);
                this.updateSelectionDisplay();
            });

            container.appendChild(div);
        });
    }

    async deleteSong(songId) {
        try {
            this.showLoading();
            await axios.delete(`/api/songs/${songId}`);
            alert('楽曲マスタを削除しました。');
            await this.loadSongs();
            await this.loadTimestamps(this.currentPage);

            if (this.selectedSong?.id === songId) {
                this.selectedSong = null;
                this.updateSelectionDisplay();
            }
        } catch (error) {
            console.error('削除に失敗しました:', error);
            alert('削除に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async linkTimestamps() {
        if (this.selectedTimestamps.length === 0 || !this.selectedSong) {
            alert('タイムスタンプと楽曲を選択してください。');
            return;
        }

        try {
            this.showLoading();

            // 選択された各タイムスタンプを紐づける
            for (const ts of this.selectedTimestamps) {
                await axios.post('/api/songs/link', {
                    normalized_text: ts.normalized_text,
                    song_id: this.selectedSong.id
                });
            }

            alert(`${this.selectedTimestamps.length}件のタイムスタンプを紐づけました。`);

            // タイムスタンプの選択をクリア、楽曲の選択は維持
            this.selectedTimestamps = [];
            this.selectedSpotifyTrack = null;
            // this.selectedSong は維持

            // 紐づけた結果を確認できるように「未連携のみ」フィルタを解除
            if (this.unlinkedOnly) {
                this.unlinkedOnly = false;
                const btn = document.getElementById('unlinkedOnlyBtn');
                btn.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'dark:hover:bg-blue-700');
                btn.classList.add('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            }

            await this.loadTimestamps(this.currentPage);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('紐づけに失敗しました:', error);
            alert('紐づけに失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async markAsNotSong() {
        if (this.selectedTimestamps.length === 0) {
            alert('タイムスタンプを選択してください。');
            return;
        }

        if (!confirm(`${this.selectedTimestamps.length}件のタイムスタンプを「楽曲ではない」とマークしますか?`)) {
            return;
        }

        try {
            this.showLoading();

            for (const ts of this.selectedTimestamps) {
                await axios.post('/api/songs/mark-not-song', {
                    normalized_text: ts.normalized_text
                });
            }

            alert('楽曲ではないとマークしました。');
            this.selectedTimestamps = [];

            // マークした結果を確認できるように「未連携のみ」フィルタを解除
            if (this.unlinkedOnly) {
                this.unlinkedOnly = false;
                const btn = document.getElementById('unlinkedOnlyBtn');
                btn.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'dark:hover:bg-blue-700');
                btn.classList.add('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            }

            await this.loadTimestamps(this.currentPage);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('マークに失敗しました:', error);
            alert('マークに失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    async unlinkTimestamps() {
        if (this.selectedTimestamps.length === 0) {
            alert('タイムスタンプを選択してください。');
            return;
        }

        if (!confirm(`${this.selectedTimestamps.length}件の紐づけを解除しますか?`)) {
            return;
        }

        try {
            this.showLoading();

            for (const ts of this.selectedTimestamps) {
                await axios.delete('/api/songs/unlink', {
                    data: { normalized_text: ts.normalized_text }
                });
            }

            alert('紐づけを解除しました。');
            this.selectedTimestamps = [];

            // 紐づけを解除したタイムスタンプは未連携になるため、
            // 「未連携のみ」フィルタがオンでも表示される
            // （フィルタは維持）

            await this.loadTimestamps(this.currentPage);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('解除に失敗しました:', error);
            alert('解除に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    showTab(tabId) {
        // すべてのタブボタンとコンテンツをリセット
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('border-green-500', 'text-green-600', 'border-blue-500', 'text-blue-600', 'border-purple-500', 'text-purple-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });

        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });

        // アクティブなタブを設定
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
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    new TimestampNormalization();
});
