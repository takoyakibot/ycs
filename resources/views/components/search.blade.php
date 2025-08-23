<div x-data="searchComponent({
        channelId: '{{ $channelId ?? '' }}'
    })" class="search-component">
    <!-- 検索フォーム -->
    <div class="flex items-center gap-2 max-w-7lg">
        <input
            type="text"
            x-model="query"
            placeholder="{{ $placeholder ?? '検索ワードを入力' }}"
            class="border p-2 rounded w-full" />
        <button
            type="submit"
            @click="search"
            class="bg-blue-500 text-white px-4 py-2 rounded min-w-[100px]">
            {{ $buttonText ?? '検索' }}
        </button>
    </div>

</div>

<script>
    /**
     * search-resultsという名前のイベントを発火し、検索窓の内容を連携する
     */
    function searchComponent({ channelId }) {
        return {
            query: '', // 検索クエリ
            results: [], // 検索結果
            loading: false, // ローディング状態
            async search() {
                this.loading = true;

                try {
                    const params = new URLSearchParams();
                    params.append('page', '1');
                    params.append('baramutsu', this.query);

                    const response = await fetch(`${apiUrl}/${channelId}?${params.toString()}`);
                    if (!response.ok) {
                        throw new Error('検索に失敗しました');
                    }

                    this.results = await response.json()
                    this.$dispatch('search-results', this.results);

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
