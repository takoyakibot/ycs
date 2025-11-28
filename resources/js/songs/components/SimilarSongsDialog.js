/**
 * 類似曲確認ダイアログコンポーネント
 */
export class SimilarSongsDialog {
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
     * @param {Array} similarSongs - 類似曲の配列
     * @param {Object} inputData - 入力された楽曲データ
     * @returns {Promise<{action: string, songId?: string}>} ユーザーの選択結果
     */
    static show(similarSongs, inputData) {
        return new Promise((resolve) => {
            const escapeHtml = this.escapeHtml;

            // ダイアログのHTMLを動的に作成
            const dialogHtml = `
                <div id="similarSongsDialog" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-2xl w-full mx-4 max-h-[80vh] overflow-y-auto">
                        <h3 class="text-lg font-bold mb-4 text-gray-900 dark:text-white">類似する楽曲マスタが見つかりました</h3>

                        <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900 rounded">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                登録しようとしている楽曲: <strong>${escapeHtml(inputData.title)} / ${escapeHtml(inputData.artist)}</strong>
                            </p>
                        </div>

                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            類似度の高い楽曲が ${escapeHtml(String(similarSongs.length))} 件見つかりました。既存のマスタを使用するか、新規登録するか選択してください。
                        </p>

                        <div id="similarSongsList" class="space-y-2 mb-6 max-h-60 overflow-y-auto">
                            ${similarSongs.map((item, index) => `
                                <div class="similar-song-item p-3 border rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:border-gray-600" data-song-id="${escapeHtml(item.song.id)}" data-index="${escapeHtml(String(index))}">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <div class="font-medium text-sm text-gray-900 dark:text-white">${escapeHtml(item.song.title)}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(item.song.artist)}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-xs font-medium text-green-600 dark:text-green-400">類似度: ${escapeHtml(String(item.similarity))}%</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                曲名: ${escapeHtml(String(item.title_similarity))}% / アーティスト: ${escapeHtml(String(item.artist_similarity))}%
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>

                        <div class="flex gap-2 justify-end">
                            <button id="useExistingSongBtn" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50" disabled>
                                選択した楽曲を使用
                            </button>
                            <button id="forceCreateNewBtn" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                                新規登録
                            </button>
                            <button id="cancelDialogBtn" class="px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-400 dark:hover:bg-gray-500">
                                キャンセル
                            </button>
                        </div>
                    </div>
                </div>
            `;

            // ダイアログを追加
            document.body.insertAdjacentHTML('beforeend', dialogHtml);

            const dialog = document.getElementById('similarSongsDialog');
            const useExistingBtn = document.getElementById('useExistingSongBtn');
            const forceCreateBtn = document.getElementById('forceCreateNewBtn');
            const cancelBtn = document.getElementById('cancelDialogBtn');

            let selectedSongId = null;

            // 楽曲選択
            dialog.querySelectorAll('.similar-song-item').forEach(item => {
                item.addEventListener('click', () => {
                    // 選択状態をリセット
                    dialog.querySelectorAll('.similar-song-item').forEach(i => {
                        i.classList.remove('bg-blue-100', 'dark:bg-blue-900', 'border-blue-500');
                    });

                    // 選択状態を適用
                    item.classList.add('bg-blue-100', 'dark:bg-blue-900', 'border-blue-500');
                    selectedSongId = item.dataset.songId;
                    useExistingBtn.disabled = false;
                });
            });

            // 既存楽曲を使用
            useExistingBtn.addEventListener('click', () => {
                if (!selectedSongId) return;
                dialog.remove();
                resolve({ action: 'use_existing', songId: selectedSongId });
            });

            // 新規登録
            forceCreateBtn.addEventListener('click', () => {
                dialog.remove();
                resolve({ action: 'force_create' });
            });

            // キャンセル
            cancelBtn.addEventListener('click', () => {
                dialog.remove();
                resolve({ action: 'cancel' });
            });
        });
    }
}
