/**
 * 共通ページネーションコンポーネント
 *
 * DOM操作ベースのページネーションUIを提供
 * 使用例:
 * ```
 * import { Pagination } from '../shared/components/Pagination.js';
 * Pagination.render(container, data, onPageChange, options);
 * ```
 */

// デフォルトのボタンスタイル
const DEFAULT_STYLES = {
    btnClass: 'px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-gray-300 dark:hover:bg-gray-600 text-sm',
    disabledBtnClass: 'px-3 py-1 bg-gray-100 dark:bg-gray-800 rounded-md text-gray-400 dark:text-gray-600 cursor-not-allowed text-sm',
    pageInfoClass: 'px-3 py-1 text-sm font-medium',
    totalInfoClass: 'px-3 py-1 text-sm text-gray-600 dark:text-gray-400'
};

export class Pagination {
    /**
     * ページネーションボタンを作成
     * @param {string} label - ボタンラベル
     * @param {number} targetPage - 遷移先ページ
     * @param {boolean} isEnabled - ボタンが有効かどうか
     * @param {Function} onPageChange - ページ変更時のコールバック
     * @param {Object} styles - ボタンスタイル
     * @returns {HTMLButtonElement} ボタン要素
     */
    static createButton(label, targetPage, isEnabled, onPageChange, styles = DEFAULT_STYLES) {
        const btn = document.createElement('button');
        btn.textContent = label;
        btn.className = isEnabled ? styles.btnClass : styles.disabledBtnClass;
        btn.disabled = !isEnabled;

        if (isEnabled) {
            btn.addEventListener('click', () => {
                onPageChange(targetPage);
            });
        }

        return btn;
    }

    /**
     * ページネーションUIを描画
     * @param {HTMLElement} container - 描画先コンテナ
     * @param {Object} data - ページネーションデータ
     * @param {number} data.current_page - 現在のページ
     * @param {number} data.last_page - 最終ページ
     * @param {number} data.total - 全件数（オプション）
     * @param {Function} onPageChange - ページ変更時のコールバック
     * @param {Object} options - オプション設定
     * @param {Object} options.styles - カスタムスタイル
     * @param {boolean} options.showJumpButtons - +5/-5, +10/-10ボタンを表示するか
     * @param {boolean} options.showFirstLast - 最初/最後ボタンを表示するか
     */
    static render(container, data, onPageChange, options = {}) {
        const {
            styles = DEFAULT_STYLES,
            showJumpButtons = true,
            showFirstLast = true
        } = options;

        container.innerHTML = '';

        if (data.last_page <= 1) {
            // ページが1ページのみの場合も件数を表示
            if (data.total !== undefined) {
                const totalInfo = document.createElement('span');
                totalInfo.textContent = `全${data.total}件`;
                totalInfo.className = styles.totalInfoClass || DEFAULT_STYLES.totalInfoClass;
                container.appendChild(totalInfo);
            }
            return;
        }

        const currentPage = parseInt(data.current_page, 10);
        const lastPage = parseInt(data.last_page, 10);

        // バリデーション
        if (Number.isNaN(currentPage) || Number.isNaN(lastPage)) {
            console.error('Invalid page numbers:', { currentPage, lastPage });
            return;
        }

        // 前へ系のボタン
        const prevButtons = [];

        if (showFirstLast) {
            prevButtons.push({ label: '最初', targetPage: 1, enableCondition: currentPage > 1 });
        }

        if (showJumpButtons) {
            prevButtons.push(
                { label: '-10', targetPage: currentPage - 10, enableCondition: currentPage > 10 },
                { label: '-5', targetPage: currentPage - 5, enableCondition: currentPage > 5 }
            );
        }

        prevButtons.push({ label: '前へ', targetPage: currentPage - 1, enableCondition: currentPage > 1 });

        prevButtons.forEach(({ label, targetPage, enableCondition }) => {
            container.appendChild(this.createButton(label, targetPage, enableCondition, onPageChange, styles));
        });

        // ページ情報
        const pageInfo = document.createElement('span');
        pageInfo.textContent = `${currentPage} / ${lastPage}`;
        pageInfo.className = styles.pageInfoClass || DEFAULT_STYLES.pageInfoClass;
        container.appendChild(pageInfo);

        // 件数表示を追加
        if (data.total !== undefined) {
            const totalInfo = document.createElement('span');
            totalInfo.textContent = `(全${data.total}件)`;
            totalInfo.className = styles.totalInfoClass || DEFAULT_STYLES.totalInfoClass;
            container.appendChild(totalInfo);
        }

        // 次へ系のボタン
        const nextButtons = [
            { label: '次へ', targetPage: currentPage + 1, enableCondition: currentPage < lastPage }
        ];

        if (showJumpButtons) {
            nextButtons.push(
                { label: '+5', targetPage: currentPage + 5, enableCondition: currentPage + 5 <= lastPage },
                { label: '+10', targetPage: currentPage + 10, enableCondition: currentPage + 10 <= lastPage }
            );
        }

        if (showFirstLast) {
            nextButtons.push({ label: '最後', targetPage: lastPage, enableCondition: currentPage < lastPage });
        }

        nextButtons.forEach(({ label, targetPage, enableCondition }) => {
            container.appendChild(this.createButton(label, targetPage, enableCondition, onPageChange, styles));
        });
    }

    /**
     * ページ計算ユーティリティ
     * @param {number} total - 全件数
     * @param {number} perPage - 1ページあたりの件数
     * @returns {number} 総ページ数
     */
    static calculateTotalPages(total, perPage) {
        return Math.ceil(total / perPage);
    }

    /**
     * 有効なページ番号かチェック
     * @param {number} page - チェックするページ番号
     * @param {number} lastPage - 最終ページ
     * @returns {boolean} 有効な場合はtrue
     */
    static isValidPage(page, lastPage) {
        return page >= 1 && page <= lastPage;
    }

    /**
     * ページ番号をバリデートして正規化
     * @param {number|string} page - ページ番号
     * @param {number} lastPage - 最終ページ
     * @returns {number} 正規化されたページ番号
     */
    static normalizePage(page, lastPage) {
        const parsed = parseInt(page, 10);
        if (Number.isNaN(parsed) || parsed < 1) {
            return 1;
        }
        if (parsed > lastPage) {
            return lastPage;
        }
        return parsed;
    }
}
