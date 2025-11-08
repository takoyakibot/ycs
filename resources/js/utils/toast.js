/**
 * Toast通知ユーティリティ
 */

class ToastManager {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        // Toast表示用のコンテナを作成
        this.container = document.createElement('div');
        this.container.id = 'toast-container';
        this.container.className = 'fixed top-4 right-4 z-[60] flex flex-col gap-2 pointer-events-none';
        document.body.appendChild(this.container);
    }

    /**
     * Toast通知を表示
     * @param {string} message - 表示するメッセージ
     * @param {string} type - 通知タイプ ('success', 'error', 'warning', 'info')
     * @param {number} duration - 表示時間（ミリ秒）
     */
    show(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = `
            pointer-events-auto
            px-4 py-3 rounded-lg shadow-lg
            flex items-center gap-3
            transform transition-all duration-300 ease-in-out
            translate-x-full opacity-0
            max-w-md
            ${this.getTypeStyles(type)}
        `;

        // アイコン
        const icon = document.createElement('div');
        icon.className = 'flex-shrink-0';
        icon.innerHTML = this.getIcon(type);

        // メッセージ
        const messageDiv = document.createElement('div');
        messageDiv.className = 'flex-1 text-sm font-medium';
        messageDiv.textContent = message;

        // 閉じるボタン
        const closeBtn = document.createElement('button');
        closeBtn.className = 'flex-shrink-0 opacity-70 hover:opacity-100 transition-opacity';
        closeBtn.innerHTML = `
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
            </svg>
        `;
        closeBtn.addEventListener('click', () => {
            this.remove(toast);
        });

        toast.appendChild(icon);
        toast.appendChild(messageDiv);
        toast.appendChild(closeBtn);

        this.container.appendChild(toast);

        // アニメーション: スライドイン
        requestAnimationFrame(() => {
            toast.classList.remove('translate-x-full', 'opacity-0');
        });

        // 自動削除
        if (duration > 0) {
            setTimeout(() => {
                this.remove(toast);
            }, duration);
        }

        return toast;
    }

    /**
     * Toast通知を削除
     * @param {HTMLElement} toast - 削除するToast要素
     */
    remove(toast) {
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    /**
     * タイプ別のスタイルを取得
     * @param {string} type - 通知タイプ
     * @returns {string} Tailwindクラス
     */
    getTypeStyles(type) {
        const styles = {
            success: 'bg-green-500 text-white dark:bg-green-600',
            error: 'bg-red-500 text-white dark:bg-red-600',
            warning: 'bg-yellow-500 text-white dark:bg-yellow-600',
            info: 'bg-blue-500 text-white dark:bg-blue-600'
        };
        return styles[type] || styles.info;
    }

    /**
     * タイプ別のアイコンを取得
     * @param {string} type - 通知タイプ
     * @returns {string} SVGアイコン
     */
    getIcon(type) {
        const icons = {
            success: `
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
            `,
            error: `
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
            `,
            warning: `
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
            `,
            info: `
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
            `
        };
        return icons[type] || icons.info;
    }

    /**
     * 成功メッセージを表示
     * @param {string} message - 表示するメッセージ
     * @param {number} duration - 表示時間（ミリ秒）
     */
    success(message, duration = 3000) {
        return this.show(message, 'success', duration);
    }

    /**
     * エラーメッセージを表示
     * @param {string} message - 表示するメッセージ
     * @param {number} duration - 表示時間（ミリ秒）
     */
    error(message, duration = 4000) {
        return this.show(message, 'error', duration);
    }

    /**
     * 警告メッセージを表示
     * @param {string} message - 表示するメッセージ
     * @param {number} duration - 表示時間（ミリ秒）
     */
    warning(message, duration = 3000) {
        return this.show(message, 'warning', duration);
    }

    /**
     * 情報メッセージを表示
     * @param {string} message - 表示するメッセージ
     * @param {number} duration - 表示時間（ミリ秒）
     */
    info(message, duration = 3000) {
        return this.show(message, 'info', duration);
    }
}

// シングルトンインスタンスをエクスポート
const toast = new ToastManager();
export default toast;
