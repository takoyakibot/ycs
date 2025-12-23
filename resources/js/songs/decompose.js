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
        this.selectedTitleIndex = null;
        this.selectedArtistIndex = null;
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
            this.selectedTitleIndex = this.currentItem.title_part_index;
            this.selectedArtistIndex = this.currentItem.artist_part_index;

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

        if (this.selectedTitleIndex === index) {
            return `${baseClass} bg-blue-100 dark:bg-blue-900 border-blue-500 text-blue-700 dark:text-blue-300`;
        }
        if (this.selectedArtistIndex === index) {
            return `${baseClass} bg-green-100 dark:bg-green-900 border-green-500 text-green-700 dark:text-green-300`;
        }
        return `${baseClass} bg-gray-100 dark:bg-gray-700 border-gray-300 dark:border-gray-600 hover:bg-gray-200 dark:hover:bg-gray-600`;
    }

    /**
     * パーツの選択を切り替え
     */
    togglePartSelection(index) {
        // 既に楽曲名として選択されている場合は解除
        if (this.selectedTitleIndex === index) {
            this.selectedTitleIndex = null;
        }
        // 既にアーティストとして選択されている場合は解除
        else if (this.selectedArtistIndex === index) {
            this.selectedArtistIndex = null;
        }
        // 未選択の場合は楽曲名として選択（アーティストが未選択ならアーティストとして）
        else {
            if (this.selectedArtistIndex === null) {
                this.selectedArtistIndex = index;
            } else if (this.selectedTitleIndex === null) {
                this.selectedTitleIndex = index;
            } else {
                // 両方選択済みの場合は楽曲名を上書き
                this.selectedTitleIndex = index;
            }
        }

        this.displayParts();
        this.updatePreview();
        this.updateConfirmButton();
    }

    /**
     * パーツを選択（役割を指定）
     */
    selectPart(index, role) {
        if (role === 'title') {
            // 既にアーティストとして選択されている場合は解除
            if (this.selectedArtistIndex === index) {
                this.selectedArtistIndex = null;
            }
            this.selectedTitleIndex = this.selectedTitleIndex === index ? null : index;
        } else if (role === 'artist') {
            // 既に楽曲名として選択されている場合は解除
            if (this.selectedTitleIndex === index) {
                this.selectedTitleIndex = null;
            }
            this.selectedArtistIndex = this.selectedArtistIndex === index ? null : index;
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

        if (this.selectedTitleIndex !== null && this.currentItem.parts[this.selectedTitleIndex]) {
            titleEl.textContent = this.currentItem.parts[this.selectedTitleIndex];
        } else {
            titleEl.textContent = '-';
        }

        if (this.selectedArtistIndex !== null && this.currentItem.parts[this.selectedArtistIndex]) {
            artistEl.textContent = this.currentItem.parts[this.selectedArtistIndex];
        } else {
            artistEl.textContent = '-';
        }
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
        btn.disabled = this.selectedTitleIndex === null;
    }

    /**
     * 選択をリセット
     */
    reset() {
        this.selectedTitleIndex = null;
        this.selectedArtistIndex = null;
        this.displayParts();
        this.updatePreview();
        this.updateConfirmButton();
    }

    /**
     * 確定
     */
    async confirm() {
        if (this.selectedTitleIndex === null) {
            toast.warning('楽曲名を選択してください');
            return;
        }

        try {
            this.showLoading();
            await axios.post('/api/songs/decompose/select', {
                id: this.currentItem.id,
                title_index: this.selectedTitleIndex,
                artist_index: this.selectedArtistIndex,
                link_to_song: true
            });

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
