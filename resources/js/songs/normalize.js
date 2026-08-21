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
        this.currentFilter = sessionStorage.getItem('timestampFilter') || 'active'; // active, all, unlinked, linked, not_song, auto_linked, pending
        this.currentSongFilter = null; // 楽曲による絞り込み（楽曲オブジェクト）
        this.operationHistory = []; // 操作履歴
        this.maxHistoryItems = 20; // 最大履歴保持数
        this.songReviewStatus = null; // 楽曲マスタのreview_statusフィルタ
        this.songsRequestSeq = 0; // 楽曲マスタ取得の世代番号（応答の追い越し防止）
        this.songsQueryActive = false; // 直近の loadSongs が絞り込み条件ありと判断したか
        // 楽曲マスタの検索方式（fuzzy: 区切り文字を無視した単語検索 / exact: 入力そのままで検索）
        this.songSearchMode = sessionStorage.getItem('songSearchMode') === CONSTANTS.SONG_SEARCH_MODE_EXACT
            ? CONSTANTS.SONG_SEARCH_MODE_EXACT
            : CONSTANTS.SONG_SEARCH_MODE_FUZZY;
        this.candidateParts = [];              // 候補タブのチップ（元テキストの分割結果）
        this.candidateSelectedIndices = new Set(); // 選択中のチップの位置
        this.candidateTextKey = null;          // どのタイムスタンプのチップかを判別する元テキスト
        this.candidateRequestSeq = 0;          // 候補取得の世代番号（応答の追い越し防止）
        this.lastCandidateSelectionKey = null; // 候補を作り直すかの判定用（前回の選択）
        this.activeTabId = null;               // 現在表示中のタブ（タブ切り替え判定用）

        this.init();
    }

    init() {
        this.spotifyEnabled = window.spotifyEnabled ?? false;
        this.bindEvents();
        this.updateFilterButtons();
        this.updateSongSearchModeButtons();
        this.loadTimestamps();
        this.showTab(this.spotifyEnabled ? 'spotifyTab' : 'manualTab');
        this.updateSelectionDisplay();
        this.initHistoryPanel();
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

        // タイムスタンプ検索クリアボタン
        document.getElementById('clearTimestampSearchBtn').addEventListener('click', () => {
            document.getElementById('timestampSearch').value = '';
            this.currentSearchQuery = '';
            this.loadTimestamps(1, '');
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

        // 楽曲マスタ検索クリアボタン
        document.getElementById('clearSongsSearchBtn').addEventListener('click', () => {
            document.getElementById('songsSearch').value = '';
            this.loadSongs('');
        });

        // 楽曲マスタ review_status フィルタ
        document.querySelectorAll('.song-review-filter').forEach(btn => {
            btn.addEventListener('click', () => {
                this.songReviewStatus = btn.dataset.reviewStatus || null;
                // ボタンのアクティブ状態を更新
                document.querySelectorAll('.song-review-filter').forEach(b => {
                    b.classList.remove('bg-blue-100', 'dark:bg-blue-900', 'text-blue-700', 'dark:text-blue-300');
                    b.classList.add('text-gray-600', 'dark:text-gray-400', 'hover:bg-gray-100', 'dark:hover:bg-gray-700');
                });
                btn.classList.add('bg-blue-100', 'dark:bg-blue-900', 'text-blue-700', 'dark:text-blue-300');
                btn.classList.remove('text-gray-600', 'dark:text-gray-400', 'hover:bg-gray-100', 'dark:hover:bg-gray-700');
                this.loadSongs(document.getElementById('songsSearch').value);
            });
        });

        // 楽曲マスタ 検索方式（あいまい / 完全一致）
        document.querySelectorAll('.song-search-mode').forEach(btn => {
            btn.addEventListener('click', () => {
                this.setSongSearchMode(btn.dataset.searchMode);
            });
        });

        // タブ切り替え
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', (e) => this.showTab(e.target.id));
        });

        // アクション
        document.getElementById('linkSongBtn').addEventListener('click', () => this.linkTimestamps());
        document.getElementById('markAsPendingBtn').addEventListener('click', () => this.markAsPending());
        document.getElementById('markAsNotSongBtn').addEventListener('click', () => this.markAsNotSong());
        document.getElementById('unmarkAsNotSongBtn').addEventListener('click', () => this.unmarkAsNotSong());
        document.getElementById('unlinkBtn').addEventListener('click', () => this.unlinkTimestamps());
        document.getElementById('clearSelectionBtn').addEventListener('click', () => this.clearSelection());

        // 編集モーダル
        document.getElementById('closeEditModalBtn').addEventListener('click', () => this.closeEditModal());
        document.getElementById('cancelEditBtn').addEventListener('click', () => this.closeEditModal());
        document.getElementById('editSongForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.updateSong();
        });
        document.getElementById('fetchDurationBtn').addEventListener('click', () => this.fetchVideoDuration());
        document.getElementById('editSongDurationMs').addEventListener('input', (e) => this.onDurationMsInput(e));
        document.getElementById('editSongDurationSeconds').addEventListener('input', (e) => this.onDurationSecondsInput(e));

        // モーダル外クリックで閉じる。
        // モーダル内でテキスト選択などのドラッグを始めて外でマウスを離すと、
        // clickイベントのtargetが背景要素になり誤って閉じてしまうため、
        // mousedownも背景で始まった場合のみ閉じる
        const editSongModal = document.getElementById('editSongModal');
        let editModalPressedOnBackdrop = false;
        editSongModal.addEventListener('mousedown', (e) => {
            editModalPressedOnBackdrop = e.target.id === 'editSongModal';
        });
        editSongModal.addEventListener('click', (e) => {
            if (e.target.id === 'editSongModal' && editModalPressedOnBackdrop) {
                this.closeEditModal();
            }
        });

        // 楽曲フィルター解除ボタン
        const clearSongFilterBtn = document.getElementById('clearSongFilterBtn');
        if (clearSongFilterBtn) {
            clearSongFilterBtn.addEventListener('click', () => this.clearSongFilter());
        }
    }

    setFilter(filter) {
        this.currentFilter = filter;
        sessionStorage.setItem('timestampFilter', filter);
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
                filter: this.currentFilter,
                song_id: this.currentSongFilter?.id || null
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

        // 候補タブは1件のテキストに対する候補を出すため単一選択にする。
        // ただし複数選択中は、複数がチェックされたラジオボタンという矛盾した表示に
        // ならないようチェックボックスのまま描画する（選択を保持したまま案内を出す）
        const singleSelect = this.isCandidateTabActive() && this.selectedTimestamps.length <= 1;

        const checkbox = document.createElement('input');
        checkbox.type = singleSelect ? 'radio' : 'checkbox';
        if (singleSelect) {
            checkbox.name = 'candidateTimestamp';
        }
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

        // ボタンコンテナ
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'flex items-center gap-1 flex-shrink-0';
        buttonContainer.appendChild(copyBtn);

        // 自動紐付け確定ボタン（自動紐付けの場合のみ表示）
        if (ts.is_manual === false && ts.song) {
            const confirmBtn = this.createConfirmButton(ts);
            buttonContainer.appendChild(confirmBtn);
        }

        div.appendChild(checkbox);
        div.appendChild(contentDiv);
        div.appendChild(buttonContainer);

        return div;
    }

    createStatusElement(ts) {
        const statusDiv = document.createElement('div');
        statusDiv.className = 'text-xs flex-shrink-0';

        if (ts.is_not_song) {
            statusDiv.className += ' text-red-600 dark:text-red-400';
            statusDiv.textContent = '楽曲ではない';
        } else if (ts.status === 'pending') {
            // 保留状態
            statusDiv.className += ' text-orange-600 dark:text-orange-400';
            statusDiv.textContent = '保留';
        } else if (ts.song) {
            // 個別マッピングの場合は青、自動紐付けの場合は黄色、手動紐付けの場合は緑
            const isAutoLinked = ts.is_manual === false && !ts.is_individual_mapping;
            const isIndividual = ts.is_individual_mapping === true;
            if (isIndividual) {
                statusDiv.className += ' text-blue-600 dark:text-blue-400';
            } else if (isAutoLinked) {
                statusDiv.className += ' text-yellow-600 dark:text-yellow-400';
            } else {
                statusDiv.className += ' text-green-600 dark:text-green-400';
            }
            const prefix = isIndividual ? '[個別] ' : (isAutoLinked ? '[自動] ' : '');
            const statusText = `${prefix}${ts.song.title} / ${ts.song.artist}`;
            statusDiv.textContent = statusText.length > CONSTANTS.MAX_STATUS_LENGTH
                ? statusText.substring(0, CONSTANTS.MAX_STATUS_LENGTH) + '...'
                : statusText;
            statusDiv.title = `${prefix}${ts.song.title} / ${ts.song.artist}`;
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

    createConfirmButton(ts) {
        const confirmBtn = document.createElement('button');
        confirmBtn.className = 'px-2 py-1 text-xs bg-yellow-500 hover:bg-yellow-600 text-white rounded transition-colors';
        confirmBtn.textContent = '確定';
        confirmBtn.title = '自動紐付けを確定';

        confirmBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.confirmAutoLink(ts);
        });

        return confirmBtn;
    }

    async confirmAutoLink(ts) {
        try {
            await axios.post('/api/songs/confirm-auto-link', { normalized_text: ts.normalized_text });
            toast.success('自動紐付けを確定しました');

            // 履歴に追加
            this.addToHistory('confirm_auto_link', [ts], ts.song);

            // 選択を解除
            this.selectedTimestamps = [];
            this.updateSelectionDisplay();

            this.loadTimestamps(this.currentPage, this.currentSearchQuery);
        } catch (error) {
            console.error('確定エラー:', error);
            if (error.response?.status === 404) {
                toast.error('確定対象のマッピングが見つかりません');
            } else {
                toast.error('確定に失敗しました');
            }
        }
    }

    displayPagination(data) {
        const container = document.getElementById('timestampPagination');
        Pagination.render(container, data, (page) => {
            this.loadTimestamps(page, this.currentSearchQuery);
            document.getElementById('timestampsList').scrollTop = 0;
        }, {
            showJumpButtons: false,
            showFirstLast: true
        });
    }

    toggleTimestampSelection(timestamp) {
        const index = this.selectedTimestamps.findIndex(t => t.id === timestamp.id);

        if (this.isCandidateTabActive() && this.selectedTimestamps.length <= 1) {
            // 候補タブでは単一選択。同じ行を選び直したときは解除できるようにする
            this.selectedTimestamps = index >= 0 ? [] : [timestamp];
            // 対象のタイムスタンプが変わるので、選んでいた候補の曲は無効化する。
            // 残したままだと、別のタイムスタンプに誤って紐づく事故につながる
            this.selectedSong = null;
        } else if (index >= 0) {
            this.selectedTimestamps.splice(index, 1);
        } else {
            this.selectedTimestamps.push(timestamp);
        }

        this.updateSelectionDisplay();
        this.loadTimestamps(this.currentPage, this.currentSearchQuery);

        // 最初のタイムスタンプが選択された時、Spotify検索窓に反映（Spotify有効時のみ）
        if (this.spotifyEnabled && this.selectedTimestamps.length === 1) {
            document.getElementById('spotifySearch').value = this.selectedTimestamps[0].text;
        }
    }

    selectAll() {
        // 候補タブは単一選択なので全選択はしない
        if (this.isCandidateTabActive()) {
            return;
        }

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
        const linkedSongSpan = document.getElementById('selectedLinkedSong');
        const confirmBtn = document.getElementById('selectedConfirmBtn');

        container.classList.remove('hidden');

        if (this.selectedTimestamps.length === 0) {
            this.displayNoSelection(countSpan, textSpan, normalizedSpan, linkedSongSpan, confirmBtn);
        } else if (this.selectedTimestamps.length === 1) {
            this.displaySingleSelection(countSpan, textSpan, normalizedSpan, linkedSongSpan, confirmBtn);
        } else {
            this.displayMultipleSelection(countSpan, textSpan, normalizedSpan, linkedSongSpan, confirmBtn);
        }

        this.updateSpotifySelectedDisplay();
        document.getElementById('linkSongBtn').disabled = !(this.selectedTimestamps.length > 0 && this.selectedSong);

        // 候補は選択中のタイムスタンプに対するものなので、選択が変わったら作り直す。
        // 楽曲を選んだだけのときは作り直さない（同じ条件での再検索が無駄に走るため）
        if (this.isCandidateTabActive()) {
            const selectionKey = this.selectedTimestamps.map(t => t.id).join(',');

            if (this.lastCandidateSelectionKey !== selectionKey) {
                this.lastCandidateSelectionKey = selectionKey;
                this.loadCandidates();
            }
        }
    }

    displayNoSelection(countSpan, textSpan, normalizedSpan, linkedSongSpan, confirmBtn) {
        countSpan.textContent = '未選択';
        textSpan.textContent = 'タイムスタンプを選択してください';
        normalizedSpan.textContent = '';
        linkedSongSpan.textContent = '';
        linkedSongSpan.classList.add('hidden');
        confirmBtn.classList.add('hidden');
        confirmBtn.onclick = null;
        document.getElementById('linkSongBtn').disabled = true;
        document.getElementById('markAsPendingBtn').disabled = true;
        document.getElementById('markAsNotSongBtn').disabled = true;
        document.getElementById('unmarkAsNotSongBtn').disabled = true;
        document.getElementById('unlinkBtn').disabled = true;
        this.updateVideoButton(false);
    }

    displaySingleSelection(countSpan, textSpan, normalizedSpan, linkedSongSpan, confirmBtn) {
        const ts = this.selectedTimestamps[0];
        countSpan.textContent = '1件選択中';
        textSpan.textContent = ts.text;
        textSpan.title = ts.text;
        normalizedSpan.textContent = `正規化: ${ts.normalized_text}`;

        // 個別マッピング解除ボタンの参照を取得（なければ作成）
        let unlinkIndividualBtn = document.getElementById('unlinkIndividualBtn');
        if (!unlinkIndividualBtn) {
            unlinkIndividualBtn = document.createElement('button');
            unlinkIndividualBtn.id = 'unlinkIndividualBtn';
            unlinkIndividualBtn.className = 'px-2 py-1 text-xs bg-blue-500 hover:bg-blue-600 text-white rounded transition-colors';
            unlinkIndividualBtn.textContent = '個別解除';
            unlinkIndividualBtn.title = '個別マッピングを解除';
            confirmBtn.parentNode.insertBefore(unlinkIndividualBtn, confirmBtn.nextSibling);
        }

        // 紐づいている楽曲情報を表示
        if (ts.is_not_song) {
            linkedSongSpan.textContent = '楽曲ではない';
            linkedSongSpan.className = 'text-xs break-words text-red-600 dark:text-red-400';
            linkedSongSpan.classList.remove('hidden');
            confirmBtn.classList.add('hidden');
            confirmBtn.onclick = null;
            unlinkIndividualBtn.classList.add('hidden');
            unlinkIndividualBtn.onclick = null;
        } else if (ts.status === 'pending') {
            linkedSongSpan.textContent = '保留';
            linkedSongSpan.className = 'text-xs break-words text-orange-600 dark:text-orange-400';
            linkedSongSpan.classList.remove('hidden');
            confirmBtn.classList.add('hidden');
            confirmBtn.onclick = null;
            unlinkIndividualBtn.classList.add('hidden');
            unlinkIndividualBtn.onclick = null;
        } else if (ts.song) {
            const isAutoLinked = ts.is_manual === false && !ts.is_individual_mapping;
            const isIndividual = ts.is_individual_mapping === true;
            let prefix = '';
            if (isIndividual) {
                prefix = '[個別] ';
                linkedSongSpan.className = 'text-xs break-words text-blue-600 dark:text-blue-400';
            } else if (isAutoLinked) {
                prefix = '[自動] ';
                linkedSongSpan.className = 'text-xs break-words text-yellow-600 dark:text-yellow-400';
            } else {
                linkedSongSpan.className = 'text-xs break-words text-green-600 dark:text-green-400';
            }
            linkedSongSpan.textContent = `紐づき: ${prefix}${ts.song.title} / ${ts.song.artist}`;
            linkedSongSpan.classList.remove('hidden');

            // 自動紐付けの場合は確定ボタンを表示
            if (isAutoLinked) {
                confirmBtn.classList.remove('hidden');
                confirmBtn.onclick = () => this.confirmAutoLink(ts);
            } else {
                confirmBtn.classList.add('hidden');
                confirmBtn.onclick = null;
            }

            // 個別マッピングの場合は解除ボタンを表示
            if (isIndividual) {
                unlinkIndividualBtn.classList.remove('hidden');
                unlinkIndividualBtn.onclick = () => this.unlinkIndividualMapping(ts);
            } else {
                unlinkIndividualBtn.classList.add('hidden');
                unlinkIndividualBtn.onclick = null;
            }
        } else {
            linkedSongSpan.textContent = '';
            linkedSongSpan.classList.add('hidden');
            confirmBtn.classList.add('hidden');
            confirmBtn.onclick = null;
            unlinkIndividualBtn.classList.add('hidden');
            unlinkIndividualBtn.onclick = null;
        }

        // 保留ボタンは紐付け済みまたは自動紐付け済みの場合のみ有効
        const canMarkAsPending = ts.mapping && !ts.is_not_song && ts.status !== 'pending';
        document.getElementById('markAsPendingBtn').disabled = !canMarkAsPending;
        document.getElementById('markAsNotSongBtn').disabled = false;
        document.getElementById('unmarkAsNotSongBtn').disabled = !ts.is_not_song;
        document.getElementById('unlinkBtn').disabled = !ts.mapping && !ts.is_individual_mapping;

        if (ts.archive?.video_id) {
            this.updateVideoButton(true, ts.archive.video_id, ts.ts_num, ts.archive.title || '');
        } else {
            this.updateVideoButton(false, null, null, '動画情報なし');
        }
    }

    /**
     * 個別マッピングを解除
     */
    async unlinkIndividualMapping(ts) {
        if (!confirm('個別マッピングを解除しますか？\n解除すると、通常のマッピングに戻ります。')) {
            return;
        }

        try {
            this.showLoading();
            await timestampApiService.unlinkTsItem(ts.id);
            toast.success('個別マッピングを解除しました。');

            this.selectedTimestamps = [];
            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('個別マッピングの解除に失敗しました:', error);
            toast.error('解除に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    displayMultipleSelection(countSpan, textSpan, normalizedSpan, linkedSongSpan, confirmBtn) {
        countSpan.textContent = `${this.selectedTimestamps.length}件選択中`;
        const joinedText = this.selectedTimestamps.map(t => t.text).join(', ');

        if (joinedText.length > CONSTANTS.MAX_SELECTION_TEXT_LENGTH) {
            textSpan.textContent = joinedText.substring(0, CONSTANTS.MAX_SELECTION_TEXT_LENGTH) + '...';
        } else {
            textSpan.textContent = joinedText;
        }
        textSpan.title = joinedText;
        normalizedSpan.textContent = '';
        linkedSongSpan.textContent = '';
        linkedSongSpan.classList.add('hidden');
        confirmBtn.classList.add('hidden');
        confirmBtn.onclick = null;

        // 保留ボタンは紐付け済みの項目がある場合のみ有効
        const hasMappedItems = this.selectedTimestamps.some(ts => ts.mapping && !ts.is_not_song && ts.status !== 'pending');
        document.getElementById('markAsPendingBtn').disabled = !hasMappedItems;
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
        if (!this.spotifyEnabled) {
            toast.warning('Spotify API連携は現在無効になっています。');
            return;
        }
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
        const videoUrl = document.getElementById('songVideoUrl')?.value.trim() || '';

        if (!title || !artist) {
            toast.warning('楽曲名とアーティスト名を入力してください。');
            return;
        }

        const songData = { title, artist };
        if (videoUrl) {
            songData.video_url = videoUrl;
        }

        await this.registerSong(songData);
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

        if (this.spotifyEnabled && songData.spotify_track_id) {
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

        if (this.spotifyEnabled && songData.spotify_track_id) {
            this.searchSpotify();
        }
    }

    /**
     * 楽曲マスタ一覧の絞り込み条件が指定されているか
     *
     * @param {string|null} [search] 判定に使う検索文字列（省略時は入力欄の値）
     * @returns {boolean} 検索文字列またはreview_statusフィルタが指定されていればtrue
     */
    hasSongsQuery(search = null) {
        const value = search ?? (document.getElementById('songsSearch')?.value ?? '');

        return value.trim() !== '' || this.songReviewStatus !== null;
    }

    /**
     * 楽曲マスタの検索方式を切り替える
     * @param {string} mode - fuzzy（あいまい） または exact（完全一致）
     */
    setSongSearchMode(mode) {
        const nextMode = mode === CONSTANTS.SONG_SEARCH_MODE_EXACT
            ? CONSTANTS.SONG_SEARCH_MODE_EXACT
            : CONSTANTS.SONG_SEARCH_MODE_FUZZY;

        if (this.songSearchMode === nextMode) {
            return;
        }

        this.songSearchMode = nextMode;
        sessionStorage.setItem('songSearchMode', nextMode);
        this.updateSongSearchModeButtons();
        this.loadSongs(document.getElementById('songsSearch')?.value ?? '');
    }

    /**
     * 検索方式ボタンのアクティブ状態と説明文を更新
     */
    updateSongSearchModeButtons() {
        document.querySelectorAll('.song-search-mode').forEach(btn => {
            const isActive = btn.dataset.searchMode === this.songSearchMode;
            btn.classList.toggle('bg-blue-100', isActive);
            btn.classList.toggle('dark:bg-blue-900', isActive);
            btn.classList.toggle('text-blue-700', isActive);
            btn.classList.toggle('dark:text-blue-300', isActive);
            btn.classList.toggle('text-gray-600', !isActive);
            btn.classList.toggle('dark:text-gray-400', !isActive);
            btn.classList.toggle('hover:bg-gray-100', !isActive);
            btn.classList.toggle('dark:hover:bg-gray-700', !isActive);
        });

        const hint = document.getElementById('songSearchModeHint');
        if (hint) {
            hint.textContent = this.songSearchMode === CONSTANTS.SONG_SEARCH_MODE_EXACT
                ? '入力した文字列をそのまま検索します。'
                : '「/」「-」などの区切り文字を無視して単語ごとに検索します。タイムスタンプをそのまま貼り付けられます。';
        }
    }

    async loadSongs(search = '') {
        // 応答の追い越しを防ぐための世代番号。
        // 絞り込み条件がないパスは await を含まず同期的に確定するため、
        // ここで世代を進めておかないと、先行して飛んだ検索の応答が後から届いて
        // 「検索してください」の案内を上書きし、古い一覧だけが残る。
        const seq = ++this.songsRequestSeq;

        // この呼び出しが判断した絞り込み状態を displaySongs に渡す。
        // displaySongs 側で入力欄を読み直すと、await の間に値が変わったときに
        // 「APIを叩いたのに案内を表示」「検索していないのに0件」といった
        // 食い違いが起きる
        this.songsQueryActive = this.hasSongsQuery(search);

        // 絞り込み条件がない場合は全件が返ってくるだけで実用的ではないため、
        // APIを叩かず一覧を空のままにする（ページ表示直後と同じ状態）
        if (! this.songsQueryActive) {
            this.displaySongs([], 0);

            return;
        }

        try {
            const response = await songApiService.fetchSongs(search, this.songReviewStatus, this.songSearchMode);

            // 後発の呼び出しが既に表示を確定させていれば何もしない
            if (seq !== this.songsRequestSeq) {
                return;
            }

            if (response.data) {
                this.displaySongs(response.data, response.total);
            } else {
                this.displaySongs(response, response.length);
            }
        } catch (error) {
            if (seq !== this.songsRequestSeq) {
                return;
            }

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

        // 選択解除時などに一覧を再描画できるよう、表示中のリストを保持する
        this.lastDisplayedSongs = songs;
        this.lastDisplayedSongsTotal = total;

        container.innerHTML = '';
        // 絞り込み条件がないときは検索していないので、「0件」と出すと
        // 検索してヒットしなかったように見えてしまう
        this.updateSongsCount(this.songsQueryActive ? (total !== null ? total : songs.length) : null);

        if (!Array.isArray(songs)) {
            console.error('songs is not an array:', songs);
            container.innerHTML = '<p class="text-red-500 dark:text-red-400 text-sm">データの形式が正しくありません。</p>';
            return;
        }

        if (songs.length === 0) {
            const message = this.songsQueryActive
                ? '楽曲マスタがありません。'
                : '楽曲名・アーティスト名で検索してください。';
            container.innerHTML = `<p class="text-gray-500 dark:text-gray-400 text-sm">${message}</p>`;
            return;
        }

        songs.forEach(song => {
            container.appendChild(this.createSongElement(song, songs, total));
        });
    }

    /**
     * 候補タブが開いているか
     */
    isCandidateTabActive() {
        return !document.getElementById('candidatesList').classList.contains('hidden');
    }

    /**
     * 候補タブの内容を読み込む
     *
     * タイムスタンプが1件だけ選択されているときに候補を取得する。
     * 複数選択中は選択に触らず案内だけ出す（一括紐付けの選択を壊さないため）。
     */
    async loadCandidates() {
        const notice = document.getElementById('candidateNotice');
        const chipsArea = document.getElementById('candidateChipsArea');
        const results = document.getElementById('candidateResults');

        if (this.selectedTimestamps.length === 0) {
            // 選択が変わったので、進行中の取得の応答は適用しない
            this.candidateRequestSeq++;

            // 対象が無くなったので、チップの状態も破棄する。
            // 残したままだと、全解除後に同じタイムスタンプを選び直したときに
            // 前のチップ選択が残っているのか新規取得なのか読みにくくなる
            this.candidateTextKey = null;
            this.candidateParts = [];
            this.candidateSelectedIndices = new Set();

            notice.textContent = 'タイムスタンプを1件選ぶと候補を表示します。';
            chipsArea.classList.add('hidden');
            results.innerHTML = '';
            return;
        }

        if (this.selectedTimestamps.length > 1) {
            // 選択が変わったので、進行中の取得の応答は適用しない
            this.candidateRequestSeq++;

            this.renderMultiSelectionNotice();
            chipsArea.classList.add('hidden');
            results.innerHTML = '';
            return;
        }

        const text = this.selectedTimestamps[0].text;

        // 同じテキストのチップが既にあるなら、選択状態を保ったまま再検索する
        if (this.candidateTextKey === text) {
            // 複数選択中はチップ欄を隠すため、1件に絞られた直後は
            // このタイミングで表示状態を復元する必要がある。
            // 可視性のルールを1箇所に閉じるため、hiddenクラスを直接操作せず
            // renderCandidateChips() 経由にする（パーツが空なら隠したままになる）
            this.renderCandidateChips();
            await this.searchCandidatesByChips();
            return;
        }

        notice.textContent = '候補を探しています…';
        results.innerHTML = '';

        // 取得中に前のタイムスタンプのチップが押されると、進行中の取得が
        // 打ち消されて candidateTextKey が古いまま固定されてしまうため、
        // 先にチップを消して押せない状態にする
        this.candidateTextKey = null;
        this.candidateParts = [];
        this.candidateSelectedIndices = new Set();
        this.renderCandidateChips();

        // 応答の追い越しを防ぐための世代番号。
        // タイムスタンプを素早く切り替えると複数のリクエストが並行して飛び、
        // 先に選んだ方の遅い応答が後から届いて候補を巻き戻すと、
        // 表示中のタイムスタンプと無関係な楽曲が紐づく事故につながる
        const seq = ++this.candidateRequestSeq;

        try {
            const data = await songApiService.fetchCandidates(text);

            // 待っている間に選択が変わって新しい取得が始まっていたら、古い応答は捨てる
            if (seq !== this.candidateRequestSeq) {
                return;
            }

            this.candidateTextKey = text;
            this.candidateParts = data.parts;
            this.candidateSelectedIndices = new Set(
                data.parts.map((_, i) => i).filter(i => !data.ignored_indices.includes(i))
            );

            this.renderCandidateChips();
            notice.textContent = '';
            this.displayCandidates(data.songs, data.total);
        } catch (error) {
            if (seq !== this.candidateRequestSeq) {
                return;
            }

            console.error('候補の取得に失敗しました:', error);
            notice.textContent = '候補の取得に失敗しました。';
            chipsArea.classList.add('hidden');
        }
    }

    /**
     * 複数選択中の案内を表示する
     */
    renderMultiSelectionNotice() {
        const notice = document.getElementById('candidateNotice');
        notice.textContent = '';

        const message = document.createElement('p');
        message.className = 'mb-2';
        message.textContent = `${this.selectedTimestamps.length}件選択中です。候補を見るには1件だけ選んでください。`;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'px-3 py-1 bg-amber-600 text-white text-sm rounded hover:bg-amber-700';
        button.textContent = '最後に選んだ1件に絞る';
        button.addEventListener('click', () => {
            this.selectedTimestamps = this.selectedTimestamps.slice(-1);
            // 選択が複数→単一に変わるので、updateSelectionDisplay() の判定キーの
            // 差分検知により候補は自動的に作り直される（ここで明示的に呼ぶと二重取得になる）
            this.updateSelectionDisplay();
            this.loadTimestamps(this.currentPage, this.currentSearchQuery);
        });

        notice.appendChild(message);
        notice.appendChild(button);
    }

    /**
     * チップを描画する
     */
    renderCandidateChips() {
        const chipsArea = document.getElementById('candidateChipsArea');
        const container = document.getElementById('candidateChips');

        container.innerHTML = '';

        if (this.candidateParts.length === 0) {
            chipsArea.classList.add('hidden');
            return;
        }

        chipsArea.classList.remove('hidden');

        this.candidateParts.forEach((part, index) => {
            const selected = this.candidateSelectedIndices.has(index);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.textContent = part;
            chip.className = `px-2 py-1 text-xs rounded border ${
                selected
                    ? 'bg-amber-600 text-white border-amber-600'
                    : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
            }`;
            chip.addEventListener('click', () => this.toggleCandidateChip(index));
            container.appendChild(chip);
        });
    }

    /**
     * 候補一覧を描画する
     *
     * 候補の見た目と選択の扱いは楽曲マスタ一覧と揃える（createSongElement を再利用する）
     */
    displayCandidates(songs, total) {
        const results = document.getElementById('candidateResults');
        const notice = document.getElementById('candidateNotice');

        results.innerHTML = '';

        if (!Array.isArray(songs) || songs.length === 0) {
            notice.textContent = this.candidateSelectedIndices.size === 0
                ? '絞り込みの語を1つ以上選んでください。'
                : '候補が見つかりませんでした。チップを外して条件を緩めてください。';
            return;
        }

        notice.textContent = songs.length < total
            ? `${total}件の候補（上位${songs.length}件を表示）`
            : `${total}件の候補`;

        songs.forEach(song => {
            results.appendChild(this.createSongElement(song, songs, total, () => {
                this.displayCandidates(songs, total);
            }));
        });
    }

    /**
     * チップの選択を切り替えて再検索する
     */
    async toggleCandidateChip(index) {
        if (this.candidateSelectedIndices.has(index)) {
            this.candidateSelectedIndices.delete(index);
        } else {
            this.candidateSelectedIndices.add(index);
        }

        this.renderCandidateChips();
        await this.searchCandidatesByChips();
    }

    /**
     * 選択中のチップの語で候補を再検索する
     */
    async searchCandidatesByChips() {
        // チップは選択中のタイムスタンプに対するものなので、
        // 食い違っていたら何もしない（古いチップの取り残し対策）
        if (this.candidateTextKey === null
            || this.candidateTextKey !== this.selectedTimestamps[0]?.text) {
            return;
        }

        const results = document.getElementById('candidateResults');

        // タイムスタンプ切り替えと同じ世代番号を使う。
        // ここで進めておくことで、チップ連打中に飛んだ古いリクエストや、
        // 検索中にタイムスタンプ自体が切り替わったケースの遅い応答を
        // 後から無効化できる
        const seq = ++this.candidateRequestSeq;

        const words = this.candidateParts.filter((_, i) => this.candidateSelectedIndices.has(i));

        if (words.length === 0) {
            results.innerHTML = '';
            this.displayCandidates([], 0);
            return;
        }

        try {
            const response = await songApiService.fetchSongs(
                words.join(' '),
                null,
                CONSTANTS.SONG_SEARCH_MODE_FUZZY
            );

            if (seq !== this.candidateRequestSeq) {
                return;
            }

            const songs = response.data ?? response;
            this.displayCandidates(songs, response.total ?? songs.length);
        } catch (error) {
            if (seq !== this.candidateRequestSeq) {
                return;
            }

            console.error('候補の検索に失敗しました:', error);
            document.getElementById('candidateNotice').textContent = '候補の検索に失敗しました。';
        }
    }

    createSongElement(song, songs, total, onSelectionChange = null) {
        const div = document.createElement('div');
        const isSelected = this.selectedSong?.id === song.id;
        div.className = `p-2 border rounded cursor-pointer flex items-center justify-between ${
            isSelected
                ? 'bg-blue-100 dark:bg-blue-900 border-blue-500'
                : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
        }`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'flex-1 min-w-0';

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

        // 楽曲の長さを表示（ある場合）
        if (song.duration_ms) {
            const durationSpan = document.createElement('span');
            durationSpan.className = 'text-xs text-gray-400 dark:text-gray-500 ml-2';
            durationSpan.textContent = this.formatDuration(song.duration_ms);
            songInfo.appendChild(durationSpan);
        }

        // ボタンコンテナ
        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'flex items-center gap-1 flex-shrink-0 ml-2';

        // コピーボタン
        const copyBtn = this.createSongCopyButton(song);
        buttonContainer.appendChild(copyBtn);

        // 絞り込みボタン
        const filterBtn = this.createSongFilterButton(song);
        buttonContainer.appendChild(filterBtn);

        // 編集ボタン
        const editBtn = document.createElement('button');
        editBtn.className = 'px-2 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700';
        editBtn.textContent = '編集';
        editBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.openEditModal(song);
        });

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

        buttonContainer.appendChild(editBtn);
        buttonContainer.appendChild(deleteBtn);

        div.appendChild(contentDiv);
        div.appendChild(buttonContainer);

        div.addEventListener('click', () => {
            // 選択済みの楽曲をもう一度クリックしたら選択を解除する
            this.selectedSong = this.selectedSong?.id === song.id ? null : song;
            if (onSelectionChange) {
                onSelectionChange();
            } else {
                this.displaySongs(songs, total);
            }
            this.updateSelectionDisplay();
        });

        return div;
    }

    /**
     * 楽曲マスタ一覧の件数表示を更新
     * @param {number|null} count - 件数。nullを渡すと件数を表示しない
     */
    updateSongsCount(count) {
        const countDiv = document.getElementById('songsCount');
        if (countDiv) {
            countDiv.textContent = count === null ? '' : `${count}件`;
        }
    }

    /**
     * 楽曲マスタ用のコピーボタンを作成
     * @param {Object} song - 楽曲オブジェクト
     * @returns {HTMLElement} コピーボタン要素
     */
    createSongCopyButton(song) {
        const copyBtn = document.createElement('button');
        copyBtn.className = 'p-1.5 text-gray-600 dark:text-gray-400 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors';
        copyBtn.title = '楽曲名 / アーティスト名をコピー';
        copyBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        `;

        const originalIcon = copyBtn.innerHTML;
        const checkIcon = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        `;

        copyBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const textToCopy = `${song.title} / ${song.artist}`;
            navigator.clipboard.writeText(textToCopy);
            copyBtn.innerHTML = checkIcon;
            copyBtn.title = 'コピー済';
            toast.success('コピーしました');
            setTimeout(() => {
                copyBtn.innerHTML = originalIcon;
                copyBtn.title = '楽曲名 / アーティスト名をコピー';
            }, 1000);
        });

        return copyBtn;
    }

    /**
     * 楽曲マスタ用の絞り込みボタンを作成
     * @param {Object} song - 楽曲オブジェクト
     * @returns {HTMLElement} 絞り込みボタン要素
     */
    createSongFilterButton(song) {
        const filterBtn = document.createElement('button');
        filterBtn.className = 'p-1.5 text-gray-600 dark:text-gray-400 bg-gray-200 dark:bg-gray-700 rounded hover:bg-purple-300 dark:hover:bg-purple-600 transition-colors';
        filterBtn.title = '紐づくTSを表示';
        filterBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
            </svg>
        `;

        filterBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.setSongFilter(song);
        });

        return filterBtn;
    }

    /**
     * 楽曲による絞り込みを設定
     * @param {Object} song - 絞り込み対象の楽曲
     */
    setSongFilter(song) {
        this.currentSongFilter = song;
        this.updateSongFilterDisplay();
        this.loadTimestamps(1, this.currentSearchQuery);
        toast.info(`「${song.title}」に紐づくTSを表示中`);
    }

    /**
     * 楽曲による絞り込みを解除
     */
    clearSongFilter() {
        this.currentSongFilter = null;

        // 絞り込みを解除したら楽曲マスタの選択も解除する。
        // 選択が残ったままだと、意図しない楽曲への紐付けが起こりやすい
        if (this.selectedSong) {
            this.selectedSong = null;
            if (Array.isArray(this.lastDisplayedSongs)) {
                this.displaySongs(this.lastDisplayedSongs, this.lastDisplayedSongsTotal);
            }
            this.updateSelectionDisplay();
        }

        this.updateSongFilterDisplay();
        this.loadTimestamps(1, this.currentSearchQuery);
    }

    /**
     * 楽曲フィルター表示を更新
     */
    updateSongFilterDisplay() {
        const filterArea = document.getElementById('songFilterArea');
        if (!filterArea) return;

        if (this.currentSongFilter) {
            filterArea.classList.remove('hidden');
            const filterText = document.getElementById('songFilterText');
            if (filterText) {
                filterText.textContent = `${this.currentSongFilter.title} / ${this.currentSongFilter.artist}`;
                filterText.title = `${this.currentSongFilter.title} / ${this.currentSongFilter.artist}`;
            }
        } else {
            filterArea.classList.add('hidden');
        }
    }

    async deleteSong(songId) {
        try {
            this.showLoading();
            await songApiService.deleteSong(songId);
            toast.success('楽曲マスタを削除しました。');
            await this.loadSongs(document.getElementById('songsSearch').value);
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

            // 履歴用にコピーを保持
            const linkedTimestamps = [...this.selectedTimestamps];
            const linkedSong = { ...this.selectedSong };

            // 各タイムスタンプについて、同じnormalized_textを持つ他のタイムスタンプがあるか確認
            for (const ts of this.selectedTimestamps) {
                // 既存のマッピングと異なる楽曲にマッピングしようとしている場合のみダイアログ
                const currentSongId = ts.mapping?.song_id || null;
                const isChangingMapping = currentSongId && currentSongId !== this.selectedSong.id;

                if (isChangingMapping) {
                    const info = await timestampApiService.getTsItemsByNormalizedText(ts.normalized_text);

                    if (info.count > 1) {
                        this.hideLoading();
                        const choice = await this.showMappingChoiceDialog(ts, info, linkedSong);
                        this.showLoading();

                        if (choice === 'cancel') {
                            continue;
                        } else if (choice === 'individual') {
                            // 個別マッピング
                            await timestampApiService.linkTsItemToSong(ts.id, this.selectedSong.id);
                        } else {
                            // 一括更新
                            await timestampApiService.linkTimestamp(ts.normalized_text, this.selectedSong.id);
                        }
                    } else {
                        // 他に同じnormalized_textのタイムスタンプがない場合は通常通り
                        await timestampApiService.linkTimestamp(ts.normalized_text, this.selectedSong.id);
                    }
                } else {
                    // 新規マッピングまたは同じ楽曲への再マッピングの場合は通常通り
                    await timestampApiService.linkTimestamp(ts.normalized_text, this.selectedSong.id);
                }
            }

            toast.success(`${this.selectedTimestamps.length}件のタイムスタンプを紐づけました。`);

            // 履歴に追加
            this.addToHistory('link', linkedTimestamps, linkedSong);

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

    /**
     * マッピング方法の選択ダイアログを表示
     * @param {Object} ts - 対象のタイムスタンプ
     * @param {Object} info - 同じnormalized_textを持つタイムスタンプの情報
     * @param {Object} newSong - 新しい楽曲
     * @returns {Promise<string>} 'all' | 'individual' | 'cancel'
     */
    showMappingChoiceDialog(ts, info, newSong) {
        return new Promise((resolve) => {
            const existingDialog = document.getElementById('mappingChoiceDialog');
            if (existingDialog) {
                existingDialog.remove();
            }

            const currentSong = info.current_mapping?.song;
            const currentSongText = currentSong
                ? `${currentSong.title} / ${currentSong.artist}`
                : '未紐付け';

            const dialog = document.createElement('div');
            dialog.id = 'mappingChoiceDialog';
            dialog.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            dialog.innerHTML = `
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full mx-4 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                        マッピング方法の選択
                    </h3>
                    <div class="space-y-3 mb-6">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            同じ正規化テキストのタイムスタンプが <strong class="text-blue-600">${info.count}件</strong> あります。
                        </p>
                        <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded text-sm">
                            <div class="mb-2">
                                <span class="text-gray-500 dark:text-gray-400">正規化テキスト:</span>
                                <span class="font-medium text-gray-900 dark:text-gray-100 ml-2">${ts.normalized_text}</span>
                            </div>
                            <div class="mb-2">
                                <span class="text-gray-500 dark:text-gray-400">現在のマッピング:</span>
                                <span class="font-medium text-green-600 dark:text-green-400 ml-2">${currentSongText}</span>
                            </div>
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">新しいマッピング:</span>
                                <span class="font-medium text-blue-600 dark:text-blue-400 ml-2">${newSong.title} / ${newSong.artist}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-3">
                        <button id="mappingChoiceAll" class="w-full px-4 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors">
                            すべて更新 (${info.count}件)
                            <span class="block text-xs font-normal opacity-80 mt-1">同じ正規化テキストの全タイムスタンプを更新</span>
                        </button>
                        <button id="mappingChoiceIndividual" class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                            この項目のみ
                            <span class="block text-xs font-normal opacity-80 mt-1">このタイムスタンプだけ個別にマッピング</span>
                        </button>
                        <button id="mappingChoiceCancel" class="w-full px-4 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-700 dark:text-gray-200 rounded-lg font-medium transition-colors">
                            キャンセル
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(dialog);

            document.getElementById('mappingChoiceAll').addEventListener('click', () => {
                dialog.remove();
                resolve('all');
            });

            document.getElementById('mappingChoiceIndividual').addEventListener('click', () => {
                dialog.remove();
                resolve('individual');
            });

            document.getElementById('mappingChoiceCancel').addEventListener('click', () => {
                dialog.remove();
                resolve('cancel');
            });

            // ESCキーでキャンセル
            const handleEsc = (e) => {
                if (e.key === 'Escape') {
                    dialog.remove();
                    document.removeEventListener('keydown', handleEsc);
                    resolve('cancel');
                }
            };
            document.addEventListener('keydown', handleEsc);
        });
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

            // 履歴用にコピーを保持
            const markedTimestamps = [...this.selectedTimestamps];

            for (const ts of this.selectedTimestamps) {
                // normalized_textが空の場合は元のtextも送信
                const normalizedText = ts.normalized_text || null;
                const text = !ts.normalized_text ? ts.text : null;
                await timestampApiService.markAsNotSong(normalizedText, text);
            }

            toast.success('楽曲ではないとマークしました。');

            // 履歴に追加
            this.addToHistory('not_song', markedTimestamps);

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

            // 履歴用にコピーを保持
            const unmarkedTimestamps = [...notSongTimestamps];

            for (const ts of notSongTimestamps) {
                await timestampApiService.unmarkAsNotSong(ts.normalized_text);
            }

            toast.success('「楽曲ではない」マークを解除しました。');

            // 履歴に追加
            this.addToHistory('unmark_not_song', unmarkedTimestamps);

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

            // 履歴用にコピーを保持
            const unlinkedTimestamps = [...this.selectedTimestamps];

            for (const ts of this.selectedTimestamps) {
                await timestampApiService.unlinkTimestamp(ts.normalized_text);
            }

            toast.success('紐づけを解除しました。');

            // 履歴に追加
            this.addToHistory('unlink', unlinkedTimestamps);

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

    async markAsPending() {
        if (this.selectedTimestamps.length === 0) {
            toast.warning('タイムスタンプを選択してください。');
            return;
        }

        // 保留可能なタイムスタンプのみをフィルタリング
        const pendableTimestamps = this.selectedTimestamps.filter(ts => ts.mapping && !ts.is_not_song && ts.status !== 'pending');

        if (pendableTimestamps.length === 0) {
            toast.warning('保留可能なタイムスタンプがありません。');
            return;
        }

        if (!confirm(`${pendableTimestamps.length}件のタイムスタンプを保留にしますか?\n紐付けが解除され、再び自動紐付けの対象にならなくなります。`)) {
            return;
        }

        try {
            this.showLoading();

            // 履歴用にコピーを保持
            const markedTimestamps = [...pendableTimestamps];

            for (const ts of pendableTimestamps) {
                await timestampApiService.markAsPending(ts.normalized_text);
            }

            toast.success('保留にしました。');

            // 履歴に追加
            this.addToHistory('pending', markedTimestamps);

            this.selectedTimestamps = [];

            await this.loadTimestamps(this.currentPage, this.currentSearchQuery);
            this.updateSelectionDisplay();
        } catch (error) {
            console.error('保留に失敗しました:', error);
            toast.error('保留に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    showTab(tabId) {
        // Spotify無効時はSpotifyタブへの切り替えを防止
        if (tabId === 'spotifyTab' && !this.spotifyEnabled) {
            return;
        }

        // 候補タブではタイムスタンプをラジオボタンで描画するため、
        // 他のタブへ移るときはチェックボックスに戻す必要がある。
        // タブ内容を隠す前に判定しておく
        const leavingCandidateTab = this.isCandidateTabActive() && tabId !== 'candidatesTab';

        // 選んでいた楽曲は切り替え前のタブの文脈でしか意味を持たない。
        // 残したまま別タブに切り替えると、そこで選んだ全く異なる楽曲が
        // 選択されたまま紐付けボタンを押せてしまう事故につながるため破棄する。
        // 同じタブを開き直しただけ（タブ内の再検索トリガーなど）では
        // 選択中の楽曲を消したくないため、実際にタブが変わった場合のみ行う
        if (tabId !== this.activeTabId) {
            this.selectedSong = null;
            document.getElementById('linkSongBtn').disabled = true;
        }
        this.activeTabId = tabId;

        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('border-green-500', 'text-green-600', 'border-blue-500', 'text-blue-600', 'border-purple-500', 'text-purple-600', 'border-amber-500', 'text-amber-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });

        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });

        // 候補タブ以外では全選択を使えるようにする
        document.getElementById('selectAllBtn').disabled = false;

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

            // 一覧タブに戻ってきたタイミングで再検索する。
            // 「検索→見つからず登録→一覧に戻る」の流れで、登録したばかりの
            // 楽曲が古い検索結果のせいで表示されない問題を防ぐ。
            // 絞り込み条件がないときは loadSongs() がAPIを叩かずに
            // 検索を促すメッセージを描画する（初回表示時もここを通る）。
            const songsSearch = document.getElementById('songsSearch')?.value ?? '';
            this.loadSongs(songsSearch);
        } else if (tabId === 'candidatesTab') {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-amber-500', 'text-amber-600');
            document.getElementById('candidatesList').classList.remove('hidden');
            document.getElementById('selectAllBtn').disabled = true;

            // ラジオ/チェックボックスの表示を選択状態に合わせて切り替える
            this.loadTimestamps(this.currentPage, this.currentSearchQuery);

            // ここで直接 loadCandidates() を呼ぶため、updateSelectionDisplay() 側の
            // 判定用キーもここで揃えておく。揃えないと、タブを開いた直後に候補内の
            // 楽曲を1件クリックしただけで（選択自体は変わっていないのに）
            // updateSelectionDisplay() 経由で無駄な再取得が走ってしまう
            this.lastCandidateSelectionKey = this.selectedTimestamps.map(t => t.id).join(',');
            this.loadCandidates();
        }

        // 候補タブから離れるときは一覧を再描画し、ラジオボタンを
        // チェックボックスに戻す（#timestampsList はタブと独立した
        // 常時表示領域のため、離脱時に明示的に再描画しないと
        // input の type が radio のまま取り残されてしまう）
        if (leavingCandidateTab) {
            this.loadTimestamps(this.currentPage, this.currentSearchQuery);
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

    // ===== 操作履歴機能 =====

    /**
     * 操作履歴パネルの初期化
     */
    initHistoryPanel() {
        // フローティングボタンを作成
        this.createHistoryButton();
        this.createHistoryPanel();
    }

    /**
     * フローティングボタンを作成
     */
    createHistoryButton() {
        const button = document.createElement('button');
        button.id = 'historyButton';
        button.className = 'fixed bottom-6 right-6 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg flex items-center justify-center transition-all z-40';
        button.title = '操作履歴';
        button.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span id="historyBadge" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center hidden">0</span>
        `;
        button.addEventListener('click', () => this.toggleHistoryPanel());
        document.body.appendChild(button);
    }

    /**
     * 履歴パネルを作成
     */
    createHistoryPanel() {
        const panel = document.createElement('div');
        panel.id = 'historyPanel';
        panel.className = 'fixed bottom-24 right-6 w-80 max-h-96 bg-white dark:bg-gray-800 rounded-lg shadow-xl border border-gray-200 dark:border-gray-700 hidden z-50 flex flex-col';
        panel.innerHTML = `
            <div class="p-3 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center flex-shrink-0">
                <h4 class="font-semibold text-gray-900 dark:text-gray-100">操作履歴</h4>
                <button id="clearHistoryBtn" class="text-xs text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-400">
                    クリア
                </button>
            </div>
            <div id="historyList" class="flex-1 overflow-y-auto p-2 space-y-2">
                <p class="text-gray-500 dark:text-gray-400 text-sm text-center py-4">履歴はありません</p>
            </div>
        `;
        document.body.appendChild(panel);

        // クリアボタンのイベント
        document.getElementById('clearHistoryBtn').addEventListener('click', () => {
            this.clearHistory();
        });

        // パネル外クリックで閉じる
        document.addEventListener('click', (e) => {
            const panel = document.getElementById('historyPanel');
            const button = document.getElementById('historyButton');
            if (!panel.contains(e.target) && !button.contains(e.target) && !panel.classList.contains('hidden')) {
                panel.classList.add('hidden');
            }
        });
    }

    /**
     * 履歴パネルの表示/非表示を切り替え
     */
    toggleHistoryPanel() {
        const panel = document.getElementById('historyPanel');
        panel.classList.toggle('hidden');
    }

    /**
     * 操作を履歴に追加
     * @param {string} type - 操作種別 (link, not_song, unlink, unmark_not_song, confirm_auto_link)
     * @param {Array} timestamps - 操作対象のタイムスタンプ
     * @param {Object|null} song - 紐付けた楽曲情報
     */
    addToHistory(type, timestamps, song = null) {
        const typeLabels = {
            'link': '紐付け',
            'not_song': '非楽曲',
            'unlink': '解除',
            'unmark_not_song': '非楽曲解除',
            'confirm_auto_link': '自動確定',
            'pending': '保留'
        };

        const typeColors = {
            'link': 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200',
            'not_song': 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200',
            'unlink': 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200',
            'unmark_not_song': 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200',
            'confirm_auto_link': 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200',
            'pending': 'bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200'
        };

        const entry = {
            id: Date.now(),
            type,
            typeLabel: typeLabels[type] || type,
            typeColor: typeColors[type] || 'bg-gray-100 text-gray-800',
            timestamps: timestamps.map(ts => ({
                text: ts.text,
                normalized_text: ts.normalized_text
            })),
            song: song ? { title: song.title, artist: song.artist } : null,
            createdAt: new Date()
        };

        this.operationHistory.unshift(entry);

        // 最大件数を超えたら古いものを削除
        if (this.operationHistory.length > this.maxHistoryItems) {
            this.operationHistory = this.operationHistory.slice(0, this.maxHistoryItems);
        }

        this.updateHistoryDisplay();
    }

    /**
     * 履歴表示を更新
     */
    updateHistoryDisplay() {
        const listContainer = document.getElementById('historyList');
        const badge = document.getElementById('historyBadge');

        // バッジ更新
        if (this.operationHistory.length > 0) {
            badge.textContent = this.operationHistory.length;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }

        // リスト更新
        if (this.operationHistory.length === 0) {
            listContainer.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-sm text-center py-4">履歴はありません</p>';
            return;
        }

        listContainer.innerHTML = '';
        this.operationHistory.forEach(entry => {
            const item = this.createHistoryItem(entry);
            listContainer.appendChild(item);
        });
    }

    /**
     * 履歴アイテム要素を作成
     */
    createHistoryItem(entry) {
        const div = document.createElement('div');
        div.className = 'p-2 bg-gray-50 dark:bg-gray-700 rounded text-sm';

        // ヘッダー（種別と時刻）
        const header = document.createElement('div');
        header.className = 'flex justify-between items-center mb-1';

        const typeSpan = document.createElement('span');
        typeSpan.className = `px-2 py-0.5 rounded text-xs font-medium ${entry.typeColor}`;
        typeSpan.textContent = entry.typeLabel;

        const timeSpan = document.createElement('span');
        timeSpan.className = 'text-xs text-gray-400';
        timeSpan.textContent = this.formatTime(entry.createdAt);

        header.appendChild(typeSpan);
        header.appendChild(timeSpan);
        div.appendChild(header);

        // タイムスタンプテキスト
        entry.timestamps.forEach(ts => {
            const tsDiv = document.createElement('div');
            tsDiv.className = 'flex items-center gap-1 mt-1';

            const textSpan = document.createElement('span');
            textSpan.className = 'text-gray-700 dark:text-gray-300 truncate flex-1 cursor-pointer hover:text-blue-600 dark:hover:text-blue-400';
            textSpan.textContent = ts.text;
            textSpan.title = `クリックでコピー: ${ts.text}`;
            textSpan.addEventListener('click', () => {
                navigator.clipboard.writeText(ts.text);
                toast.success('コピーしました');
            });

            tsDiv.appendChild(textSpan);
            div.appendChild(tsDiv);
        });

        // 楽曲情報（紐付けの場合）
        if (entry.song) {
            const songDiv = document.createElement('div');
            songDiv.className = 'mt-1 text-xs text-gray-500 dark:text-gray-400 truncate cursor-pointer hover:text-blue-600 dark:hover:text-blue-400';
            songDiv.textContent = `→ ${entry.song.title} / ${entry.song.artist}`;
            songDiv.title = `クリックでコピー: ${entry.song.title} / ${entry.song.artist}`;
            songDiv.addEventListener('click', () => {
                navigator.clipboard.writeText(`${entry.song.title} / ${entry.song.artist}`);
                toast.success('コピーしました');
            });
            div.appendChild(songDiv);
        }

        return div;
    }

    /**
     * 時刻をフォーマット
     */
    formatTime(date) {
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) {
            return 'たった今';
        } else if (diff < 3600000) {
            return `${Math.floor(diff / 60000)}分前`;
        } else {
            return date.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
        }
    }

    /**
     * 履歴をクリア
     */
    clearHistory() {
        this.operationHistory = [];
        this.updateHistoryDisplay();
        toast.info('履歴をクリアしました');
    }

    // ===== 楽曲編集機能 =====

    /**
     * 編集モーダルを開く
     * @param {Object} song - 編集対象の楽曲
     */
    openEditModal(song) {
        this.editingSong = song;
        document.getElementById('editSongId').value = song.id;
        document.getElementById('editSongTitle').value = song.title;
        document.getElementById('editSongArtist').value = song.artist;
        document.getElementById('editSongVideoUrl').value = song.video_url || '';
        document.getElementById('editSongDurationMs').value = song.duration_ms || '';
        document.getElementById('editSongDurationSeconds').value = song.duration_ms ? Math.round(song.duration_ms / 1000) : '';
        this.updateDurationDisplay(song.duration_ms);
        document.getElementById('editSongModal').classList.remove('hidden');
    }

    /**
     * 編集モーダルを閉じる
     */
    closeEditModal() {
        this.editingSong = null;
        document.getElementById('editSongModal').classList.add('hidden');
        document.getElementById('editSongForm').reset();
        document.getElementById('editSongDurationFormatted').textContent = '';
    }

    /**
     * 楽曲マスタを更新
     */
    async updateSong() {
        const songId = document.getElementById('editSongId').value;
        const title = document.getElementById('editSongTitle').value.trim();
        const artist = document.getElementById('editSongArtist').value.trim();
        const videoUrl = document.getElementById('editSongVideoUrl').value.trim();
        const durationMs = document.getElementById('editSongDurationMs').value;

        if (!title || !artist) {
            toast.warning('楽曲名とアーティスト名を入力してください。');
            return;
        }

        const updateData = { title, artist };
        if (videoUrl) {
            updateData.video_url = videoUrl;
        } else {
            updateData.video_url = null;
        }
        if (durationMs) {
            updateData.duration_ms = parseInt(durationMs, 10);
        } else {
            updateData.duration_ms = null;
        }

        try {
            this.showLoading();
            const response = await songApiService.updateSong(songId, updateData);
            toast.success('楽曲マスタを更新しました。');
            this.closeEditModal();
            await this.loadSongs(document.getElementById('songsSearch').value);

            // 選択中の楽曲が更新された場合は更新
            if (this.selectedSong?.id === songId) {
                this.selectedSong = response.song;
                this.updateSelectionDisplay();
            }
        } catch (error) {
            console.error('更新に失敗しました:', error);
            toast.error('更新に失敗しました。');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * 動画URLから秒数を取得
     * YouTube および ニコニコ動画に対応
     */
    async fetchVideoDuration() {
        const videoUrl = document.getElementById('editSongVideoUrl').value.trim();

        if (!videoUrl) {
            toast.warning('動画URLを入力してください。');
            return;
        }

        try {
            this.showLoading();
            const response = await songApiService.fetchVideoDuration(videoUrl);
            document.getElementById('editSongDurationMs').value = response.duration_ms;
            document.getElementById('editSongDurationSeconds').value = Math.round(response.duration_ms / 1000);
            this.updateDurationDisplay(response.duration_ms);

            const platformName = response.platform === 'youtube' ? 'YouTube' : 'ニコニコ動画';
            toast.success(`${platformName}から秒数を取得しました。`);
        } catch (error) {
            console.error('秒数取得に失敗しました:', error);
            const errorMessage = error.response?.data?.error || '秒数の取得に失敗しました。';
            toast.error(errorMessage);
        } finally {
            this.hideLoading();
        }
    }

    /**
     * 秒数入力時の自動変換（秒→ミリ秒）
     */
    onDurationSecondsInput(e) {
        const seconds = parseInt(e.target.value, 10);
        if (!isNaN(seconds) && seconds >= 0) {
            const ms = seconds * 1000;
            document.getElementById('editSongDurationMs').value = ms;
            this.updateDurationDisplay(ms);
        }
    }

    /**
     * ミリ秒入力時の自動変換（ミリ秒→秒）
     */
    onDurationMsInput(e) {
        const ms = parseInt(e.target.value, 10);
        if (!isNaN(ms) && ms >= 0) {
            const seconds = Math.round(ms / 1000);
            document.getElementById('editSongDurationSeconds').value = seconds;
            this.updateDurationDisplay(ms);
        }
    }

    /**
     * 秒数表示を更新
     * @param {number|string} durationMs - ミリ秒
     */
    updateDurationDisplay(durationMs) {
        const formatted = durationMs ? this.formatDuration(durationMs) : '';
        document.getElementById('editSongDurationFormatted').textContent = formatted;
    }

    /**
     * ミリ秒を時間フォーマットに変換
     * @param {number|string} durationMs - ミリ秒
     * @returns {string} フォーマットされた時間（例: "3:45" または "1:23:45"）
     */
    formatDuration(durationMs) {
        const ms = parseInt(durationMs, 10);
        if (isNaN(ms) || ms <= 0) return '';

        const totalSeconds = Math.floor(ms / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        if (hours > 0) {
            return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    new TimestampNormalization();
});
