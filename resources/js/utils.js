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
