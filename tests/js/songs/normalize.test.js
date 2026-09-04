import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';

vi.mock('axios', () => ({
    default: {
        defaults: { withCredentials: true, headers: { common: {} } },
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));
vi.mock('@/utils/toast.js', () => ({
    default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));
vi.mock('@/songs/services/TimestampApiService.js', () => ({
    timestampApiService: {
        fetchTimestamps: vi.fn().mockResolvedValue({ data: [], total: 0 }),
        searchTimestamps: vi.fn().mockResolvedValue({ data: [], total: 0 }),
    },
}));
vi.mock('@/songs/services/SongApiService.js', () => ({
    songApiService: {
        fetchSongs: vi.fn().mockResolvedValue({ data: [], total: 0 }),
        searchSongs: vi.fn().mockResolvedValue({ data: [], total: 0 }),
        fetchNotations: vi.fn().mockResolvedValue({ data: { notations: [] } }),
    },
}));
vi.mock('@/songs/components/SimilarSongsDialog.js', () => ({
    SimilarSongsDialog: { show: vi.fn() },
}));
vi.mock('@/songs/components/SongOperationDialog.js', () => ({
    SongOperationDialog: { show: vi.fn().mockResolvedValue({ action: 'cancelled' }) },
}));
vi.mock('@/songs/components/ArtistTagSyncDialog.js', () => ({
    ArtistTagSyncDialog: { show: vi.fn() },
}));
vi.mock('@/shared/components/Pagination.js', () => ({
    Pagination: vi.fn(),
}));

function setupMinimalDOM() {
    document.body.innerHTML = `
        <input id="timestampSearch" />
        <div id="timestampsList"></div>
        <div id="selectedTimestamp" class="hidden"></div>
        <span id="selectedCount"></span>
        <span id="selectedText"></span>
        <span id="selectedNormalized"></span>
        <span id="selectedLinkedSong"></span>
        <button id="selectedConfirmBtn" class="hidden"></button>
        <input id="songsSearch" />
        <div id="songsList"></div>
        <div id="songsCount"></div>
        <div id="candidatesList" class="hidden"></div>
        <div id="candidateNotice"></div>
        <div id="candidateTextArea" class="hidden"></div>
        <div id="candidateKeywordsArea" class="hidden"></div>
        <div id="candidateResults"></div>
        <div id="candidateKeywords"></div>
        <div id="spotifyResults" class="hidden"></div>
        <input id="spotifySearch" />
        <div id="loadingModal" class="hidden"></div>
        <button id="linkSongBtn" disabled></button>
        <button id="markNotSongBtn"></button>
        <button id="markAsPendingBtn" disabled></button>
        <button id="markAsNotSongBtn" disabled></button>
        <button id="unmarkAsNotSongBtn" disabled></button>
        <button id="unlinkBtn" disabled></button>
        <button id="setPendingBtn"></button>
        <button id="selectAllBtn"></button>
        <button id="deselectAllBtn"></button>
        <button id="undoBtn" disabled></button>
        <div id="operationHistory" class="hidden"></div>
        <div id="historyList"></div>
        <span id="videoTitle"></span>
        <button id="videoLinkBtn" disabled></button>
        <div class="tab-button" data-tab="songsTab"></div>
        <div class="tab-button" data-tab="candidatesTab"></div>
        <div class="tab-button" data-tab="spotifyTab"></div>
        <div class="tab-content" id="songsTab"></div>
        <div class="tab-content" id="candidatesTab"></div>
        <div class="tab-content" id="spotifyTab"></div>
        <div id="filterButtons"></div>
        <button class="filter-btn" data-filter="active"></button>
        <button class="filter-btn" data-filter="all"></button>
        <button class="filter-btn" data-filter="unlinked"></button>
        <button class="filter-btn" data-filter="linked"></button>
        <button class="filter-btn" data-filter="not_song"></button>
        <button class="filter-btn" data-filter="auto_linked"></button>
        <button class="filter-btn" data-filter="pending"></button>
        <button id="songSearchModeFuzzy"></button>
        <button id="songSearchModeExact"></button>
        <div id="songFilterDisplay" class="hidden"></div>
        <span id="songFilterName"></span>
        <button id="clearSongFilterBtn"></button>
        <div id="songReviewFilter"></div>
        <select id="songReviewSelect"><option value="">すべて</option></select>
        <div id="editSongModal" class="hidden"></div>
        <input id="editSongId" />
        <input id="editSongTitle" />
        <input id="editSongArtist" />
        <input id="editSongVideoUrl" />
        <input id="editSongDurationMs" />
        <span id="editSongDurationFormatted"></span>
        <div id="notationCandidatesList" class="hidden"></div>
        <svg id="notationToggleIcon"></svg>
        <div id="editSongModalTitle"></div>
        <button id="editSongSaveBtn"></button>
        <button id="editSongCancelBtn"></button>
        <button id="editSongDeleteBtn"></button>
        <button id="editSongSpotifyBtn" class="hidden"></button>
        <div id="timestampPagination"></div>
    `;
}

/**
 * constructorがinit()を呼ぶとAPIリクエスト等の副作用が出るため、
 * Object.create でプロトタイプだけ借りたインスタンスを作る
 */
async function createInstance() {
    const { TimestampNormalization } = await import('@/songs/normalize.js');
    const instance = Object.create(TimestampNormalization.prototype);
    instance.selectedTimestamps = [];
    instance.selectedSong = null;
    instance.selectedSpotifyTrack = null;
    instance.currentPage = 1;
    instance.currentSearchQuery = '';
    instance.timestampSearchTimeout = null;
    instance.songsSearchTimeout = null;
    instance.currentFilter = 'active';
    instance.currentSongFilter = null;
    instance.operationHistory = [];
    instance.maxHistoryItems = 20;
    instance.songReviewStatus = null;
    instance.songsRequestSeq = 0;
    instance.songsQueryActive = false;
    instance.songSearchMode = 'fuzzy';
    instance.candidateKeywords = [];
    instance.candidateTextKey = null;
    instance.candidateRequestSeq = 0;
    instance.lastDisplayedCandidates = [];
    instance.lastDisplayedCandidatesTotal = 0;
    instance.lastCandidateSelectionKey = null;
    instance.activeTabId = null;
    instance.currentPageTimestamps = [];
    instance.spotifyEnabled = false;
    return instance;
}

describe('TimestampNormalization', () => {
    let instance;

    beforeEach(async () => {
        setupMinimalDOM();
        instance = await createInstance();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // =========================================================================
    // formatDuration — 純粋関数
    // =========================================================================
    describe('formatDuration', () => {
        it('ミリ秒を分:秒にフォーマットする', () => {
            expect(instance.formatDuration(225000)).toBe('3:45');
        });

        it('秒が1桁のときゼロ埋めする', () => {
            expect(instance.formatDuration(61000)).toBe('1:01');
        });

        it('1時間以上のとき時:分:秒にフォーマットする', () => {
            expect(instance.formatDuration(5025000)).toBe('1:23:45');
        });

        it('0ミリ秒は空文字を返す', () => {
            expect(instance.formatDuration(0)).toBe('');
        });

        it('負の値は空文字を返す', () => {
            expect(instance.formatDuration(-1000)).toBe('');
        });

        it('NaNは空文字を返す', () => {
            expect(instance.formatDuration('abc')).toBe('');
        });

        it('文字列の数値も変換できる', () => {
            expect(instance.formatDuration('225000')).toBe('3:45');
        });

        it('ちょうど60秒は1:00', () => {
            expect(instance.formatDuration(60000)).toBe('1:00');
        });

        it('ちょうど1時間は1:00:00', () => {
            expect(instance.formatDuration(3600000)).toBe('1:00:00');
        });

        it('1秒未満（999ms）は0:00', () => {
            expect(instance.formatDuration(999)).toBe('0:00');
        });
    });

    // =========================================================================
    // generateVideoUrl — 純粋関数
    // =========================================================================
    describe('generateVideoUrl', () => {
        it('videoIdとtsNumからURLを生成する', () => {
            expect(instance.generateVideoUrl('dQw4w9WgXcQ', 120))
                .toBe('https://youtu.be/dQw4w9WgXcQ?t=120s');
        });

        it('tsNumが0のときタイムパラメータなし', () => {
            expect(instance.generateVideoUrl('dQw4w9WgXcQ', 0))
                .toBe('https://youtu.be/dQw4w9WgXcQ');
        });

        it('tsNumがnullのときタイムパラメータなし', () => {
            expect(instance.generateVideoUrl('dQw4w9WgXcQ', null))
                .toBe('https://youtu.be/dQw4w9WgXcQ');
        });

        it('videoIdが空のときnullを返す', () => {
            expect(instance.generateVideoUrl('', 120)).toBeNull();
        });

        it('videoIdがnullのときnullを返す', () => {
            expect(instance.generateVideoUrl(null, 120)).toBeNull();
        });
    });

    // =========================================================================
    // applyNotation — DOM操作（入力フォームへの値セット）
    // =========================================================================
    describe('applyNotation', () => {
        it('スラッシュ区切りでアーティストとタイトルを分割する', () => {
            instance.applyNotation('YOASOBI / 夜に駆ける');
            expect(document.getElementById('editSongArtist').value).toBe('YOASOBI');
            expect(document.getElementById('editSongTitle').value).toBe('夜に駆ける');
        });

        it('全角スラッシュでも分割する', () => {
            instance.applyNotation('Ado／うっせぇわ');
            expect(document.getElementById('editSongArtist').value).toBe('Ado');
            expect(document.getElementById('editSongTitle').value).toBe('うっせぇわ');
        });

        it('ハイフン区切りでも分割する', () => {
            instance.applyNotation('米津玄師 - Lemon');
            expect(document.getElementById('editSongArtist').value).toBe('米津玄師');
            expect(document.getElementById('editSongTitle').value).toBe('Lemon');
        });

        it('コロン区切りでも分割する', () => {
            instance.applyNotation('King Gnu：飛行艇');
            expect(document.getElementById('editSongArtist').value).toBe('King Gnu');
            expect(document.getElementById('editSongTitle').value).toBe('飛行艇');
        });

        it('パイプ区切りでも分割する', () => {
            instance.applyNotation('LiSA｜紅蓮華');
            expect(document.getElementById('editSongArtist').value).toBe('LiSA');
            expect(document.getElementById('editSongTitle').value).toBe('紅蓮華');
        });

        it('区切り文字なしの場合はタイトルとして全体をセットする', () => {
            instance.applyNotation('夜に駆ける');
            expect(document.getElementById('editSongTitle').value).toBe('夜に駆ける');
        });

        it('前後の空白をトリムする', () => {
            instance.applyNotation('  YOASOBI  /  夜に駆ける  ');
            expect(document.getElementById('editSongArtist').value).toBe('YOASOBI');
            expect(document.getElementById('editSongTitle').value).toBe('夜に駆ける');
        });

        it('複数の区切り文字がある場合は最初のもので分割する', () => {
            instance.applyNotation('A/B/C');
            expect(document.getElementById('editSongArtist').value).toBe('A');
            expect(document.getElementById('editSongTitle').value).toBe('B/C');
        });
    });

    // =========================================================================
    // createStatusElement — DOM生成
    // =========================================================================
    describe('createStatusElement', () => {
        it('is_not_songのとき「楽曲ではない」を表示する', () => {
            const el = instance.createStatusElement({ is_not_song: true });
            expect(el.textContent).toBe('楽曲ではない');
            expect(el.className).toContain('text-red-600');
        });

        it('status=pendingのとき「保留」を表示する', () => {
            const el = instance.createStatusElement({ status: 'pending' });
            expect(el.textContent).toBe('保留');
            expect(el.className).toContain('text-orange-600');
        });

        it('手動紐付けの楽曲は緑で表示する', () => {
            const el = instance.createStatusElement({
                song: { title: '夜に駆ける', artist: 'YOASOBI' },
                is_manual: true,
            });
            expect(el.textContent).toBe('夜に駆ける / YOASOBI');
            expect(el.className).toContain('text-green-600');
        });

        it('自動紐付けの楽曲は黄色で「[自動]」プレフィックスを表示する', () => {
            const el = instance.createStatusElement({
                song: { title: '夜に駆ける', artist: 'YOASOBI' },
                is_manual: false,
                is_individual_mapping: false,
            });
            expect(el.textContent).toBe('[自動] 夜に駆ける / YOASOBI');
            expect(el.className).toContain('text-yellow-600');
        });

        it('個別マッピングの楽曲は青で「[個別]」プレフィックスを表示する', () => {
            const el = instance.createStatusElement({
                song: { title: '夜に駆ける', artist: 'YOASOBI' },
                is_individual_mapping: true,
            });
            expect(el.textContent).toBe('[個別] 夜に駆ける / YOASOBI');
            expect(el.className).toContain('text-blue-600');
        });

        it('楽曲なしのとき「未紐づけ」を表示する', () => {
            const el = instance.createStatusElement({});
            expect(el.textContent).toBe('未紐づけ');
            expect(el.className).toContain('text-gray-400');
        });

        it('ステータスが長いとき切り詰める', () => {
            const longTitle = 'あ'.repeat(30);
            const el = instance.createStatusElement({
                song: { title: longTitle, artist: 'B' },
                is_manual: true,
            });
            expect(el.textContent).toContain('...');
            expect(el.textContent.length).toBeLessThanOrEqual(34); // 30 + "..."
        });
    });

    // =========================================================================
    // createTimestampElement — DOM生成
    // =========================================================================
    describe('createTimestampElement', () => {
        const baseTs = {
            id: 'ts-001',
            text: '夜に駆ける',
            archive: { title: '歌枠 2024/01/01' },
        };

        it('タイムスタンプテキストを表示する', () => {
            const el = instance.createTimestampElement(baseTs);
            expect(el.querySelector('.font-medium').textContent).toBe('夜に駆ける');
        });

        it('data-ts-id属性を設定する', () => {
            const el = instance.createTimestampElement(baseTs);
            expect(el.dataset.tsId).toBe('ts-001');
        });

        it('アーカイブタイトルを表示する', () => {
            const el = instance.createTimestampElement(baseTs);
            const archiveEl = el.querySelector('.text-xs.text-gray-500');
            expect(archiveEl.textContent).toBe('歌枠 2024/01/01');
        });

        it('選択中のタイムスタンプはハイライトされる', () => {
            instance.selectedTimestamps = [{ id: 'ts-001' }];
            const el = instance.createTimestampElement(baseTs);
            expect(el.className).toContain('bg-blue-100');
            expect(el.className).toContain('border-blue-500');
        });

        it('未選択のタイムスタンプはデフォルトスタイル', () => {
            const el = instance.createTimestampElement(baseTs);
            expect(el.className).toContain('border-gray-300');
            expect(el.className).not.toContain('bg-blue-100');
        });

        it('候補タブがアクティブで単一選択時はラジオボタンを使う', () => {
            document.getElementById('candidatesList').classList.remove('hidden');
            const el = instance.createTimestampElement(baseTs);
            const radio = el.querySelector('input[type="radio"]');
            expect(radio).not.toBeNull();
        });

        it('候補タブ以外ではチェックボックスを使う', () => {
            const el = instance.createTimestampElement(baseTs);
            const checkbox = el.querySelector('input[type="checkbox"]');
            expect(checkbox).not.toBeNull();
        });

        it('自動紐付けの場合は確定ボタンを表示する', () => {
            const ts = { ...baseTs, is_manual: false, song: { title: 'A', artist: 'B' } };
            const el = instance.createTimestampElement(ts);
            const buttons = el.querySelectorAll('button');
            const hasConfirmBtn = Array.from(buttons).some(b =>
                b.textContent.includes('確定') || b.title.includes('確定')
            );
            expect(hasConfirmBtn).toBe(true);
        });

        it('手動紐付けの場合は確定ボタンを表示しない', () => {
            const ts = { ...baseTs, is_manual: true, song: { title: 'A', artist: 'B' } };
            const el = instance.createTimestampElement(ts);
            const buttons = el.querySelectorAll('button');
            const hasConfirmBtn = Array.from(buttons).some(b =>
                b.textContent.includes('確定') || b.title.includes('確定')
            );
            expect(hasConfirmBtn).toBe(false);
        });
    });

    // =========================================================================
    // displayTimestamps — コンテナへの描画
    // =========================================================================
    describe('displayTimestamps', () => {
        it('タイムスタンプのリストを描画する', () => {
            const timestamps = [
                { id: 'ts-1', text: 'Song A', archive: { title: 'Archive 1' } },
                { id: 'ts-2', text: 'Song B', archive: { title: 'Archive 2' } },
            ];
            instance.displayTimestamps(timestamps);
            const container = document.getElementById('timestampsList');
            expect(container.querySelectorAll('[data-ts-id]').length).toBe(2);
        });

        it('空配列のとき「タイムスタンプがありません」メッセージを表示する', () => {
            instance.displayTimestamps([]);
            const container = document.getElementById('timestampsList');
            expect(container.textContent).toContain('タイムスタンプがありません');
        });

        it('currentPageTimestampsを更新する', () => {
            const timestamps = [{ id: 'ts-1', text: 'A', archive: {} }];
            instance.displayTimestamps(timestamps);
            expect(instance.currentPageTimestamps).toBe(timestamps);
        });
    });

    // =========================================================================
    // createSongElement — 楽曲行のDOM生成
    // =========================================================================
    describe('createSongElement', () => {
        const baseSong = {
            id: 'song-001',
            title: '夜に駆ける',
            artist: 'YOASOBI',
            tags: [],
        };

        it('楽曲タイトルとアーティストを表示する', () => {
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            expect(el.querySelector('.font-medium').textContent).toBe('夜に駆ける');
            expect(el.textContent).toContain('YOASOBI');
        });

        it('data-song-id属性を設定する', () => {
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            expect(el.dataset.songId).toBe('song-001');
        });

        it('選択中の楽曲はハイライトされる', () => {
            instance.selectedSong = { id: 'song-001' };
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            expect(el.className).toContain('bg-blue-100');
        });

        it('未選択の楽曲はデフォルトスタイル', () => {
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            expect(el.className).toContain('border-gray-300');
            expect(el.className).not.toContain('bg-blue-100');
        });

        it('タグがあればバッジとして表示する', () => {
            const songWithTags = {
                ...baseSong,
                tags: [{ value: 'カバー' }, { value: 'オリジナル' }],
            };
            const el = instance.createSongElement(songWithTags, [songWithTags], 1);
            const badges = el.querySelectorAll('span.bg-blue-600');
            expect(badges.length).toBe(2);
            expect(badges[0].textContent).toBe('カバー');
            expect(badges[1].textContent).toBe('オリジナル');
        });

        it('duration_msがあれば楽曲の長さを表示する', () => {
            const songWithDuration = { ...baseSong, duration_ms: 225000 };
            const el = instance.createSongElement(songWithDuration, [songWithDuration], 1);
            expect(el.textContent).toContain('3:45');
        });

        it('showActions=trueのとき操作ボタンを表示する', () => {
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            const buttons = el.querySelectorAll('button');
            const hasEditBtn = Array.from(buttons).some(b => b.textContent === '編集');
            const hasDeleteBtn = Array.from(buttons).some(b => b.textContent === '削除');
            const hasOpBtn = Array.from(buttons).some(b => b.textContent === '操作');
            expect(hasEditBtn).toBe(true);
            expect(hasDeleteBtn).toBe(true);
            expect(hasOpBtn).toBe(true);
        });

        it('showActions=falseのとき操作ボタンを非表示にする', () => {
            const el = instance.createSongElement(baseSong, [baseSong], 1, null, { showActions: false });
            const buttons = el.querySelectorAll('button');
            const hasEditBtn = Array.from(buttons).some(b => b.textContent === '編集');
            expect(hasEditBtn).toBe(false);
        });

        it('クリックでselectedSongを更新する', () => {
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            el.click();
            expect(instance.selectedSong).toEqual(baseSong);
        });

        it('選択済み楽曲のクリックで選択を解除する', () => {
            instance.selectedSong = { id: 'song-001' };
            const el = instance.createSongElement(baseSong, [baseSong], 1);
            el.click();
            expect(instance.selectedSong).toBeNull();
        });

        it('onSelectionChangeコールバックが渡されたらクリック時に呼ばれる', () => {
            const callback = vi.fn();
            const el = instance.createSongElement(baseSong, [baseSong], 1, callback);
            el.click();
            expect(callback).toHaveBeenCalledOnce();
        });
    });

    // =========================================================================
    // toggleTimestampSelection — 状態管理
    // =========================================================================
    describe('toggleTimestampSelection', () => {
        const ts1 = { id: 'ts-1', text: 'Song A' };
        const ts2 = { id: 'ts-2', text: 'Song B' };

        beforeEach(() => {
            instance.displayTimestamps([
                { ...ts1, archive: {} },
                { ...ts2, archive: {} },
            ]);
        });

        it('未選択のタイムスタンプを選択に追加する', () => {
            instance.toggleTimestampSelection(ts1);
            expect(instance.selectedTimestamps).toEqual([ts1]);
        });

        it('選択済みのタイムスタンプを解除する', () => {
            instance.selectedTimestamps = [ts1];
            instance.toggleTimestampSelection(ts1);
            expect(instance.selectedTimestamps).toEqual([]);
        });

        it('複数のタイムスタンプを選択できる', () => {
            instance.toggleTimestampSelection(ts1);
            instance.toggleTimestampSelection(ts2);
            expect(instance.selectedTimestamps).toEqual([ts1, ts2]);
        });

        it('候補タブアクティブ時は単一選択になる', () => {
            document.getElementById('candidatesList').classList.remove('hidden');
            instance.toggleTimestampSelection(ts1);
            instance.toggleTimestampSelection(ts2);
            expect(instance.selectedTimestamps).toEqual([ts2]);
        });

        it('候補タブで同じタイムスタンプを再選択すると解除する', () => {
            document.getElementById('candidatesList').classList.remove('hidden');
            instance.toggleTimestampSelection(ts1);
            instance.toggleTimestampSelection(ts1);
            expect(instance.selectedTimestamps).toEqual([]);
        });
    });

    // =========================================================================
    // showTab — タブ切り替え
    // =========================================================================
    describe('showTab', () => {
        it('activeTabIdを更新する', () => {
            instance.showTab('songsTab');
            expect(instance.activeTabId).toBe('songsTab');
        });

        it('タブ切り替え時にselectedSongをクリアする', () => {
            instance.activeTabId = 'songsTab';
            instance.selectedSong = { id: 'song-001' };
            instance.showTab('candidatesTab');
            expect(instance.selectedSong).toBeNull();
        });

        it('同じタブでselectedSongを維持する', () => {
            instance.activeTabId = 'songsTab';
            instance.selectedSong = { id: 'song-001' };
            instance.showTab('songsTab');
            expect(instance.selectedSong).toEqual({ id: 'song-001' });
        });

        it('Spotify無効時はspotifyTabに切り替えられない', () => {
            instance.spotifyEnabled = false;
            instance.activeTabId = 'songsTab';
            instance.showTab('spotifyTab');
            expect(instance.activeTabId).toBe('songsTab');
        });
    });

    // =========================================================================
    // isCandidateTabActive
    // =========================================================================
    describe('isCandidateTabActive', () => {
        it('candidatesListが表示中のときtrueを返す', () => {
            document.getElementById('candidatesList').classList.remove('hidden');
            expect(instance.isCandidateTabActive()).toBe(true);
        });

        it('candidatesListが非表示のときfalseを返す', () => {
            expect(instance.isCandidateTabActive()).toBe(false);
        });
    });

    // =========================================================================
    // selectAll / deselectAll
    // =========================================================================
    describe('selectAll / deselectAll', () => {
        beforeEach(() => {
            instance.currentPageTimestamps = [
                { id: 'ts-1', text: 'A' },
                { id: 'ts-2', text: 'B' },
            ];
            instance.displayTimestamps([
                { id: 'ts-1', text: 'A', archive: {} },
                { id: 'ts-2', text: 'B', archive: {} },
            ]);
        });

        it('selectAllで全タイムスタンプを選択する', () => {
            instance.selectAll();
            expect(instance.selectedTimestamps.length).toBe(2);
        });

        it('selectAllは候補タブでは動作しない', () => {
            document.getElementById('candidatesList').classList.remove('hidden');
            instance.selectAll();
            expect(instance.selectedTimestamps.length).toBe(0);
        });

        it('deselectAllで全選択を解除する', () => {
            instance.selectedTimestamps = [{ id: 'ts-1' }, { id: 'ts-2' }];
            instance.deselectAll();
            expect(instance.selectedTimestamps.length).toBe(0);
        });

        it('selectAllは重複して追加しない', () => {
            instance.selectedTimestamps = [{ id: 'ts-1', text: 'A' }];
            instance.selectAll();
            expect(instance.selectedTimestamps.length).toBe(2);
        });
    });

    // =========================================================================
    // updateSongsCount
    // =========================================================================
    describe('updateSongsCount', () => {
        it('数値を「n件」として表示する', () => {
            instance.updateSongsCount(42);
            expect(document.getElementById('songsCount').textContent).toBe('42件');
        });

        it('nullのとき表示をクリアする', () => {
            instance.updateSongsCount(42);
            instance.updateSongsCount(null);
            expect(document.getElementById('songsCount').textContent).toBe('');
        });

        it('文字列をそのまま表示する', () => {
            instance.updateSongsCount('検索中...');
            expect(document.getElementById('songsCount').textContent).toBe('検索中...');
        });
    });
});
