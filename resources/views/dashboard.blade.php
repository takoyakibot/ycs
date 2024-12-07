<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="px-6 py-12">
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
            <input type="text" id="channel_id" name="channel_id" placeholder="7777777">
            <button type="submit">登録</button>
            <span class="text-gray-500">(
                https://youtube.com/@</span><span class="text-black font-bold">7777777</span><span
                class="text-gray-500">
                )</span>
        </form>

        <!-- チャンネル一覧 -->
        <h2>登録チャンネル</h2>
        <ul>
            @foreach ($channels as $channel)
                <li>
                    <a href="{{ route('dashboard.channel', $channel->channel_id) }}">
                        <img src="{{ $channel->thumbnail }}" alt="サムネイル">
                        <span>{{ $channel->name }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-app-layout>