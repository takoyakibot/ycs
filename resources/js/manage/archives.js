import axios from 'axios';
import DOMPurify from 'dompurify';
import { escapeHTML, toggleButtonDisabled } from "../utils";

document.addEventListener('DOMContentLoaded', function () {
    const registerForm = document.getElementById('archiveRegisterForm');
    const registerButton = document.getElementById('registerButton');
    const resultsContainer = document.getElementById('archives');
    const errorMessage = document.getElementById('errorMessage');
    const handle = document.getElementById('handle');

    // 初期状態の表示フラグは「しぼりこみなし」になっているので、デフォルトで設定されるようにする
    function firstUrl(params = 'visible=2') {
        return `/api/manage/channels/${handle.value}?page=1` + (params ? `&${params}` : '');
    };

    // アーカイブ一覧の取得処理
    function fetchArchives(url = null) {
        if (!url) url = firstUrl();

        axios.get(url)
            .then(function (response) {
                const d = response.data
                let archives = [];
                if (Array.isArray(d['data'])) {
                    archives = d['data'];
                } else {
                    console.error('データの形式が不正です');
                }

                const paginationButtons = `
                    <div id="paginationButtons" class="flex gap-2 justify-center w-[100%]">
                        <!-- 前へボタン -->
                        <button class="pagination-button prev ${d['prev_page_url'] ? '' : 'pagination-button-disabled'}" aria-label="Prev page" data-url="${d['prev_page_url']}">
                            <
                        </button>

                        <!-- 次へボタン -->
                        <button class="pagination-button next ${d['next_page_url'] ? '' : 'pagination-button-disabled'}" aria-label="Next page" data-url="${d['next_page_url']}">
                            >
                        </button>
                    </div>
                `;

                let html = paginationButtons;

                archives.forEach(archive => {
                    const youtubeUrl = "https://youtube.com/watch?v=" + encodeURIComponent(archive.video_id || '');
                    html += `
                        <div class="archive flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-6 ${archive.is_display ? 'bg-white' : 'bg-gray-200'}">
                            <div class="flex flex-col flex-shrink-0 sm:w-1/3">
                                <div class="flex flex-col gap-2">
                                    <a href="${youtubeUrl}" target="_blank">
                                        <img src="${escapeHTML(archive.thumbnail || '')}" alt="サムネイル" loading="lazy"
                                            class="h-auto rounded-md object-cover filter ${archive.is_display ? 'grayscale-0' : 'grayscale'}" />
                                    </a>
                                    <div>
                                        <h4 class="font-semibold ${archive.is_display ? 'text-gray-800' : 'text-gray-500'}">
                                            ${escapeHTML(archive.title || '')}
                                        </h4>
                                        <p class="text-sm text-gray-600">
                                            公開日: ${new Date(archive.published_at || 0).toLocaleString().split(' ')[0]}
                                        </p>
                                    </div>
                                    <div class="flex flex-col">
                                        <div class="flex gap-2">
                                            <button 
                                                class="toggle-display-btn ${archive.is_display ? 'bg-red-500' : 'bg-green-500'} text-white px-4 py-1 rounded-full font-semibold w-auto"
                                                data-id="${archive.id}"
                                                data-display="${archive.is_display}">
                                                ${archive.is_display ? '非表示にする' : '表示にする'}
                                            </button>
                                            <button
                                                class="fetch-comments-btn bg-blue-500 text-white px-4 py-1 rounded-full font-semibold w-auto"
                                                data-id="${archive.id}">
                                                コメント取得
                                            </button>
                                        </div>
                                        <div class="error-message mt-1 text-red-500 text-sm"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col flex-grow sm:w-2/3 gap-2">
                                <div class="timestamps flex flex-col gap-2 sm:gap-0">
                    `;

                    if (archive.ts_items) {
                        html += getTsItems(archive.ts_items);
                    }

                    html += `
                                </div>
                                <div>
                                    <button 
                                        class="edit-timestamps-btn bg-blue-500 text-white ${archive.is_display || archive.ts_items.length ? '' : 'hidden'} px-4 py-1 rounded-full font-semibold w-auto"
                                        data-id="${archive.id}"
                                        data-is-edit="0">
                                        タイムスタンプ編集
                                    </button>
                                    <button class="cancel-timestamps-btn ml-1 bg-gray-200 text-gray-500 hidden px-4 py-1 rounded-full font-semibold w-auto">
                                        編集をキャンセル
                                    </button>
                                    <!-- エラーメッセージ表示 -->
                                    <div class="error-message mt-1 text-red-500 text-sm"></div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += paginationButtons;

                resultsContainer.innerHTML = DOMPurify.sanitize(html);
            })
            .catch(function (error) {
                console.error("Error fetching channels:", error);
                resultsContainer.innerHTML = '<p class="text-red-500">アーカイブの取得に失敗しました。</p>';
            })
            .finally(() => {
                isProcessing = false;
            });
    }

    // 初期表示でアーカイブ一覧を取得
    fetchArchives();

    let isProcessing = false;

    // アーカイブ登録処理
    registerButton.addEventListener('click', function () {
        if (isProcessing) { return; }
        isProcessing = true;
        toggleButtonDisabled(registerButton, isProcessing);

        // 確認メッセージを表示
        if (!confirm('アーカイブを取得します。すでに取得済みの場合、編集内容などが初期化されますがよろしいですか？')) {
            isProcessing = false;
            toggleButtonDisabled(registerButton, isProcessing);
            return;
        }

        const formData = new FormData(registerForm);

        // エラーメッセージをクリア
        errorMessage.textContent = '';

        axios.post('/api/manage/archives', formData)
            .then(function (response) {
                // 登録成功後にアーカイブ一覧を再取得
                alert(response.data);
                fetchArchives();
            })
            .catch(function (error) {
                if (error.response && error.response.data && error.response.data.message) {
                    errorMessage.textContent = error.response.data.message;
                } else {
                    errorMessage.textContent = 'エラーが発生しました。';
                }
            })
            .finally(() => {
                isProcessing = false;
                toggleButtonDisabled(registerButton, isProcessing);
            });
    });

    // アーカイブ編集ボタン類イベント追加
    // 親要素全体のクリックイベントを拾い、それがボタンなど処理が必要なものかどうかを判定する
    resultsContainer.addEventListener('click', function (event) {
        if (isProcessing) { return; }
        isProcessing = true;
        const target = event.target;
        // 一旦非活性に変更
        toggleButtonDisabled(target, isProcessing);

        const errorProcessing = function (errorMessage) {
            console.error(errorMessage);
            isProcessing = false;
            toggleButtonDisabled(target, isProcessing);
        };

        // 表示非表示切り替えボタン押下時
        if (target.classList.contains('toggle-display-btn')) {
            const archiveElement = target.closest('.archive'); // 親要素を取得
            const id = target.getAttribute('data-id');
            const isDisplay = target.getAttribute('data-display'); // 現在のフラグ
            if (!id || !isDisplay) {
                errorProcessing('Invalid data attributes for toggle display');
                return;
            }

            const errorMessage = target.parentElement.parentElement.querySelector('.error-message');
            errorMessage.textContent = '';

            // サーバーに送信するデータ
            const data = {
                id: id,
                is_display: isDisplay,
            };

            // Ajaxリクエスト
            axios.patch('/api/manage/archives/toggle-display', data)
                .then(response => {
                    // サーバーからのレスポンスを処理
                    const newDisplay = response.data;
                    if (!newDisplay) {
                        console.error('Invalid response from toggle display API');
                        errorMessage.textContent = 'サーバーからの応答が無効です。';
                        return;
                    }
                    toggleDisplay(archiveElement, newDisplay);
                })
                .catch(error => {
                    console.error('エラーが発生しました:', error);
                    errorMessage.textContent = '変更に失敗しました。もう一度お試しください。';
                })
                .finally(() => {
                    isProcessing = false;
                    toggleButtonDisabled(target, isProcessing);
                });
        }

        // コメント取得ボタン押下時
        if (target.classList.contains('fetch-comments-btn')) {
            const timestampsElement = target.closest('.archive').querySelector('.timestamps');
            const id = target.getAttribute('data-id');
            if (!id) {
                errorProcessing('Invalid data attributes for fetch comment');
                return;
            }

            const errorMessage = target.parentElement.parentElement.querySelector('.error-message');
            errorMessage.textContent = '';

            // サーバーに送信するデータ
            const data = {
                id: id,
            };

            // Ajaxリクエスト
            axios.patch('/api/manage/archives/fetch-comments', data)
                .then(response => {
                    // サーバーからのレスポンスを処理
                    const ts_items = response.data;
                    if (!ts_items) {
                        console.error('Invalid response from fetch comment API');
                        errorMessage.textContent = 'サーバーからの応答が無効です。';
                        return;
                    }
                    timestampsElement.innerHTML = getTsItems(ts_items);
                    if (ts_items.length > 0) {
                        alert("コメント取得完了");
                    } else {
                        alert("抽出できるコメントがありませんでした");
                    }
                })
                .catch(error => {
                    console.error('エラーが発生しました:', error);
                    errorMessage.textContent = 'コメント取得に失敗しました。もう一度お試しください。';
                })
                .finally(() => {
                    isProcessing = false;
                    toggleButtonDisabled(target, isProcessing);
                });
        }

        // タイムスタンプ編集ボタン押下時
        if (target.classList.contains('edit-timestamps-btn')) {
            toggleButtonDisabled(target, isProcessing);

            const id = target.getAttribute('data-id');
            const isEdit = target.getAttribute('data-is-edit');
            if (!id || !isEdit) {
                errorProcessing('Invalid data attributes for edit timestamps');
                return;
            }

            const errorMessage = target.parentElement.querySelector('.error-message');
            errorMessage.textContent = '';

            // 編集状態かどうかで処理を分岐
            if (isEdit !== "1") {
                try {
                    // 編集状態ではない場合、表示を編集モードに切り替える
                    toggleTsItemsStyle(target, isEdit);
                } catch (e) {
                    errorProcessing('Failed to toggle timestamp styles:', e);
                    return;
                }
            } else {
                // 編集後の表示非表示状態を取得してAPIの引数を作成する
                const updateTsItems = [];
                const tsItems = target.closest('.archive').querySelectorAll('.timestamp');
                tsItems.forEach(tsItem => {
                    const id = tsItem.dataset.key;
                    const commentId = tsItem.dataset.comment;
                    const isDisplay = tsItem.classList.contains('is-display');
                    updateTsItems.push({
                        id: id,
                        comment_id: commentId,
                        is_display: isDisplay ? '1' : '0',
                    });
                });

                // Ajaxリクエスト
                axios.patch('/api/manage/archives/edit-timestamps', updateTsItems)
                    .then(response => {
                        alert(response.data.message);
                        // 通常モードに戻す
                        toggleTsItemsStyle(target, isEdit);
                    })
                    .catch(error => {
                        console.error('エラーが発生しました:', error);
                        errorMessage.textContent = 'タイムスタンプの編集に失敗しました。もう一度お試しください。';
                    })
                    .finally(() => {
                        isProcessing = false;
                        toggleButtonDisabled(target, isProcessing);
                    });
            }
        }

        // タイムスタンプ押下時（編集モードのみ）
        // 子要素のクリックでも反応させる
        if (target.classList.contains('timestamp')) {
            toggleTsItemGrayout(target);
        } else {
            const parent = target.closest('.timestamp');
            if (parent) {
                toggleTsItemGrayout(parent);
            }
        }

        // キャンセルボタン押下時
        if (target.classList.contains('cancel-timestamps-btn')) {
            toggleButtonDisabled(target, isProcessing);

            const tsItems = target.closest('.archive').querySelectorAll('.timestamp')
            tsItems.forEach(tsItem => {
                const isDisplay = tsItem.classList.contains('is-display');
                const defaultDisplay = tsItem.classList.contains('default-display');
                // フラグが異なる場合は値と見た目を戻す
                if (isDisplay !== defaultDisplay) {
                    toggleTsItemGrayout(tsItem);
                }
            });
            // 通常モードに戻す
            // 編集時しか表示しないので固定値
            alert('タイムスタンプの編集をキャンセルしました');
            toggleTsItemsStyle(target, '1');
        }

        // ページネーションボタン押下時
        if (target.classList.contains('pagination-button') && !target.classList.contains('pagination-button-disabled')) {
            toggleButtonDisabled(target, isProcessing);
            // data-urlから対応するURLを取得
            const url = target.dataset.url;
            // URLがnullの場合は何もしない（たぶん押せないのでありえないが一応挙動を合わせておく）
            if (!url) {
                errorProcessing('pagination-url is not existed.');
                return;
            }
            // アーカイブ一覧を取得
            fetchArchives(url);
        }

        // 空振りの場合も含めて、最終的に状態を戻す
        isProcessing = false;
        toggleButtonDisabled(target, isProcessing);
    });

    // 検索コンポーネントのイベントのリスナーを定義
    document.addEventListener('search-results', (e) => {
        // 渡されたクエリを付与したurlでfetchする
        fetchArchives(firstUrl(e.detail));
    });
});

function toggleDisplay(element, newDisplay) {
    // 文字列なので'0'もtrueになってしまうため、ややこしくないように内部ではboolで扱う
    const newDisplayFlg = newDisplay === '1';
    // ボタンのテキストとクラスを更新
    const toggleButton = element.querySelector('.toggle-display-btn');
    if (toggleButton) {
        toggleButton.textContent = newDisplayFlg ? '非表示にする' : '表示にする';
        toggleButton.setAttribute('data-display', newDisplayFlg ? '1' : '0');
        toggleButton.classList.toggle('bg-red-500', newDisplayFlg);
        toggleButton.classList.toggle('bg-green-500', !newDisplayFlg);
    }

    // サムネイルのグレースケール
    const thumbnail = element.querySelector('img');
    if (thumbnail) {
        thumbnail.classList.toggle('filter', true);
        thumbnail.classList.toggle('grayscale-0', newDisplayFlg);
        thumbnail.classList.toggle('grayscale', !newDisplayFlg);
    }

    // タイトルのスタイル
    const title = element.querySelector('h4');
    if (title) {
        title.classList.toggle('text-gray-800', newDisplayFlg);
        title.classList.toggle('text-gray-500', !newDisplayFlg);
    }

    //NOTE: よく考えると表示にした瞬間に編集できてないとだめだから、他のボタンは出しっぱなしにしなきゃだわ
    // // コメント取得ボタンの表示非表示
    // const commentButton = element.querySelector('.fetch-comments-btn');
    // if (commentButton) {
    //     commentButton.classList.toggle('hidden', !newDisplayFlg);
    // }

    // タイムスタンプ編集ボタンの表示非表示
    // const editButton = element.querySelector('.edit-timestamps-btn');
    // // ts_itemが存在しなければ非表示のまま
    // if (editButton && element.querySelectorAll('.timestamp').length > 0) {
    //     editButton.classList.toggle('hidden', !newDisplayFlg);
    // }

    // 全体の背景色
    element.classList.toggle('bg-white', newDisplayFlg);
    element.classList.toggle('bg-gray-200', !newDisplayFlg);
}

// 編集モードと通常モードの表示切り替え
function toggleTsItemsStyle(btn, currentIsEdit) {
    const timestampsElement = btn.closest('.archive').querySelector('.timestamps');
    const editBtn = btn.closest('.archive').querySelector('.edit-timestamps-btn');
    const camcelBtn = btn.closest('.archive').querySelector('.cancel-timestamps-btn');
    const tsItems = timestampsElement.querySelectorAll('.timestamp');
    // 現在のisEditと逆の状態をnewIsEditとし、その状態に各要素を更新していく
    // 現在が通常時で編集ボタンを押した場合は、newIsEdit = true となり編集モードのスタイルが適用される
    // 現在が編集中で完了ボタンを押した場合は、newIsEdit = false となり通常モードのスタイルが適用される
    const newIsEditFlg = currentIsEdit !== '1'
    if (editBtn) {
        editBtn.setAttribute('data-is-edit', newIsEditFlg ? '1' : '0');
        editBtn.textContent = newIsEditFlg ? '編集を完了する' : 'タイムスタンプ編集';
        editBtn.classList.toggle('bg-blue-500', !newIsEditFlg);
        editBtn.classList.toggle('bg-orange-500', newIsEditFlg);
    }
    if (camcelBtn) {
        // キャンセルボタンの表示
        camcelBtn.classList.toggle('hidden', !newIsEditFlg);
    }
    // TS項目のモード変更
    tsItems.forEach(tsItem => {
        tsItem.classList.toggle('border-[0.5px]', newIsEditFlg);
        tsItem.classList.toggle('mb-[1px]', newIsEditFlg);
        tsItem.classList.toggle('px-2', newIsEditFlg);
        tsItem.classList.toggle('hover:cursor-pointer', newIsEditFlg);
        tsItem.classList.toggle('hover:shadow-md', newIsEditFlg);
        tsItem.classList.toggle('hover:border-red-500', newIsEditFlg);
        tsItem.querySelector('a').classList.toggle('hidden', newIsEditFlg);
        tsItem.querySelector('span').classList.toggle('hidden', !newIsEditFlg);
    });
}

/**
 * TS項目クリック時の表示切替
 * フラグの変更などは内部で実施してくれるので、グレイアウトを反転させたいTS項目を指定して実行するだけでよい
 */
function toggleTsItemGrayout(tsItem) {
    // 編集モードでなければ終了（リンクが非表示の場合は通常モードなので終了）
    const linkElement = tsItem.querySelector('a');
    if (!linkElement || !linkElement.classList.contains('hidden')) { return; }

    // 現在の表示状態を取得し、状態を反転させてそれに合わせてclassを更新する
    const newIsDisplayFlg = !tsItem.classList.contains('is-display');

    // 対象の data-comment を取得
    const commentId = tsItem.dataset.comment;

    // tsItemの親である .timestamps 要素を取得
    const container = tsItem.closest('.timestamps');
    if (!container) { return; }

    // container内の、同じ data-comment を持つ timestamp 要素を取得
    const matchingItems = container.querySelectorAll(`.timestamp[data-comment="${commentId}"]`);

    // tsItemのclassを更新
    matchingItems.forEach(item => {
        // 表示時
        item.classList.toggle('is-display', newIsDisplayFlg);
        item.classList.toggle('text-gray-700', newIsDisplayFlg);
        // 非表示時
        item.classList.toggle('text-gray-500', !newIsDisplayFlg);
        item.classList.toggle('pl-4', !newIsDisplayFlg);
        item.classList.toggle('bg-gray-200', !newIsDisplayFlg);
    });
}

function getTsItems(tsItems) {
    let html = '';
    let lastCommentId = '';
    tsItems.forEach(tsItem => {
        html += `
                <div class="timestamp text-sm ${tsItem.is_display ? 'text-gray-700 is-display default-display' : 'text-gray-500 pl-4 bg-gray-200'}
                    ${lastCommentId != tsItem.comment_id && lastCommentId != '' ? 'mt-2' : ''}" data-key="${tsItem.id}" data-comment="${tsItem.comment_id}">
                    <a href="${"https://youtube.com/watch?v=" + encodeURIComponent(tsItem.video_id || '')}&t=${encodeURIComponent(tsItem.ts_num || '0')}s"
                        target="_blank" class="text-blue-500 tabular-nums hover:underline">
                        ${tsItem.ts_text || '0:00:00'}
                    </a>
                    <span class="tabular-nums hidden">
                        ${tsItem.ts_text || '0:00:00'}
                    </span>
                    <span class="ml-2">${escapeHTML(tsItem.text || '')}</span>
                </div>
        `;
        lastCommentId = tsItem.comment_id;
    });
    return html;
}

// ページネーション関連を追加する