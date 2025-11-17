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
            </h2>
            <h2 class="text-gray-500 justify-self-center sm:hidden">
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 hover:opacity-80">
                    <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                </a>
            </h2>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
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
                                        <div class="flex flex-col sm:flex-row sm:items-center gap-1">
                                            <div class="flex items-baseline gap-2">
                                                <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                                    target="_blank" rel="noopener noreferrer" class="text-blue-500 tabular-nums hover:underline"
                                                    x-text="tsItem.ts_text || '0:00:00'">
                                                </a>
                                                <span x-text="tsItem.text || ''"></span>
                                            </div>
                                            <!-- 配信リンク -->
                                            <template x-if="tsItem.song">
                                                <div class="flex flex-wrap items-center gap-2 ml-2">
                                                    <!-- Spotify -->
                                                    <a :href="getSpotifyUrl(tsItem.song)"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="inline-flex items-center gap-1 text-xs text-green-600 hover:text-green-700 dark:text-green-400 dark:hover:text-green-300 hover:underline">
                                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                                                        </svg>
                                                        <span>Spotify</span>
                                                    </a>
                                                    <!-- Apple Music -->
                                                    <a :href="getAppleMusicUrl(tsItem.song)"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="inline-flex items-center gap-1 text-xs text-pink-600 hover:text-pink-700 dark:text-pink-400 dark:hover:text-pink-300 hover:underline">
                                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M23.994 6.124a9.23 9.23 0 00-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043a5.022 5.022 0 00-1.877-.726 10.496 10.496 0 00-1.564-.15c-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026-.747.043-1.49.123-2.193.4-1.336.53-2.3 1.452-2.865 2.78-.192.448-.292.925-.363 1.408a10.61 10.61 0 00-.1 1.18c0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.802.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03a12.5 12.5 0 001.57-.1c.822-.106 1.596-.35 2.295-.81a5.046 5.046 0 001.88-2.207c.186-.42.293-.87.344-1.328.077-.69.098-1.383.098-2.076.007-2.84 0-5.68 0-8.52 0-.026-.007-.053-.01-.08zM14.55 14.615c-.017 1.378-1.093 2.388-2.555 2.388-1.434 0-2.52-.99-2.52-2.294 0-1.305 1.086-2.294 2.52-2.294.213 0 .427.033.64.098V9.47c0-.05-.007-.1-.01-.148-.01-.133-.08-.204-.214-.214-.36-.027-.72-.054-1.08-.08l-2.127-.16c-.43-.033-.643.18-.643.61v8.078c0 .02-.003.04-.007.06-.016 1.378-1.092 2.388-2.554 2.388-1.434 0-2.52-.99-2.52-2.294 0-1.305 1.086-2.294 2.52-2.294.213 0 .427.033.64.098V7.118c0-.646.27-.916.917-.916h3.473c.646 0 .916.27.916.917v7.496z"/>
                                                        </svg>
                                                        <span>Apple</span>
                                                    </a>
                                                    <!-- YouTube Music -->
                                                    <a :href="getYouTubeMusicUrl(tsItem.song)"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:underline">
                                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/>
                                                        </svg>
                                                        <span>YouTube</span>
                                                    </a>
                                                    <!-- Amazon Music -->
                                                    <a :href="getAmazonMusicUrl(tsItem.song)"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="inline-flex items-center gap-1 text-xs text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 hover:underline">
                                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M18.996 23.436c-4.428 0-8.232-1.632-11.412-4.896-.36-.36-.036-.852.396-.576 4.968 2.88 11.124 4.62 17.496 4.62 4.284 0 9-0.888 13.32-2.736.648-0.288 1.188 0.432 0.54 0.936-3.888 3.168-9.036 4.68-13.716 4.68zM20.844 21.42c-0.576-0.72-3.78-0.36-5.22-0.18-0.432 0.036-0.504-0.324-0.108-0.612 2.556-1.8 6.756-1.284 7.236-0.684 0.48 0.612-0.144 4.788-2.52 6.78-0.36 0.324-0.72 0.144-0.54-0.252 0.54-1.404 1.764-4.536 1.152-5.052zM19.152 3.276V1.692c0-0.252 0.18-0.396 0.396-0.396h7.02c0.216 0 0.396 0.18 0.396 0.396v1.368c0 0.216-0.18 0.504-0.504 0.936L21.78 10.368c1.476-0.036 3.036 0.18 4.368 0.936 0.288 0.144 0.36 0.36 0.396 0.576v1.692c0 0.216-0.252 0.468-0.504 0.324-2.052-1.08-4.788-1.188-7.056 0.036-0.216 0.108-0.468-0.108-0.468-0.324v-1.62c0-0.252 0-0.684 0.252-1.08l5.904-8.46h-3.636c-0.216 0-0.396-0.18-0.396-0.396z"/>
                                                        </svg>
                                                        <span>Amazon</span>
                                                    </a>
                                                    <!-- LINE MUSIC -->
                                                    <a :href="getLineMusicUrl(tsItem.song)"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="inline-flex items-center gap-1 text-xs text-lime-600 hover:text-lime-700 dark:text-lime-400 dark:hover:text-lime-300 hover:underline">
                                                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
                                                        </svg>
                                                        <span>LINE</span>
                                                    </a>
                                                </div>
                                            </template>
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
                                <div class="flex-shrink-0 w-full sm:w-[300px]">
                                    <div class="truncate" :title="ts.mapping?.song ? `${ts.mapping.song.title} / ${ts.mapping.song.artist}` : ts.text">
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
                                    <!-- 配信リンク -->
                                    <template x-if="ts.mapping?.song">
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <!-- Spotify -->
                                            <a :href="getSpotifyUrl(ts.mapping.song)"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 text-xs text-green-600 hover:text-green-700 dark:text-green-400 dark:hover:text-green-300 hover:underline">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                                                </svg>
                                                <span>Spotify</span>
                                            </a>
                                            <!-- Apple Music -->
                                            <a :href="getAppleMusicUrl(ts.mapping.song)"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 text-xs text-pink-600 hover:text-pink-700 dark:text-pink-400 dark:hover:text-pink-300 hover:underline">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M23.994 6.124a9.23 9.23 0 00-.24-2.19c-.317-1.31-1.062-2.31-2.18-3.043a5.022 5.022 0 00-1.877-.726 10.496 10.496 0 00-1.564-.15c-.04-.003-.083-.01-.124-.013H5.986c-.152.01-.303.017-.455.026-.747.043-1.49.123-2.193.4-1.336.53-2.3 1.452-2.865 2.78-.192.448-.292.925-.363 1.408a10.61 10.61 0 00-.1 1.18c0 .032-.007.062-.01.093v12.223c.01.14.017.283.027.424.05.815.154 1.624.497 2.373.65 1.42 1.738 2.353 3.234 2.802.42.127.856.187 1.293.228.555.053 1.11.06 1.667.06h11.03a12.5 12.5 0 001.57-.1c.822-.106 1.596-.35 2.295-.81a5.046 5.046 0 001.88-2.207c.186-.42.293-.87.344-1.328.077-.69.098-1.383.098-2.076.007-2.84 0-5.68 0-8.52 0-.026-.007-.053-.01-.08zM14.55 14.615c-.017 1.378-1.093 2.388-2.555 2.388-1.434 0-2.52-.99-2.52-2.294 0-1.305 1.086-2.294 2.52-2.294.213 0 .427.033.64.098V9.47c0-.05-.007-.1-.01-.148-.01-.133-.08-.204-.214-.214-.36-.027-.72-.054-1.08-.08l-2.127-.16c-.43-.033-.643.18-.643.61v8.078c0 .02-.003.04-.007.06-.016 1.378-1.092 2.388-2.554 2.388-1.434 0-2.52-.99-2.52-2.294 0-1.305 1.086-2.294 2.52-2.294.213 0 .427.033.64.098V7.118c0-.646.27-.916.917-.916h3.473c.646 0 .916.27.916.917v7.496z"/>
                                                </svg>
                                                <span>Apple</span>
                                            </a>
                                            <!-- YouTube Music -->
                                            <a :href="getYouTubeMusicUrl(ts.mapping.song)"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 text-xs text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 hover:underline">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M12 0C5.376 0 0 5.376 0 12s5.376 12 12 12 12-5.376 12-12S18.624 0 12 0zm0 19.104c-3.924 0-7.104-3.18-7.104-7.104S8.076 4.896 12 4.896s7.104 3.18 7.104 7.104-3.18 7.104-7.104 7.104zm0-13.332c-3.432 0-6.228 2.796-6.228 6.228S8.568 18.228 12 18.228s6.228-2.796 6.228-6.228S15.432 5.772 12 5.772zM9.684 15.54V8.46L15.816 12l-6.132 3.54z"/>
                                                </svg>
                                                <span>YouTube</span>
                                            </a>
                                            <!-- Amazon Music -->
                                            <a :href="getAmazonMusicUrl(ts.mapping.song)"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 text-xs text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 hover:underline">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M18.996 23.436c-4.428 0-8.232-1.632-11.412-4.896-.36-.36-.036-.852.396-.576 4.968 2.88 11.124 4.62 17.496 4.62 4.284 0 9-0.888 13.32-2.736.648-0.288 1.188 0.432 0.54 0.936-3.888 3.168-9.036 4.68-13.716 4.68zM20.844 21.42c-0.576-0.72-3.78-0.36-5.22-0.18-0.432 0.036-0.504-0.324-0.108-0.612 2.556-1.8 6.756-1.284 7.236-0.684 0.48 0.612-0.144 4.788-2.52 6.78-0.36 0.324-0.72 0.144-0.54-0.252 0.54-1.404 1.764-4.536 1.152-5.052zM19.152 3.276V1.692c0-0.252 0.18-0.396 0.396-0.396h7.02c0.216 0 0.396 0.18 0.396 0.396v1.368c0 0.216-0.18 0.504-0.504 0.936L21.78 10.368c1.476-0.036 3.036 0.18 4.368 0.936 0.288 0.144 0.36 0.36 0.396 0.576v1.692c0 0.216-0.252 0.468-0.504 0.324-2.052-1.08-4.788-1.188-7.056 0.036-0.216 0.108-0.468-0.108-0.468-0.324v-1.62c0-0.252 0-0.684 0.252-1.08l5.904-8.46h-3.636c-0.216 0-0.396-0.18-0.396-0.396z"/>
                                                </svg>
                                                <span>Amazon</span>
                                            </a>
                                            <!-- LINE MUSIC -->
                                            <a :href="getLineMusicUrl(ts.mapping.song)"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="inline-flex items-center gap-1 text-xs text-lime-600 hover:text-lime-700 dark:text-lime-400 dark:hover:text-lime-300 hover:underline">
                                                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.346 0 .627.285.627.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
                                                </svg>
                                                <span>LINE</span>
                                            </a>
                                        </div>
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
