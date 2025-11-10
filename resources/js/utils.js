export function escapeHTML(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

export function toggleButtonDisabled(target, flg) {
    target.disabled = flg;
    target.classList.toggle('opacity-50', flg);
    target.classList.toggle('cursor-not-allowed', flg);
}

/**
 * 日付を正規化してフォーマットする
 * @param {string|Date} dateStr - 日付文字列またはDateオブジェクト
 * @returns {string} - YYYY年MM月DD日 形式の文字列
 */
export function formatDate(dateStr) {
    if (!dateStr) return '';

    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '';

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}年${month}月${day}日`;
}
