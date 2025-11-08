<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            // initで取得するのでこちらはコメントアウト
            // window.archives = @json($archives ?? []);
        </script>
        @vite('resources/js/channels/archive-list.js')
    </x-slot>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('アーカイブ一覧') }}
        </h2>
    </x-slot>

    <div class="px-2 sm:px-6 py-4 sm:py-12" x-data="archiveListComponent">
        <div class="p-2">
            <h2 class="text-gray-500 items-center justify-center gap-4 hidden sm:flex">
                <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="hover:opacity-80">
                    Youtubeチャンネルはこちら
                </a>
            </h2>
            <h2 class="text-gray-500 justify-self-center sm:hidden">
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 hover:opacity-80">
                    <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                </a>
            </h2>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            <x-search
                :channel-id="$channel->handle"
                placeholder="タイムスタンプを検索"
                button-text="検索"
                manage-flg=""
                alpine-parent="archiveListComponent"
            />

            <!-- タブUI -->
            <div class="mb-4">
                <nav class="flex space-x-4 border-b border-gray-200 dark:border-gray-700">
                    <button @click="activeTab = 'archives'"
                            :class="activeTab === 'archives' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px hover:text-gray-700 dark:hover:text-gray-300">
                        アーカイブ
                    </button>
                    <button @click="activeTab = 'timestamps'"
                            :class="activeTab === 'timestamps' ? 'border-green-500 text-green-600 dark:text-green-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px hover:text-gray-700 dark:hover:text-gray-300">
                        タイムスタンプ
                    </button>
                </nav>
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
                                    <h4 class="font-semibold text-gray-800 line-clamp-2" x-text="archive.title || ''"></h4>
                                    <p class="text-sm text-gray-600"
                                        x-text="'公開日: ' + (new Date(archive.published_at || 0)).toLocaleString().split(' ')[0]"></p>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col flex-grow gap-2" :class="isFiltered ? 'sm:w-1/2' : 'sm:w-2/3'">
                            <div class="timestamps flex flex-col gap-2 sm:gap-0">
                                <template x-for="tsItem in archive.ts_items_display" :key="tsItem.id">
                                    <div class="timestamp text-sm text-gray-700">
                                        <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                            target="_blank" rel="noopener noreferrer" class="text-blue-500 tabular-nums hover:underline"
                                            x-text="tsItem.ts_text || '0:00:00'">
                                        </a> <span class="ml-2" x-text="tsItem.text || ''"></span>
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
                <!-- 検索エリア -->
                <div class="mb-4">
                    <div class="flex gap-2">
                        <input type="text"
                               x-model="searchQuery"
                               placeholder="楽曲名・アーティスト名・タイムスタンプで検索..."
                               class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <button @click="searchQuery = ''"
                                class="px-3 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 text-sm">
                            クリア
                        </button>
                    </div>
                    <div class="mt-2 flex justify-between items-center">
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <span x-show="searchQuery">検索結果: </span>
                            <span x-text="timestamps.total !== undefined ? `${timestamps.total}件` : ''"></span>
                        </div>
                        <div class="flex gap-2 items-center">
                            <label class="text-xs sm:text-sm text-gray-600 dark:text-gray-400">並び替え:</label>
                            <select x-model="timestampSort"
                                    @change="fetchTimestamps(1, searchQuery)"
                                    class="px-2 py-1 sm:px-3 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="time_desc">時刻↓</option>
                                <option value="time_asc">時刻↑</option>
                                <option value="song_asc">楽曲名</option>
                                <option value="archive_desc">公開日</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ページネーション（上） -->
                <div class="flex justify-center gap-2 mb-4">
                    <button @click="fetchTimestamps(1, searchQuery)"
                            :disabled="timestamps.current_page <= 1"
                            :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        最初
                    </button>
                    <button @click="fetchTimestamps(timestamps.current_page - 1, searchQuery)"
                            :disabled="timestamps.current_page <= 1"
                            :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        前へ
                    </button>
                    <span class="px-3 py-1 text-sm font-medium" x-text="`${timestamps.current_page || 1} / ${timestamps.last_page || 1}`"></span>
                    <button @click="fetchTimestamps(timestamps.current_page + 1, searchQuery)"
                            :disabled="timestamps.current_page >= timestamps.last_page"
                            :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        次へ
                    </button>
                    <button @click="fetchTimestamps(timestamps.last_page, searchQuery)"
                            :disabled="timestamps.current_page >= timestamps.last_page"
                            :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        最後
                    </button>
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
                        <div class="p-2 border rounded hover:bg-gray-50 dark:hover:bg-gray-700 active:bg-gray-100 dark:active:bg-gray-600 dark:border-gray-600 transition-colors"
                             :class="{'bg-green-50 dark:bg-green-900/20': ts.mapping?.song}">
                            <div class="flex items-center gap-2 sm:gap-3">
                                <!-- ステータスアイコン -->
                                <div class="flex-shrink-0 text-xs sm:text-base">
                                    <template x-if="ts.mapping?.song">
                                        <span class="text-green-600 dark:text-green-400" title="楽曲紐づけ済み">✓</span>
                                    </template>
                                    <template x-if="!ts.mapping">
                                        <span class="text-gray-400" title="未紐づけ">○</span>
                                    </template>
                                </div>

                                <!-- 楽曲情報: モバイル30%, タブレット以上40% -->
                                <div class="truncate flex-shrink-0 w-[30%] sm:w-[40%]"
                                     :title="ts.mapping?.song ? `${ts.mapping.song.title} / ${ts.mapping.song.artist}` : ts.text">
                                    <template x-if="ts.mapping?.song">
                                        <span>
                                            <span class="font-medium text-xs sm:text-sm" x-text="ts.mapping.song.title"></span>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs sm:text-sm"> / </span>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs sm:text-sm" x-text="ts.mapping.song.artist"></span>
                                        </span>
                                    </template>
                                    <template x-if="!ts.mapping?.song">
                                        <span class="text-xs sm:text-sm text-gray-700 dark:text-gray-300" x-text="ts.text"></span>
                                    </template>
                                </div>

                                <!-- アーカイブタイトル: モバイルでは非表示 -->
                                <div class="hidden sm:block text-sm text-gray-600 dark:text-gray-400 truncate flex-1"
                                     :title="ts.archive.title"
                                     x-text="ts.archive.title">
                                </div>

                                <!-- 動画リンク: モバイルではコンパクト -->
                                <a :href="getYoutubeUrl(ts.video_id, ts.ts_num)"
                                   class="text-blue-500 hover:underline whitespace-nowrap tabular-nums text-xs sm:text-sm"
                                   target="_blank"
                                   x-text="ts.ts_text + ' ↗'">
                                </a>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- ページネーション（下） -->
                <div class="flex justify-center gap-2 mt-4">
                    <button @click="fetchTimestamps(1, searchQuery); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                            :disabled="timestamps.current_page <= 1"
                            :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        最初
                    </button>
                    <button @click="fetchTimestamps(timestamps.current_page - 1, searchQuery); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                            :disabled="timestamps.current_page <= 1"
                            :class="timestamps.current_page <= 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        前へ
                    </button>
                    <span class="px-3 py-1 text-sm font-medium" x-text="`${timestamps.current_page || 1} / ${timestamps.last_page || 1}`"></span>
                    <button @click="fetchTimestamps(timestamps.current_page + 1, searchQuery); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                            :disabled="timestamps.current_page >= timestamps.last_page"
                            :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        次へ
                    </button>
                    <button @click="fetchTimestamps(timestamps.last_page, searchQuery); document.querySelector('#archives').scrollIntoView({ behavior: 'auto' })"
                            :disabled="timestamps.current_page >= timestamps.last_page"
                            :class="timestamps.current_page >= timestamps.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-300 dark:hover:bg-gray-600'"
                            class="px-3 py-1 bg-gray-200 dark:bg-gray-700 rounded text-sm">
                        最後
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
