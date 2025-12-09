{{-- 統合スティッキーバー（チャンネル情報 + タブ + ガチャボタン） --}}
<div class="sticky top-0 z-30 bg-white dark:bg-gray-900 -mx-2 px-2 py-2 mb-2 border-b border-gray-200 dark:border-gray-700"
     x-data="{ showChannelName: true, resizeTimeout: null }"
     x-init="
        const container = $refs.headerContainer;
        const checkWrap = () => {
            // 一時的にチャンネル名を表示して高さを測定
            showChannelName = true;
            $nextTick(() => {
                const singleLineHeight = 48;
                if (container.offsetHeight > singleLineHeight) {
                    showChannelName = false;
                }
            });
        };
        checkWrap();
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(checkWrap, 150);
        });
     ">
    <div class="flex items-center justify-center gap-2 sm:gap-4 flex-wrap" x-ref="headerContainer">
        {{-- チャンネル情報 --}}
        <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')"
           target="_blank"
           rel="noopener noreferrer"
           class="flex items-center gap-2 hover:opacity-80 text-gray-600 dark:text-gray-400">
            <img :src="escapeHTML(channel.thumbnail || '')"
                 alt="アイコン"
                 class="w-8 h-8 sm:w-10 sm:h-10 rounded-full">
            <span x-show="showChannelName" class="font-bold text-sm sm:text-base" x-text="channel.title || '未設定'"></span>
        </a>

        {{-- 区切り線 --}}
        <div class="h-6 w-px bg-gray-300 dark:bg-gray-600 hidden sm:block"></div>

        {{-- タブ切り替えボタン --}}
        <div class="flex gap-1 sm:gap-2">
            <button @click="activeTab = 'timestamps'"
                    :class="activeTab === 'timestamps' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                    :aria-pressed="activeTab === 'timestamps'"
                    role="tab"
                    aria-label="タイムスタンプタブに切り替え"
                    class="px-3 sm:px-4 py-1.5 sm:py-2 rounded-lg font-medium text-sm transition-colors hover:opacity-80">
                タイムスタンプ
            </button>
            <button @click="activeTab = 'archives'"
                    :class="activeTab === 'archives' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                    :aria-pressed="activeTab === 'archives'"
                    role="tab"
                    aria-label="アーカイブタブに切り替え"
                    class="px-3 sm:px-4 py-1.5 sm:py-2 rounded-lg font-medium text-sm transition-colors hover:opacity-80">
                アーカイブ
            </button>
        </div>

        {{-- 区切り線（タイムスタンプタブのみ） --}}
        <div x-show="activeTab === 'timestamps'" class="h-6 w-px bg-gray-300 dark:bg-gray-600 hidden sm:block"></div>

        {{-- 楽曲ガチャボタン（タイムスタンプタブのみ表示） --}}
        <div x-show="activeTab === 'timestamps'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="flex items-center gap-1 sm:gap-2">
            {{-- ガチャボタン --}}
            <button @click="playRandomTimestamp()"
                    :disabled="isRandomPlaying"
                    class="inline-flex items-center gap-1 sm:gap-2 px-3 sm:px-4 py-1.5 sm:py-2 bg-gradient-to-r from-pink-500 to-purple-600 hover:from-pink-600 hover:to-purple-700 text-white font-bold rounded-lg shadow transition-all duration-200 hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100 text-sm">
                <template x-if="!isRandomPlaying">
                    <span class="flex items-center gap-1 sm:gap-2">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span class="hidden sm:inline">楽曲ガチャ</span>
                        <span class="sm:hidden">ガチャ</span>
                    </span>
                </template>
                <template x-if="isRandomPlaying">
                    <span class="flex items-center gap-1 sm:gap-2">
                        <svg class="animate-spin w-4 h-4 sm:w-5 sm:h-5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="hidden sm:inline">抽選中...</span>
                    </span>
                </template>
            </button>

            {{-- 自動再抽選トグル --}}
            <button @click="toggleAutoReshuffle()"
                    :class="autoReshuffle ? 'bg-green-500 hover:bg-green-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300'"
                    :title="autoReshuffle ? '自動再抽選: ON' : '自動再抽選: OFF'"
                    class="inline-flex items-center gap-1 px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg font-medium text-sm transition-all duration-200 shadow">
                {{-- リピートアイコン --}}
                <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <span class="hidden sm:inline" x-text="autoReshuffle ? '自動' : '手動'"></span>
            </button>
        </div>
    </div>
</div>
