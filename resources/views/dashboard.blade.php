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

        <!-- チャンネル登録フォーム -->
        <div class="p-2">
            <form method="POST" action="{{ route('dashboard.addChannel') }}">
                @csrf
                <label for="handle" class="text-gray-500">ハンドル</label>
                <input type="text" id="handle" name="handle" placeholder="7777777">
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
                        <a href="{{ route('dashboard.channel', $channel['handle']) }}">
                            <img src="{{ $channel['thumbnail'] }}" alt="サムネイル">
                            <span>{{ $channel['title'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>