<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            // initで取得するのでこちらはコメントアウト
            // window.archives = @json($archives ?? []);
        </script>
        @vite('resources/js/channels/archive-list.js')
    </x-slot>

    <div class="px-2 sm:px-6 py-2 sm:py-6" x-data="archiveListComponent">
        <div class="p-2">
            <h2 class="text-gray-500 items-center justify-center gap-4 hidden sm:flex">
                <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="hover:opacity-80">
                    Youtubeチャンネルはこちら
                </a>
                <!-- デスクトップ用切り替えボタン -->
                <div class="flex gap-2 ml-auto hidden sm:flex">
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
            </h2>
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
                        <button
                            type="button"
                            @click="searchQuery = ''"
                            class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                            クリア
                        </button>
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
                <!-- 検索結果 -->
                <div class="mb-4">
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        <span x-show="searchQuery">検索結果: </span>
                        <span x-text="timestamps.total !== undefined ? `${timestamps.total}件` : ''"></span>
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

                <!-- 頭文字ジャンプナビゲーション（楽曲名ソート時のみ表示） -->
                <div x-show="timestampSort === 'song_asc' && timestamps.available_indexes && timestamps.available_indexes.length > 0" class="mb-4 border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div class="text-xs text-gray-600 dark:text-gray-400 mb-2">頭文字でジャンプ:</div>

                    <!-- アルファベット -->
                    <div class="flex flex-wrap gap-1 mb-2">
                        <template x-for="letter in ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z']" :key="letter">
                            <button
                                @click="jumpToIndex(letter)"
                                :disabled="!timestamps.available_indexes?.includes(letter)"
                                :class="timestamps.available_indexes?.includes(letter)
                                    ? 'bg-blue-500 hover:bg-blue-600 text-white cursor-pointer'
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed'"
                                class="w-8 h-8 text-xs rounded transition-colors">
                                <span x-text="letter"></span>
                            </button>
                        </template>
                    </div>

                    <!-- 五十音 -->
                    <div class="flex flex-wrap gap-1 mb-2">
                        <template x-for="kana in ['あ','か','さ','た','な','は','ま','や','ら','わ']" :key="kana">
                            <button
                                @click="jumpToIndex(kana)"
                                :disabled="!timestamps.available_indexes?.includes(kana)"
                                :class="timestamps.available_indexes?.includes(kana)
                                    ? 'bg-green-500 hover:bg-green-600 text-white cursor-pointer'
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed'"
                                class="w-8 h-8 text-xs rounded transition-colors">
                                <span x-text="kana"></span>
                            </button>
                        </template>
                    </div>

                    <!-- その他 -->
                    <div class="flex gap-1">
                        <button
                            @click="jumpToIndex('0-9')"
                            :disabled="!timestamps.available_indexes?.includes('0-9')"
                            :class="timestamps.available_indexes?.includes('0-9')
                                ? 'bg-purple-500 hover:bg-purple-600 text-white cursor-pointer'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed'"
                            class="px-3 py-1 text-xs rounded transition-colors">
                            0-9
                        </button>
                        <button
                            @click="jumpToIndex('その他')"
                            :disabled="!timestamps.available_indexes?.includes('その他')"
                            :class="timestamps.available_indexes?.includes('その他')
                                    ? 'bg-gray-500 hover:bg-gray-600 text-white cursor-pointer'
                                    : 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed'"
                            class="px-3 py-1 text-xs rounded transition-colors">
                            その他
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
                        <div class="p-2 border rounded hover:bg-gray-50 dark:hover:bg-gray-700 active:bg-gray-100 dark:active:bg-gray-600 dark:border-gray-600 transition-colors">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                                <!-- 楽曲情報 -->
                                <div class="truncate flex-shrink-0 w-full sm:w-[300px]"
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

                                <!-- アーカイブタイトル & 公開日: モバイルでは非表示 -->
                                <div class="hidden sm:block text-sm truncate flex-1">
                                    <div class="text-gray-600 dark:text-gray-400 truncate"
                                         :title="ts.archive.title"
                                         x-text="ts.archive.title">
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500 mt-0.5"
                                         x-text="'公開日: ' + (ts.archive.published_at ? new Date(ts.archive.published_at).toLocaleDateString() : '不明')">
                                    </div>
                                </div>

                                <!-- 動画リンク: モバイルではコンパクト -->
                                <a :href="getYoutubeUrl(ts.video_id, ts.ts_num)"
                                   class="text-blue-500 hover:underline whitespace-nowrap tabular-nums text-xs sm:text-sm"
                                   target="_blank"
                                   x-text="ts.ts_text + ' ↗'">
                                </a>

                                <!-- 報告ボタン -->
                                <button @click="openReportModal(ts)"
                                        class="text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-400 text-xs px-2 py-1 border border-gray-300 dark:border-gray-600 rounded hover:border-red-500 dark:hover:border-red-400 transition-colors whitespace-nowrap"
                                        title="問題を報告">
                                    報告
                                </button>
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

        <!-- 報告モーダル -->
        <div x-show="showReportModal"
             x-cloak
             class="fixed inset-0 z-50 overflow-y-auto"
             @click.self="showReportModal = false">
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
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
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
    </div>
</x-app-layout>
