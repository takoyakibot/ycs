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
            <div>
                <p class="text-gray-500">Googleアカウントでログインしてください。</p>
            </div>
        @endif
    </div>
</x-app-layout>

@vite('resources/js/manage/channels.js')
