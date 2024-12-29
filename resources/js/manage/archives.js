import { escapeHTML } from "../utils";

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
                    html += `
                        <div class="flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-6 ${archive.is_display ? 'bg-white' : 'bg-gray-200'}">
                            <div class="flex flex-col flex-shrink-0 sm:w-1/3">
                                <div class="flex flex-col gap-2">
                                    <a href="https://youtube.com/watch?v=${encodeURIComponent(archive.video_id || '')}" target="_blank">
                                        <img src="${escapeHTML(archive.thumbnail || '')}" alt="サムネイル"
                                            class="h-auto rounded-md object-cover ${archive.is_display ? 'filter grayscale-0' : 'filter grayscale'}" />
                                    </a>
                                    <div>
                                        <p class="font-semibold ${archive.is_display ? 'text-gray-800' : 'text-gray-500'}">
                                            ${escapeHTML(archive.title || '')}
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            公開日: ${new Date(archive.published_at || 0).toLocaleString()}
                                        </p>
                                    </div>
                                    <div>
                                        <button 
                                            class="toggle-display-btn ${archive.is_display ? 'bg-red-500' : 'bg-green-500'} text-white px-4 py-1 rounded-full font-semibold w-auto"
                                            data-id="${archive.video_id}"
                                            data-display="${archive.is_display}">
                                            ${archive.is_display ? '非表示にする' : '表示にする'}
                                        </button>
                                        <button 
                                            class="fetch-comment-btn ${archive.is_display ? '' : 'hidden'} bg-blue-500 text-white px-4 py-1 rounded-full font-semibold w-auto"
                                            data-id="${archive.video_id}"
                                            data-display="${archive.is_display}">
                                            コメント取得
                                        </button>
                                        <div class="error-message mt-1 text-red-500 text-sm"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col flex-grow sm:w-2/3 gap-2">
                                <div class="flex flex-col gap-2 sm:gap-0">
                    `;

                    if (archive.ts_items) {
                        archive.ts_items.forEach(ts_item => {
                            html += `
                                    <div class="text-sm text-gray-700" key="${ts_item.id}">
                                        <a href="https://youtube.com/watch?v=${encodeURIComponent(archive.video_id || '')}&t=${encodeURIComponent(ts_item.ts_num || '0')}s"
                                            target="_blank" class="text-blue-500 tabular-nums hover:underline">
                                            ${ts_item.ts_text || '00:00:00'}
                                        </a>
                                        <span class="ml-2">${escapeHTML(ts_item.text || '')}</span>
                                    </div>
                            `;
                        });
                    }

                    html += `
                                </div>
                                <div>
                                    <button 
                                        class="edit-timestamps-btn bg-blue-500 text-white ${archive.is_display || archive.ts_items.length ? '' : 'hidden'} px-4 py-1 rounded-full font-semibold w-auto"
                                        data-id="${archive.video_id}"
                                        data-display="${archive.is_display}">
                                        タイムスタンプ編集
                                    </button>
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

    // アーカイブ登録処理
    registerButton.addEventListener('click', function () {
        const formData = new FormData(registerForm);

        // エラーメッセージをクリア
        errorMessage.textContent = '';

        axios.post('/api/archives', formData)
            .then(function (response) {
                // 登録成功後にアーカイブ一覧を再取得
                fetchArchives();
            })
            .catch(function (error) {
                if (error.response && error.response.data && error.response.data.message) {
                    errorMessage.textContent = error.response.data.message;
                } else {
                    errorMessage.textContent = 'エラーが発生しました。';
                }
            });
    });
});
