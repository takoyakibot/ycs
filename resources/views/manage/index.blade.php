<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('チャンネル管理') }}
        </h2>
    </x-slot>

    <div class="flex flex-col p-6 items-center">

        @if ($api_key_flg)
            <!-- チャンネル登録フォーム -->
            <form id="channelRegisterForm">
                <div class="p-2 flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <x-input-label for="handle" :value="__('handle')" class="mr-2" />
                        <x-text-input id="handle" name="handle" type="text" class="mt-1 block w-[200px]" required autofocus
                            autocomplete="handle" placeholder="7777777" />
                        <x-primary-button id="registerButton" type="button" class="mt-1">登録</x-primary-button>
                    </div>
                    <div class="gap-0">
                        <span class="text-gray-500 mt-1">(handle ->
                            https://youtube.com/@</span><span class="text-black font-bold mt-1">7777777</span><span
                            class="text-gray-500 mt-1">
                            )
                        </span>
                    </div>
                    <!-- エラーメッセージ表示 -->
                    <div id="errorMessage" class="text-red-500 mt-2"></div>
                </div>
            </form>

            <!-- チャンネル一覧 -->
            <div class="p-2">
                <h3 class="text-lg text-gray-800 font-bold mb-2">登録チャンネル</h3>
                <div id="channels" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 w-[100%] max-w-5xl border shadow p-4 rounded-lg"></div>
            </div>
        @else
            <div class="text-center p-6 bg-white dark:bg-gray-800 rounded-lg shadow">
                <p class="text-gray-700 dark:text-gray-300 mb-4">YouTube Data API キーが設定されていません。</p>
                <p class="text-gray-600 dark:text-gray-400 mb-6 text-sm">プロフィール画面でAPIキーを登録してください。</p>
                <a href="{{ route('profile.edit') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                    プロフィール画面へ
                </a>
            </div>
        @endif
    </div>
</x-app-layout>

@vite('resources/js/manage/channels.js')
