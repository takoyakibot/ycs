<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        {{ session('status') }}
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- APIキー -->
        <form method="POST" action="{{ route('dashboard.updateApiKey') }}">
            @csrf
            <label for="api_key">YouTube APIキー</label>
            <input type="text" id="api_key" name="api_key" value="{{ $apiKey }}">
            <button type="submit">登録</button>
        </form>

        <!-- チャンネル登録フォーム -->
        <form method="POST" action="{{ route('dashboard.addChannel') }}">
            @csrf
            <label for="channel_id">チャンネルID</label>
            <input type="text" id="channel_id" name="channel_id">
            <button type="submit">登録</button>
        </form>

        <!-- チャンネル一覧 -->
        <h2>登録チャンネル</h2>
        <ul>
            @foreach ($channels as $channel)
                <li>
                    <a href="{{ route('dashboard.channel', $channel->id) }}">
                        <img src="{{ $channel->thumbnail }}" alt="サムネイル">
                        {{ $channel->name }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-app-layout>