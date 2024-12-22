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

            <form id="archiveRegisterForm">
                <div class="flex items-center gap-2">
                    <x-text-input type="hidden" id="handle" name="handle" value="{{ $crypt_handle }}" />
                    <x-primary-button id="registerButton" type="button" class="mt-1">アーカイブ取得</x-primary-button>
                </div>
                <!-- エラーメッセージ表示 -->
                <div id="errorMessage" class="text-red-500 mt-2"></div>
            </form>
        </div>

        <div class="p-2">
            <h3 class="text-gray-500">アーカイブ一覧</h3>
            <div id="archives"></div>
        </div>
    </div>
</x-app-layout>

@vite('resources/js/manage/archives.js')
