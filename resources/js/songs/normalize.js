import axios from 'axios';
import toast from '../utils/toast.js';

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
        this.unlinkedOnly = false;

        // 定数定義
        this.CONSTANTS = {
            MAX_TIMESTAMP_WIDTH: '200px',
            MAX_ARCHIVE_TITLE_WIDTH: '150px',
            MAX_STATUS_LENGTH: 30,
            MAX_SELECTION_TEXT_LENGTH: 100,
            YOUTUBE_BASE_URL: 'https://youtube.com/watch?v='
        };

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
            this.loadTimestamps(1, this.currentSearchQuery);
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

            const parsedPage = parseInt(response.data.current_page, 10);
            this.currentPage = Number.isNaN(parsedPage) ? 1 : parsedPage;
            this.displayTimestamps(response.data.data);
            this.displayPagination(response.data);
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

            // タイムスタンプテキスト（最大200px、他の要素より優先的に表示）
            const textDiv = document.createElement('div');
            textDiv.className = 'font-medium text-sm truncate flex-shrink-0';
            textDiv.style.maxWidth = this.CONSTANTS.MAX_TIMESTAMP_WIDTH;
            textDiv.textContent = ts.text;
            textDiv.title = ts.text; // ホバーで全文表示

            contentDiv.appendChild(textDiv);

            // 動画タイトル
            const archiveTitle = document.createElement('span');
            archiveTitle.textContent = ts.archive?.title || '';
            archiveTitle.className = 'text-xs text-gray-500 dark:text-gray-400 truncate';
            archiveTitle.style.maxWidth = this.CONSTANTS.MAX_ARCHIVE_TITLE_WIDTH;
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
                statusDiv.textContent = statusText.length > this.CONSTANTS.MAX_STATUS_LENGTH
                    ? statusText.substring(0, this.CONSTANTS.MAX_STATUS_LENGTH) + '...'
                    : statusText;
                statusDiv.title = `${ts.song.title} / ${ts.song.artist}`;
            } else {
                statusDiv.className += ' text-gray-400';
                statusDiv.textContent = '未紐づけ';
            }

            contentDiv.appendChild(statusDiv);

            // コピーボタン
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
                navigator.clipboard.writeText(ts.text);
                copyBtn.innerHTML = checkIcon;
                copyBtn.title = 'コピー済';
                setTimeout(() => {
                    copyBtn.innerHTML = originalIcon;
                    copyBtn.title = 'コピー';
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

        const currentPage = parseInt(data.current_page, 10);
        const lastPage = parseInt(data.last_page, 10);

        // バリデーション
        if (Number.isNaN(currentPage) || Number.isNaN(lastPage)) {
            console.error('Invalid page numbers:', { currentPage, lastPage });
            return;
        }

        // ボタンのスタイル
        const btnClass = 'px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-gray-300 dark:hover:bg-gray-600 text-sm';
        const disabledBtnClass = 'px-3 py-1 bg-gray-100 dark:bg-gray-800 rounded-md text-gray-400 dark:text-gray-600 cursor-not-allowed text-sm';

        // ボタン作成ヘルパー関数
        const createButton = (label, targetPage, isEnabled) => {
            const btn = document.createElement('button');
            btn.textContent = label;
            btn.className = isEnabled ? btnClass : disabledBtnClass;
            btn.disabled = !isEnabled;

            if (isEnabled) {
                btn.addEventListener('click', () => {
                    this.loadTimestamps(targetPage, this.currentSearchQuery);
                    // ページ切り替え時に一覧の先頭へスクロール
                    document.getElementById('timestampsList').scrollTop = 0;
                });
            }

            return btn;
        };

        // ボタン定義
        const buttons = [
            { label: '最初', targetPage: 1, showCondition: true, enableCondition: currentPage > 1 },
            { label: '-10', targetPage: currentPage - 10, showCondition: true, enableCondition: currentPage > 10 },
            { label: '-5', targetPage: currentPage - 5, showCondition: true, enableCondition: currentPage > 5 },
            { label: '前へ', targetPage: currentPage - 1, showCondition: true, enableCondition: currentPage > 1 },
        ];

        // ボタンを追加
        buttons.forEach(({ label, targetPage, showCondition, enableCondition }) => {
            if (showCondition) {
                container.appendChild(createButton(label, targetPage, enableCondition));
            }
        });

        // ページ情報
        const pageInfo = document.createElement('span');
        pageInfo.textContent = `${currentPage} / ${lastPage}`;
        pageInfo.className = 'px-3 py-1 text-sm font-medium';
        container.appendChild(pageInfo);

        // 次へ系のボタン
        const nextButtons = [
            { label: '次へ', targetPage: currentPage + 1, showCondition: true, enableCondition: currentPage < lastPage },
            { label: '+5', targetPage: currentPage + 5, showCondition: true, enableCondition: currentPage + 5 <= lastPage },
            { label: '+10', targetPage: currentPage + 10, showCondition: true, enableCondition: currentPage + 10 <= lastPage },
            { label: '最後', targetPage: lastPage, showCondition: true, enableCondition: currentPage < lastPage },
        ];

        nextButtons.forEach(({ label, targetPage, showCondition, enableCondition }) => {
            if (showCondition) {
                container.appendChild(createButton(label, targetPage, enableCondition));
            }
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
        // 現在表示中のタイムスタンプを全て選択
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

        // 常に表示
        container.classList.remove('hidden');

        if (this.selectedTimestamps.length === 0) {
            countSpan.textContent = '未選択';
            textSpan.textContent = 'タイムスタンプを選択してください';
            normalizedSpan.textContent = '';
            document.getElementById('linkSongBtn').disabled = true;
            document.getElementById('markAsNotSongBtn').disabled = true;
            document.getElementById('unlinkBtn').disabled = true;

            // 動画ボタンを無効化
            this.updateVideoButton(false);
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
                this.updateVideoButton(true, ts.archive.video_id, ts.ts_num, ts.archive.title || '');
            } else {
                this.updateVideoButton(false, null, null, '動画情報なし');
            }
        } else {
            countSpan.textContent = `${this.selectedTimestamps.length}件選択中`;
            const joinedText = this.selectedTimestamps.map(t => t.text).join(', ');
            // 長い文字列は切り詰める
            if (joinedText.length > this.CONSTANTS.MAX_SELECTION_TEXT_LENGTH) {
                textSpan.textContent = joinedText.substring(0, this.CONSTANTS.MAX_SELECTION_TEXT_LENGTH) + '...';
                textSpan.title = joinedText; // ホバーで全文表示
            } else {
                textSpan.textContent = joinedText;
                textSpan.title = joinedText;
            }
            normalizedSpan.textContent = '';
            document.getElementById('markAsNotSongBtn').disabled = false;
            document.getElementById('unlinkBtn').disabled = false;

            // 動画ボタンを無効化
            this.updateVideoButton(false);
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

        await this.registerSong({
            title,
            artist,
            spotify_track_id: spotifyTrackId,
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

    /**
     * 楽曲マスタを登録（完全一致・類似度チェック対応）
     */
    async registerSong(songData, options = {}) {
        try {
            this.showLoading();
            const response = await axios.post('/api/songs', {
                ...songData,
                ...options
            });

            const { status, song, similar_songs, input } = response.data;

            if (status === 'exact_match' || status === 'existing_used') {
                // 完全一致または既存マスタ使用
                this.selectedSong = song;
                this.updateSelectionDisplay();
                toast.info(`既存の楽曲マスタを使用します: ${song.title} / ${song.artist}`);

                // フォームをリセット
                if (document.getElementById('createSongForm')) {
                    document.getElementById('createSongForm').reset();
                }

                // タイムスタンプが選択されていれば紐づける
                if (this.selectedTimestamps.length > 0) {
                    await this.linkTimestamps();
                }

                // Spotify検索結果を再描画（Spotifyから登録の場合）
                if (songData.spotify_track_id) {
                    this.searchSpotify();
                }

            } else if (status === 'similar_found') {
                // 類似曲が見つかった場合
                this.hideLoading();
                await this.showSimilarSongsDialog(similar_songs, input);

            } else if (status === 'created') {
                // 新規登録
                this.selectedSong = song;
                this.updateSelectionDisplay();
                toast.success(`楽曲マスタに登録しました: ${song.title} / ${song.artist}`);

                // フォームをリセット
                if (document.getElementById('createSongForm')) {
                    document.getElementById('createSongForm').reset();
                }

                // タイムスタンプが選択されていれば紐づける
                if (this.selectedTimestamps.length > 0) {
                    await this.linkTimestamps();
                }

                // Spotify検索結果を再描画（Spotifyから登録の場合）
                if (songData.spotify_track_id) {
                    this.searchSpotify();
                }
            }

        } catch (error) {
            console.error('楽曲マスタの登録に失敗しました:', error);
            toast.error('登録に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * 類似曲確認ダイアログを表示
     */
    async showSimilarSongsDialog(similarSongs, inputData) {
        return new Promise((resolve) => {
            // HTMLエスケープ関数
            const escapeHtml = (str) => {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            };

            // ダイアログのHTMLを動的に作成
            const dialogHtml = `
                <div id="similarSongsDialog" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-2xl w-full mx-4 max-h-[80vh] overflow-y-auto">
                        <h3 class="text-lg font-bold mb-4 text-gray-900 dark:text-white">類似する楽曲マスタが見つかりました</h3>

                        <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900 rounded">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                登録しようとしている楽曲: <strong>${escapeHtml(inputData.title)} / ${escapeHtml(inputData.artist)}</strong>
                            </p>
                        </div>

                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            類似度の高い楽曲が ${escapeHtml(String(similarSongs.length))} 件見つかりました。既存のマスタを使用するか、新規登録するか選択してください。
                        </p>

                        <div id="similarSongsList" class="space-y-2 mb-6 max-h-60 overflow-y-auto">
                            ${similarSongs.map((item, index) => `
                                <div class="similar-song-item p-3 border rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:border-gray-600" data-song-id="${escapeHtml(item.song.id)}" data-index="${escapeHtml(String(index))}">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <div class="font-medium text-sm text-gray-900 dark:text-white">${escapeHtml(item.song.title)}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(item.song.artist)}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-xs font-medium text-green-600 dark:text-green-400">類似度: ${escapeHtml(String(item.similarity))}%</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                曲名: ${escapeHtml(String(item.title_similarity))}% / アーティスト: ${escapeHtml(String(item.artist_similarity))}%
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>

                        <div class="flex gap-2 justify-end">
                            <button id="useExistingSongBtn" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50" disabled>
                                選択した楽曲を使用
                            </button>
                            <button id="forceCreateNewBtn" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                                新規登録
                            </button>
                            <button id="cancelDialogBtn" class="px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-400 dark:hover:bg-gray-500">
                                キャンセル
                            </button>
                        </div>
                    </div>
                </div>
            `;

            // ダイアログを追加
            document.body.insertAdjacentHTML('beforeend', dialogHtml);

            const dialog = document.getElementById('similarSongsDialog');
            const useExistingBtn = document.getElementById('useExistingSongBtn');
            const forceCreateBtn = document.getElementById('forceCreateNewBtn');
            const cancelBtn = document.getElementById('cancelDialogBtn');

            let selectedSongId = null;

            // 楽曲選択
            dialog.querySelectorAll('.similar-song-item').forEach(item => {
                item.addEventListener('click', () => {
                    // 選択状態をリセット
                    dialog.querySelectorAll('.similar-song-item').forEach(i => {
                        i.classList.remove('bg-blue-100', 'dark:bg-blue-900', 'border-blue-500');
                    });

                    // 選択状態を適用
                    item.classList.add('bg-blue-100', 'dark:bg-blue-900', 'border-blue-500');
                    selectedSongId = item.dataset.songId;
                    useExistingBtn.disabled = false;
                });
            });

            // 既存楽曲を使用
            useExistingBtn.addEventListener('click', async () => {
                if (!selectedSongId) return;

                dialog.remove();
                this.showLoading();

                try {
                    await this.registerSong(inputData, { use_existing_id: selectedSongId });
                    resolve();
                } catch (error) {
                    console.error('楽曲の使用に失敗しました:', error);
                    toast.error('楽曲の使用に失敗しました。');
                } finally {
                    this.hideLoading();
                }
            });

            // 新規登録
            forceCreateBtn.addEventListener('click', async () => {
                dialog.remove();
                this.showLoading();

                try {
                    await this.registerSong(inputData, { force_create: true });
                    resolve();
                } catch (error) {
                    console.error('新規登録に失敗しました:', error);
                    toast.error('新規登録に失敗しました。');
                } finally {
                    this.hideLoading();
                }
            });

            // キャンセル
            cancelBtn.addEventListener('click', () => {
                dialog.remove();
                resolve();
            });
        });
    }

    async loadSongs(search = '') {
        try {
            const response = await axios.get('/api/songs', {
                params: { search }
            });

            this.displaySongs(response.data);
        } catch (error) {
            console.error('楽曲マスタの取得に失敗しました:', error);
            toast.error('楽曲マスタの取得に失敗しました。');
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

            // 選択された各タイムスタンプを紐づける
            for (const ts of this.selectedTimestamps) {
                await axios.post('/api/songs/link', {
                    normalized_text: ts.normalized_text,
                    song_id: this.selectedSong.id
                });
            }

            toast.success(`${this.selectedTimestamps.length}件のタイムスタンプを紐づけました。`);

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
                await axios.post('/api/songs/mark-not-song', {
                    normalized_text: ts.normalized_text
                });
            }

            toast.success('楽曲ではないとマークしました。');
            this.selectedTimestamps = [];

            // マークした結果を確認できるように「未連携のみ」フィルタを解除
            if (this.unlinkedOnly) {
                this.unlinkedOnly = false;
                const btn = document.getElementById('unlinkedOnlyBtn');
                btn.classList.remove('bg-blue-600', 'text-white', 'hover:bg-blue-700', 'dark:hover:bg-blue-700');
                btn.classList.add('bg-gray-200', 'dark:bg-gray-700', 'hover:bg-gray-300', 'dark:hover:bg-gray-600');
            }

            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('マークに失敗しました:', error);
            toast.error('マークに失敗しました。');
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
                await axios.delete('/api/songs/unlink', {
                    data: { normalized_text: ts.normalized_text }
                });
            }

            toast.success('紐づけを解除しました。');
            this.selectedTimestamps = [];

            // 紐づけを解除したタイムスタンプは未連携になるため、
            // 「未連携のみ」フィルタがオンでも表示される
            // （フィルタは維持）

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

    /**
     * 動画URLを生成する
     * @param {string} videoId - 動画ID
     * @param {number|null} tsNum - タイムスタンプ秒数
     * @returns {string|null} 生成されたURL、またはvideoIdがない場合はnull
     */
    generateVideoUrl(videoId, tsNum) {
        if (!videoId) return null;
        const timeParam = tsNum ? `&t=${tsNum}s` : '';
        return `${this.CONSTANTS.YOUTUBE_BASE_URL}${videoId}${timeParam}`;
    }

    /**
     * 動画ボタンの状態を更新する
     * @param {boolean} enabled - ボタンを有効化するか
     * @param {string|null} videoId - 動画ID
     * @param {number|null} tsNum - タイムスタンプ秒数
     * @param {string} title - 動画タイトル
     */
    updateVideoButton(enabled, videoId = null, tsNum = null, title = '') {
        const videoTitle = document.getElementById('videoTitle');
        const videoLinkBtn = document.getElementById('videoLinkBtn');

        videoTitle.textContent = title;
        videoTitle.title = title;
        videoLinkBtn.disabled = !enabled;
        videoLinkBtn.setAttribute('aria-disabled', enabled ? 'false' : 'true');

        // 既存のイベントリスナーをクリア
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

                // ポップアップブロック検出
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
