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

        this.init();
    }

    init() {
        this.bindEvents();
        this.bindKeyboard();
        this.loadStatistics();
        this.loadNext();
    }

    bindEvents() {
        // スキャンボタン
        document.getElementById('scanBtn').addEventListener('click', () => this.scan());

        // 一括紐付けボタン
        document.getElementById('bulkLinkBtn').addEventListener('click', () => this.bulkLink());

        // スキップボタン
        document.getElementById('skipBtn').addEventListener('click', () => this.skip());

        // 全体を楽曲名ボタン
        document.getElementById('wholeTitleBtn').addEventListener('click', () => this.saveAsWholeTitle());

        // リセットボタン
        document.getElementById('resetBtn').addEventListener('click', () => this.reset());

        // 確定ボタン
        document.getElementById('confirmBtn').addEventListener('click', () => this.confirm());
    }

    bindKeyboard() {
        document.addEventListener('keydown', (e) => {
            // フォーカスが入力フィールドの場合はスキップ
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
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
     */
    joinSelectedParts(indices) {
        if (!indices || indices.length === 0) return '';
        if (indices.length === 1) {
            return this.currentItem.parts[indices[0]] || '';
        }

        const sortedIndices = [...indices].sort((a, b) => a - b);
        const originalText = this.currentItem.original_text;
        const parts = this.currentItem.parts;

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

        // 各グループを元テキストから抽出して連結
        const result = groups.map(group => {
            return this.extractRangeFromOriginal(originalText, parts, group[0], group[group.length - 1]);
        });

        return result.join(' / ');
    }

    /**
     * 元テキストから指定範囲のパーツを抽出（区切り文字を維持）
     */
    extractRangeFromOriginal(originalText, parts, startIndex, endIndex) {
        if (startIndex === endIndex) {
            return parts[startIndex] || '';
        }

        // 各パーツの位置を特定
        let currentPos = 0;
        let startPos = -1;
        let endPos = -1;

        for (let i = 0; i < parts.length; i++) {
            const partPos = originalText.indexOf(parts[i], currentPos);
            if (partPos === -1) continue;

            if (i === startIndex) {
                startPos = partPos;
            }
            if (i === endIndex) {
                endPos = partPos + parts[i].length;
                break;
            }
            currentPos = partPos + parts[i].length;
        }

        if (startPos !== -1 && endPos !== -1) {
            return originalText.substring(startPos, endPos).trim();
        }

        // フォールバック: 単純に連結
        return parts.slice(startIndex, endIndex + 1).join(' / ');
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
        this.selectedTitleIndices = [];
        this.selectedArtistIndices = [];
        this.displayParts();
        this.updatePreview();
        this.updateConfirmButton();
    }

    /**
     * 確定
     */
    async confirm() {
        if (this.selectedTitleIndices.length === 0) {
            toast.warning('楽曲名を選択してください');
            return;
        }

        try {
            this.showLoading();
            const response = await axios.post('/api/songs/decompose/select', {
                id: this.currentItem.id,
                title_indices: this.selectedTitleIndices,
                artist_indices: this.selectedArtistIndices,
                link_to_song: true
            });

            const cascadedCount = response.data.cascaded_count || 0;
            if (cascadedCount > 0) {
                toast.success(`保存しました（同じアーティストの ${cascadedCount} 件も自動処理）`);
            } else {
                toast.success('保存しました');
            }
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
     * 自動判定を一括紐付け
     */
    async bulkLink() {
        if (!confirm('自動判定済み（確信度80%以上）のアイテムを一括で楽曲マスタに紐付けします。続行しますか？')) {
            return;
        }

        try {
            this.showLoading();
            const response = await axios.post('/api/songs/decompose/bulk-link');

            toast.success(`${response.data.linked_count}件を楽曲マスタに紐付けました`);
            this.statistics = response.data.statistics;
            this.displayStatistics();
        } catch (error) {
            console.error('一括紐付けに失敗しました:', error);
            toast.error('一括紐付けに失敗しました');
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
