<x-app-layout>

    <h1>チャンネル一覧</h1>
    <ul>
        @foreach ($channels as $channel)
            <li>
                <a href="/{{ $channel['id'] }}">{{ $channel['name'] }}</a>
            </li>
        @endforeach
    </ul>
    <a href="/login">管理画面へ</a>

</x-app-layout>