<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>トップ画面</title>
</head>
<body>
    <h1>チャンネル一覧</h1>
    <ul>
        @foreach ($channels as $channel)
            <li>
                <a href="/{{ $channel['id'] }}">{{ $channel['name'] }}</a>
            </li>
        @endforeach
    </ul>
    <a href="/login">管理画面へ</a>
</body>
</html>
