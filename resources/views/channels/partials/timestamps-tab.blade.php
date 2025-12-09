{{-- タイムスタンプタブ --}}
<div x-show="activeTab === 'timestamps'">
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
            {{-- 折りたたみトグル --}}
            <button
                @click="toggleFilterExpanded()"
                class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 mr-1 flex items-center gap-1">
                絞り込み
                <svg class="w-3 h-3 transition-transform" :class="filterExpanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            {{-- すべて --}}
            <button
                @click="clearIndexFilter()"
                :class="!selectedIndex
                    ? 'bg-gray-600 text-white cursor-pointer'
                    : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-300 dark:hover:bg-gray-600 cursor-pointer'"
                class="px-2 h-7 text-xs rounded transition-colors">
                すべて
            </button>
            {{-- 最近使用したフィルタ（常に表示） --}}
            <template x-if="recentFilters.length > 0 && !filterExpanded">
                <span class="flex items-center gap-1">
                    <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
                    <span class="text-xs text-gray-400">最近:</span>
                    <template x-for="filter in recentFilters" :key="'recent-' + filter">
                        <button
                            @click="filterByIndex(filter)"
                            :disabled="!timestamps.available_indexes?.includes(filter)"
                            :class="selectedIndex === filter
                                ? 'bg-indigo-700 text-white cursor-pointer ring-2 ring-indigo-300'
                                : (timestamps.available_indexes?.includes(filter)
                                    ? 'bg-indigo-500 hover:bg-indigo-600 text-white cursor-pointer'
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed')"
                            class="px-2 h-7 text-xs rounded transition-colors">
                            <span x-text="filter"></span>
                        </button>
                    </template>
                </span>
            </template>
            {{-- フィルタ本体（展開時のみ表示） --}}
            <template x-if="filterExpanded">
                <span class="flex flex-wrap items-center gap-1">
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
                </span>
            </template>
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
            <div class="border rounded transition-colors overflow-hidden"
                 :data-timestamp-id="ts.id"
                 x-data="{ expanded: false }"
                 :class="{
                     'border-blue-500 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-400': selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title)),
                     'bg-gray-200 dark:bg-gray-700 border-gray-300 dark:border-gray-500': ts.has_pending_report && !(selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title))),
                     'dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 active:bg-gray-100 dark:active:bg-gray-600': !ts.has_pending_report && !(selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title)))
                 }">
                {{-- 上部: 楽曲情報 --}}
                <div class="p-2 cursor-pointer transition-colors"
                     :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'hover:text-blue-600 dark:hover:text-blue-400'"
                     @click="ts.mapping?.song ? selectSong(ts.mapping.song, ts) : (ts.text ? selectText(ts.text, ts) : null); expanded = !expanded">
                    <div :class="expanded ? '' : 'line-clamp-2'">
                        <template x-if="ts.mapping?.song">
                            <div>
                                <div>
                                    <span class="font-bold text-sm sm:text-base" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : ''" x-text="ts.mapping.song.title"></span>
                                    <span class="text-xs sm:text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400'"> / </span>
                                    <span class="text-xs sm:text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400'" x-text="ts.mapping.song.artist"></span>
                                </div>
                                {{-- 自動紐付けの場合、元のタイムスタンプテキストを表示 --}}
                                <template x-if="ts.mapping.is_manual === false && ts.text && ts.text !== ts.mapping.song.title">
                                    <div class="text-xs text-yellow-600 dark:text-yellow-400 mt-0.5">
                                        <span class="opacity-75">[自動]</span> <span x-text="ts.text"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!ts.mapping?.song && ts.text">
                            <span class="font-bold text-sm sm:text-base"
                                  :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300'"
                                  x-text="ts.text"></span>
                        </template>
                        <template x-if="!ts.mapping?.song && !ts.text">
                            <span class="text-sm" :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300'">-</span>
                        </template>
                    </div>
                </div>

                {{-- 下部: メタ情報グレー帯 --}}
                <div class="px-2 py-1.5 bg-gray-100 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        {{-- タイムスタンプ --}}
                        <span class="text-xs font-mono w-20 flex-shrink-0"
                              :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-600 dark:text-gray-400'"
                              x-text="ts.ts_text"></span>
                        {{-- 公開日 --}}
                        <span class="text-xs hidden sm:inline w-32 flex-shrink-0"
                              :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-500'"
                              x-text="ts.archive.published_at ? formatPublishedDate(ts.archive.published_at) : ''"></span>
                        {{-- 配信タイトル（デスクトップのみ） --}}
                        <span class="hidden sm:inline text-xs truncate flex-1 min-w-0"
                              :class="ts.has_pending_report ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-500'"
                              :title="ts.archive.title"
                              x-text="ts.archive.title"></span>
                        {{-- スペーサー（モバイル時に右寄せ用） --}}
                        <span class="flex-1 sm:hidden"></span>
                        {{-- YouTubeボタン & 共有メニュー --}}
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a :href="getYoutubeUrl(ts.video_id, ts.ts_num)"
                               class="inline-flex items-center justify-center p-1.5 text-white rounded transition-colors"
                               :class="ts.has_pending_report ? 'bg-red-800 hover:bg-red-900' : 'bg-red-600 hover:bg-red-700'"
                               target="_blank"
                               title="YouTubeで再生">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                </svg>
                            </a>
                            {{-- 共有メニュー --}}
                            <div class="relative" x-data="{ shareOpen: false, menuPos: { top: 0, left: 0 } }" x-ref="shareContainer">
                                <button @click="shareOpen = !shareOpen; if(shareOpen) { const rect = $refs.shareContainer.getBoundingClientRect(); menuPos = { top: rect.bottom + 4, left: rect.right - 144 }; }"
                                    class="inline-flex items-center justify-center p-1.5 text-white rounded transition-colors"
                                    :class="ts.has_pending_report ? 'bg-gray-500 hover:bg-gray-600' : 'bg-gray-600 hover:bg-gray-700'"
                                    title="共有">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                </svg>
                            </button>
                            {{-- ドロップダウンメニュー（bodyにテレポート） --}}
                            <template x-teleport="body">
                                <div x-show="shareOpen"
                                     @click.away="shareOpen = false"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="transform opacity-0 scale-95"
                                     x-transition:enter-end="transform opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="transform opacity-100 scale-100"
                                     x-transition:leave-end="transform opacity-0 scale-95"
                                     class="fixed w-36 bg-white dark:bg-gray-800 rounded-md shadow-lg border border-gray-200 dark:border-gray-700 z-[9999]"
                                     :style="'top: ' + menuPos.top + 'px; left: ' + menuPos.left + 'px;'"
                                     @click="shareOpen = false">
                                    {{-- Twitter --}}
                                    <a :href="getTwitterShareUrl(ts.ts_text || '', ts.mapping?.song ? ts.mapping.song.title + (ts.mapping.song.artist ? ' / ' + ts.mapping.song.artist : '') : (ts.text || ''), ts.archive?.title || '', ts.video_id, ts.ts_num || 0)"
                                       target="_blank"
                                       class="block w-full">
                                        <span class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-t-md">
                                            <svg class="w-4 h-4 text-sky-500 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                                            </svg>
                                            <span>Twitter</span>
                                        </span>
                                    </a>
                                    {{-- URLコピー --}}
                                    <button @click="copyToClipboard(getArchiveUrl(ts.video_id, ts.ts_num)); $dispatch('show-toast', { message: 'URLをコピーしました', type: 'success' })"
                                            class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-b-md w-full text-left">
                                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                        </svg>
                                        URLをコピー
                                    </button>
                                </div>
                            </template>
                            </div>
                        </div>
                    </div>
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
