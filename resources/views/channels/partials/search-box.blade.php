{{-- 統一検索ボックス --}}
<div class="search-unified">
    <form @submit.prevent="activeTab === 'archives' ? archiveSearch() : searchTimestamps()" class="flex items-stretch sm:items-center gap-2 max-w-7lg">
        <div class="flex gap-2 w-full sm:flex-grow flex-col sm:flex-row">
            {{-- アーカイブタブ用の検索ボックス --}}
            <template x-if="activeTab === 'archives'">
                <input
                    type="text"
                    x-model="archiveQuery"
                    placeholder="タイムスタンプを検索"
                    aria-label="タイムスタンプを検索"
                    class="border p-2 rounded w-full dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100" />
            </template>
            {{-- タイムスタンプタブ用の検索ボックス --}}
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
            <div class="flex items-center gap-2">
                <button
                    type="submit"
                    class="bg-blue-500 text-white px-4 py-2 rounded sm:min-w-[100px] hover:bg-blue-600">
                    検索
                </button>
                {{-- 件数表示 --}}
                <span class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap"
                      x-text="archives.total !== undefined ? `${archives.total}件` : ''"></span>
            </div>
        </template>
        <template x-if="activeTab === 'timestamps'">
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="searchQuery = ''"
                    class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 whitespace-nowrap">
                    クリア
                </button>
                {{-- 件数表示 --}}
                <span class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap"
                      x-text="timestamps.total !== undefined ? `${timestamps.total}件` : ''"></span>
                {{-- ダウンロードボタン --}}
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
