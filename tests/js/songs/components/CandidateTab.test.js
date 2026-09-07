import { vi, describe, it, expect, beforeEach } from 'vitest';

vi.mock('@/songs/services/SongApiService.js', () => ({
    songApiService: {
        fetchCandidates: vi.fn(),
        fetchSongs: vi.fn(),
    },
}));

import { CandidateTab } from '@/songs/components/CandidateTab.js';
import { songApiService } from '@/songs/services/SongApiService.js';

function setupDOM() {
    document.body.innerHTML = `
        <div id="candidatesList" class="hidden"></div>
        <div id="candidateNotice"></div>
        <div id="candidateTextArea" class="hidden"></div>
        <div id="candidateDelimitersArea" class="hidden"></div>
        <div id="candidateDelimiters"></div>
        <input id="candidateDelimiterInput" type="text" maxlength="1">
        <button id="candidateDelimiterAdd"></button>
        <div id="candidateKeywordsArea" class="hidden"></div>
        <div id="candidateResults"></div>
        <div id="candidateKeywords"></div>
        <span id="candidateOriginalText"></span>
        <button id="candidateKeywordsClear"></button>
    `;
}

function createTab(overrides = {}) {
    return new CandidateTab({
        getSelectedTimestamps: overrides.getSelectedTimestamps ?? (() => []),
        createSongElement: overrides.createSongElement ?? (() => document.createElement('div')),
        onNarrowToSingle: overrides.onNarrowToSingle ?? vi.fn(),
    });
}

describe('CandidateTab', () => {
    beforeEach(() => {
        setupDOM();
        vi.clearAllMocks();
    });

    // =========================================================================
    // isActive
    // =========================================================================
    describe('isActive', () => {
        it('candidatesListが表示中のときtrueを返す', () => {
            const tab = createTab();
            document.getElementById('candidatesList').classList.remove('hidden');
            expect(tab.isActive()).toBe(true);
        });

        it('candidatesListが非表示のときfalseを返す', () => {
            const tab = createTab();
            expect(tab.isActive()).toBe(false);
        });
    });

    // =========================================================================
    // getSelectionKey / setSelectionKey
    // =========================================================================
    describe('getSelectionKey / setSelectionKey', () => {
        it('初期値はnull', () => {
            const tab = createTab();
            expect(tab.getSelectionKey()).toBeNull();
        });

        it('setした値をgetで取得できる', () => {
            const tab = createTab();
            tab.setSelectionKey('abc,def');
            expect(tab.getSelectionKey()).toBe('abc,def');
        });
    });

    // =========================================================================
    // load
    // =========================================================================
    describe('load', () => {
        it('タイムスタンプ0件のとき案内を出してリセットする', async () => {
            const tab = createTab({ getSelectedTimestamps: () => [] });
            await tab.load();

            expect(document.getElementById('candidateNotice').textContent)
                .toBe('タイムスタンプを1件選ぶと候補を表示します。');
            expect(document.getElementById('candidateTextArea').classList.contains('hidden')).toBe(true);
            expect(document.getElementById('candidateResults').innerHTML).toBe('');
        });

        it('タイムスタンプ複数選択時に案内を出す', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [
                    { id: '1', text: 'A' },
                    { id: '2', text: 'B' },
                ],
            });
            await tab.load();

            const notice = document.getElementById('candidateNotice');
            expect(notice.querySelector('p').textContent).toContain('2件選択中');
            expect(notice.querySelector('button').textContent).toBe('最後に選んだ1件に絞る');
        });

        it('複数選択時の「1件に絞る」ボタンがonNarrowToSingleを呼ぶ', async () => {
            const onNarrow = vi.fn();
            const tab = createTab({
                getSelectedTimestamps: () => [
                    { id: '1', text: 'A' },
                    { id: '2', text: 'B' },
                ],
                onNarrowToSingle: onNarrow,
            });
            await tab.load();

            document.getElementById('candidateNotice').querySelector('button').click();
            expect(onNarrow).toHaveBeenCalledOnce();
        });

        it('1件選択時にAPIを呼び候補を表示する', async () => {
            songApiService.fetchCandidates.mockResolvedValue({
                parts: ['夜', 'に', '駆ける'],
                ignored_indices: [1],
                songs: [{ id: 's1', title: '夜に駆ける' }],
                total: 1,
            });

            const createEl = vi.fn(() => document.createElement('div'));
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: '夜に駆ける' }],
                createSongElement: createEl,
            });
            await tab.load();

            expect(songApiService.fetchCandidates).toHaveBeenCalledWith('夜に駆ける');
            expect(tab.candidateTextKey).toBe('夜に駆ける');
            expect(tab.candidateKeywords).toEqual(['夜', '駆ける']);
            expect(document.getElementById('candidateOriginalText').textContent).toBe('夜に駆ける');
            expect(document.getElementById('candidateNotice').textContent).toBe('1件の候補');
            expect(createEl).toHaveBeenCalledOnce();
        });

        it('同じテキストで再呼び出しするとAPIを呼ばない', async () => {
            songApiService.fetchCandidates.mockResolvedValue({
                parts: ['A'],
                ignored_indices: [],
                songs: [],
                total: 0,
            });
            songApiService.fetchSongs.mockResolvedValue({ data: [], total: 0 });

            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A' }],
            });
            await tab.load();
            expect(songApiService.fetchCandidates).toHaveBeenCalledTimes(1);

            await tab.load();
            expect(songApiService.fetchCandidates).toHaveBeenCalledTimes(1);
            expect(document.getElementById('candidateTextArea').classList.contains('hidden')).toBe(false);
        });

        it('API失敗時にエラーメッセージを出す', async () => {
            songApiService.fetchCandidates.mockRejectedValue(new Error('network'));

            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'X' }],
            });
            await tab.load();

            expect(document.getElementById('candidateNotice').textContent).toBe('候補の取得に失敗しました。');
        });

        it('リクエスト追い越し時に古い応答を無視する', async () => {
            let resolveFirst;
            songApiService.fetchCandidates
                .mockImplementationOnce(() => new Promise(r => { resolveFirst = r; }))
                .mockResolvedValueOnce({
                    parts: ['B'],
                    ignored_indices: [],
                    songs: [{ id: 's2', title: 'B' }],
                    total: 1,
                });

            const createEl = vi.fn(() => document.createElement('div'));
            let currentText = 'A';
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: currentText }],
                createSongElement: createEl,
            });

            const first = tab.load();
            currentText = 'B';
            const second = tab.load();

            resolveFirst({
                parts: ['A'],
                ignored_indices: [],
                songs: [{ id: 's1', title: 'A' }],
                total: 1,
            });

            await first;
            await second;

            expect(tab.candidateTextKey).toBe('B');
            expect(document.getElementById('candidateResults').children.length).toBe(1);
        });
    });

    // =========================================================================
    // display
    // =========================================================================
    describe('display', () => {
        it('楽曲リストを描画する', () => {
            const createEl = vi.fn(() => {
                const el = document.createElement('div');
                el.className = 'song';
                return el;
            });
            const tab = createTab({ createSongElement: createEl });

            const songs = [
                { id: 's1', title: 'A' },
                { id: 's2', title: 'B' },
            ];
            tab.display(songs, 2);

            expect(document.getElementById('candidateResults').children.length).toBe(2);
            expect(document.getElementById('candidateNotice').textContent).toBe('2件の候補');
            expect(tab.lastDisplayedCandidates).toBe(songs);
            expect(tab.lastDisplayedCandidatesTotal).toBe(2);
        });

        it('件数がtotalより少ない場合に上位N件表示と出す', () => {
            const createEl = vi.fn(() => document.createElement('div'));
            const tab = createTab({ createSongElement: createEl });

            tab.display([{ id: 's1' }, { id: 's2' }], 10);
            expect(document.getElementById('candidateNotice').textContent).toBe('10件の候補（上位2件を表示）');
        });

        it('空配列のとき案内メッセージを出す', () => {
            const tab = createTab();
            tab.candidateKeywords = ['test'];
            tab.display([], 0);
            expect(document.getElementById('candidateNotice').textContent)
                .toBe('候補が見つかりませんでした。検索語を減らして条件を緩めてください。');
        });

        it('キーワード0件で結果0件のとき「検索語を追加」メッセージを出す', () => {
            const tab = createTab();
            tab.display([], 0);
            expect(document.getElementById('candidateNotice').textContent).toBe('検索語を追加してください。');
        });
    });

    // =========================================================================
    // removeSong
    // =========================================================================
    describe('removeSong', () => {
        it('指定IDの楽曲を除外して再描画する', () => {
            const createEl = vi.fn(() => document.createElement('div'));
            const tab = createTab({ createSongElement: createEl });

            tab.lastDisplayedCandidates = [
                { id: 's1', title: 'A' },
                { id: 's2', title: 'B' },
                { id: 's3', title: 'C' },
            ];
            tab.lastDisplayedCandidatesTotal = 5;

            tab.removeSong('s2');

            expect(tab.lastDisplayedCandidates.length).toBe(2);
            expect(tab.lastDisplayedCandidatesTotal).toBe(4);
        });

        it('存在しないIDでは何も変わらない', () => {
            const tab = createTab();
            tab.lastDisplayedCandidates = [{ id: 's1' }];
            tab.lastDisplayedCandidatesTotal = 1;

            tab.removeSong('xxx');
            expect(tab.lastDisplayedCandidates.length).toBe(1);
            expect(tab.lastDisplayedCandidatesTotal).toBe(1);
        });

        it('lastDisplayedCandidatesが未設定でもエラーにならない', () => {
            const tab = createTab();
            tab.lastDisplayedCandidates = null;
            expect(() => tab.removeSong('s1')).not.toThrow();
        });
    });

    // =========================================================================
    // renderKeywords
    // =========================================================================
    describe('renderKeywords', () => {
        it('キーワードをタグとして描画する', () => {
            const tab = createTab();
            tab.candidateKeywords = ['夜', '駆ける'];
            tab.renderKeywords();

            const container = document.getElementById('candidateKeywords');
            expect(container.children.length).toBe(2);
            expect(container.children[0].textContent).toContain('夜');
            expect(container.children[1].textContent).toContain('駆ける');
            expect(document.getElementById('candidateKeywordsArea').classList.contains('hidden')).toBe(false);
        });

        it('キーワード0件のときエリアを非表示にする', () => {
            const tab = createTab();
            tab.candidateKeywords = [];
            tab.renderKeywords();

            expect(document.getElementById('candidateKeywordsArea').classList.contains('hidden')).toBe(true);
            expect(document.getElementById('candidateKeywords').children.length).toBe(0);
        });

        it('×ボタンクリックでキーワードが削除されsearchByKeywordsが再実行される', async () => {
            songApiService.fetchSongs.mockResolvedValue({ data: [], total: 0 });

            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A B' }],
            });
            tab.candidateTextKey = 'A B';
            tab.candidateKeywords = ['A', 'B', 'C'];
            tab.renderKeywords();

            const container = document.getElementById('candidateKeywords');
            expect(container.children.length).toBe(3);

            const removeBtnB = container.children[1].querySelector('button');
            removeBtnB.click();
            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A', 'C']);
            });
        });
    });

    // =========================================================================
    // _setupTextSelection (テキスト選択によるキーワード追加)
    // =========================================================================
    describe('テキスト選択によるキーワード追加', () => {
        async function loadWithCandidates(tab) {
            songApiService.fetchCandidates.mockResolvedValue({
                parts: ['A', 'B'],
                ignored_indices: [],
                songs: [],
                total: 0,
            });
            await tab.load();
        }

        function simulateTextSelection(text, container) {
            const textNode = container.firstChild ?? container;
            const mockRange = {
                startContainer: textNode,
            };
            const mockSelection = {
                rangeCount: 1,
                getRangeAt: () => mockRange,
                toString: () => text,
                removeAllRanges: vi.fn(),
            };
            vi.spyOn(window, 'getSelection').mockReturnValue(mockSelection);
            document.dispatchEvent(new Event('mouseup'));
            window.getSelection.mockRestore();
        }

        it('テキスト選択でキーワードが追加される', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'Hello World' }],
            });
            await loadWithCandidates(tab);

            const originalText = document.getElementById('candidateOriginalText');
            simulateTextSelection('Hello', originalText);

            expect(tab.candidateKeywords).toContain('Hello');
        });

        it('重複するキーワードは追加されない', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'Hello' }],
            });
            await loadWithCandidates(tab);

            const originalText = document.getElementById('candidateOriginalText');
            simulateTextSelection('A', originalText);
            const countBefore = tab.candidateKeywords.filter(k => k === 'A').length;

            simulateTextSelection('A', originalText);
            const countAfter = tab.candidateKeywords.filter(k => k === 'A').length;

            expect(countAfter).toBe(countBefore);
        });

        it('candidateOriginalText外の選択は無視される', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'X' }],
            });
            await loadWithCandidates(tab);

            const outsideEl = document.createElement('div');
            outsideEl.textContent = 'outside';
            document.body.appendChild(outsideEl);

            const keywordsBefore = [...tab.candidateKeywords];
            simulateTextSelection('outside', outsideEl);
            expect(tab.candidateKeywords).toEqual(keywordsBefore);
        });

        it('再load時に古いmouseupリスナーが解除される', async () => {
            const removeSpy = vi.spyOn(document, 'removeEventListener');
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'X' }],
            });

            await loadWithCandidates(tab);
            const firstHandler = tab._textSelectionHandler;
            expect(firstHandler).not.toBeNull();

            tab.candidateTextKey = null;
            await loadWithCandidates(tab);

            expect(removeSpy).toHaveBeenCalledWith('mouseup', firstHandler);
            removeSpy.mockRestore();
        });

        it('クリアボタンでキーワードが全削除される', async () => {
            songApiService.fetchSongs.mockResolvedValue({ data: [], total: 0 });

            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'X' }],
            });
            await loadWithCandidates(tab);

            expect(tab.candidateKeywords.length).toBeGreaterThan(0);

            document.getElementById('candidateKeywordsClear').click();

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual([]);
            });
        });
    });

    // =========================================================================
    // カスタム区切り文字
    // =========================================================================
    describe('カスタム区切り文字', () => {
        async function loadWithParts(tab, parts, ignoredIndices = []) {
            songApiService.fetchCandidates.mockResolvedValue({
                parts,
                ignored_indices: ignoredIndices,
                songs: [],
                total: 0,
            });
            songApiService.fetchSongs.mockResolvedValue({ data: [], total: 0 });
            await tab.load();
        }

        it('区切り文字を追加するとキーワードが再分割される', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A×B' }],
            });
            await loadWithParts(tab, ['A×B']);

            expect(tab.candidateKeywords).toEqual(['A×B']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '×';
            document.getElementById('candidateDelimiterAdd').click();

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A', 'B']);
            });
        });

        it('区切り文字を削除するとキーワードが元に戻る', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A×B' }],
            });
            await loadWithParts(tab, ['A×B']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '×';
            document.getElementById('candidateDelimiterAdd').click();

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A', 'B']);
            });

            const removeBtn = document.getElementById('candidateDelimiters').querySelector('button');
            removeBtn.click();

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A×B']);
            });
        });

        it('重複する区切り文字は追加されない', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A×B' }],
            });
            await loadWithParts(tab, ['A×B']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '×';
            document.getElementById('candidateDelimiterAdd').click();

            input.value = '×';
            document.getElementById('candidateDelimiterAdd').click();

            expect(tab._customDelimiters).toEqual(['×']);
        });

        it('空文字は区切り文字として追加されない', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'AB' }],
            });
            await loadWithParts(tab, ['AB']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '';
            document.getElementById('candidateDelimiterAdd').click();

            expect(tab._customDelimiters).toEqual([]);
        });

        it('ignored partsは区切り文字追加後も無視される', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A×B C' }],
            });
            await loadWithParts(tab, ['A×B', 'C'], [1]);

            expect(tab.candidateKeywords).toEqual(['A×B']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '×';
            document.getElementById('candidateDelimiterAdd').click();

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A', 'B']);
            });
        });

        it('ハイフンを区切り文字として追加しても正規表現エラーにならない', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A-B' }],
            });
            await loadWithParts(tab, ['A-B']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '-';
            document.getElementById('candidateDelimiterAdd').click();

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A', 'B']);
            });
        });

        it('Enterキーで区切り文字を追加できる', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A×B' }],
            });
            await loadWithParts(tab, ['A×B']);

            const input = document.getElementById('candidateDelimiterInput');
            input.value = '×';
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

            await vi.waitFor(() => {
                expect(tab.candidateKeywords).toEqual(['A', 'B']);
            });
        });
    });

    // =========================================================================
    // searchByKeywords
    // =========================================================================
    describe('searchByKeywords', () => {
        it('キーワードでAPIを呼び結果を表示する', async () => {
            const createEl = vi.fn(() => document.createElement('div'));
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'テスト' }],
                createSongElement: createEl,
            });
            tab.candidateTextKey = 'テスト';
            tab.candidateKeywords = ['テスト', '曲'];

            songApiService.fetchSongs.mockResolvedValue({
                data: [{ id: 's1', title: 'テスト曲' }],
                total: 1,
            });

            await tab.searchByKeywords();

            expect(songApiService.fetchSongs).toHaveBeenCalledWith('テスト 曲', null, 'fuzzy');
            expect(createEl).toHaveBeenCalledOnce();
        });

        it('キーワード0件のとき空表示にする', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A' }],
            });
            tab.candidateTextKey = 'A';
            tab.candidateKeywords = [];

            await tab.searchByKeywords();

            expect(songApiService.fetchSongs).not.toHaveBeenCalled();
            expect(tab.lastDisplayedCandidates).toEqual([]);
        });

        it('candidateTextKeyがnullのときスキップする', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A' }],
            });
            tab.candidateTextKey = null;
            tab.candidateKeywords = ['A'];

            await tab.searchByKeywords();

            expect(songApiService.fetchSongs).not.toHaveBeenCalled();
        });

        it('candidateTextKeyと選択中テキストが不一致のときスキップする', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'B' }],
            });
            tab.candidateTextKey = 'A';
            tab.candidateKeywords = ['A'];

            await tab.searchByKeywords();

            expect(songApiService.fetchSongs).not.toHaveBeenCalled();
        });

        it('API失敗時にエラーメッセージを出す', async () => {
            const tab = createTab({
                getSelectedTimestamps: () => [{ id: '1', text: 'A' }],
            });
            tab.candidateTextKey = 'A';
            tab.candidateKeywords = ['A'];

            songApiService.fetchSongs.mockRejectedValue(new Error('fail'));

            await tab.searchByKeywords();

            expect(document.getElementById('candidateNotice').textContent).toBe('候補の検索に失敗しました。');
        });
    });
});
