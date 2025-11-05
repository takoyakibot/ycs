/**
 * Toast通知ユーティリティ
 * トースターからパンが飛び出すようにメッセージを表示します
 */
class Toast {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        // Toast通知用のコンテナを作成
        if (!document.getElementById('toast-container')) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none';
            this.container.style.maxWidth = '400px';
            document.body.appendChild(this.container);
        } else {
            this.container = document.getElementById('toast-container');
        }
    }

    /**
     * Toast通知を表示
     * @param {string} message - 表示するメッセージ
     * @param {string} type - 'success' | 'error' | 'warning' | 'info'
     * @param {number} duration - 表示時間（ミリ秒）、デフォルトは3000ms
     */
    show(message, type = 'info', duration = 3000) {
        const toast = document.createElement('div');
        toast.className = 'toast-item pointer-events-auto transform translate-x-full transition-all duration-300 ease-out';

        // タイプに応じた色を設定
        let bgColor, borderColor, iconSvg;
        switch (type) {
            case 'success':
                bgColor = 'bg-green-50 dark:bg-green-900';
                borderColor = 'border-green-500';
                iconSvg = `
                    <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                `;
                break;
            case 'error':
                bgColor = 'bg-red-50 dark:bg-red-900';
                borderColor = 'border-red-500';
                iconSvg = `
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                `;
                duration = duration || 4000; // エラーは少し長めに表示
                break;
            case 'warning':
                bgColor = 'bg-yellow-50 dark:bg-yellow-900';
                borderColor = 'border-yellow-500';
                iconSvg = `
                    <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                `;
                break;
            default: // info
                bgColor = 'bg-blue-50 dark:bg-blue-900';
                borderColor = 'border-blue-500';
                iconSvg = `
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
                break;
        }

        toast.innerHTML = `
            <div class="flex items-start gap-3 p-4 rounded-lg shadow-lg border-l-4 ${bgColor} ${borderColor}">
                <div class="flex-shrink-0 mt-0.5">
                    ${iconSvg}
                </div>
                <div class="flex-1 text-sm text-gray-800 dark:text-gray-200">
                    ${this.escapeHtml(message)}
                </div>
                <button class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        `;

        // 閉じるボタンのイベント
        const closeBtn = toast.querySelector('button');
        closeBtn.addEventListener('click', () => {
            this.remove(toast);
        });

        // コンテナに追加
        this.container.appendChild(toast);

        // アニメーション: スライドイン
        setTimeout(() => {
            toast.classList.remove('translate-x-full');
            toast.classList.add('translate-x-0');
        }, 10);

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
     * 成功メッセージを表示
     */
    success(message, duration) {
        return this.show(message, 'success', duration);
    }

    /**
     * エラーメッセージを表示
     */
    error(message, duration) {
        return this.show(message, 'error', duration);
    }

    /**
     * 警告メッセージを表示
     */
    warning(message, duration) {
        return this.show(message, 'warning', duration);
    }

    /**
     * 情報メッセージを表示
     */
    info(message, duration) {
        return this.show(message, 'info', duration);
    }

    /**
     * HTMLエスケープ
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// シングルトンインスタンスをエクスポート
export default new Toast();
