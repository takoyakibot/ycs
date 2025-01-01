import { escapeHTML, toggleButtonDisabled } from "../utils";

document.addEventListener('DOMContentLoaded', function () {
    const registerForm = document.getElementById('archiveRegisterForm');
    const registerButton = document.getElementById('registerButton');
    const resultsContainer = document.getElementById('archives');
    const errorMessage = document.getElementById('errorMessage');
    const handle = document.getElementById('handle');

    // アーカイブ一覧の取得処理
    function fetchArchives() {
        axios.get('/api/channels/' + handle.value)
            .then(function (response) {
                const archives = response.data;
                let html = '';

                archives.forEach(archive => {
                    const youtubeUrl = "https://youtube.com/watch?v=" + encodeURIComponent(archive.video_id || '');
                    html += `
                        <div class="archive flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-6 ${archive.is_display ? 'bg-white' : 'bg-gray-200'}">
                            <div class="flex flex-col flex-shrink-0 sm:w-1/3">
                                <div class="flex flex-col gap-2">
                                    <a href="${youtubeUrl}" target="_blank">
                                        <img src="${escapeHTML(archive.thumbnail || '')}" alt="サムネイル"
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
                                    <!-- エラーメッセージ表示 -->
                                    <div class="error-message mt-1 text-red-500 text-sm"></div>
                                </div>
                            </div>
                        </div>
                    `;
                });

                resultsContainer.innerHTML = html;
            })
            .catch(function (error) {
                console.error("Error fetching channels:", error);
                resultsContainer.innerHTML = '<p class="text-red-500">アーカイブの取得に失敗しました。</p>';
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

        axios.post('/api/archives', formData)
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
    resultsContainer.addEventListener('click', function (event) {
        if (isProcessing) { return; }
        isProcessing = true;
        const target = event.target;

        // 表示非表示切り替えボタン押下時
        if (target.classList.contains('toggle-display-btn')) {
            toggleButtonDisabled(target, isProcessing);
            const archiveElement = target.closest('.archive'); // 親要素を取得
            const id = target.getAttribute('data-id');
            const isDisplay = target.getAttribute('data-display'); // 現在のフラグ
            if (!id || !isDisplay) {
                console.error('Invalid data attributes for toggle display');
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
            axios.patch('/api/archives/toggle-display', data)
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
            toggleButtonDisabled(target, isProcessing);

            const timestampsElement = target.closest('.archive').querySelector('.timestamps');
            const id = target.getAttribute('data-id');
            if (!id) {
                console.error('Invalid data attributes for fetch comment');
                return;
            }

            const errorMessage = target.parentElement.parentElement.querySelector('.error-message');
            errorMessage.textContent = '';

            // サーバーに送信するデータ
            const data = {
                id: id,
            };

            // Ajaxリクエスト
            axios.patch('/api/archives/fetch-comments', data)
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

            const timestampsElement = target.closest('.archive').querySelector('.timestamps');
            const id = target.getAttribute('data-id');
            const isEdit = target.getAttribute('data-is-edit');
            if (!id || !isEdit) {
                console.error('Invalid data attributes for edit timestamps');
                return;
            }

            const errorMessage = target.parentElement.querySelector('.error-message');
            errorMessage.textContent = '';

            // 編集状態かどうかで処理を分岐
            if (isEdit !== "1") {
                // 編集状態ではない場合、表示を編集モードに切り替える
                toggleTsItemsStyle(timestampsElement, isEdit);
            } else {
                // サーバーに送信するデータ
                const data = {
                    id: id,
                    ts_items: null,
                };

                // Ajaxリクエスト
                axios.patch('/api/archives/edit-timestamps', data)
                    .then(response => {
                        alert(response.data.message);
                        errorMessage.textContent = 'ほげ';
                        // 通常モードに戻す
                        toggleTsItemsStyle(timestampsElement, isEdit);
                    })
                    .catch(error => {
                        console.error('エラーが発生しました:', error);
                        errorMessage.textContent = 'コメント編集に失敗しました。もう一度お試しください。';
                    })
                    .finally(() => {
                        isProcessing = false;
                        toggleButtonDisabled(target, isProcessing);
                    });
            }
        }
        isProcessing = false;
        toggleButtonDisabled(target, isProcessing);
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

// 編集中かどうかと表示非表示で表示方法を変更する
function toggleTsItemsStyle(timestampsElement, currentIsEdit) {
    const btn = timestampsElement.querySelector('.edit-timestamps-btn');
    const tsItems = timestampsElement.querySelectorAll('.timestamp');
    const newIsEditFlg = currentIsEdit !== '1'
    if (btn) {
        btn.setAttribute('data-is-edit', newIsEditFlg ? '0' : '1');
        btn.textContent = newIsEditFlg ? '編集を完了する' : 'タイムスタンプ編集';
        btn.classList.toggle('bg-blue-500', !newIsEditFlg);
        btn.classList.toggle('bg-orange-500', newIsEditFlg);
        // キャンセルボタンの表示
    }
    // TS項目のモード変更
    tsItems.forEach(tsItem => {
        tsItem.classList.toggle('bg-gray-200', newIsEditFlg);
        tsItem.classList.toggle('mb-1', newIsEditFlg);
        tsItem.querySelector('a').classList.toggle('hidden', newIsEditFlg);
        tsItem.querySelector('span').classList.toggle('hidden', !newIsEditFlg);
    });
}

function getTsItems(tsItems) {
    let html = '';
    tsItems.forEach(tsItem => {
        html += `
                <div class="timestamp text-sm ${tsItem.is_display ? 'text-gray-700' : 'text-gray-400'}" key="${tsItem.id}">
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
    });
    return html;
}
