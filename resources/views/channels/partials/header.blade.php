{{-- チャンネルヘッダー --}}
<div class="p-2">
    {{-- デスクトップ表示: 全体を中央寄せ --}}
    <div class="flex justify-center">
        <div class="text-gray-500 flex items-center gap-4 hidden sm:flex">
            <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
            <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
            <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="hover:opacity-80">
                Youtubeチャンネルはこちら
            </a>
            {{-- 区切り線 --}}
            <div class="h-8 w-px bg-gray-300 dark:bg-gray-600 mx-2"></div>
            {{-- デスクトップ用切り替えボタン --}}
            <div class="flex gap-2">
                <button @click="activeTab = 'timestamps'"
                        :class="activeTab === 'timestamps' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                        :aria-pressed="activeTab === 'timestamps'"
                        role="tab"
                        aria-label="タイムスタンプタブに切り替え"
                        class="px-4 py-2 rounded-lg font-medium text-sm transition-colors hover:opacity-80">
                    タイムスタンプ
                </button>
                <button @click="activeTab = 'archives'"
                        :class="activeTab === 'archives' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                        :aria-pressed="activeTab === 'archives'"
                        role="tab"
                        aria-label="アーカイブタブに切り替え"
                        class="px-4 py-2 rounded-lg font-medium text-sm transition-colors hover:opacity-80">
                    アーカイブ
                </button>
            </div>
        </div>
    </div>
    {{-- モバイル表示 --}}
    <h2 class="text-gray-500 justify-self-center sm:hidden">
        <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 hover:opacity-80">
            <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
            <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
        </a>
    </h2>
</div>
