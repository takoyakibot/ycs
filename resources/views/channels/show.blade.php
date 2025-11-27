<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            // initで取得するのでこちらはコメントアウト
            // window.archives = @json($archives ?? []);
        </script>
        @vite('resources/js/channels/archive-list.js')
    </x-slot>

    <div class="px-2 sm:px-6 py-2 sm:py-6 transition-all duration-300"
         :style="showDistributionPanel && activeTab === 'timestamps' ? 'padding-bottom: 10rem;' : ''"
         x-data="archiveListComponent">
        <div class="p-2">
            <!-- デスクトップ表示: 全体を中央寄せ -->
            <div class="flex justify-center">
                <div class="text-gray-500 flex items-center gap-4 hidden sm:flex">
                    <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                    <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="hover:opacity-80">
                        Youtubeチャンネルはこちら
                    </a>
                    <!-- 区切り線 -->
                    <div class="h-8 w-px bg-gray-300 dark:bg-gray-600 mx-2"></div>
                    <!-- デスクトップ用切り替えボタン -->
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
            <!-- モバイル表示: 変更なし -->
            <h2 class="text-gray-500 justify-self-center sm:hidden">
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 hover:opacity-80">
                    <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                </a>
            </h2>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            <!-- タブUI（モバイル専用） -->
            <div class="mb-4 sm:hidden">
                <nav class="flex space-x-4 border-b border-gray-200 dark:border-gray-700">
                    <button @click="activeTab = 'timestamps'"
                            :class="activeTab === 'timestamps' ? 'border-green-500 text-green-600 dark:text-green-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px hover:text-gray-700 dark:hover:text-gray-300">
                        タイムスタンプ
                    </button>
                    <button @click="activeTab = 'archives'"
                            :class="activeTab === 'archives' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px hover:text-gray-700 dark:hover:text-gray-300">
                        アーカイブ
                    </button>
                </nav>
            </div>

            <!-- 統一検索ボックス -->
            <div class="search-unified">
                <form @submit.prevent="activeTab === 'archives' ? archiveSearch() : searchTimestamps()" class="flex items-stretch sm:items-center gap-2 max-w-7lg">
                    <div class="flex gap-2 w-full sm:flex-grow flex-col sm:flex-row">
                        <!-- アーカイブタブ用の検索ボックス -->
                        <template x-if="activeTab === 'archives'">
                            <input
                                type="text"
                                x-model="archiveQuery"
                                placeholder="タイムスタンプを検索"
                                aria-label="タイムスタンプを検索"
                                class="border p-2 rounded w-full dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100" />
                        </template>
                        <!-- タイムスタンプタブ用の検索ボックス -->
                        <template x-if="activeTab === 'timestamps'">
                            <input
                                type="text"
                                x-model="searchQuery"
                                placeholder="楽曲名・アーティスト名・タイムスタンプで検索..."
                                aria-label="楽曲名・アーティスト名・タイムスタンプで検索"
                                maxlength="255"
                                class="border p-2 rounded w-full dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100" />
                        </template>
                        <template x-if="activeTab === 'archives'">
                            <div class="flex flex-row gap-2">
                                <select x-model="tsFlg" aria-label="タイムスタンプフィルター" class="border p-2 pr-8 rounded dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100">
                                    <option value="">タイムスタンプ</option>
                                    <option value="1">有のみ</option>
                                    <option value="2">無のみ</option>
                                </select>
                            </div>
                        </template>
                    </div>
                    <template x-if="activeTab === 'archives'">
                        <button
                            type="submit"
                            class="bg-blue-500 text-white px-4 py-2 rounded sm:min-w-[100px] hover:bg-blue-600">
                            検索
                        </button>
                    </template>
                    <template x-if="activeTab === 'timestamps'">
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="searchQuery = ''"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 whitespace-nowrap">
                                クリア
                            </button>
                            <!-- 件数表示 -->
                            <span class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap"
                                  x-text="timestamps.total !== undefined ? `${timestamps.total}件` : ''"></span>
                            <!-- ダウンロードボタン -->
                            <button
                                type="button"
                                @click="downloadTimestamps()"
                                :disabled="loading || !timestamps.total"
                                :class="loading || !timestamps.total ? 'opacity-50 cursor-not-allowed' : ''"
                                class="hidden sm:flex bg-green-600 text-white px-3 py-2 rounded hover:bg-green-700 items-center gap-1 whitespace-nowrap text-sm"
                                title="全タイムスタンプをテキストファイルとしてダウンロード">
                                📥
                            </button>
                        </div>
                    </template>
                </form>
            </div>

            <!-- アーカイブタブ -->
            <div x-show="activeTab === 'archives'">
            <x-pagination
                :total="0"
                :current-page="1"
                :last-page="1"
            ></x-pagination>
            <div id="archives" x-data="{ isFiltered : false }"
             @filter-changed.window="isFiltered = $event.detail"
             class="flex flex-col items-center w-[100%]">
                <!-- アーカイブリスト -->
                <template x-for="archive in (archives.data || [])" :key="archive.id">
                    <div class="archive flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-2 bg-white">
                        <div class="flex flex-col flex-shrink-0" :class="isFiltered ? 'sm:w-1/2' : 'sm:w-1/3'">
                            <div class="flex gap-2" :class="isFiltered ? 'flex-row' : 'flex-col'">
                                <a :href="getArchiveUrl(archive.video_id || '')" target="_blank" rel="noopener noreferrer" :class="isFiltered ? 'w-1/4' : 'h-auto'" >
                                    <img :src="escapeHTML(archive.thumbnail || '')" alt="サムネイル" loading="lazy"
                                        class="rounded-md object-cover flex flex-shrink-0"/>
                                </a>
                                <div :class="isFiltered ? 'w-3/4' : ''">
                                    <h4 class="font-semibold text-gray-800 cursor-pointer hover:text-blue-600 transition-colors transition-all duration-200 ease-in-out"
                                        x-data="{ expanded: false }"
                                        :class="expanded ? '' : 'truncate'"
                                        :title="archive.title || ''"
                                        @click="expanded = !expanded"
                                        role="button"
                                        tabindex="0"
                                        :aria-expanded="expanded"
                                        aria-label="タイトルを展開/折りたたみ"
                                        @keydown.enter="expanded = !expanded"
                                        @keydown.space.prevent="expanded = !expanded"
                                        x-text="archive.title || ''">
                                    </h4>
                                    <p class="text-sm text-gray-600"
                                        :title="'元の値: ' + (archive.published_at || '')"
                                        x-text="'公開日: ' + formatPublishedDate(archive.published_at)"></p>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col flex-grow gap-2" :class="isFiltered ? 'sm:w-1/2' : 'sm:w-2/3'">
                            <div class="timestamps flex flex-col gap-2 sm:gap-0">
                                <template x-for="tsItem in archive.ts_items_display" :key="tsItem.id">
                                    <div class="timestamp text-sm text-gray-700">
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-1">
                                            <div class="flex items-baseline gap-2">
                                                <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                                    target="_blank" rel="noopener noreferrer" class="text-blue-500 tabular-nums hover:underline flex-shrink-0"
                                                    x-text="tsItem.ts_text || '0:00:00'">
                                                </a>
                                                <div class="flex-1 cursor-pointer hover:text-blue-600 dark:hover:text-blue-400 transition-colors"
                                                     @click="tsItem.song ? selectSong(tsItem.song, tsItem) : (tsItem.text ? selectText(tsItem.text, tsItem) : null)"
                                                     :title="tsItem.song ? `配信サービスで聴く: ${tsItem.song.title} / ${tsItem.song.artist}` : (tsItem.text ? `配信サービスで検索: ${tsItem.text}` : '')">
                                                    <span x-text="tsItem.text || ''"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <x-pagination
                :total="0"
                :current-page="1"
                :last-page="1"
            ></x-pagination>
            </div>

            <!-- タイムスタンプタブ -->
            <div x-show="activeTab === 'timestamps'">
                <!-- ページネーション（上） -->
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

                <!-- 頭文字フィルタナビゲーション -->
                <div x-show="timestamps.available_indexes && timestamps.available_indexes.length > 0" class="mb-4 hidden sm:block">
                    <div class="flex flex-wrap items-center gap-1">
                        <span class="text-xs text-gray-500 dark:text-gray-400 mr-1">絞り込み:</span>
                        <!-- すべて -->
                        <button
                            @click="clearIndexFilter()"
                            :class="!selectedIndex
                                ? 'bg-gray-600 text-white cursor-pointer'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-300 dark:hover:bg-gray-600 cursor-pointer'"
                            class="px-2 h-7 text-xs rounded transition-colors">
                            すべて
                        </button>
                        <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
                        <!-- 数字 -->
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
                        <!-- アルファベット（ABCDE, FGHIJ, KLMNO, PQRST, UVWXYZ） -->
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
                        <!-- 五十音 -->
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
                        <!-- 漢字・その他 -->
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

                <!-- エラー表示 -->
                <div x-show="error" class="bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 rounded p-4 mb-4">
                    <p class="text-red-800 dark:text-red-200" x-text="error"></p>
                </div>

                <!-- ローディング表示 -->
                <div x-show="loading" class="flex justify-center items-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
                    <span class="ml-2 text-gray-600 dark:text-gray-400">読み込み中...</span>
                </div>

                <!-- タイムスタンプ一覧 -->
                <div x-show="!loading" class="flex flex-col gap-2">
                    <!-- 空状態メッセージ -->
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
                             :class="{
                                 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-400': selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title)),
                                 'bg-gray-200 dark:bg-gray-700 border-gray-300 dark:border-gray-500': ts.has_pending_report && !(selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title))),
                                 'dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 active:bg-gray-100 dark:active:bg-gray-600': !ts.has_pending_report && !(selectedSong && ((ts.mapping?.song && ts.mapping.song.title === selectedSong.title && ts.mapping.song.artist === selectedSong.artist) || (!ts.mapping?.song && ts.text === selectedSong.title)))
                             }">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                                <!-- 楽曲情報 -->
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

                                <!-- アーカイブタイトル & 公開日: モバイルでは非表示 -->
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

                                <!-- 動画リンク: YouTubeらしい赤いボタン（報告済みの場合は暗め） -->
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

                <!-- ページネーション（下） -->
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
        </div>

        <!-- 報告モーダル -->
        <div x-show="showReportModal"
             x-cloak
             class="fixed inset-0 z-50 overflow-y-auto"
             role="dialog"
             aria-modal="true"
             aria-labelledby="report-modal-title"
             @click.self="showReportModal = false"
             @keydown.escape.window="showReportModal = false">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <!-- 背景オーバーレイ -->
                <div x-show="showReportModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75 dark:bg-gray-900 dark:bg-opacity-75"
                     @click="showReportModal = false"></div>

                <!-- モーダルコンテンツ -->
                <div x-show="showReportModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">

                    <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 id="report-modal-title" class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            タイムスタンプの報告
                        </h3>

                        <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded text-sm">
                            <div class="text-gray-700 dark:text-gray-300" x-text="reportTarget?.text || ''"></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1" x-text="reportTarget?.ts_text || ''"></div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                報告の種類を選択してください
                            </label>
                            <select x-model="reportType"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                                <option value="">選択してください</option>
                                <option value="wrong_song">表示される楽曲名が違う</option>
                                <option value="not_song">楽曲ではない</option>
                                <option value="not_timestamp">タイムスタンプではない</option>
                                <option value="problem">問題がある</option>
                                <option value="other">その他</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                詳細（任意）
                            </label>
                            <textarea x-model="reportComment"
                                      rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300"
                                      placeholder="詳細な情報があれば記入してください"></textarea>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                        <button @click="submitReport()"
                                :disabled="!reportType"
                                :class="!reportType ? 'opacity-50 cursor-not-allowed' : 'hover:bg-red-700'"
                                class="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            報告する
                        </button>
                        <button @click="showReportModal = false"
                                class="w-full sm:w-auto mt-3 sm:mt-0 px-4 py-2 bg-white dark:bg-gray-600 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-500 rounded-md hover:bg-gray-50 dark:hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                            キャンセル
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 配信リンクパネル（タイムスタンプタブのみ表示） -->
        <div x-show="showDistributionPanel && selectedSong && activeTab === 'timestamps'"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="transform translate-y-full"
             x-transition:enter-end="transform translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="transform translate-y-0"
             x-transition:leave-end="transform translate-y-full"
             class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-800 border-t-2 border-gray-300 dark:border-gray-600 shadow-lg z-50 px-4 py-3">
            <div class="max-w-7xl mx-auto">
                <!-- ヘッダー: 動画コントロール / 楽曲タイトル / 閉じる -->
                <div class="flex items-center justify-between mb-2 gap-2">
                    <!-- 動画コントロール -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <!-- 自動再生チェック（モバイルでは非表示） -->
                        <label x-show="!isMobile" class="hidden sm:inline-flex items-center gap-1 cursor-pointer select-none">
                            <input type="checkbox"
                                   x-model="autoPlay"
                                   @change="saveAutoPlay()"
                                   class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                            <span class="text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">自動再生</span>
                        </label>
                        <!-- 再生/停止ボタン -->
                        <button @click="togglePlayPause()"
                                :disabled="!selectedTimestamp?.video_id"
                                :class="!selectedTimestamp?.video_id ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-gray-600'"
                                class="inline-flex items-center gap-1 px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs transition-colors"
                                :title="isPlaying ? '停止' : '再生'">
                            <!-- 再生アイコン -->
                            <svg x-show="!isPlaying" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                            <!-- 停止アイコン -->
                            <svg x-show="isPlaying" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                            </svg>
                            <span class="hidden sm:inline" x-text="isPlaying ? '停止' : '再生'"></span>
                        </button>
                    </div>
                    <!-- 楽曲タイトル -->
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate flex-1"
                         x-text="selectedSong ? `${selectedSong.title}${selectedSong.artist ? ' / ' + selectedSong.artist : ''}` : ''"></div>
                    <button @click="closePanel()"
                            class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 p-1 flex-shrink-0"
                            aria-label="閉じる">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- コンテンツ: 配信サービス / 報告 -->
                <div class="flex flex-wrap gap-3 items-start">
                    <!-- 配信サービスボタン群 -->
                    <div class="flex flex-wrap gap-2 flex-1">
                        <!-- Spotify -->
                        <a :href="getSpotifyUrl(selectedSong)"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm rounded-md transition-colors">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                            </svg>
                            <span class="hidden sm:inline">Spotify</span>
                        </a>
                        <!-- Apple Music -->
                        <a :href="getAppleMusicUrl(selectedSong)"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-pink-600 hover:bg-pink-700 text-white text-sm rounded-md transition-colors">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M23.997 6.124c0-.738-.065-1.47-.24-2.19-.317-1.31-1.062-2.31-2.18-3.043C21.003.517 20.373.285 19.7.164c-.517-.093-1.038-.135-1.564-.15-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026C4.786.07 4.043.15 3.34.428 2.004.958 1.04 1.88.475 3.208c-.192.448-.292.925-.363 1.408-.056.392-.088.785-.1 1.18 0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.801.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03c.525 0 1.048-.034 1.57-.1.823-.106 1.597-.35 2.296-.81a5.08 5.08 0 0 0 1.88-2.207c.186-.42.293-.87.37-1.324.113-.675.138-1.358.137-2.04-.002-3.8 0-7.595-.003-11.393zm-6.423 3.99v5.712c0 .417-.058.827-.244 1.206-.29.59-.76 1.035-1.388 1.29-.47.19-.96.27-1.46.27-.93 0-1.72-.407-2.22-1.24-.34-.565-.435-1.187-.39-1.822.09-1.232.85-2.011 2.07-2.067.582-.027 1.164-.017 1.745-.017.153 0 .306-.01.46-.016v-3.46c0-.08-.018-.097-.096-.086-.86.12-1.72.24-2.58.36-.86.12-1.72.24-2.58.36-.085.01-.106.04-.105.124.002 2.644 0 5.29 0 7.934 0 .4-.06.796-.24 1.167-.283.585-.756 1.026-1.38 1.278-.474.192-.965.273-1.47.273-.93 0-1.717-.408-2.216-1.242-.34-.566-.435-1.188-.39-1.823.09-1.232.85-2.01 2.07-2.066.582-.027 1.164-.018 1.745-.018.153 0 .306-.01.46-.016V7.21c0-.08.018-.097.096-.086 1.72.24 3.44.48 5.16.72.86.12 1.72.24 2.58.36.085.01.106-.04.105-.124-.002-.645 0-1.29 0-1.935z"/>
                            </svg>
                            <span class="hidden sm:inline">Apple</span>
                        </a>
                        <!-- YouTube Music -->
                        <a :href="getYouTubeMusicUrl(selectedSong)"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-red-500 hover:bg-red-600 text-white text-sm rounded-md transition-colors">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/>
                            </svg>
                            <span class="hidden sm:inline">YT Music</span>
                        </a>
                        <!-- Amazon Music -->
                        <a :href="getAmazonMusicUrl(selectedSong)"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-md transition-colors">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M13.55 17.526L10.2 15.3v-2.4l5.7 3.4v2.8l-2.35-1.574zM10.2 8.7v2.4l3.35 2.226 2.35-1.574v-2.8l-5.7 3.4V8.7zm8.4 7.674l-6.05 4.026v2.4l8.45-5.626v-2.8l-2.4 1.6zm-2.4-10.8L10.2 2.3v2.4l5.7 3.8 2.3-1.926V3.774L16.2 5.574z"/>
                            </svg>
                            <span class="hidden sm:inline">Amazon</span>
                        </a>
                        <!-- LINE MUSIC -->
                        <a :href="getLineMusicUrl(selectedSong)"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-green-500 hover:bg-green-600 text-white text-sm rounded-md transition-colors">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
                            </svg>
                            <span class="hidden sm:inline">LINE</span>
                        </a>
                    </div>
                    <!-- 報告ボタン（タイムスタンプ選択時のみ） -->
                    <template x-if="selectedTimestamp">
                        <div class="flex-shrink-0">
                            <template x-if="selectedTimestamp.has_pending_report">
                                <div class="inline-flex items-center gap-1 px-2 py-2 bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs rounded-md">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <span class="hidden sm:inline">報告済み</span>
                                </div>
                            </template>
                            <template x-if="!selectedTimestamp.has_pending_report">
                                <button @click="openReportModal(selectedTimestamp)"
                                        class="inline-flex items-center gap-1 px-2 py-2 bg-gray-400 hover:bg-gray-500 text-white text-xs rounded-md transition-colors"
                                        title="このタイムスタンプを報告">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span class="hidden sm:inline">報告</span>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- PIP風動画プレイヤー -->
        <div x-show="showVideoPlayer"
             x-ref="videoPlayer"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95"
             class="fixed z-50 shadow-2xl rounded-lg overflow-hidden w-[320px] max-w-[calc(100vw-2rem)]"
             :class="playerPosition.x === null ? (showDistributionPanel ? 'bottom-28 right-4' : 'bottom-4 right-4') : ''"
             :style="getPlayerStyle()">
            <!-- プレイヤーヘッダー（ドラッグ可能） -->
            <div class="bg-gray-800 text-white px-2 py-1 flex items-center justify-between cursor-move select-none"
                 @mousedown="startDrag($event)"
                 @touchstart="startDrag($event)">
                <span class="text-xs truncate flex-1"
                      x-text="selectedSong ? `${selectedSong.title}${selectedSong.artist ? ' / ' + selectedSong.artist : ''}` : '動画プレビュー'"></span>
                <button @click="closeVideoPlayer()"
                        @mousedown.stop
                        @touchstart.stop
                        class="text-gray-400 hover:text-white p-1"
                        aria-label="プレイヤーを閉じる">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <!-- YouTube Player -->
            <div class="bg-black" style="aspect-ratio: 16/9;">
                <div id="youtube-player"></div>
            </div>
        </div>

        <!-- 戻すボタン（パネル非表示時、タイムスタンプタブのみ） -->
        <button x-show="panelDismissed && !showDistributionPanel && activeTab === 'timestamps'"
                @click="openPanel()"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="fixed bottom-4 right-4 p-2 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg z-40 transition-colors"
                title="配信リンクを表示"
                aria-label="配信リンクを表示">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>
            </svg>
        </button>
    </div>
</x-app-layout>
