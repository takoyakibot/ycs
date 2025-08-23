<x-app-layout>
    <x-slot name="alpine_script">
        <script>
            window.channel = @json($channel ?? []);
            window.archives = @json($archives ?? []);
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
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" class="hover:opacity-80">
                    Youtubeチャンネルはこちら
                </a>
            </h2>
            <h2 class="text-gray-500 justify-self-center sm:hidden">
                <a :href="'https://youtube.com/@' + escapeHTML(channel.handle || '')" target="_blank" class="flex items-center gap-4 hover:opacity-80">
                    <img :src="escapeHTML(channel.thumbnail || '')" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold" x-text="channel.title || '未設定'"></span>
                </a>
            </h2>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-5xl gap-2">
            <x-search
                :channel-id="$channel->handle"
                placeholder="チャンネル内を検索"
                button-text="検索"
                alpine-parent="archiveListComponent"
            />
            <x-pagination></x-pagination>
            <div id="archives" class="flex flex-col items-center w-[100%]">
                <!-- アーカイブリスト -->
                <template x-for="archive in (archives.data || [])" :key="archive.id">
                    <div class="archive flex flex-col sm:flex-row w-[100%] max-w-5xl border rounded-lg shadow-lg p-4 gap-4 mb-2 bg-white">
                        <div class="flex flex-col flex-shrink-0 sm:w-1/3">
                            <div class="flex flex-col gap-2">
                                <a :href="getArchiveUrl(archive.video_id || '')" target="_blank">
                                    <img :src="escapeHTML(archive.thumbnail || '')" alt="サムネイル" loading="lazy"
                                        class="h-auto rounded-md object-cover" />
                                </a>
                                <div>
                                    <h4 class="font-semibold text-gray-800" x-text="archive.title || ''"></h4>
                                    <p class="text-sm text-gray-600" x-text="'公開日: ' + (new Date(archive.published_at || 0)).toLocaleString().split(' ')[0]"></p>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col flex-grow sm:w-2/3 gap-2">
                            <div class="timestamps flex flex-col gap-2 sm:gap-0">
                                <template x-for="tsItem in archive.ts_items_display" :key="tsItem.id">
                                    <div class="timestamp text-sm text-gray-700">
                                        <a :href="getArchiveUrl(tsItem.video_id, tsItem.ts_num)"
                                            target="_blank" class="text-blue-500 tabular-nums hover:underline"
                                            x-text="tsItem.ts_text || '0:00:00'">
                                        </a> <span class="ml-2" x-text="tsItem.text || ''"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <x-pagination></x-pagination>
        </div>
    </div>

    <script>
        // Alpine.jsのコンポーネントを定義
        window.addEventListener('alpine:init', () => {
            Alpine.data('archiveListComponent', () => ({
                channel: window.channel || {},
                archives: window.archives || {},

                // ページのデータを取得するメソッド
                async fetchData(page) {
                    try {
                        const response = await fetch(`/api/channels/${channel.handle}?page=${page}`);
                        if (!response.ok) throw new Error('データ取得エラー');
                        this.archives = await response.json(); // データを更新
                    } catch (error) {
                        console.error('データの取得に失敗しました:', error);
                    }
                },

                // ページ遷移の処理
                // 読み込み時にボタンに割り当てる
                handlePaginationClick(event) {
                    const button = event.target;
                    const isNext = button.classList.contains('next');
                    // 戻るときに戻り先urlがnullなら終了
                    if (!isNext && !this.archives.prev_page_url) return;
                    // 進むときに進み先urlがnullなら終了
                    if (isNext && !this.archives.next_page_url) return;
                    // 遷移先のページ番号を取得
                    const page = isNext
                        ? this.archives.current_page + 1
                        : this.archives.current_page - 1;
                    const paginationButtons = document.querySelectorAll('#paginationButtons button');
                    paginationButtons.forEach(button => {
                        togglePaginationButtonDisabled(button, page);
                    });

                    this.fetchData(page); // Alpine.js内でfetchDataを呼び出し
                    window.scroll({top: 0, behavior: 'auto'});
                },

                // Alpine.js初期化後にイベントリスナーを設定
                init() {
                    this.$el.addEventListener('search-results', (e) => {
                        this.archives = e.detail;
                    });

                    const paginationButtons = document.querySelectorAll('#paginationButtons button');
                    paginationButtons.forEach(button => {
                        togglePaginationButtonDisabled(button, 1);
                        button.addEventListener('click', this.handlePaginationClick.bind(this));
                    });
                }
            }));
        });

        // ページネーションボタンのクラス修正
        const maxPage = Math.ceil(this.archives.total / this.archives.per_page);
        // 受け取ったボタンに対して、nextのボタンなら現在のページが最大値のときにdisabled、
        // それ以外（previewのボタン）なら1ページのときにdisabledとする
        function togglePaginationButtonDisabled(button, newPage) {
            const isNext = button.classList.contains('next');
            if (!isNext && 1 < newPage || isNext && newPage < maxPage) {
                button.classList.remove('pagination-button-disabled');
            } else {
                button.classList.add('pagination-button-disabled');
            }
        }
    </script>
</x-app-layout>
