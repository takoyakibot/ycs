<x-app-layout>
    <x-slot name="alpine_script">
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
                        <div class="flex items-center gap-4 border rounded-lg shadow-lg p-4 bg-white cursor-pointer hover:bg-gray-200">
                            <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full" />
                            <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                        </div>
                    </a>
                </template>
            </div>
        </div>
    </div>

</x-app-layout>
