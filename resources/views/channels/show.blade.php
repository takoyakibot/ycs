<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>コメント検索 {{ $channelData['name'] }}</title>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    @vite('resources/js/show.js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    <h1>コメント検索 {{ $channelData['name'] }}</h1>

    <!-- 検索フォーム -->
    <form id="searchForm">
        <!-- 検索キーワード -->
        <input type="text" name="keyword" id="keyword" placeholder="コメントを検索" value="{{ request('keyword') }}">
        
        <!-- ラジオボタンで選択 -->
        <label>
            <input type="radio" name="type" value="1"> チャットのみ
        </label>
        <label>
            <input type="radio" name="type" value="2"> コメントのみ
        </label>
        <label>
            <input type="radio" name="type" value="0" checked> 指定しない
        </label>

        <!-- 検索ボタン -->
        <button type="submit">検索</button>
        <!-- リセットボタン -->
        <button type="button" id="resetButton">リセット</button>
    </form>

    <ul id="results">
        <li>検索ワードを入力してください。</li>
    </ul>
</body>
</html>
