import { escapeHTML } from "../utils";

document.addEventListener('DOMContentLoaded', function () {
    const registerForm = document.getElementById('archiveRegisterForm');
    const registerButton = document.getElementById('registerButton');
    const resultsContainer = document.getElementById('archives');
    const errorMessage = document.getElementById('errorMessage');

    // アーカイブ一覧の取得処理
    function fetchArchives() {
        axios.get('/api/archives')
            .then(function (response) {
                const archives = response.data;
                let html = '';
                archives.forEach(archive => {
                    html += `
                        <div class="card border rounded shadow p-4 mb-4">
                            <a href="https://youtube.com/watch?v=${encodeURIComponent(archive.video_id)}" target="_blank">
                                <img src="${escapeHTML(archive.thumbnail)}" alt="サムネイル" class="" />
                            </a>
                            <span>${escapeHTML(archive.title)}</span>
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
