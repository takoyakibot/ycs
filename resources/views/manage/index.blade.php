<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('チャンネル管理') }}
        </h2>
    </x-slot>

    <div class="px-6 py-12">

        @if ($api_key_flg)
            <!-- チャンネル登録フォーム -->
            <div class="p-2">
                <form id="channelRegisterForm">
                    @csrf
                    <x-input-label for="handle" :value="__('handle')" class="mr-2" />
                    <div class="flex items-center gap-2">
                        <x-text-input id="handle" name="handle" type="text" class="mt-1 block w-[200px]" required autofocus
                            autocomplete="handle" placeholder="7777777" />
                        <x-primary-button id="registerButton" type="button" class="mt-1">登録</x-primary-button>
                        <div class="gap-0">
                            <span class="text-gray-500 mt-1">(
                                https://youtube.com/@</span><span class="text-black font-bold mt-1">7777777</span><span
                                class="text-gray-500 mt-1">
                                )
                            </span>
                        </div>
                    </div>
                </form>
                <!-- エラーメッセージ表示 -->
                <div id="errorMessage" class="text-red-500 mt-2"></div>
            </div>

            <!-- チャンネル一覧 -->
            <div class="p-2">
                <h2 class="text-gray-500">登録チャンネル</h2>
                <div id="channels"></div>
            </div>
        @else
            <div>
                <p class="text-gray-500">APIキーを登録してください。</p>
            </div>
        @endif
    </div>
</x-app-layout>

@vite('resources/js/manage/channels.js')