import axios from 'axios';

// 検索フォームの送信イベントをハンドリング
document.getElementById('searchForm').addEventListener('submit', function (event) {
    event.preventDefault(); // デフォルトのフォーム送信を防止

    // フォームデータを取得
    const keyword = document.getElementById('keyword').value;
    const type = document.querySelector('input[name="type"]:checked').value;

    if (!keyword) {
        const results = document.getElementById('results');
        results.innerHTML = '<li>検索ワードを入力してください。</li>';
        return;
    }

    // 非同期リクエストを送信
    axios.get(window.location.href, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        params: {
            keyword: keyword,
            type: type
        },
    }).then(function (response) {
        // 結果を更新
        const comments = Object.values(response.data);
        const results = document.getElementById('results');
        if (comments.length > 0) {
            results.innerHTML = ''; // 現在の内容をクリア
            comments.forEach(item => {
                const li = document.createElement('li');
                li.textContent = `${item.timestamp} ${item.message}`;
                results.appendChild(li);
            });
        } else {
            results.innerHTML = '<li>結果が見つかりません。</li>';
        }
    }).catch(function (error) {
        console.error(error);
    });
});

// リセットボタンの動作
document.getElementById('resetButton').addEventListener('click', function () {
    document.getElementById('keyword').value = '';
    document.querySelector('input[name="type"][value="0"]').checked = true;

    // 結果リセット
    const results = document.getElementById('results');
    results.innerHTML = '<li>検索ワードを入力してください。</li>';
});
