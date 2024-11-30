<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>コメント検索 {{ $channelData['name'] }}</title>
</head>
<body>
    <h1>コメント検索 {{ $channelData['name'] }}</h1>

    <ul>
        @foreach ($channelData['comments'] as $comment)
            <li>
                {{ $comment['timestamp'] }} {{ $comment['message'] }}
            </li>
        @endforeach
    </ul>
</body>
</html>
