import axios from 'axios';
import { escapeHTML, toggleButtonDisabled } from "../utils";

document.addEventListener('DOMContentLoaded', function () {
    const cryptHandle = document.getElementById('cryptHandle').value;
    const newExcludedWordInput = document.getElementById('newExcludedWord');
    const addExcludedWordBtn = document.getElementById('addExcludedWordBtn');
    const excludedWordError = document.getElementById('excludedWordError');
    const excludedWordsList = document.getElementById('excludedWordsList');
    const newStripPatternInput = document.getElementById('newStripPattern');
    const addStripPatternBtn = document.getElementById('addStripPatternBtn');
    const stripPatternError = document.getElementById('stripPatternError');
    const stripPatternsList = document.getElementById('stripPatternsList');
    const reapplyStripPatternsBtn = document.getElementById('reapplyStripPatternsBtn');
    const stripPatternMessage = document.getElementById('stripPatternMessage');
    const loadPreviewBtn = document.getElementById('loadPreviewBtn');
    const reprocessBtn = document.getElementById('reprocessBtn');
    const previewMessage = document.getElementById('previewMessage');
    const previewList = document.getElementById('previewList');
    const previewTableBody = document.getElementById('previewTableBody');
    const noPreviewData = document.getElementById('noPreviewData');

    let isProcessing = false;

    // 除外ワード一覧の取得
    function fetchExcludedWords() {
        axios.get(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/excluded-words`)
            .then(function (response) {
                const words = response.data;
                if (words.length === 0) {
                    excludedWordsList.innerHTML = '<div class="text-center text-gray-500 dark:text-gray-400 py-4">除外ワードが登録されていません</div>';
                    return;
                }

                let html = '<ul class="divide-y dark:divide-gray-700">';
                words.forEach(word => {
                    html += `
                        <li class="flex items-center justify-between px-4 py-3">
                            <span class="text-gray-800 dark:text-gray-200">${escapeHTML(word.word)}</span>
                            <button type="button" class="delete-word-btn text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 text-sm" data-id="${escapeHTML(word.id)}">
                                削除
                            </button>
                        </li>
                    `;
                });
                html += '</ul>';
                excludedWordsList.innerHTML = html;

                // 削除ボタンのイベント設定
                document.querySelectorAll('.delete-word-btn').forEach(btn => {
                    btn.addEventListener('click', function () {
                        deleteExcludedWord(this.dataset.id);
                    });
                });
            })
            .catch(function (error) {
                console.error("Error fetching excluded words:", error);
                excludedWordsList.innerHTML = '<div class="text-center text-red-500 py-4">除外ワードの取得に失敗しました</div>';
            });
    }

    // 除外ワードの追加
    function addExcludedWord() {
        const word = newExcludedWordInput.value.trim();
        if (!word) {
            showError('除外ワードを入力してください');
            return;
        }

        if (isProcessing) return;
        isProcessing = true;
        toggleButtonDisabled(addExcludedWordBtn, true);

        axios.post(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/excluded-words`, { word })
            .then(function (response) {
                newExcludedWordInput.value = '';
                hideError();
                fetchExcludedWords();
            })
            .catch(function (error) {
                if (error.response && error.response.data && error.response.data.message) {
                    showError(error.response.data.message);
                } else {
                    showError('エラーが発生しました');
                }
            })
            .finally(function () {
                isProcessing = false;
                toggleButtonDisabled(addExcludedWordBtn, false);
            });
    }

    // 除外ワードの削除
    function deleteExcludedWord(wordId) {
        if (isProcessing) return;
        if (!confirm('この除外ワードを削除しますか？')) return;

        isProcessing = true;

        axios.delete(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/excluded-words/${encodeURIComponent(wordId)}`)
            .then(function (response) {
                fetchExcludedWords();
            })
            .catch(function (error) {
                console.error("Error deleting excluded word:", error);
                alert('削除に失敗しました');
            })
            .finally(function () {
                isProcessing = false;
            });
    }

    // プレビュー読み込み
    function loadPreview() {
        if (isProcessing) return;
        isProcessing = true;
        toggleButtonDisabled(loadPreviewBtn, true);

        showPreviewMessage('読み込み中...', 'text-gray-600');

        axios.get(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/cover-songs/preview`)
            .then(function (response) {
                const previews = response.data;
                if (previews.length === 0) {
                    previewList.classList.add('hidden');
                    noPreviewData.classList.remove('hidden');
                    hidePreviewMessage();
                    return;
                }

                noPreviewData.classList.add('hidden');
                previewList.classList.remove('hidden');

                let html = '';
                previews.forEach(preview => {
                    const mappingClass = getMappingClass(preview.mapping.status);
                    html += `
                        <tr class="border-t dark:border-gray-700">
                            <td class="px-4 py-2 text-gray-800 dark:text-gray-200">${escapeHTML(preview.original_title)}</td>
                            <td class="px-4 py-2 text-blue-600 dark:text-blue-400 font-medium">${escapeHTML(preview.extracted_text)}</td>
                            <td class="px-4 py-2">
                                <span class="${mappingClass}">${escapeHTML(preview.mapping.label)}</span>
                                ${preview.mapping.song_info ? `<br><span class="text-xs text-gray-500">${escapeHTML(preview.mapping.song_info)}</span>` : ''}
                            </td>
                        </tr>
                    `;
                });
                previewTableBody.innerHTML = html;
                hidePreviewMessage();
            })
            .catch(function (error) {
                console.error("Error loading preview:", error);
                showPreviewMessage('プレビューの読み込みに失敗しました', 'text-red-500');
            })
            .finally(function () {
                isProcessing = false;
                toggleButtonDisabled(loadPreviewBtn, false);
            });
    }

    // 紐付け再実行
    function reprocessCoverSongs() {
        if (isProcessing) return;
        if (!confirm('カバー曲の紐付けを再処理しますか？\n自動紐付けがリセットされ、新しい設定で再処理されます。')) return;

        isProcessing = true;
        toggleButtonDisabled(reprocessBtn, true);

        showPreviewMessage('再処理中...', 'text-gray-600');

        axios.post(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/cover-songs/reprocess`)
            .then(function (response) {
                showPreviewMessage(response.data.message, 'text-green-600');
                // プレビューを再読み込み
                setTimeout(() => loadPreview(), 1000);
            })
            .catch(function (error) {
                console.error("Error reprocessing cover songs:", error);
                showPreviewMessage('再処理に失敗しました', 'text-red-500');
            })
            .finally(function () {
                isProcessing = false;
                toggleButtonDisabled(reprocessBtn, false);
            });
    }

    // 除去パターン一覧の取得
    function fetchStripPatterns() {
        axios.get(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/strip-patterns`)
            .then(function (response) {
                const patterns = response.data;
                if (patterns.length === 0) {
                    stripPatternsList.innerHTML = '<div class="text-center text-gray-500 dark:text-gray-400 py-4">除去パターンが登録されていません</div>';
                    return;
                }

                let html = '<ul class="divide-y dark:divide-gray-700">';
                patterns.forEach(pattern => {
                    html += `
                        <li class="flex items-center justify-between px-4 py-3">
                            <span class="text-gray-800 dark:text-gray-200">${escapeHTML(pattern.pattern)}</span>
                            <button type="button" class="delete-pattern-btn text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 text-sm" data-id="${escapeHTML(pattern.id)}">
                                削除
                            </button>
                        </li>
                    `;
                });
                html += '</ul>';
                stripPatternsList.innerHTML = html;

                document.querySelectorAll('.delete-pattern-btn').forEach(btn => {
                    btn.addEventListener('click', function () {
                        deleteStripPattern(this.dataset.id);
                    });
                });
            })
            .catch(function (error) {
                console.error("Error fetching strip patterns:", error);
                stripPatternsList.innerHTML = '<div class="text-center text-red-500 py-4">除去パターンの取得に失敗しました</div>';
            });
    }

    // 除去パターンの追加
    function addStripPattern() {
        const pattern = newStripPatternInput.value.trim();
        if (!pattern) {
            showStripPatternError('除去パターンを入力してください');
            return;
        }

        if (isProcessing) return;
        isProcessing = true;
        toggleButtonDisabled(addStripPatternBtn, true);

        axios.post(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/strip-patterns`, { pattern })
            .then(function () {
                newStripPatternInput.value = '';
                hideStripPatternError();
                fetchStripPatterns();
            })
            .catch(function (error) {
                if (error.response && error.response.data && error.response.data.message) {
                    showStripPatternError(error.response.data.message);
                } else {
                    showStripPatternError('エラーが発生しました');
                }
            })
            .finally(function () {
                isProcessing = false;
                toggleButtonDisabled(addStripPatternBtn, false);
            });
    }

    // 除去パターンの削除
    function deleteStripPattern(patternId) {
        if (isProcessing) return;
        if (!confirm('この除去パターンを削除しますか？')) return;

        isProcessing = true;

        axios.delete(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/strip-patterns/${encodeURIComponent(patternId)}`)
            .then(function () {
                fetchStripPatterns();
            })
            .catch(function (error) {
                console.error("Error deleting strip pattern:", error);
                alert('削除に失敗しました');
            })
            .finally(function () {
                isProcessing = false;
            });
    }

    // 除去パターンの再適用
    function reapplyStripPatterns() {
        if (isProcessing) return;
        if (!confirm('既存のタイムスタンプに除去パターンを再適用しますか？\nnormalized_textが再生成されます。')) return;

        isProcessing = true;
        toggleButtonDisabled(reapplyStripPatternsBtn, true);

        showStripPatternMessage('再適用中...', 'text-gray-600');

        axios.post(`/api/manage/channels/${encodeURIComponent(cryptHandle)}/strip-patterns/reapply`)
            .then(function (response) {
                showStripPatternMessage(response.data.message, 'text-green-600');
            })
            .catch(function (error) {
                console.error("Error reapplying strip patterns:", error);
                showStripPatternMessage('再適用に失敗しました', 'text-red-500');
            })
            .finally(function () {
                isProcessing = false;
                toggleButtonDisabled(reapplyStripPatternsBtn, false);
            });
    }

    // 除去パターンエラー表示
    function showStripPatternError(message) {
        stripPatternError.textContent = message;
        stripPatternError.classList.remove('hidden');
    }

    // 除去パターンエラー非表示
    function hideStripPatternError() {
        stripPatternError.textContent = '';
        stripPatternError.classList.add('hidden');
    }

    // 除去パターンメッセージ表示
    function showStripPatternMessage(message, colorClass) {
        stripPatternMessage.textContent = message;
        stripPatternMessage.className = `text-sm mt-2 ${colorClass}`;
        stripPatternMessage.classList.remove('hidden');
    }

    // マッピング状態に応じたCSSクラスを返す
    function getMappingClass(status) {
        switch (status) {
            case 'linked':
                return 'text-green-600 dark:text-green-400';
            case 'auto_linked':
                return 'text-blue-600 dark:text-blue-400';
            case 'not_song':
                return 'text-gray-500 dark:text-gray-400';
            case 'unlinked':
            default:
                return 'text-orange-600 dark:text-orange-400';
        }
    }

    // エラーメッセージ表示
    function showError(message) {
        excludedWordError.textContent = message;
        excludedWordError.classList.remove('hidden');
    }

    // エラーメッセージ非表示
    function hideError() {
        excludedWordError.textContent = '';
        excludedWordError.classList.add('hidden');
    }

    // プレビューメッセージ表示
    function showPreviewMessage(message, colorClass) {
        previewMessage.textContent = message;
        previewMessage.className = `text-sm mb-4 ${colorClass}`;
        previewMessage.classList.remove('hidden');
    }

    // プレビューメッセージ非表示
    function hidePreviewMessage() {
        previewMessage.classList.add('hidden');
    }

    // イベント設定
    addExcludedWordBtn.addEventListener('click', addExcludedWord);
    newExcludedWordInput.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addExcludedWord();
        }
    });
    addStripPatternBtn.addEventListener('click', addStripPattern);
    newStripPatternInput.addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addStripPattern();
        }
    });
    reapplyStripPatternsBtn.addEventListener('click', reapplyStripPatterns);
    loadPreviewBtn.addEventListener('click', loadPreview);
    reprocessBtn.addEventListener('click', reprocessCoverSongs);

    // 初期化
    fetchExcludedWords();
    fetchStripPatterns();
});
