<div x-data="searchComponent({
        channelId: '{{ $channelId ?? '' }}'
    })" class="search-component">
    <!-- 検索フォーム -->
    <form @submit.prevent="search" class="flex items-stretch sm:items-center gap-2 max-w-7lg">
        <div class="flex gap-2 w-full sm:flex-grow flex-col sm:flex-row">
            <input
                type="text"
                x-model="query"
                placeholder="{{ $placeholder ?? '検索ワードを入力' }}"
                class="border p-2 rounded w-full" />
            <div class="flex flex-row gap-2">
                <select x-model="visibleFlg" class="border p-2 pr-8 rounded {{ $manageFlg ? '' : 'hidden' }}">
                    <option value="">表示のみ</option>
                    <option value="1">非表示のみ</option>
                    <option value="2">絞り込み無</option>
                </select>
                <select x-model="tsFlg" class="border p-2 pr-8 rounded">
                    <option value="">タイムスタンプ</option>
                    <option value="1">有のみ</option>
                    <option value="2">無のみ</option>
                </select>
            </div>
        </div>
        <button
            type="submit"
            class="bg-blue-500 text-white px-4 py-2 rounded sm:min-w-[100px]">
            {{ $buttonText ?? '検索' }}
        </button>
    </form>

</div>

<script>
    /**
     * search-resultsという名前のイベントを発火し、検索窓の内容を連携する
     */
    function searchComponent({ channelId }) {
        return {
            query: '', // 検索クエリ
            visibleFlg: '', // 表示非表示
            tsFlg: '', // タイムスタンプ有無
            results: [], // 検索結果
            loading: false, // ローディング状態
            async search() {
                this.loading = true;

                try {
                    const params = new URLSearchParams();
                    params.append('baramutsu', this.query);
                    params.append('visible', this.visibleFlg);
                    params.append('ts', this.tsFlg);

                    this.$dispatch('filter-changed', this.query.length > 0);
                    this.$dispatch('search-results', params.toString());

                } catch (error) {
                    console.error(error);
                    alert('検索エラーが発生しました');
                } finally {
                    this.loading = false;
                }
            },
        };
    }
    window.searchComponent = searchComponent;
</script>
