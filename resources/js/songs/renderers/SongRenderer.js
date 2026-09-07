import toast from '../../utils/toast.js';
import { SongOperationDialog } from '../components/SongOperationDialog.js';

export class SongRenderer {
    constructor({
        getSelectedSong,
        setSelectedSong,
        updateSelectionDisplay,
        displaySongs,
        loadSongs,
        loadTimestamps,
        openEditModal,
        deleteSong,
        setSongFilter,
    }) {
        this._getSelectedSong = getSelectedSong;
        this._setSelectedSong = setSelectedSong;
        this._updateSelectionDisplay = updateSelectionDisplay;
        this._displaySongs = displaySongs;
        this._loadSongs = loadSongs;
        this._loadTimestamps = loadTimestamps;
        this._openEditModal = openEditModal;
        this._deleteSong = deleteSong;
        this._setSongFilter = setSongFilter;
    }

    createElement(song, songs, total, onSelectionChange = null, { showActions = true } = {}) {
        const div = document.createElement('div');
        div.dataset.songId = song.id;
        const selectedSong = this._getSelectedSong();
        const isSelected = selectedSong?.id === song.id;
        div.className = `p-2 border rounded cursor-pointer flex items-start justify-between ${
            isSelected
                ? 'bg-blue-100 dark:bg-blue-900 border-blue-500'
                : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
        }`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'flex-1 min-w-0';

        const songInfo = document.createElement('div');
        songInfo.className = 'text-sm flex items-center min-w-0';

        const textWrapper = document.createElement('span');
        textWrapper.className = 'truncate min-w-0';

        const titleSpan = document.createElement('span');
        titleSpan.className = 'font-medium';
        titleSpan.textContent = song.title;

        const separatorSpan = document.createElement('span');
        separatorSpan.className = 'text-gray-500 dark:text-gray-400';
        separatorSpan.textContent = ' / ' + song.artist;

        textWrapper.appendChild(titleSpan);
        textWrapper.appendChild(separatorSpan);
        songInfo.appendChild(textWrapper);
        songInfo.title = `${song.title} / ${song.artist}`;

        contentDiv.appendChild(songInfo);

        if (song.tags && song.tags.length > 0) {
            const tagContainer = document.createElement('div');
            tagContainer.className = 'flex gap-1 mt-0.5 overflow-hidden';
            song.tags.forEach(tag => {
                const badge = document.createElement('span');
                badge.className = 'inline-block px-1.5 py-0.5 text-[10px] rounded bg-blue-600 text-white whitespace-nowrap';
                badge.textContent = tag.value;
                tagContainer.appendChild(badge);
            });
            contentDiv.appendChild(tagContainer);
        }

        if (song.duration_ms) {
            const durationSpan = document.createElement('span');
            durationSpan.className = 'text-xs text-gray-400 dark:text-gray-500 ml-2';
            durationSpan.textContent = this.formatDuration(song.duration_ms);
            songInfo.appendChild(durationSpan);
        }

        const buttonContainer = document.createElement('div');
        buttonContainer.className = 'flex items-center gap-1 flex-shrink-0 ml-2';

        const copyBtn = this.createCopyButton(song);
        buttonContainer.appendChild(copyBtn);

        if (showActions) {
            const filterBtn = this.createFilterButton(song);
            buttonContainer.appendChild(filterBtn);

            const opBtn = document.createElement('button');
            opBtn.className = 'px-2 py-1 text-xs bg-teal-600 text-white rounded hover:bg-teal-700';
            opBtn.textContent = '操作';
            opBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const result = await SongOperationDialog.show(song);
                if (result.action === 'merged' || result.action === 'artist_renamed') {
                    this._setSelectedSong(null);
                    this._loadSongs(document.getElementById('songsSearch')?.value ?? '');
                    this._loadTimestamps();
                }
            });
            buttonContainer.appendChild(opBtn);

            const editBtn = document.createElement('button');
            editBtn.className = 'px-2 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700';
            editBtn.textContent = '編集';
            editBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this._openEditModal(song);
            });

            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'px-2 py-1 text-xs bg-red-600 text-white rounded hover:bg-red-700';
            deleteBtn.textContent = '削除';
            deleteBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                if (confirm(`楽曲マスタを削除しますか?\n${song.title} / ${song.artist}`)) {
                    await this._deleteSong(song.id);
                }
            });

            buttonContainer.appendChild(editBtn);
            buttonContainer.appendChild(deleteBtn);
        }

        div.appendChild(contentDiv);
        div.appendChild(buttonContainer);

        div.addEventListener('click', () => {
            const current = this._getSelectedSong();
            this._setSelectedSong(current?.id === song.id ? null : song);
            if (onSelectionChange) {
                onSelectionChange();
            } else {
                this._displaySongs(songs, total);
            }
            this._updateSelectionDisplay();
        });

        return div;
    }

    createCopyButton(song) {
        const copyBtn = document.createElement('button');
        copyBtn.className = 'p-1.5 text-gray-600 dark:text-gray-400 bg-gray-200 dark:bg-gray-700 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors';
        copyBtn.title = '楽曲名 / アーティスト名をコピー';
        copyBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        `;

        const originalIcon = copyBtn.innerHTML;
        const checkIcon = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        `;

        copyBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const textToCopy = `${song.title} / ${song.artist}`;
            navigator.clipboard.writeText(textToCopy);
            copyBtn.innerHTML = checkIcon;
            copyBtn.title = 'コピー済';
            toast.success('コピーしました');
            setTimeout(() => {
                copyBtn.innerHTML = originalIcon;
                copyBtn.title = '楽曲名 / アーティスト名をコピー';
            }, 1000);
        });

        return copyBtn;
    }

    createFilterButton(song) {
        const filterBtn = document.createElement('button');
        filterBtn.className = 'p-1.5 text-gray-600 dark:text-gray-400 bg-gray-200 dark:bg-gray-700 rounded hover:bg-purple-300 dark:hover:bg-purple-600 transition-colors';
        filterBtn.title = '紐づくTSを表示';
        filterBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
            </svg>
        `;

        filterBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this._setSongFilter(song);
        });

        return filterBtn;
    }

    formatDuration(durationMs) {
        const ms = parseInt(durationMs, 10);
        if (isNaN(ms) || ms <= 0) return '';

        const totalSeconds = Math.floor(ms / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        if (hours > 0) {
            return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
}
