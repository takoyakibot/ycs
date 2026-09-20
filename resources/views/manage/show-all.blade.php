<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('全アーカイブ管理') }}
        </h2>
    </x-slot>

    <div class="px-2 sm:px-6 py-4 sm:py-12">
        <div class="p-2">
            <div class="flex items-center justify-center gap-4 flex-wrap">
                <span class="font-bold text-sm sm:text-base text-gray-600 dark:text-gray-400">全チャンネル横断</span>

                <div class="h-6 w-px bg-gray-300 dark:bg-gray-600"></div>

                <div class="flex gap-2">
                    <span class="px-3 sm:px-4 py-1.5 sm:py-2 bg-blue-500 text-white rounded-lg font-medium text-sm">
                        全アーカイブ管理
                    </span>
                    <a href="{{ route('manage.index') }}" class="px-3 sm:px-4 py-1.5 sm:py-2 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg font-medium text-sm hover:opacity-80 transition-colors">
                        チャンネル一覧
                    </a>
                </div>
            </div>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            <x-search
                channel-id=""
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
