/**
 * ドラッグ機能を管理するマネージャークラス
 *
 * 責務:
 * - マウス/タッチによるドラッグ操作の処理
 * - 位置の計算と境界制約
 * - イベントリスナーの管理
 */
export class DragManager {
    constructor() {
        // 状態
        this.isDragging = false;
        this.position = { x: null, y: null };
        this.dragOffset = { x: 0, y: 0 };

        // イベントハンドラ参照
        this.boundOnDrag = null;
        this.boundStopDrag = null;

        // ドラッグ対象要素
        this.targetElement = null;

        // コールバック
        this.onPositionChange = null;
    }

    /**
     * ドラッグを開始
     * @param {Event} event - マウスまたはタッチイベント
     * @param {HTMLElement} element - ドラッグ対象の要素
     */
    startDrag(event, element) {
        if (!element) return;

        const clientX = event.touches ? event.touches[0].clientX : event.clientX;
        const clientY = event.touches ? event.touches[0].clientY : event.clientY;

        const rect = element.getBoundingClientRect();
        this.isDragging = true;
        this.targetElement = element;
        this.dragOffset = {
            x: clientX - rect.left,
            y: clientY - rect.top
        };

        this.boundOnDrag = this._onDrag.bind(this);
        this.boundStopDrag = this._stopDrag.bind(this);

        document.addEventListener('mousemove', this.boundOnDrag);
        document.addEventListener('mouseup', this.boundStopDrag);
        document.addEventListener('touchmove', this.boundOnDrag, { passive: false });
        document.addEventListener('touchend', this.boundStopDrag);

        event.preventDefault();
    }

    /**
     * ドラッグ中の処理（内部用）
     * @private
     */
    _onDrag(event) {
        if (!this.isDragging || !this.targetElement) return;

        const clientX = event.touches ? event.touches[0].clientX : event.clientX;
        const clientY = event.touches ? event.touches[0].clientY : event.clientY;

        const playerWidth = this.targetElement.offsetWidth;
        const playerHeight = this.targetElement.offsetHeight;

        let newX = clientX - this.dragOffset.x;
        let newY = clientY - this.dragOffset.y;

        // 境界制約
        newX = Math.max(0, Math.min(newX, window.innerWidth - playerWidth));
        newY = Math.max(0, Math.min(newY, window.innerHeight - playerHeight));

        this.position = { x: newX, y: newY };

        // コールバックで位置変更を通知
        if (this.onPositionChange) {
            this.onPositionChange(this.position);
        }

        event.preventDefault();
    }

    /**
     * ドラッグ終了（内部用）
     * @private
     */
    _stopDrag() {
        this.isDragging = false;
        this.targetElement = null;

        if (this.boundOnDrag) {
            document.removeEventListener('mousemove', this.boundOnDrag);
            document.removeEventListener('touchmove', this.boundOnDrag);
        }
        if (this.boundStopDrag) {
            document.removeEventListener('mouseup', this.boundStopDrag);
            document.removeEventListener('touchend', this.boundStopDrag);
        }

        this.boundOnDrag = null;
        this.boundStopDrag = null;
    }

    /**
     * 位置を取得
     * @returns {Object} { x: number|null, y: number|null }
     */
    getPosition() {
        return this.position;
    }

    /**
     * 位置をリセット
     */
    resetPosition() {
        this.position = { x: null, y: null };
        if (this.onPositionChange) {
            this.onPositionChange(this.position);
        }
    }

    /**
     * スタイルオブジェクトを取得
     * @returns {Object} CSSスタイルオブジェクト
     */
    getStyle() {
        if (this.position.x !== null && this.position.y !== null) {
            return {
                left: `${this.position.x}px`,
                top: `${this.position.y}px`,
                right: 'auto',
                bottom: 'auto'
            };
        }
        return {};
    }

    /**
     * ドラッグ中かどうか
     * @returns {boolean}
     */
    getIsDragging() {
        return this.isDragging;
    }

    /**
     * クリーンアップ
     */
    cleanup() {
        this._stopDrag();
        this.position = { x: null, y: null };
    }
}

// シングルトンインスタンスをエクスポート
export const dragManager = new DragManager();
