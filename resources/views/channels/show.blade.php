<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            window.archives = @json($archives['data'] ?? []);
        </script>
    </x-slot>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('アーカイブ一覧') }}
        </h2>
    </x-slot>

    <div class="px-2 sm:px-6 py-4 sm:py-12" x-data="{ channel: window.channel, archives: window.archives, getArchiveUrl }">
        <div class="p-2">
            <h2 class="text-gray-500 items-center justify-center gap-4 hidden sm:flex">
                <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank">
                    Youtubeチャンネルはこちら
                </a>
            </h2>
            <h2 class="text-gray-500 justify-self-center sm:hidden">
                <a href="{{ url('https://youtube.com/@' . $channel->handle) }}" target="_blank" class="flex items-center gap-4">
                    <img :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                </a>
            </h2>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            <div id="archives" class="flex flex-col items-center w-[100%]">
                <template x-for="archive in archives">
                    <div class="archive flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-6 bg-white">
                        <div class="flex flex-col flex-shrink-0 sm:w-1/3">
                            <div class="flex flex-col gap-2">
                                <a :href="getArchiveUrl(archive.video_id || '')" target="_blank">
                                    <img :src="escapeHTML(archive.thumbnail || '')" alt="サムネイル" loading="lazy"
                                        class="h-auto rounded-md object-cover" />
                                </a>
                                <div>
                                    <h4 class="font-semibold text-gray-800" x-text="archive.title || ''"></h4>
                                    <p class="text-sm text-gray-600" x-text="'公開日: ' + (new Date(archive.published_at || 0)).toLocaleString().split(' ')[0]"></p>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col flex-grow sm:w-2/3 gap-2">
                            <div class="timestamps flex flex-col gap-2 sm:gap-0">
                                <template x-for="tsItem in archive.ts_items_display">
                                    <div class="timestamp text-sm text-gray-700" :key="tsItem.id">
                                        <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                            target="_blank" class="text-blue-500 tabular-nums hover:underline"
                                            x-text="tsItem.ts_text || '0:00:00'">
                                        </a> <span class="ml-2" x-text="tsItem.text || ''"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-app-layout>
