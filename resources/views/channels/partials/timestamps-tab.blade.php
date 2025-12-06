{{-- タイムスタンプタブ --}}
<div x-show="activeTab === 'timestamps'">
    {{-- ランダム再生ボタン --}}
    <div class="flex justify-center mb-4">
        <button @click="playRandomTimestamp()"
                :disabled="isRandomPlaying"
                class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-pink-500 to-purple-600 hover:from-pink-600 hover:to-purple-700 text-white font-bold rounded-full shadow-lg transition-all duration-200 transform hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
            <template x-if="!isRandomPlaying">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    楽曲ガチャ
                </span>
            </template>
            <template x-if="isRandomPlaying">
                <span class="flex items-center gap-2">
                    <svg class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    抽選中...
                </span>
            </template>
        </button>
    </div>

    {{-- ページネーション（上） --}}
    <div class="flex justify-center gap-2 mb-4">
        <button @click="fetchTimestamps(1, searchQuery, selectedIndex)"
                :disabled="timestamps.current_page <= 1"
                :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            最初
        </button>
        <button @click="fetchTimestamps(timestamps.current_page - 1, searchQuery, selectedIndex)"
                :disabled="timestamps.current_page <= 1"
                :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            前へ
        </button>
        <span class="px-3 py-1 text-sm font-medium" x-text="`${timestamps.current_page || 1} / ${timestamps.last_page || 1}`"></span>
        <button @click="fetchTimestamps(timestamps.current_page + 1, searchQuery, selectedIndex)"
                :disabled="timestamps.current_page >= timestamps.last_page"
                :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            次へ
        </button>
        <button @click="fetchTimestamps(timestamps.last_page, searchQuery, selectedIndex)"
                :disabled="timestamps.current_page >= timestamps.last_page"
                :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            最後
        </button>
    </div>

    {{-- 頭文字フィルタナビゲーション --}}
    <div x-show="timestamps.available_indexes && timestamps.available_indexes.length > 0" class="mb-4 hidden sm:block">
        <div class="flex flex-wrap items-center gap-1">
            <span class="text-xs text-gray-500 dark:text-gray-400 mr-1">絞り込み:</span>
            {{-- すべて --}}
            <button
                @click="clearIndexFilter()"
                :class="!selectedIndex
                    ? 'bg-gray-600 text-white cursor-pointer'
                    : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-300 dark:hover:bg-gray-600 cursor-pointer'"
                class="px-2 h-7 text-xs rounded transition-colors">
                すべて
            </button>
            <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
            {{-- 数字 --}}
            <button
                @click="filterByIndex('0-9')"
                :disabled="!timestamps.available_indexes?.includes('0-9')"
                :class="selectedIndex === '0-9'
                    ? 'bg-purple-700 text-white cursor-pointer ring-2 ring-purple-300'
                    : (timestamps.available_indexes?.includes('0-9')
                        ? 'bg-purple-500 hover:bg-purple-600 text-white cursor-pointer'
                        : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed')"
                class="px-2 h-7 text-xs rounded transition-colors">
                0-9
            </button>
            <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
            {{-- アルファベット（ABCDE, FGHIJ, KLMNO, PQRST, UVWXYZ） --}}
            <template x-for="group in ['ABCDE','FGHIJ','KLMNO','PQRST','UVWXYZ']" :key="group">
                <button
                    @click="filterByIndex(group)"
                    :disabled="!timestamps.available_indexes?.includes(group)"
                    :class="selectedIndex === group
                        ? 'bg-blue-700 text-white cursor-pointer ring-2 ring-blue-300'
                        : (timestamps.available_indexes?.includes(group)
                            ? 'bg-blue-500 hover:bg-blue-600 text-white cursor-pointer'
                            : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed')"
                    class="px-2 h-7 text-xs rounded transition-colors">
                    <span x-text="group"></span>
                </button>
            </template>
            <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
            {{-- 五十音 --}}
            <template x-for="kana in ['あ','か','さ','た','な','は','ま','や','ら','わ']" :key="kana">
                <button
                    @click="filterByIndex(kana)"
                    :disabled="!timestamps.available_indexes?.includes(kana)"
                    :class="selectedIndex === kana
                        ? 'bg-green-700 text-white cursor-pointer ring-2 ring-green-300'
                        : (timestamps.available_indexes?.includes(kana)
                            ? 'bg-green-500 hover:bg-green-600 text-white cursor-pointer'
                            : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed')"
                    class="w-7 h-7 text-xs rounded transition-colors">
                    <span x-text="kana"></span>
                </button>
            </template>
            <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
            {{-- 漢字・その他 --}}
            <button
                @click="filterByIndex('その他')"
                :disabled="!timestamps.available_indexes?.includes('その他')"
                :class="selectedIndex === 'その他'
                    ? 'bg-orange-700 text-white cursor-pointer ring-2 ring-orange-300'
                    : (timestamps.available_indexes?.includes('その他')
                        ? 'bg-orange-500 hover:bg-orange-600 text-white cursor-pointer'
                        : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed')"
                class="px-2 h-7 text-xs rounded transition-colors">
                漢字
            </button>
        </div>
    </div>

    {{-- エラー表示 --}}
    <div x-show="error" class="bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 rounded p-4 mb-4">
        <p class="text-red-800 dark:text-red-200" x-text="error"></p>
    </div>

    {{-- ローディング表示 --}}
    <div x-show="loading" class="flex justify-center items-center py-8">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
        <span class="ml-2 text-gray-600 dark:text-gray-400">読み込み中...</span>
    </div>

    {{-- タイムスタンプ一覧 --}}
    <div x-show="!loading" class="flex flex-col gap-2">
        {{-- 空状態メッセージ --}}
        <template x-if="timestamps.data && timestamps.data.length === 0">
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <template x-if="searchQuery">
                    <p>「<span x-text="searchQuery"></span>」に一致するタイムスタンプが見つかりませんでした</p>
                </template>
                <template x-if="!searchQuery">
                    <p>タイムスタンプが見つかりませんでした</p>
                </template>
            </div>
        </template>

        <template x-for="ts in (timestamps.data || [])" :key="ts.id">
            <div class="p-2 border rounded transition-colors"
                 :data-timestamp-id="ts.id"
                 :class="{
                     'border-blue-500 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-400': selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title)),
                     'bg-gray-200 dark:bg-gray-700 border-gray-300 dark:border-gray-500': ts.has_pending_report && !(selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title))),
                     'dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 active:bg-gray-100 dark:active:bg-gray-600': !ts.has_pending_report && !(selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title)))
                 }">
                <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                    {{-- 楽曲情報 --}}
                    <div class="flex-shrink-0 w-full sm:w-[300px] cursor-pointer transition-colors"
                         :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'hover:text-blue-600 dark:hover:text-blue-400'"
                         @click="ts.mapping?.song ? selectSong(ts.mapping.song, ts) : (ts.text ? selectText(ts.text, ts) : null)"
                         :title="ts.mapping?.song ? `配信サービスで聴く: ${ts.mapping.song.title} / ${ts.mapping.song.artist}` : (ts.text ? `配信サービスで検索: ${ts.text}` : '')">
                        <div class="truncate">
                            <template x-if="ts.mapping?.song">
                                <span>
                                    <span class="font-medium text-xs sm:text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : ''" x-text="ts.mapping.song.title"></span>
                                    <span class="text-xs sm:text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400'"> / </span>
                                    <span class="text-xs sm:text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400'" x-text="ts.mapping.song.artist"></span>
                                </span>
                            </template>
                            <template x-if="!ts.mapping?.song && ts.text">
                                <span class="text-xs sm:text-sm"
                                      :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300'"
                                      x-text="ts.text"></span>
                            </template>
                            <template x-if="!ts.mapping?.song && !ts.text">
                                <span class="text-xs sm:text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300'">-</span>
                            </template>
                        </div>
                    </div>

                    {{-- アーカイブタイトル & 公開日: モバイルでは非表示 --}}
                    <div class="hidden sm:block text-sm truncate flex-1 cursor-pointer transition-colors"
                         @click="ts.mapping?.song ? selectSong(ts.mapping.song, ts) : (ts.text ? selectText(ts.text, ts) : null)"
                         :title="ts.mapping?.song ? `配信サービスで聴く: ${ts.mapping.song.title} / ${ts.mapping.song.artist}` : (ts.text ? `配信サービスで検索: ${ts.text}` : ts.archive.title)">
                        <div class="truncate"
                             :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-600 dark:text-gray-400'"
                             x-text="ts.archive.title">
                        </div>
                        <div class="mt-0.5"
                             :class="ts.has_pending_report ? 'text-xs text-gray-400 dark:text-gray-500' : 'text-xs text-gray-500 dark:text-gray-500'"
                             x-text="'公開日: ' + (ts.archive.published_at ? formatPublishedDate(ts.archive.published_at) : '不明') + '　タイムスタンプ: ' + ts.ts_text">
                        </div>
                    </div>

                    {{-- 動画リンク: YouTubeらしい赤いボタン（報告済みの場合は暗め） --}}
                    <a :href="getYoutubeUrl(ts.video_id, ts.ts_num)"
                       class="inline-flex items-center gap-1 px-3 py-1.5 text-white rounded text-xs sm:text-sm whitespace-nowrap transition-colors"
                       :class="ts.has_pending_report ? 'bg-red-800 hover:bg-red-900' : 'bg-red-600 hover:bg-red-700'"
                       target="_blank"
                       title="YouTubeで再生">
                        <svg class="w-3 h-3 sm:w-4 sm:h-4" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                        </svg>
                        <span>YTで開く</span>
                    </a>
                </div>
            </div>
        </template>
    </div>

    {{-- ページネーション（下） --}}
    <div class="flex justify-center gap-2 mt-4">
        <button @click="fetchTimestamps(1, searchQuery, selectedIndex); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                :disabled="timestamps.current_page <= 1"
                :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            最初
        </button>
        <button @click="fetchTimestamps(timestamps.current_page - 1, searchQuery, selectedIndex); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                :disabled="timestamps.current_page <= 1"
                :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            前へ
        </button>
        <span class="px-3 py-1 text-sm font-medium" x-text="`${timestamps.current_page || 1} / ${timestamps.last_page || 1}`"></span>
        <button @click="fetchTimestamps(timestamps.current_page + 1, searchQuery, selectedIndex); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                :disabled="timestamps.current_page >= timestamps.last_page"
                :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            次へ
        </button>
        <button @click="fetchTimestamps(timestamps.last_page, searchQuery, selectedIndex); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                :disabled="timestamps.current_page >= timestamps.last_page"
                :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
            最後
        </button>
    </div>
</div>
