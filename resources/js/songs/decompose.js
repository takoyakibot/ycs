import axios from 'axios';
import toast from '../utils/toast.js';

// axiosの設定: クロスオリジンリクエストでクッキーを送信
axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// CSRFトークンの設定
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

/**
 * タイムスタンプ分解・選別機能
 */
class TimestampDecomposition {
    constructor() {
        this.currentItem = null;
        this.selectedTitleIndices = [];  // 複数選択対応（配列）
        this.selectedArtistIndices = []; // 複数選択対応（配列）
        this.statistics = null;
        this.lastProcessedItem = null;   // 直前に処理したアイテム（undo用）
        this.cleanupOpen = false;        // 補足除去候補パネルの表示状態

        this.init();
    }

    init() {
        this.bindEvents();
        this.bindKeyboard();
        this.loadStatistics();
        this.loadNext();
        this.updateUndoButton();
    }

    bindEvents() {
        // スキャンボタン
        document.getElementById('scanBtn').addEventListener('click', () => this.scan());

        // スキップボタン
        document.getElementById('skipBtn').addEventListener('click', () => this.skip());

        // 全体を楽曲名ボタン
        document.getElementById('wholeTitleBtn').addEventListener('click', () => this.saveAsWholeTitle());

        // リセットボタン
        document.getElementById('resetBtn').addEventListener('click', () => this.reset());

        // 確定ボタン
        document.getElementById('confirmBtn').addEventListener('click', () => this.confirm());

        // 戻るボタン
        document.getElementById('undoBtn').addEventListener('click', () => this.undo());

        // 補足除去候補パネル
        document.getElementById('cleanupConfirmBtn').addEventListener('click', () => this.confirmCleanup());
        document.getElementById('cleanupCancelBtn').addEventListener('click', () => this.closeCleanup());

        // パネル内の入力欄では Enter で確定 / Esc でキャンセル
        ['cleanupTitle', 'cleanupArtist'].forEach((id) => {
            document.getElementById(id).addEventListener('keydown', (e) => {
                // 日本語入力の変換確定のEnterで送信してしまわないようにする
                // （keyCode 229 は isComposing 未対応環境向けのフォールバック）
                if (e.isComposing || e.keyCode === 229) {
                    return;
                }

                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.confirmCleanup();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    this.closeCleanup();
                }
            });
        });
    }

    bindKeyboard() {
        document.addEventListener('keydown', (e) => {
            // フォーカスが入力フィールドの場合はスキップ
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                return;
            }

            // 補足除去候補パネルを開いている間は、そのパネルの操作を優先する
            if (this.cleanupOpen) {
                // ボタンにフォーカスがあるときはボタン本来の動作に任せる。
                // ここで拾うと、キャンセルボタンにフォーカスして Enter を押した
                // ときに確定が走ってしまう（操作と逆の結果になる）。
                if (e.target.tagName === 'BUTTON') {
                    return;
                }

                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.closeCleanup();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    this.confirmCleanup();
                }
                return;
            }

            // カードがない場合はスキップ
            if (!this.currentItem) {
                return;
            }

            const parts = this.currentItem.parts;

            // 数字キー 1-9: アーティスト選択
            if (e.key >= '1' && e.key <= '9' && !e.shiftKey) {
                const index = parseInt(e.key, 10) - 1;
                if (index < parts.length) {
                    e.preventDefault();
                    this.selectPart(index, 'artist');
                }
                return;
            }

            // Shift + 数字キー: 楽曲名選択
            if (e.key >= '1' && e.key <= '9' && e.shiftKey) {
                const index = parseInt(e.key, 10) - 1;
                if (index < parts.length) {
                    e.preventDefault();
                    this.selectPart(index, 'title');
                }
                return;
            }

            // Q, W, E, R, T, Y, U, I, O: 楽曲名選択（代替キー）
            const titleKeys = ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o'];
            const titleIndex = titleKeys.indexOf(e.key.toLowerCase());
            if (titleIndex !== -1 && titleIndex < parts.length) {
                e.preventDefault();
                this.selectPart(titleIndex, 'title');
                return;
            }

            // ]: 補足除去候補を表示（Enterの近くに置いて確定まで指を動かさずに済むようにする）
            if (e.key === ']') {
                e.preventDefault();
                this.openCleanup();
                return;
            }

            // X: 選択解除
            if (e.key.toLowerCase() === 'x') {
                e.preventDefault();
                this.reset();
                return;
            }

            // Enter: 確定
            if (e.key === 'Enter') {
                e.preventDefault();
                this.confirm();
                return;
            }

            // S: スキップ
            if (e.key.toLowerCase() === 's') {
                e.preventDefault();
                this.skip();
                return;
            }

            // A: 全体を楽曲名として登録
            if (e.key.toLowerCase() === 'a') {
                e.preventDefault();
                this.saveAsWholeTitle();
                return;
            }

            // R: リセット
            if (e.key.toLowerCase() === 'r') {
                e.preventDefault();
                this.reset();
                return;
            }

            // Z: 戻る（undo）
            if (e.key.toLowerCase() === 'z' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                this.undo();
                return;
            }
        });
    }

    /**
     * 統計情報を読み込み
     */
    async loadStatistics() {
        try {
            const response = await axios.get('/api/songs/decompose/statistics');
            this.statistics = response.data;
            this.displayStatistics();
        } catch (error) {
            console.error('統計情報の取得に失敗しました:', error);
        }
    }

    /**
     * 統計情報を表示
     */
    displayStatistics() {
        if (!this.statistics) return;

        document.getElementById('statPending').textContent = this.statistics.pending.toLocaleString();
        document.getElementById('statSelected').textContent = this.statistics.selected.toLocaleString();
        document.getElementById('statAutoMatched').textContent = this.statistics.auto_matched.toLocaleString();
        document.getElementById('statSkipped').textContent = this.statistics.skipped.toLocaleString();
    }

    /**
     * 次のカードを読み込み
     */
    async loadNext() {
        try {
            this.closeCleanup();
            this.showLoading();
            const response = await axios.get('/api/songs/decompose/next');

            if (!response.data.item) {
                this.currentItem = null;
                this.showNoCard(response.data.message);
                return;
            }

            this.currentItem = response.data.item;
            // title_part_index / artist_part_index が数値の場合は配列に変換
            this.selectedTitleIndices = this.currentItem.title_part_index !== null
                ? [this.currentItem.title_part_index]
                : [];
            this.selectedArtistIndices = this.currentItem.artist_part_index !== null
                ? [this.currentItem.artist_part_index]
                : [];

            this.displayCard();
        } catch (error) {
            console.error('次のアイテムの取得に失敗しました:', error);
            toast.error('データの取得に失敗しました');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * カードがない状態を表示
     */
    showNoCard(message = '処理待ちのアイテムがありません') {
        document.getElementById('noCard').classList.remove('hidden');
        document.getElementById('cardArea').classList.add('hidden');
        document.getElementById('noCard').querySelector('p').textContent = message;
    }

    /**
     * カードを表示
     */
    displayCard() {
        document.getElementById('noCard').classList.add('hidden');
        document.getElementById('cardArea').classList.remove('hidden');

        // 元テキスト
        document.getElementById('originalText').textContent = this.currentItem.original_text;

        // パーツボタン
        this.displayParts();

        // プレビュー更新
        this.updatePreview();

        // 確信度表示
        this.displayConfidence();

        // 確定ボタンの状態を更新
        this.updateConfirmButton();
    }

    /**
     * パーツボタンを表示
     */
    displayParts() {
        const container = document.getElementById('partsContainer');
        container.innerHTML = '';

        this.currentItem.parts.forEach((part, index) => {
            const btn = document.createElement('button');
            btn.className = this.getPartButtonClass(index);
            btn.textContent = part;
            btn.dataset.index = index;

            // クリックで選択切り替え
            btn.addEventListener('click', () => this.togglePartSelection(index));

            container.appendChild(btn);
        });
    }

    /**
     * パーツボタンのクラスを取得
     */
    getPartButtonClass(index) {
        const baseClass = 'px-4 py-2 rounded-lg border-2 transition-all font-medium';

        if (this.selectedTitleIndices.includes(index)) {
            return `${baseClass} bg-blue-100 dark:bg-blue-900 border-blue-500 text-blue-700 dark:text-blue-300`;
        }
        if (this.selectedArtistIndices.includes(index)) {
            return `${baseClass} bg-green-100 dark:bg-green-900 border-green-500 text-green-700 dark:text-green-300`;
        }
        return `${baseClass} bg-gray-100 dark:bg-gray-700 border-gray-300 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600`;
    }

    /**
     * パーツの選択を切り替え（クリック時）
     * 未選択 → アーティスト → 楽曲名 → 解除 のサイクル
     */
    togglePartSelection(index) {
        const isTitleSelected = this.selectedTitleIndices.includes(index);
        const isArtistSelected = this.selectedArtistIndices.includes(index);

        if (isTitleSelected) {
            // 楽曲名選択中 → 解除
            this.selectedTitleIndices = this.selectedTitleIndices.filter(i => i !== index);
        } else if (isArtistSelected) {
            // アーティスト選択中 → 楽曲名に変更
            this.selectedArtistIndices = this.selectedArtistIndices.filter(i => i !== index);
            this.selectedTitleIndices.push(index);
            this.selectedTitleIndices.sort((a, b) => a - b);
        } else {
            // 未選択 → アーティストとして追加
            this.selectedArtistIndices.push(index);
            this.selectedArtistIndices.sort((a, b) => a - b);
        }

        this.displayParts();
        this.updatePreview();
        this.updateConfirmButton();
    }

    /**
     * パーツを選択（役割を指定、トグル動作）
     */
    selectPart(index, role) {
        if (role === 'title') {
            // アーティストから削除
            this.selectedArtistIndices = this.selectedArtistIndices.filter(i => i !== index);
            // 楽曲名をトグル
            if (this.selectedTitleIndices.includes(index)) {
                this.selectedTitleIndices = this.selectedTitleIndices.filter(i => i !== index);
            } else {
                this.selectedTitleIndices.push(index);
                this.selectedTitleIndices.sort((a, b) => a - b);
            }
        } else if (role === 'artist') {
            // 楽曲名から削除
            this.selectedTitleIndices = this.selectedTitleIndices.filter(i => i !== index);
            // アーティストをトグル
            if (this.selectedArtistIndices.includes(index)) {
                this.selectedArtistIndices = this.selectedArtistIndices.filter(i => i !== index);
            } else {
                this.selectedArtistIndices.push(index);
                this.selectedArtistIndices.sort((a, b) => a - b);
            }
        }

        this.displayParts();
        this.updatePreview();
        this.updateConfirmButton();
    }

    /**
     * プレビューを更新
     */
    updatePreview() {
        const titleEl = document.getElementById('previewTitle');
        const artistEl = document.getElementById('previewArtist');

        if (this.selectedTitleIndices.length > 0) {
            titleEl.textContent = this.joinSelectedParts(this.selectedTitleIndices);
        } else {
            titleEl.textContent = '-';
        }

        if (this.selectedArtistIndices.length > 0) {
            artistEl.textContent = this.joinSelectedParts(this.selectedArtistIndices);
        } else {
            artistEl.textContent = '-';
        }
    }

    /**
     * 選択されたパーツを元の区切り文字を維持して連結
     *
     * @param {number[]} indices 選択されたパーツのインデックス
     * @param {string[]} [parts] 連結対象のパーツ配列（省略時は元のパーツ）
     */
    joinSelectedParts(indices, parts = null) {
        if (!indices || indices.length === 0) return '';

        const originalParts = this.currentItem.parts;
        parts = parts || originalParts;

        if (indices.length === 1) {
            return parts[indices[0]] || '';
        }

        const sortedIndices = [...indices].sort((a, b) => a - b);

        // 連続するインデックスのグループを作成
        const groups = [];
        let currentGroup = [sortedIndices[0]];

        for (let i = 1; i < sortedIndices.length; i++) {
            if (sortedIndices[i] === sortedIndices[i - 1] + 1) {
                currentGroup.push(sortedIndices[i]);
            } else {
                groups.push(currentGroup);
                currentGroup = [sortedIndices[i]];
            }
        }
        groups.push(currentGroup);

        // 各グループを、元テキストの区切り文字で連結する。
        //
        // 値は parts を使う。parts には補足除去済みの配列が渡ることがあり、
        // 元テキストを範囲でスライスすると除去した補足が復活してしまう
        // （画面は「変化なし」と表示したまま、確定すると補足付きの値が入る）。
        // 一方で区切り文字は8種類（/／-−－:：|｜）あるため固定文字で連結すると
        // 「曲名 - アーティスト」が「曲名 / アーティスト」に化ける。
        // そこで区切りだけを元テキストから取り、値は parts のものを使う。
        const separators = this.extractSeparatorsFromOriginal(
            this.currentItem.original_text,
            originalParts
        );

        const result = groups.map(group => {
            let joined = parts[group[0]] || '';

            for (let i = 1; i < group.length; i++) {
                const index = group[i];
                const separator = separators[index - 1] ?? ' / ';
                joined += separator + (parts[index] || '');
            }

            return joined.trim();
        });

        return result.join(' / ');
    }

    /**
     * 元テキストから、隣り合うパーツの間にある区切り文字を取り出す
     *
     * 戻り値の i 番目は originalParts[i] と originalParts[i + 1] の間の文字列。
     * 位置を特定できなかった場合は ' / ' で代替する。
     *
     * @param {string} originalText 分解元のテキスト
     * @param {string[]} originalParts 元のパーツ配列（掃除前）
     * @returns {string[]}
     */
    extractSeparatorsFromOriginal(originalText, originalParts) {
        const ranges = [];
        let currentPos = 0;

        for (let i = 0; i < originalParts.length; i++) {
            const partPos = originalText.indexOf(originalParts[i], currentPos);

            if (partPos === -1) {
                ranges.push(null);
                continue;
            }

            ranges.push({ start: partPos, end: partPos + originalParts[i].length });
            currentPos = partPos + originalParts[i].length;
        }

        const separators = [];

        for (let i = 0; i + 1 < originalParts.length; i++) {
            const current = ranges[i];
            const next = ranges[i + 1];

            separators.push(
                current && next ? originalText.substring(current.end, next.start) : ' / '
            );
        }

        return separators;
    }

    /**
     * 確信度を表示
     */
    displayConfidence() {
        const confidenceArea = document.getElementById('confidenceArea');
        const confidence = this.currentItem.confidence;

        if (confidence !== null && confidence !== undefined) {
            confidenceArea.classList.remove('hidden');
            const percentage = Math.round(confidence * 100);
            document.getElementById('confidenceBar').style.width = `${percentage}%`;
            document.getElementById('confidenceValue').textContent = `${percentage}%`;

            // 確信度によって色を変える
            const bar = document.getElementById('confidenceBar');
            bar.classList.remove('bg-red-500', 'bg-yellow-500', 'bg-green-500', 'bg-blue-500');
            if (percentage >= 80) {
                bar.classList.add('bg-green-500');
            } else if (percentage >= 50) {
                bar.classList.add('bg-yellow-500');
            } else {
                bar.classList.add('bg-red-500');
            }
        } else {
            confidenceArea.classList.add('hidden');
        }
    }

    /**
     * 確定ボタンの状態を更新
     */
    updateConfirmButton() {
        const btn = document.getElementById('confirmBtn');
        // 少なくとも楽曲名が選択されている場合に有効化
        btn.disabled = this.selectedTitleIndices.length === 0;
    }

    /**
     * 選択をリセット
     */
    reset() {
        this.closeCleanup();
        this.selectedTitleIndices = [];
        this.selectedArtistIndices = [];
        this.displayParts();
        this.updatePreview();
        this.updateConfirmButton();
    }

    /**
     * 補足除去候補を表示（] キー）
     *
     * 現在の選択から「曲名ではない補足」を落とした候補を作り、
     * そのまま確定するか、入力欄で微調整できるようにする。
     */
    openCleanup() {
        if (!this.currentItem) return;

        if (this.selectedTitleIndices.length === 0 && this.selectedArtistIndices.length === 0) {
            toast.warning('先にパーツを選択してください');
            return;
        }

        const cleanedParts = this.currentItem.cleaned_parts || this.currentItem.parts;

        const currentTitle = this.joinSelectedParts(this.selectedTitleIndices);
        const currentArtist = this.joinSelectedParts(this.selectedArtistIndices);
        const cleanedTitle = this.joinSelectedParts(this.selectedTitleIndices, cleanedParts);
        const cleanedArtist = this.joinSelectedParts(this.selectedArtistIndices, cleanedParts);

        document.getElementById('cleanupTitle').value = cleanedTitle;
        document.getElementById('cleanupArtist').value = cleanedArtist;

        this.displayRemoved('cleanupTitleRemoved', currentTitle, cleanedTitle);
        this.displayRemoved('cleanupArtistRemoved', currentArtist, cleanedArtist);

        const noChange = currentTitle === cleanedTitle && currentArtist === cleanedArtist;
        document.getElementById('cleanupNoChange').classList.toggle('hidden', !noChange);

        document.getElementById('cleanupPanel').classList.remove('hidden');
        this.cleanupOpen = true;

        // 微調整しやすいよう、変化があった方の入力欄にフォーカスする
        const focusTarget = currentTitle !== cleanedTitle ? 'cleanupTitle' : 'cleanupArtist';
        const input = document.getElementById(focusTarget);
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    }

    /**
     * 除去された部分を表示
     */
    displayRemoved(elementId, before, after) {
        const el = document.getElementById(elementId);

        if (before === after) {
            el.textContent = '';
            el.classList.add('hidden');
            return;
        }

        el.textContent = `除去前: ${before}`;
        el.classList.remove('hidden');
    }

    /**
     * 補足除去候補を閉じる
     */
    closeCleanup() {
        const panel = document.getElementById('cleanupPanel');
        if (panel) {
            panel.classList.add('hidden');
        }
        this.cleanupOpen = false;
    }

    /**
     * 補足除去候補の内容で確定
     */
    async confirmCleanup() {
        const title = document.getElementById('cleanupTitle').value.trim();
        const artist = document.getElementById('cleanupArtist').value.trim();

        if (title === '') {
            toast.warning('楽曲名を入力してください');
            return;
        }

        await this.confirm({ title, artist });
    }

    /**
     * 確定
     *
     * @param {{title: string, artist: string}|null} overrides
     *        パーツ連結ではなくこの値で確定する（補足除去候補・微調整用）
     */
    async confirm(overrides = null) {
        if (!overrides && this.selectedTitleIndices.length === 0) {
            toast.warning('楽曲名を選択してください');
            return;
        }

        try {
            this.showLoading();

            const payload = {
                id: this.currentItem.id,
                title_indices: this.selectedTitleIndices,
                artist_indices: this.selectedArtistIndices,
                link_to_song: true
            };

            if (overrides) {
                payload.title = overrides.title;
                payload.artist = overrides.artist;
            }

            await axios.post('/api/songs/decompose/select', payload);

            // undo用に処理したアイテムを保存
            this.lastProcessedItem = {
                id: this.currentItem.id,
                action: 'confirm'
            };
            this.updateUndoButton();

            toast.success('保存しました');
            await this.loadStatistics();
            await this.loadNext();
        } catch (error) {
            console.error('保存に失敗しました:', error);
            toast.error('保存に失敗しました');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * スキップ
     */
    async skip() {
        if (!this.currentItem) return;

        try {
            this.showLoading();
            await axios.post(`/api/songs/decompose/${this.currentItem.id}/skip`);

            // undo用に処理したアイテムを保存
            this.lastProcessedItem = {
                id: this.currentItem.id,
                action: 'skip'
            };
            this.updateUndoButton();

            toast.info('スキップしました');
            await this.loadStatistics();
            await this.loadNext();
        } catch (error) {
            console.error('スキップに失敗しました:', error);
            toast.error('スキップに失敗しました');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * 全体を楽曲名として登録（分割しない）
     */
    async saveAsWholeTitle() {
        if (!this.currentItem) return;

        try {
            this.showLoading();
            await axios.post('/api/songs/decompose/whole-title', {
                id: this.currentItem.id,
                link_to_song: true
            });

            // undo用に処理したアイテムを保存
            this.lastProcessedItem = {
                id: this.currentItem.id,
                action: 'wholeTitle'
            };
            this.updateUndoButton();

            toast.success('全体を楽曲名として登録しました');
            await this.loadStatistics();
            await this.loadNext();
        } catch (error) {
            console.error('登録に失敗しました:', error);
            toast.error('登録に失敗しました');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * 戻る（undo）
     */
    async undo() {
        if (!this.lastProcessedItem) {
            toast.warning('戻れる操作がありません');
            return;
        }

        try {
            this.showLoading();
            await axios.post(`/api/songs/decompose/${this.lastProcessedItem.id}/undo`);

            toast.success('操作を取り消しました');

            this.lastProcessedItem = null;
            this.updateUndoButton();
            await this.loadStatistics();
            await this.loadNext();
        } catch (error) {
            console.error('取り消しに失敗しました:', error);
            toast.error('取り消しに失敗しました');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * 戻るボタンの状態を更新
     */
    updateUndoButton() {
        const btn = document.getElementById('undoBtn');
        if (btn) {
            btn.disabled = !this.lastProcessedItem;
        }
    }

    /**
     * スキャン実行
     */
    async scan() {
        try {
            this.showLoading();
            const response = await axios.post('/api/songs/decompose/scan');

            toast.success(`${response.data.scanned_count}件のタイムスタンプをスキャンしました`);
            this.statistics = response.data.statistics;
            this.displayStatistics();
            await this.loadNext();
        } catch (error) {
            console.error('スキャンに失敗しました:', error);
            toast.error('スキャンに失敗しました');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * ローディング表示
     */
    showLoading() {
        document.getElementById('loadingModal').classList.remove('hidden');
    }

    /**
     * ローディング非表示
     */
    hideLoading() {
        document.getElementById('loadingModal').classList.add('hidden');
    }
}

// 初期化
document.addEventListener('DOMContentLoaded', () => {
    new TimestampDecomposition();
});
