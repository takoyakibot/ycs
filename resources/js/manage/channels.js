import axios from 'axios';
import { escapeHTML, toggleButtonDisabled } from "../utils";

document.addEventListener('DOMContentLoaded', function () {
    const registerForm = document.getElementById('channelRegisterForm');
    const registerButton = document.getElementById('registerButton');
    const resultsContainer = document.getElementById('channels');
    const errorMessage = document.getElementById('errorMessage');

    // チャンネル一覧の取得処理
    function fetchChannels() {
        axios.get('/api/manage/channels')
            .then(function (response) {
                const channels = response.data;
                let html = '';
                channels.forEach(channel => {
                    html += `
                        <a href="manage/${encodeURIComponent(channel.handle || '')}">
                            <div class="flex items-center gap-4 border rounded-lg shadow-lg p-4 bg-white cursor-pointer hover:bg-gray-200">
                                <img src="${escapeHTML(channel.thumbnail || '')}" alt="アイコン" class="w-20 h-20 rounded-full" />
                                <span class="text-lg font-bold">${escapeHTML(channel.title || '未設定')}</span>
                            </div>
                        </a>
                    `;
                });
                resultsContainer.innerHTML = html;

                // 入力したハンドルをクリア
                document.getElementById('handle').value = '';
            })
            .catch(function (error) {
                console.error("Error fetching channels:", error);
                resultsContainer.innerHTML = '<p class="text-red-500">チャンネルの取得に失敗しました。</p>';
            });
    }

    // 初期表示でチャンネル一覧を取得
    fetchChannels();

    // チャンネル登録処理
    let isProcessing = false;
    registerButton.addEventListener('click', function () {
        if (isProcessing) { return; }
        isProcessing = true;
        toggleButtonDisabled(registerButton, isProcessing);

        const formData = new FormData(registerForm);

        // エラーメッセージをクリア
        errorMessage.textContent = '';

        axios.post('/api/manage/channels', formData)
            .then(function (response) {
                // 登録成功後にチャンネル一覧を再取得
                fetchChannels();
            })
            .catch(function (error) {
                if (error.response && error.response.data && error.response.data.message) {
                    errorMessage.textContent = error.response.data.message;
                } else {
                    errorMessage.textContent = 'エラーが発生しました。';
                }
            })
            .finally(function () {
                isProcessing = false;
                toggleButtonDisabled(registerButton, isProcessing);
            });
    });
});
