/**
 * アーティスト名変更時のタグ同期確認ダイアログ
 */
export class ArtistTagSyncDialog {
    /**
     * HTMLエスケープ
     * @param {string} str - エスケープする文字列
     * @returns {string} エスケープされた文字列
     */
    static escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * ダイアログを表示
     * @param {string} oldArtist - 変更前のアーティスト名
     * @param {string} newArtist - 変更後のアーティスト名
     * @param {Array} matchingTags - 一致するタグの配列
     * @returns {Promise<{action: string}>} ユーザーの選択結果
     */
    static show(oldArtist, newArtist, matchingTags) {
        return new Promise((resolve) => {
            const escapeHtml = this.escapeHtml;

            const tagListHtml = matchingTags.map(tag => `
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">${escapeHtml(tag.value)}</span>
                    <span class="text-gray-400 dark:text-gray-500">&rarr;</span>
                    <span class="font-medium text-gray-900 dark:text-white">${escapeHtml(newArtist)}</span>
                </div>
            `).join('');

            const dialogHtml = `
                <div id="artistTagSyncDialog" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4">
                        <h3 class="text-lg font-bold mb-4 text-gray-900 dark:text-white">アーティスト名の変更に伴うタグの更新</h3>

                        <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900 rounded">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                アーティスト名: <strong>${escapeHtml(oldArtist)}</strong> &rarr; <strong>${escapeHtml(newArtist)}</strong>
                            </p>
                        </div>

                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                            以下のタグもアーティスト名に合わせて更新しますか？
                        </p>

                        <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded space-y-1">
                            ${tagListHtml}
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            ※ 一致しないタグはそのまま残ります
                        </p>

                        <div class="flex gap-2 justify-end">
                            <button id="syncTagsBtn" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                                タグも更新する
                            </button>
                            <button id="skipTagsBtn" class="px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-400 dark:hover:bg-gray-500">
                                タグはそのまま
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', dialogHtml);

            const dialog = document.getElementById('artistTagSyncDialog');
            const syncBtn = document.getElementById('syncTagsBtn');
            const skipBtn = document.getElementById('skipTagsBtn');

            syncBtn.addEventListener('click', () => {
                dialog.remove();
                resolve({ action: 'sync' });
            });

            skipBtn.addEventListener('click', () => {
                dialog.remove();
                resolve({ action: 'skip' });
            });
        });
    }
}
