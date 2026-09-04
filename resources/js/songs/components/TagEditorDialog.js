import { songApiService } from '../services/SongApiService';
import toast from '../../utils/toast.js';

export class TagEditorDialog {
    /**
     * @param {Object} song - 楽曲オブジェクト
     * @returns {Promise<{tags: Array}>} 更新後のタグ一覧
     */
    static show(song) {
        return new Promise((resolve) => {
            let tags = [...(song.tags || [])];

            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';

            const dialog = document.createElement('div');
            dialog.className = 'bg-white dark:bg-gray-800 rounded-lg p-6 max-w-lg w-full mx-4';

            const title = document.createElement('h3');
            title.className = 'text-lg font-bold mb-2 text-gray-900 dark:text-white';
            title.textContent = 'タグ編集';

            const songLabel = document.createElement('p');
            songLabel.className = 'text-sm text-gray-500 dark:text-gray-400 mb-4 truncate';
            songLabel.textContent = `${song.title} / ${song.artist}`;

            const tagListContainer = document.createElement('div');
            tagListContainer.className = 'flex flex-wrap gap-2 mb-4 min-h-[2rem]';

            const inputRow = document.createElement('div');
            inputRow.className = 'flex gap-2 mb-4';

            const input = document.createElement('input');
            input.type = 'text';
            input.placeholder = 'タグを入力（Enter/カンマで追加）';
            input.className = 'flex-1 px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500';

            const addBtn = document.createElement('button');
            addBtn.className = 'px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 flex-shrink-0';
            addBtn.textContent = '追加';

            inputRow.appendChild(input);
            inputRow.appendChild(addBtn);

            const footer = document.createElement('div');
            footer.className = 'flex justify-end';

            const closeBtn = document.createElement('button');
            closeBtn.className = 'px-4 py-2 text-sm bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-400 dark:hover:bg-gray-500';
            closeBtn.textContent = '閉じる';

            footer.appendChild(closeBtn);

            dialog.appendChild(title);
            dialog.appendChild(songLabel);
            dialog.appendChild(tagListContainer);
            dialog.appendChild(inputRow);
            dialog.appendChild(footer);
            overlay.appendChild(dialog);

            const renderTags = () => {
                tagListContainer.innerHTML = '';
                if (tags.length === 0) {
                    const empty = document.createElement('span');
                    empty.className = 'text-sm text-gray-400 dark:text-gray-500';
                    empty.textContent = 'タグなし';
                    tagListContainer.appendChild(empty);
                    return;
                }
                tags.forEach(tag => {
                    const badge = document.createElement('span');
                    badge.className = 'inline-flex items-center gap-1 px-2 py-1 text-xs rounded bg-blue-600 text-white';

                    const label = document.createElement('span');
                    label.textContent = tag.value;

                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'hover:text-red-200 font-bold';
                    removeBtn.textContent = '×';
                    removeBtn.addEventListener('click', async () => {
                        try {
                            await songApiService.deleteTag(song.id, tag.id);
                            tags = tags.filter(t => t.id !== tag.id);
                            renderTags();
                        } catch (e) {
                            toast.error('タグの削除に失敗しました');
                        }
                    });

                    badge.appendChild(label);
                    badge.appendChild(removeBtn);
                    tagListContainer.appendChild(badge);
                });
            };

            const addTag = async (value) => {
                const trimmed = value.trim();
                if (!trimmed) return;
                if (tags.some(t => t.value === trimmed)) {
                    input.value = '';
                    return;
                }
                try {
                    const result = await songApiService.addTag(song.id, trimmed);
                    tags.push(result.tag);
                    renderTags();
                    input.value = '';
                } catch (e) {
                    toast.error('タグの追加に失敗しました');
                }
            };

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    addTag(input.value);
                }
            });

            addBtn.addEventListener('click', () => addTag(input.value));

            const close = () => {
                overlay.remove();
                resolve({ tags });
            };

            closeBtn.addEventListener('click', close);

            overlay.addEventListener('mousedown', (e) => {
                if (e.target === overlay) close();
            });

            renderTags();
            document.body.appendChild(overlay);
            input.focus();
        });
    }
}
