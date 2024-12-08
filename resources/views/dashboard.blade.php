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
        <div class="p-2">
            @if ($apiKey == "1")
                <form method="POST" action="{{ route('dashboard.registerApiKey') }}">
                    @csrf
                    <label for="api_key" class="text-gray-500">YouTube APIキー</label>
                    <span>登録済み</span>
                    <button type="submit">編集</button>
                </form>
            @else
                <form method="POST" action="{{ route('dashboard.updateApiKey') }}">
                    @csrf
                    @method('PUT')
                    <label for="api_key" class="text-gray-500">YouTube APIキー</label>
                    <input type="text" id="api_key" name="api_key" class="w-[400px]" value="{{ $apiKey }}">
                    <button type="submit">登録</button>
                </form>
            @endif
        </div>

        <!-- チャンネル登録フォーム -->
        <div class="p-2">
            <form method="POST" action="{{ route('dashboard.addChannel') }}">
                @csrf
                <label for="channel_id" class="text-gray-500">チャンネルID</label>
                <input type="text" id="channel_id" name="channel_id" placeholder="7777777">
                <button type="submit">登録</button>
                <span class="text-gray-500">(
                    https://youtube.com/@</span><span class="text-black font-bold">7777777</span><span
                    class="text-gray-500">
                    )</span>
            </form>
        </div>

        <!-- チャンネル一覧 -->
        <div class="p-2">
            <h2 class="text-gray-500">登録チャンネル</h2>
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
    </div>
</x-app-layout>