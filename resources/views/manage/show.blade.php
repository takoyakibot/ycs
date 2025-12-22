<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('アーカイブ管理') }}
        </h2>
    </x-slot>

    <div class="px-2 sm:px-6 py-4 sm:py-12">
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
            <!-- チャンネル情報 + ナビゲーション（1行表示） -->
            <div class="flex items-center justify-center gap-4 flex-wrap">
                <a href="{{ url('https://youtube.com/@' . $channel->handle) }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 hover:opacity-80 text-gray-600 dark:text-gray-400">
                    <img src="{{ $channel->thumbnail ?? '' }}" alt="アイコン" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full">
                    <span class="font-bold text-sm sm:text-base">{{ $channel->title ?? '' }}</span>
                </a>

                <div class="h-6 w-px bg-gray-300 dark:bg-gray-600"></div>

                <div class="flex gap-2">
                    <span class="px-3 sm:px-4 py-1.5 sm:py-2 bg-blue-500 text-white rounded-lg font-medium text-sm">
                        アーカイブ管理
                    </span>
                    <a href="{{ route('manage.settings', $channel->handle) }}" class="px-3 sm:px-4 py-1.5 sm:py-2 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg font-medium text-sm hover:opacity-80 transition-colors">
                        チャンネル設定
                    </a>
                </div>
            </div>

            <form id="archiveRegisterForm" class="mt-4">
                <div class="flex items-center gap-2 justify-center">
                    <x-text-input type="hidden" id="handle" name="handle" value="{{ $crypt_handle }}" />
                    <x-primary-button id="registerButton" type="button" class="mt-1">アーカイブ取得</x-primary-button>
                </div>
                <!-- エラーメッセージ表示 -->
                <div id="errorMessage" class="text-red-500 mt-2"></div>
            </form>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            <x-search
                :channel-id="$channel->handle"
                placeholder="アーカイブ名を検索"
                button-text="検索"
                manage-flg="なんか書いとけ"
                alpine-parent="archiveListComponent"
            />
            <div id="archives" class="flex flex-col items-center w-[100%] gap-2"></div>
        </div>
    </div>
</x-app-layout>

@vite('resources/js/manage/archives.js')
