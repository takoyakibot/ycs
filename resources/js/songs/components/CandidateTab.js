import { CONSTANTS } from '../utils/constants.js';
import { songApiService } from '../services/SongApiService.js';

/**
 * 候補タブの状態管理・描画
 *
 * 選択中のタイムスタンプのテキストを分解し、候補楽曲を検索・表示する。
 */
export class CandidateTab {
    /**
     * @param {Object} deps
     * @param {() => Array} deps.getSelectedTimestamps
     * @param {() => Object|null} deps.getSelectedSong
     * @param {(song, songs, total, onSelectionChange, opts) => HTMLElement} deps.createSongElement
     * @param {(msg: string) => void} deps.onNarrowToSingle - 「1件に絞る」ボタン押下時のコールバック
     */
    constructor(deps) {
        this.candidateKeywords = [];
        this.candidateTextKey = null;
        this.candidateRequestSeq = 0;
        this.lastDisplayedCandidates = [];
        this.lastDisplayedCandidatesTotal = 0;
        this.lastCandidateSelectionKey = null;

        this._getSelectedTimestamps = deps.getSelectedTimestamps;
        this._getSelectedSong = deps.getSelectedSong;
        this._createSongElement = deps.createSongElement;
        this._onNarrowToSingle = deps.onNarrowToSingle;

        this._textSelectionHandler = null;
        this._keywordsClearHandler = null;
    }

    isActive() {
        return !document.getElementById('candidatesList').classList.contains('hidden');
    }

    getSelectionKey() {
        return this.lastCandidateSelectionKey;
    }

    setSelectionKey(key) {
        this.lastCandidateSelectionKey = key;
    }

    /**
     * 候補タブの内容を読み込む
     *
     * タイムスタンプが1件だけ選択されているときに候補を取得する。
     * 複数選択中は選択に触らず案内だけ出す（一括紐付けの選択を壊さないため）。
     */
    async load() {
        const notice = document.getElementById('candidateNotice');
        const textArea = document.getElementById('candidateTextArea');
        const keywordsArea = document.getElementById('candidateKeywordsArea');
        const results = document.getElementById('candidateResults');
        const selectedTimestamps = this._getSelectedTimestamps();

        if (selectedTimestamps.length === 0) {
            this.candidateRequestSeq++;
            this.candidateTextKey = null;
            this.candidateKeywords = [];

            notice.textContent = 'タイムスタンプを1件選ぶと候補を表示します。';
            textArea.classList.add('hidden');
            keywordsArea.classList.add('hidden');
            results.innerHTML = '';
            return;
        }

        if (selectedTimestamps.length > 1) {
            this.candidateRequestSeq++;
            this._renderMultiSelectionNotice(selectedTimestamps.length);
            textArea.classList.add('hidden');
            keywordsArea.classList.add('hidden');
            results.innerHTML = '';
            return;
        }

        const text = selectedTimestamps[0].text;

        if (this.candidateTextKey === text) {
            textArea.classList.remove('hidden');
            this.renderKeywords();
            await this.searchByKeywords();
            return;
        }

        notice.textContent = '候補を探しています…';
        results.innerHTML = '';

        this.candidateTextKey = null;
        this.candidateKeywords = [];
        textArea.classList.add('hidden');
        keywordsArea.classList.add('hidden');

        const seq = ++this.candidateRequestSeq;

        try {
            const data = await songApiService.fetchCandidates(text);

            if (seq !== this.candidateRequestSeq) {
                return;
            }

            this.candidateTextKey = text;

            const originalTextEl = document.getElementById('candidateOriginalText');
            originalTextEl.textContent = text;
            textArea.classList.remove('hidden');

            this.candidateKeywords = [...new Set(
                data.parts.filter((_, i) => !data.ignored_indices.includes(i))
            )];

            this.renderKeywords();
            this._setupTextSelection();
            notice.textContent = '';
            this.display(data.songs, data.total);
        } catch (error) {
            if (seq !== this.candidateRequestSeq) {
                return;
            }

            console.error('候補の取得に失敗しました:', error);
            notice.textContent = '候補の取得に失敗しました。';
            textArea.classList.add('hidden');
        }
    }

    _renderMultiSelectionNotice(count) {
        const notice = document.getElementById('candidateNotice');
        notice.textContent = '';

        const message = document.createElement('p');
        message.className = 'mb-2';
        message.textContent = `${count}件選択中です。候補を見るには1件だけ選んでください。`;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'px-3 py-1 bg-amber-600 text-white text-sm rounded hover:bg-amber-700';
        button.textContent = '最後に選んだ1件に絞る';
        button.addEventListener('click', () => {
            this._onNarrowToSingle();
        });

        notice.appendChild(message);
        notice.appendChild(button);
    }

    renderKeywords() {
        const keywordsArea = document.getElementById('candidateKeywordsArea');
        const container = document.getElementById('candidateKeywords');

        container.innerHTML = '';

        if (this.candidateKeywords.length === 0) {
            keywordsArea.classList.add('hidden');
            return;
        }

        keywordsArea.classList.remove('hidden');

        this.candidateKeywords.forEach((keyword, index) => {
            const tag = document.createElement('span');
            tag.className = 'inline-flex items-center gap-1 px-2 py-1 text-xs rounded bg-amber-600 text-white';
            tag.textContent = keyword;

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'ml-0.5 hover:text-amber-200';
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', () => this._removeKeyword(index));
            tag.appendChild(removeBtn);

            container.appendChild(tag);
        });
    }

    /**
     * 候補一覧を描画する
     *
     * 候補の見た目と選択の扱いは楽曲マスタ一覧と揃える（createSongElement を再利用する）
     */
    display(songs, total) {
        this.lastDisplayedCandidates = songs;
        this.lastDisplayedCandidatesTotal = total;

        const results = document.getElementById('candidateResults');
        const notice = document.getElementById('candidateNotice');

        results.innerHTML = '';

        if (!Array.isArray(songs) || songs.length === 0) {
            notice.textContent = this.candidateKeywords.length === 0
                ? '検索語を追加してください。'
                : '候補が見つかりませんでした。検索語を減らして条件を緩めてください。';
            return;
        }

        notice.textContent = songs.length < total
            ? `${total}件の候補（上位${songs.length}件を表示）`
            : `${total}件の候補`;

        songs.forEach(song => {
            results.appendChild(this._createSongElement(song, songs, total, () => {
                this.display(songs, total);
            }, { showActions: false }));
        });
    }

    /**
     * 表示中の候補から指定IDの楽曲を除外して再描画する
     */
    removeSong(songId) {
        if (!Array.isArray(this.lastDisplayedCandidates)) return;
        const before = this.lastDisplayedCandidates.length;
        const filtered = this.lastDisplayedCandidates.filter(s => s.id !== songId);
        if (filtered.length < before) {
            const totalDiff = before - filtered.length;
            this.display(filtered, Math.max(0, (this.lastDisplayedCandidatesTotal ?? 0) - totalDiff));
        }
    }

    _setupTextSelection() {
        const el = document.getElementById('candidateOriginalText');

        if (this._textSelectionHandler) {
            document.removeEventListener('mouseup', this._textSelectionHandler);
        }

        this._textSelectionHandler = () => {
            const selection = window.getSelection();
            if (!selection.rangeCount) return;

            const range = selection.getRangeAt(0);
            if (!el.contains(range.startContainer)) return;

            const selectedText = selection.toString().trim();
            if (!selectedText) return;

            if (!this.candidateKeywords.includes(selectedText)) {
                this.candidateKeywords.push(selectedText);
                this.renderKeywords();
                this.searchByKeywords();
            }

            selection.removeAllRanges();
        };

        document.addEventListener('mouseup', this._textSelectionHandler);

        const clearBtn = document.getElementById('candidateKeywordsClear');
        if (clearBtn && !this._keywordsClearHandler) {
            this._keywordsClearHandler = () => {
                this.candidateKeywords = [];
                this.renderKeywords();
                this.searchByKeywords();
            };
            clearBtn.addEventListener('click', this._keywordsClearHandler);
        }
    }

    async _removeKeyword(index) {
        this.candidateKeywords.splice(index, 1);
        this.renderKeywords();
        await this.searchByKeywords();
    }

    async searchByKeywords() {
        const selectedTimestamps = this._getSelectedTimestamps();
        if (this.candidateTextKey === null
            || this.candidateTextKey !== selectedTimestamps[0]?.text) {
            return;
        }

        const results = document.getElementById('candidateResults');
        const seq = ++this.candidateRequestSeq;

        if (this.candidateKeywords.length === 0) {
            results.innerHTML = '';
            this.display([], 0);
            return;
        }

        try {
            const response = await songApiService.fetchSongs(
                this.candidateKeywords.join(' '),
                null,
                CONSTANTS.SONG_SEARCH_MODE_FUZZY
            );

            if (seq !== this.candidateRequestSeq) {
                return;
            }

            const songs = response.data ?? response;
            this.display(songs, response.total ?? songs.length);
        } catch (error) {
            if (seq !== this.candidateRequestSeq) {
                return;
            }

            console.error('候補の検索に失敗しました:', error);
            document.getElementById('candidateNotice').textContent = '候補の検索に失敗しました。';
        }
    }
}
