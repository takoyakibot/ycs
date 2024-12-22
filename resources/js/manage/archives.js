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
                        <div class="flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-6 bg-white">
                            <div class="flex flex-col flex-shrink-0 sm:w-1/3">
                                <div class="flex-col">
                                    <a href="https://youtube.com/watch?v=${encodeURIComponent(archive.video_id)}" target="_blank">
                                        <img src="${escapeHTML(archive.thumbnail)}" alt="サムネイル" class="h-auto rounded-md object-cover" />
                                    </a>
                                </div>
                                <div class="mt-4">
                                    <h3 class="font-semibold text-gray-800 mb-2">
                                        ${escapeHTML(archive.title)}
                                    </h3>
                                    <p class="text-sm text-gray-600">
                                        アップロード日: ${new Date(archive.published_at).toLocaleString()}
                                    </p>
                                </div>
                            </div>
                            <div class="flex-grow sm:w-2/3">
                    `;

                    archive.ts_items.forEach(ts_item => {
                        html += `
                                <div class="text-sm text-gray-700">
                                    <a href="https://youtube.com/watch?v=${encodeURIComponent(archive.video_id)}&t=${encodeURIComponent(ts_item.ts_num)}s"
                                        target="_blank" class="text-blue-500 tabular-nums hover:underline">
                                        ${ts_item.ts_text}
                                    </a>
                                    <span class="ml-2">${escapeHTML(ts_item.text)}</span>
                                </div>
                        `;
                    });

                    html += `
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
