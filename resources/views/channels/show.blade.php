<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            // initで取得するのでこちらはコメントアウト
            // window.archives = @json($archives ?? []);
        </script>
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

    <script>
        // Alpine.jsのコンポーネントを定義
        window.addEventListener('alpine:init', () => {
            Alpine.data('archiveListComponent', () => ({
                channel: window.channel || {},
                archives: window.archives || {},
                timestamps: {},
                activeTab: 'archives',
                searchQuery: '',
                searchTimeout: null,
                currentTimestampPage: 1,
                timestampSort: 'time_desc',
                loading: false,
                error: null,
                isFiltered: false,

                // ページのデータを取得するメソッド
                async fetchData(url) {
                    try {
                        const response = await fetch(url);
                        if (!response.ok) throw new Error('データ取得エラー');
                        this.archives = await response.json(); // データを更新

                        const paginationButtons = document.querySelectorAll('#paginationButtons button');
                        // ページネーションボタンを取得してイベント設定
                        paginationButtons.forEach(button => {
                            // 定義されているjsの呼び出し、初期化処理なので1ページ固定で実施
                            togglePaginationButtonDisabled(button, this.archives.current_page, this.maxPage);
                        });
                    } catch (error) {
                        console.error('データの取得に失敗しました:', error);
                    }
                },

                get maxPage() {
                    if (!this.archives.total || !this.archives.per_page) return 1;
                    return Math.ceil(this.archives.total / this.archives.per_page);
                },

                firstUrl(params) {
                    return `/api/channels/${this.channel.handle}?page=1` + (params ? `&${params}` : '');
                },

                // YouTube URLを安全に構築するヘルパー
                getYoutubeUrl(videoId, tsNum) {
                    const safeVideoId = encodeURIComponent(videoId || '');
                    const safeTsNum = parseInt(tsNum) || 0;
                    return `https://youtube.com/watch?v=${safeVideoId}&t=${safeTsNum}s`;
                },

                // タイムスタンプデータを取得するメソッド（検索・ソート対応）
                async fetchTimestamps(page = 1, search = '') {
                    try {
                        this.loading = true;
                        this.error = null;

                        const params = new URLSearchParams({
                            page: page,
                            per_page: 50,
                            sort: this.timestampSort
                        });

                        if (search) {
                            params.set('search', search);
                        }

                        const response = await fetch(`/api/channels/${this.channel.handle}/timestamps?${params}`);
                        if (!response.ok) throw new Error('タイムスタンプの取得に失敗しました');

                        const data = await response.json();

                        // ページ番号を数値として保存（文字列連結バグの防止）
                        const parsedPage = parseInt(data.current_page, 10);
                        data.current_page = Number.isNaN(parsedPage) ? 1 : parsedPage;

                        const parsedLastPage = parseInt(data.last_page, 10);
                        data.last_page = Number.isNaN(parsedLastPage) ? 1 : parsedLastPage;

                        this.timestamps = data;
                        this.currentTimestampPage = page;
                        this.updateURL();
                    } catch (error) {
                        console.error('タイムスタンプの取得に失敗しました:', error);
                        this.error = error.message;
                    } finally {
                        this.loading = false;
                    }
                },

                // 検索実行
                searchTimestamps() {
                    this.currentTimestampPage = 1;
                    this.fetchTimestamps(1, this.searchQuery);
                },

                // URLパラメータを更新
                updateURL() {
                    const params = new URLSearchParams();

                    // タブ
                    if (this.activeTab !== 'archives') {
                        params.set('view', this.activeTab);
                    }

                    // 検索キーワード
                    if (this.searchQuery) {
                        params.set('search', this.searchQuery);
                    }

                    // ソート（デフォルト以外の場合のみ）
                    if (this.timestampSort && this.timestampSort !== 'time_desc') {
                        params.set('sort', this.timestampSort);
                    }

                    // ページ番号
                    const page = this.activeTab === 'timestamps' ? this.currentTimestampPage : this.archives.current_page;
                    if (page && page > 1) {
                        params.set('page', page);
                    }

                    const paramString = params.toString();
                    const newURL = paramString ? `${window.location.pathname}?${paramString}` : window.location.pathname;
                    window.history.pushState({}, '', newURL);
                },

                // ページ遷移の処理
                // 読み込み時にボタンに割り当てる
                handlePaginationClick(event) {
                    const button = event.target;
                    const isNext = button.classList.contains('next');
                    const url = isNext ? this.archives.next_page_url : this.archives.prev_page_url;

                    // 遷移先に対応するurlがnullなら終了
                    if (!url) return;
                    // 対応するurlでfetch
                    this.fetchData(url);
                    window.scroll({top: 0, behavior: 'auto'});
                },

                // Alpine.js初期化後にイベントリスナーを設定
                init() {
                    // URLパラメータを読み取って初期化
                    const params = new URLSearchParams(window.location.search);
                    const view = params.get('view');
                    const search = params.get('search');
                    const sort = params.get('sort');
                    const page = parseInt(params.get('page')) || 1;

                    if (view === 'timestamps') {
                        this.activeTab = 'timestamps';
                        this.searchQuery = search || '';
                        this.timestampSort = sort || 'time_desc';
                        this.currentTimestampPage = page;
                        this.fetchTimestamps(page, this.searchQuery);
                    } else {
                        // 初回呼び出し（アーカイブタブ）
                        this.fetchData(this.firstUrl());
                    }

                    // 検索コンポーネントのイベントのリスナーを定義
                    this.$el.addEventListener('search-results', (e) => {
                        // 渡されたクエリを付与したurlでfetchする
                        this.fetchData(this.firstUrl(e.detail));
                    });

                    // ページネーションボタンを取得してイベント設定
                    const paginationButtons = document.querySelectorAll('#paginationButtons button');
                    paginationButtons.forEach(button => {
                        button.addEventListener('click', this.handlePaginationClick.bind(this));
                    });

                    // タブ切り替え監視
                    this.$watch('activeTab', (newTab) => {
                        if (newTab === 'timestamps' && !this.timestamps.data) {
                            this.fetchTimestamps(1, this.searchQuery);
                        }
                        this.updateURL();
                    });

                    // 検索キーワード監視（debounce）
                    this.$watch('searchQuery', () => {
                        clearTimeout(this.searchTimeout);
                        this.searchTimeout = setTimeout(() => {
                            this.searchTimestamps();
                        }, 300);
                    });

                    // ブラウザの戻る/進むボタン対応
                    window.addEventListener('popstate', () => {
                        const params = new URLSearchParams(window.location.search);
                        const view = params.get('view');
                        const search = params.get('search');
                        const sort = params.get('sort');
                        const page = parseInt(params.get('page')) || 1;

                        this.activeTab = view || 'archives';

                        if (view === 'timestamps') {
                            this.searchQuery = search || '';
                            this.timestampSort = sort || 'time_desc';
                            this.currentTimestampPage = page;
                            this.fetchTimestamps(page, search || '');
                        }
                    });
                }
            }));

        });

        // ページネーションボタンのクラス修正
        // 受け取ったボタンに対して、nextのボタンなら現在のページが最大値のときにdisabled、
        // それ以外（previewのボタン）なら1ページのときにdisabledとする
        function togglePaginationButtonDisabled(button, newPage, maxPage) {
            const isNext = button.classList.contains('next');
            if (!isNext && 1 < newPage || isNext && newPage < maxPage) {
                button.classList.remove('pagination-button-disabled');
            } else {
                button.classList.add('pagination-button-disabled');
            }
        }
    </script>
</x-app-layout>
