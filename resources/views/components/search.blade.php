<div x-data="searchComponent({
        apiUrl: '{{ $apiUrl }}',
        channelId: '{{ $channelId ?? '' }}'
    })" class="search-component">
    <!-- 検索フォーム -->
    <form @submit.prevent="searchComponent" class="flex items-center gap-2 max-w-7lg">
        <input
            type="text"
            x-model="query"
            placeholder="{{ $placeholder ?? '検索ワードを入力' }}"
            class="border p-2 rounded w-full" />
        <button
            type="submit"
            class="bg-blue-500 text-white px-4 py-2 rounded min-w-[100px]">
            {{ $buttonText ?? '検索' }}
        </button>
    </form>

    <!-- 検索結果 -->
    <div class="mt-4">
        <template x-if="loading">
            <p>検索中...</p>
        </template>
        <template x-if="!loading && results.length === 0">
            <p>結果が見つかりませんでした。</p>
        </template>
        <template x-for="result in results" :key="result.id">
            <div class="border-b py-2">
                <p x-text="result.title"></p>
                <a :href="result.url" target="_blank" class="text-blue-500 hover:underline">詳細を見る</a>
            </div>
        </template>
    </div>
</div>

<script>
    function searchComponent({ apiUrl, channelId }) {
        console.log("1");
        return {
            query: '', // 検索クエリ
            results: [], // 検索結果
            loading: false, // ローディング状態
            async search() {
                if (!this.query.trim()) return; // 空白クエリのチェック
                this.loading = true;

                try {
                    const params = new URLSearchParams();
                    params.append('query', this.query);
                    if (channelId) {
                        params.append('channel_id', channelId); // チャンネル内検索
                    }

                    const response = await fetch(`${apiUrl}/${params.toString()}`);
                    if (!response.ok) {
                        throw new Error('検索に失敗しました');
                    }

                    this.results = await response.json();
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
