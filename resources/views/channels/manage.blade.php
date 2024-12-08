<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('チャンネル管理') }}
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
            <h2 class="text-gray-500">
                <img src="{{ $channel->thumbnail }}" alt="サムネイル">
                <span>{{ $channel->name }}</span>
            </h2>

            <form method="POST" action="{{ route('dashboard.updateAchives', ['id' => $channel->handle]) }}">
                @csrf
                <button type="submit">アーカイブ取得</button>
            </form>
        </div>

        <div class="p-2">
            <h3 class="text-gray-500">アーカイブ一覧</h3>
            <ul>
                @foreach ($archives as $archive)
                    <li>
                        {{ $archive['archive_id'] . ' ' . $archive['archive_name'] }}
                        @foreach ($archive['comments'] as $comment)
                            <li>{{ $comment['timestamp'] . ' ' . $comment['comment'] }}</li>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>