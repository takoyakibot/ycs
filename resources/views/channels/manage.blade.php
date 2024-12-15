<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('アーカイブ管理') }}
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

        <div class="p-2">
            <h2 class="text-gray-500 flex items-center justify-center gap-4">
                <img src="{{ $channel->thumbnail }}" alt="アイコン" class="w-20 h-20 rounded-full">
                <span class="text-lg font-bold text-black">{{ $channel->title }}</span>
                <a href="{{ url('https://youtube.com/@' . $channel->handle) }}" target="_blank">
                    Youtubeチャンネルはこちら
                </a>
            </h2>

            <form method="POST" action="{{ route('dashboard.updateAchives', ['id' => $channel->handle]) }}">
                @csrf
                <x-primary-button>アーカイブ取得</x-primary-button>
            </form>
        </div>

        <div class="p-2">
            <h3 class="text-gray-500">アーカイブ一覧</h3>
            <ul>
                @foreach ($archives as $archive)
                    <li>
                        <a href="{{ url('https://youtube.com/watch?v=' . $archive['video_id']) }}" target="_blank">
                            <img src="{{ $archive['thumbnail'] }}" alt="サムネイル">
                            {{ $archive['title'] }}
                            {{ 'アップロード日: ' . $archive['published_at'] }}
                        </a>
                        {{ $archive['is_display'] }}
                        @foreach ($archive->tsItems()->get() as $ts_item)
                            <li>
                                <a href="{{ url('https://youtube.com/watch?v=' . $archive['video_id'] . '&t=' . $ts_item['ts_num'] . 's') }}"
                                    target="_blank">
                                    {{ $ts_item['ts_text'] . ' ' . $ts_item['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>