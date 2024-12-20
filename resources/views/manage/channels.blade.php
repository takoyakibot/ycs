<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('チャンネル管理') }}
        </h2>
    </x-slot>

    <div class="px-6 py-12">
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                    @if (session('status'))
                        <li>{{ session('status') }}</li>
                    @endif
                </ul>
            </div>
        @endif

        <!-- チャンネル登録フォーム -->
        <div class="p-2">
            <form method="POST" action="{{ route('manage.addChannel') }}">
                @csrf
                <x-input-label for="handle" :value="__('handle')" class="mr-2" />
                <div class="flex items-center gap-2">
                    <x-text-input id="handle" name="handle" type="text" class="mt-1 block w-[200px]" required autofocus
                        autocomplete="handle" placeholder="7777777" />
                    <x-primary-button class="mt-1">登録</x-primary-button>
                    <div class="gap-0">
                        <span class="text-gray-500 mt-1">(
                            https://youtube.com/@</span><span class="text-black font-bold mt-1">7777777</span><span
                            class="text-gray-500 mt-1">
                            )
                        </span>
                    </div>
                </div>
            </form>
        </div>

        <!-- チャンネル一覧 -->
        <div class="p-2">
            <h2 class="text-gray-500">登録チャンネル</h2>
            <ul>
                @foreach ($channels as $channel)
                    <li>
                        <a href="{{ route('manage.channel', $channel['handle']) }}">
                            <img src="{{ $channel['thumbnail'] }}" alt="アイコン">
                            <span>{{ $channel['title'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-app-layout>
