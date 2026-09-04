import { songApiService } from '../services/SongApiService';
import toast from '../../utils/toast.js';

const STORAGE_KEY = 'songOperationDialog_lastTab';

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

function createMessageArea() {
    const el = document.createElement('div');
    el.className = 'mb-3 hidden';
    el.show = (text, type) => {
        el.className = `mb-3 p-2 rounded text-sm ${
            type === 'success'
                ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
        }`;
        el.textContent = text;
    };
    el.clear = () => {
        el.className = 'mb-3 hidden';
        el.textContent = '';
    };
    return el;
}

export class SongOperationDialog {
    static show(song) {
        return new Promise((resolve) => {
            const lastTab = localStorage.getItem(STORAGE_KEY) || 'tags';
            let tags = [...(song.tags || [])];
            let actionPerformed = null;

            const overlay = document.createElement('div');
            overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';

            const dialog = document.createElement('div');
            dialog.className = 'bg-white dark:bg-gray-800 rounded-lg max-w-2xl w-full mx-4 max-h-[80vh] flex flex-col';
            dialog.addEventListener('mousedown', (e) => e.stopPropagation());

            const header = document.createElement('div');
            header.className = 'px-6 pt-6 pb-0';

            const titleRow = document.createElement('div');
            titleRow.className = 'flex items-center justify-between mb-1';

            const titleEl = document.createElement('h3');
            titleEl.className = 'text-lg font-bold text-gray-900 dark:text-white';
            titleEl.textContent = '楽曲操作';

            const closeX = document.createElement('button');
            closeX.className = 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200';
            closeX.setAttribute('aria-label', '閉じる');
            closeX.innerHTML = '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>';

            titleRow.appendChild(titleEl);
            titleRow.appendChild(closeX);

            const songLabel = document.createElement('p');
            songLabel.className = 'text-sm text-gray-500 dark:text-gray-400 truncate mb-4';
            songLabel.textContent = `${song.title} / ${song.artist}`;

            header.appendChild(titleRow);
            header.appendChild(songLabel);

            const tabBar = document.createElement('div');
            tabBar.className = 'flex border-b border-gray-200 dark:border-gray-700 px-6';

            const tabDefs = [
                { key: 'tags', label: 'タグ' },
                { key: 'merge', label: 'マージ' },
                { key: 'artist', label: 'アーティスト変更' },
            ];

            const tabBtns = {};
            tabDefs.forEach(t => {
                const btn = document.createElement('button');
                btn.textContent = t.label;
                tabBtns[t.key] = btn;
                btn.addEventListener('click', () => switchTab(t.key));
                tabBar.appendChild(btn);
            });

            const content = document.createElement('div');
            content.className = 'flex-1 overflow-y-auto px-6 py-4';

            const { panel: tagPanel, focusInput: focusTagInput } = buildTagPanel();
            const { panel: mergePanel, doSearch: mergeDoSearch } = buildMergePanel();
            const { panel: artistPanel, loadArtists } = buildArtistPanel();

            content.appendChild(tagPanel);
            content.appendChild(mergePanel);
            content.appendChild(artistPanel);

            dialog.appendChild(header);
            dialog.appendChild(tabBar);
            dialog.appendChild(content);
            overlay.appendChild(dialog);

            let artistsLoaded = false;

            function switchTab(key) {
                localStorage.setItem(STORAGE_KEY, key);
                Object.entries(tabBtns).forEach(([k, btn]) => {
                    btn.className = k === key
                        ? 'px-4 py-2 text-sm font-medium border-b-2 -mb-px border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'px-4 py-2 text-sm font-medium border-b-2 -mb-px border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200';
                });
                tagPanel.style.display = key === 'tags' ? '' : 'none';
                mergePanel.style.display = key === 'merge' ? '' : 'none';
                artistPanel.style.display = key === 'artist' ? '' : 'none';

                if (key === 'tags') focusTagInput();
                if (key === 'artist' && !artistsLoaded) {
                    artistsLoaded = true;
                    loadArtists();
                }
            }

            function close() {
                overlay.remove();
                if (actionPerformed) {
                    resolve({ action: actionPerformed });
                } else {
                    resolve({ action: 'close', tags });
                }
            }

            closeX.addEventListener('click', close);
            overlay.addEventListener('mousedown', (e) => {
                if (e.target === overlay) close();
            });

            // ========================================
            // Tag panel
            // ========================================
            function buildTagPanel() {
                const panel = document.createElement('div');

                const tagListContainer = document.createElement('div');
                tagListContainer.className = 'flex flex-wrap gap-2 mb-4 min-h-[2rem]';

                const inputRow = document.createElement('div');
                inputRow.className = 'flex gap-2';

                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'タグを入力（Enter/カンマで追加）';
                input.className = 'flex-1 px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500';

                const addBtn = document.createElement('button');
                addBtn.className = 'px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 flex-shrink-0';
                addBtn.textContent = '追加';

                inputRow.appendChild(input);
                inputRow.appendChild(addBtn);
                panel.appendChild(tagListContainer);
                panel.appendChild(inputRow);

                function renderTags() {
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
                }

                async function addTag(value) {
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
                }

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        addTag(input.value);
                    }
                });

                addBtn.addEventListener('click', () => addTag(input.value));
                renderTags();

                return { panel, focusInput: () => input.focus() };
            }

            // ========================================
            // Merge panel
            // ========================================
            function buildMergePanel() {
                const panel = document.createElement('div');

                const msgArea = createMessageArea();
                panel.appendChild(msgArea);

                const desc = document.createElement('p');
                desc.className = 'text-xs text-gray-500 dark:text-gray-400 mb-3';
                desc.textContent = '楽曲を検索し、2件以上を選択して統合先を指定してください。統合先以外の楽曲は削除され、紐付けが統合先に移行します。';
                panel.appendChild(desc);

                const searchRow = document.createElement('div');
                searchRow.className = 'flex gap-2 mb-3';

                const searchInput = document.createElement('input');
                searchInput.type = 'text';
                searchInput.value = song.title;
                searchInput.placeholder = '楽曲名で検索';
                searchInput.className = 'flex-1 px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500';

                const searchBtn = document.createElement('button');
                searchBtn.className = 'px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 flex-shrink-0';
                searchBtn.textContent = '検索';

                searchRow.appendChild(searchInput);
                searchRow.appendChild(searchBtn);
                panel.appendChild(searchRow);

                const resultsList = document.createElement('div');
                resultsList.className = 'space-y-1 max-h-[40vh] overflow-y-auto mb-3';
                panel.appendChild(resultsList);

                const mergeBtn = document.createElement('button');
                mergeBtn.className = 'px-4 py-2 text-sm bg-orange-600 text-white rounded hover:bg-orange-700 disabled:opacity-50';
                mergeBtn.textContent = 'マージ実行';
                mergeBtn.disabled = true;
                panel.appendChild(mergeBtn);

                let searchResults = [];
                let selectedIds = [];
                let targetId = null;
                let merging = false;

                function updateMergeBtn() {
                    mergeBtn.disabled = !(targetId && selectedIds.length >= 2 && selectedIds.includes(targetId) && !merging);
                }

                function renderResults() {
                    resultsList.innerHTML = '';
                    if (searchResults.length === 0) return;
                    searchResults.forEach(s => {
                        const row = document.createElement('div');
                        row.className = 'flex items-center gap-2 p-2 border rounded border-gray-200 dark:border-gray-700 text-sm';

                        const cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.checked = selectedIds.includes(s.id);
                        cb.className = 'flex-shrink-0';
                        cb.addEventListener('change', () => {
                            if (cb.checked) {
                                selectedIds.push(s.id);
                            } else {
                                selectedIds = selectedIds.filter(id => id !== s.id);
                                if (targetId === s.id) targetId = null;
                            }
                            renderResults();
                            updateMergeBtn();
                        });

                        const info = document.createElement('div');
                        info.className = 'flex-1 min-w-0 truncate';
                        info.innerHTML = `<span class="font-medium">${escapeHtml(s.title)}</span> <span class="text-gray-500 dark:text-gray-400">/ ${escapeHtml(s.artist)}</span>`;
                        if (s.mappings_count !== undefined) {
                            info.innerHTML += ` <span class="text-xs text-gray-400">(MP:${escapeHtml(String(s.mappings_count))} TS:${escapeHtml(String(s.ts_items_count))})</span>`;
                        }

                        const isTarget = targetId === s.id;
                        const isSelected = selectedIds.includes(s.id);
                        const targetBtn = document.createElement('button');
                        targetBtn.className = `px-2 py-1 text-xs rounded flex-shrink-0 ${
                            isTarget
                                ? 'bg-green-600 text-white'
                                : isSelected
                                    ? 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300 hover:bg-green-100 dark:hover:bg-green-900'
                                    : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 opacity-50 cursor-not-allowed'
                        }`;
                        targetBtn.textContent = isTarget ? '統合先 ✓' : '統合先';
                        if (isSelected && !isTarget) {
                            targetBtn.addEventListener('click', () => {
                                targetId = s.id;
                                renderResults();
                                updateMergeBtn();
                            });
                        }

                        row.appendChild(cb);
                        row.appendChild(info);
                        row.appendChild(targetBtn);
                        resultsList.appendChild(row);
                    });
                }

                async function doSearch() {
                    const query = searchInput.value.trim();
                    if (!query) return;
                    searchBtn.disabled = true;
                    searchBtn.textContent = '検索中...';
                    msgArea.clear();
                    try {
                        const params = new URLSearchParams({ search: query });
                        const res = await fetch(`/api/songs/search-for-merge?${params}`);
                        if (!res.ok) throw new Error('検索に失敗しました');
                        searchResults = await res.json();
                        selectedIds = [];
                        targetId = null;
                        renderResults();
                        updateMergeBtn();
                    } catch (e) {
                        msgArea.show(e.message, 'error');
                    } finally {
                        searchBtn.disabled = false;
                        searchBtn.textContent = '検索';
                    }
                }

                searchBtn.addEventListener('click', doSearch);
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') doSearch();
                });

                mergeBtn.addEventListener('click', async () => {
                    const target = searchResults.find(s => s.id === targetId);
                    const sources = selectedIds.filter(id => id !== targetId);
                    if (!target || sources.length === 0) return;

                    if (!confirm(`「${target.title}」に統合します。${sources.length}件の楽曲が削除されます。よろしいですか？`)) return;

                    merging = true;
                    mergeBtn.disabled = true;
                    mergeBtn.textContent = 'マージ中...';
                    msgArea.clear();

                    let mergedCount = 0;
                    try {
                        for (const sourceId of sources) {
                            const res = await fetch('/api/songs/merge', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': getCsrfToken(),
                                },
                                body: JSON.stringify({
                                    source_song_id: sourceId,
                                    target_song_id: targetId,
                                }),
                            });
                            if (!res.ok) {
                                const data = await res.json();
                                throw new Error(data.message || 'マージに失敗しました');
                            }
                            mergedCount++;
                        }
                        actionPerformed = 'merged';
                        toast.success(`${sources.length}件の楽曲を統合しました`);
                        close();
                    } catch (e) {
                        if (mergedCount > 0) actionPerformed = 'merged';
                        msgArea.show(e.message, 'error');
                    } finally {
                        merging = false;
                        mergeBtn.textContent = 'マージ実行';
                        updateMergeBtn();
                    }
                });

                return { panel, doSearch };
            }

            // ========================================
            // Artist rename panel
            // ========================================
            function buildArtistPanel() {
                const panel = document.createElement('div');

                const msgArea = createMessageArea();
                panel.appendChild(msgArea);

                const desc = document.createElement('p');
                desc.className = 'text-xs text-gray-500 dark:text-gray-400 mb-3';
                desc.textContent = '変換前のアーティスト名が付いた楽曲マスタを、まとめて変換後の名前に統一します。同じ曲名のマスタが既に存在する場合は統合されます。';
                panel.appendChild(desc);

                const inputRow = document.createElement('div');
                inputRow.className = 'flex flex-wrap gap-2 mb-3 items-center';

                const fromWrapper = document.createElement('div');
                fromWrapper.className = 'relative flex-1 min-w-[180px]';

                const fromInput = document.createElement('input');
                fromInput.type = 'text';
                fromInput.value = song.artist;
                fromInput.placeholder = '変換前のアーティスト名（完全一致）';
                fromInput.autocomplete = 'off';
                fromInput.className = 'w-full px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500';

                const fromDropdown = document.createElement('div');
                fromDropdown.className = 'absolute z-50 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto hidden';

                fromWrapper.appendChild(fromInput);
                fromWrapper.appendChild(fromDropdown);

                const arrow = document.createElement('span');
                arrow.className = 'text-gray-400 flex-shrink-0';
                arrow.textContent = '→';

                const toWrapper = document.createElement('div');
                toWrapper.className = 'relative flex-1 min-w-[180px]';

                const toInput = document.createElement('input');
                toInput.type = 'text';
                toInput.placeholder = '変換後のアーティスト名';
                toInput.autocomplete = 'off';
                toInput.className = 'w-full px-3 py-2 text-sm border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500';

                const toDropdown = document.createElement('div');
                toDropdown.className = 'absolute z-50 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto hidden';

                toWrapper.appendChild(toInput);
                toWrapper.appendChild(toDropdown);

                const previewBtn = document.createElement('button');
                previewBtn.className = 'px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 flex-shrink-0';
                previewBtn.textContent = 'プレビュー';

                inputRow.appendChild(fromWrapper);
                inputRow.appendChild(arrow);
                inputRow.appendChild(toWrapper);
                inputRow.appendChild(previewBtn);
                panel.appendChild(inputRow);

                const planArea = document.createElement('div');
                planArea.className = 'hidden';
                panel.appendChild(planArea);

                let allArtists = [];
                let fromHighlight = -1;
                let toHighlight = -1;
                let fromSuggestions = [];
                let toSuggestions = [];

                function filterSuggestions(query) {
                    if (!query.trim()) return [];
                    const q = query.toLowerCase();
                    return allArtists.filter(a => a.toLowerCase().includes(q)).slice(0, 20);
                }

                function renderDropdown(dropdown, suggestions, highlightIdx, onSelect) {
                    dropdown.innerHTML = '';
                    if (suggestions.length === 0) {
                        dropdown.classList.add('hidden');
                        return;
                    }
                    dropdown.classList.remove('hidden');
                    suggestions.forEach((artist, i) => {
                        const item = document.createElement('div');
                        item.className = `px-3 py-2 cursor-pointer text-sm text-gray-900 dark:text-gray-100 ${
                            i === highlightIdx ? 'bg-blue-100 dark:bg-blue-900' : 'hover:bg-gray-100 dark:hover:bg-gray-600'
                        }`;
                        item.textContent = artist;
                        item.addEventListener('click', () => onSelect(artist));
                        dropdown.appendChild(item);
                    });
                }

                function setupAutocomplete(input, dropdown, getSuggestions, setSuggestions, getHighlight, setHighlight) {
                    const updateDropdown = () => {
                        const suggs = filterSuggestions(input.value);
                        setSuggestions(suggs);
                        setHighlight(-1);
                        renderDropdown(dropdown, suggs, -1, (a) => {
                            input.value = a;
                            dropdown.classList.add('hidden');
                        });
                    };

                    input.addEventListener('input', updateDropdown);
                    input.addEventListener('focus', updateDropdown);

                    input.addEventListener('keydown', (e) => {
                        const suggs = getSuggestions();
                        const isVisible = !dropdown.classList.contains('hidden');

                        if (e.key === 'ArrowDown' && isVisible) {
                            e.preventDefault();
                            const idx = Math.min(getHighlight() + 1, suggs.length - 1);
                            setHighlight(idx);
                            renderDropdown(dropdown, suggs, idx, (a) => {
                                input.value = a;
                                dropdown.classList.add('hidden');
                            });
                        } else if (e.key === 'ArrowUp' && isVisible) {
                            e.preventDefault();
                            const idx = Math.max(getHighlight() - 1, -1);
                            setHighlight(idx);
                            renderDropdown(dropdown, suggs, idx, (a) => {
                                input.value = a;
                                dropdown.classList.add('hidden');
                            });
                        } else if (e.key === 'Enter') {
                            if (isVisible && getHighlight() >= 0) {
                                e.preventDefault();
                                input.value = suggs[getHighlight()];
                                dropdown.classList.add('hidden');
                            } else if (input === toInput) {
                                doPreview();
                            }
                        }
                    });
                }

                setupAutocomplete(
                    fromInput, fromDropdown,
                    () => fromSuggestions, (v) => { fromSuggestions = v; },
                    () => fromHighlight, (v) => { fromHighlight = v; }
                );
                setupAutocomplete(
                    toInput, toDropdown,
                    () => toSuggestions, (v) => { toSuggestions = v; },
                    () => toHighlight, (v) => { toHighlight = v; }
                );

                overlay.addEventListener('click', (e) => {
                    if (!fromWrapper.contains(e.target)) fromDropdown.classList.add('hidden');
                    if (!toWrapper.contains(e.target)) toDropdown.classList.add('hidden');
                });

                async function doPreview() {
                    const from = fromInput.value.trim();
                    const to = toInput.value.trim();
                    if (!from || !to) return;
                    previewBtn.disabled = true;
                    previewBtn.textContent = '確認中...';
                    planArea.classList.add('hidden');
                    msgArea.clear();
                    try {
                        const params = new URLSearchParams({ from, to });
                        const res = await fetch(`/api/songs/cleansing/artist-rename-preview?${params}`);
                        if (!res.ok) {
                            const data = await res.json();
                            throw new Error(data.message || 'プレビューの取得に失敗しました');
                        }
                        const plan = await res.json();
                        if (plan.plan.length === 0) {
                            msgArea.show(`「${from}」に一致する楽曲マスタがありません`, 'error');
                        } else {
                            renderPlan(plan);
                        }
                    } catch (e) {
                        msgArea.show(e.message, 'error');
                    } finally {
                        previewBtn.disabled = false;
                        previewBtn.textContent = 'プレビュー';
                    }
                }

                previewBtn.addEventListener('click', doPreview);

                function renderPlan(plan) {
                    planArea.classList.remove('hidden');
                    planArea.innerHTML = '';

                    const summary = document.createElement('div');
                    summary.className = 'mb-2 text-sm text-gray-600 dark:text-gray-400';
                    summary.textContent = `リネームのみ: ${plan.rename_count}件 / 統合が必要: ${plan.merge_count}件`;
                    planArea.appendChild(summary);

                    const list = document.createElement('div');
                    list.className = 'space-y-1 max-h-[30vh] overflow-y-auto mb-3';
                    plan.plan.forEach(item => {
                        const row = document.createElement('div');
                        row.className = 'flex items-center justify-between p-2 border border-gray-200 dark:border-gray-700 rounded-md text-sm';

                        const titleSpan = document.createElement('span');
                        titleSpan.textContent = item.title;

                        const badge = document.createElement('span');
                        badge.className = `text-xs px-2 py-0.5 rounded ${
                            item.action === 'merge'
                                ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
                                : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                        }`;
                        badge.textContent = item.action === 'merge' ? '統合' : 'リネーム';

                        row.appendChild(titleSpan);
                        row.appendChild(badge);
                        list.appendChild(row);
                    });
                    planArea.appendChild(list);

                    const execBtn = document.createElement('button');
                    execBtn.className = 'px-4 py-2 text-sm bg-orange-600 text-white rounded hover:bg-orange-700 disabled:opacity-50';
                    execBtn.textContent = '実行する';
                    execBtn.addEventListener('click', async () => {
                        const { rename_count, merge_count } = plan;
                        const confirmMessage = merge_count > 0
                            ? `${rename_count}件をリネーム、${merge_count}件を統合します。統合される側の元マスタは削除されます。よろしいですか？`
                            : `${rename_count}件をリネームします。よろしいですか？`;
                        if (!confirm(confirmMessage)) return;

                        execBtn.disabled = true;
                        execBtn.textContent = '実行中...';
                        try {
                            const res = await fetch('/api/songs/cleansing/artist-rename', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': getCsrfToken(),
                                },
                                body: JSON.stringify({ from: fromInput.value.trim(), to: toInput.value.trim() }),
                            });
                            if (!res.ok) {
                                const data = await res.json();
                                throw new Error(data.message || '変換に失敗しました');
                            }
                            const data = await res.json();
                            actionPerformed = 'artist_renamed';
                            toast.success(data.message || 'アーティスト名を変更しました');
                            close();
                        } catch (e) {
                            msgArea.show(e.message, 'error');
                            execBtn.disabled = false;
                            execBtn.textContent = '実行する';
                        }
                    });
                    planArea.appendChild(execBtn);
                }

                async function doLoadArtists() {
                    try {
                        const res = await fetch('/api/songs/artists');
                        if (res.ok) allArtists = await res.json();
                    } catch (_) {}
                }

                return { panel, loadArtists: doLoadArtists };
            }

            switchTab(lastTab);
            document.body.appendChild(overlay);

            if (lastTab === 'tags') focusTagInput();
            if (lastTab === 'merge') mergeDoSearch();
            if (lastTab === 'artist') {
                artistsLoaded = true;
                loadArtists();
            }
        });
    }
}
