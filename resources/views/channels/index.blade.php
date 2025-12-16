<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/channels/play-history.js')
        <script>
            window.channels = @json($channels['data'] ?? []);
        </script>
    </x-slot>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('チャンネル一覧') }}
        </h2>
    </x-slot>

    <div class="flex flex-col p-6 items-center">
        <div class="p-2">
            <div x-data='{ channels: window.channels }'
                class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 w-[100%] max-w-5xl border shadow p-4 rounded-lg">
                <template x-for="channel in channels" :key="channel.handle">
                    <a :href="'/channels/'+channel.handle">
                        <div class="flex items-center gap-4 border rounded-lg shadow-lg p-4 bg-white dark:bg-gray-800 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-700">
                            <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full" />
                            <div class="flex flex-col">
                                <span class="text-lg font-bold dark:text-gray-100" x-text="channel.title || '未設定'"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400"
                                      x-show="channel.last_refresh_at"
                                      x-text="'最終更新: ' + (channel.last_refresh_at ? new Date(channel.last_refresh_at).toLocaleDateString('ja-JP') : '')"></span>
                            </div>
                        </div>
                    </a>
                </template>
            </div>
        </div>
    </div>

</x-app-layout>
