<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>コメント検索 {{ $channelData['name'] }}</title>
</head>
<body>
    <h1>コメント検索 {{ $channelData['name'] }}</h1>

    <!-- 検索フォーム -->
    <form method="GET" action="{{ url()->current() }}">
        <input type="text" name="keyword" placeholder="検索ワードを入力" value="{{ request('keyword') }}">
        <label>
            <input type="radio" name="type" value="1" {{ request('type') === '1' ? 'checked' : '' }}>
            チャットのみ
        </label>
        <label>
            <input type="radio" name="type" value="2" {{ request('type') === '2' ? 'checked' : '' }}>
            コメントのみ
        </label>
        <label>
            <input type="radio" name="type" value="0" {{ request('type') === '0' || !request('type') ? 'checked' : '' }}>
            指定しない
        </label>
        <button type="submit">検索</button>
        <button type="button" onclick="window.location.href='{{ url()->current() }}'">リセット</button>
    </form>

    <ul>
        @forelse ($channelData['comments'] as $comment)
            <li>{{ $comment['timestamp'] }} {{ $comment['message'] }}</li>
        @empty
            @if (empty(request('keyword')))
                <li>検索ワードを入力してください。</li>
            @else
                <li>コメントが見つかりません。</li>
            @endif
        @endforelse
    </ul>
</body>
</html>
