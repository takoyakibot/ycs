import { BUTTON_STYLES } from '../utils/constants.js';

/**
 * ページネーションコンポーネント
 */
export class Pagination {
    /**
     * ページネーションボタンを作成
     * @param {string} label - ボタンラベル
     * @param {number} targetPage - 遷移先ページ
     * @param {boolean} isEnabled - ボタンが有効かどうか
     * @param {Function} onPageChange - ページ変更時のコールバック
     * @returns {HTMLButtonElement} ボタン要素
     */
    static createButton(label, targetPage, isEnabled, onPageChange) {
        const btn = document.createElement('button');
        btn.textContent = label;
        btn.className = isEnabled ? BUTTON_STYLES.btnClass : BUTTON_STYLES.disabledBtnClass;
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
     * @param {number} data.total - 全件数
     * @param {Function} onPageChange - ページ変更時のコールバック
     */
    static render(container, data, onPageChange) {
        container.innerHTML = '';

        if (data.last_page <= 1) {
            // ページが1ページのみの場合も件数を表示
            if (data.total !== undefined) {
                const totalInfo = document.createElement('span');
                totalInfo.textContent = `全${data.total}件`;
                totalInfo.className = 'px-3 py-1 text-sm font-medium text-gray-600 dark:text-gray-400';
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
        const prevButtons = [
            { label: '最初', targetPage: 1, enableCondition: currentPage > 1 },
            { label: '-10', targetPage: currentPage - 10, enableCondition: currentPage > 10 },
            { label: '-5', targetPage: currentPage - 5, enableCondition: currentPage > 5 },
            { label: '前へ', targetPage: currentPage - 1, enableCondition: currentPage > 1 },
        ];

        prevButtons.forEach(({ label, targetPage, enableCondition }) => {
            container.appendChild(this.createButton(label, targetPage, enableCondition, onPageChange));
        });

        // ページ情報
        const pageInfo = document.createElement('span');
        pageInfo.textContent = `${currentPage} / ${lastPage}`;
        pageInfo.className = 'px-3 py-1 text-sm font-medium';
        container.appendChild(pageInfo);

        // 件数表示を追加
        if (data.total !== undefined) {
            const totalInfo = document.createElement('span');
            totalInfo.textContent = `(全${data.total}件)`;
            totalInfo.className = 'px-3 py-1 text-sm text-gray-600 dark:text-gray-400';
            container.appendChild(totalInfo);
        }

        // 次へ系のボタン
        const nextButtons = [
            { label: '次へ', targetPage: currentPage + 1, enableCondition: currentPage < lastPage },
            { label: '+5', targetPage: currentPage + 5, enableCondition: currentPage + 5 <= lastPage },
            { label: '+10', targetPage: currentPage + 10, enableCondition: currentPage + 10 <= lastPage },
            { label: '最後', targetPage: lastPage, enableCondition: currentPage < lastPage },
        ];

        nextButtons.forEach(({ label, targetPage, enableCondition }) => {
            container.appendChild(this.createButton(label, targetPage, enableCondition, onPageChange));
        });
    }
}
