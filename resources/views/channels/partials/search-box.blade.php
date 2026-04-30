{{-- 統一検索ボックス --}}
<div class="channel-search-wrapper">
    <form @submit.prevent="activeTab === 'archives' ? archiveSearch() : searchTimestamps()" class="flex items-stretch sm:items-center gap-2 max-w-7lg">
        <div class="flex gap-2 w-full sm:flex-grow flex-col sm:flex-row">
            {{-- アーカイブタブ用の検索ボックス --}}
            <template x-if="activeTab === 'archives'">
                <div class="relative w-full">
                    <div class="channel-search-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        x-model="archiveQuery"
                        placeholder="タイムスタンプを検索"
                        aria-label="タイムスタンプを検索"
                        class="channel-search-input channel-search-input-with-icon" />
                </div>
            </template>
            {{-- タイムスタンプタブ用の検索ボックス（サジェスト機能付き） --}}
            <template x-if="activeTab === 'timestamps'">
                <div class="relative w-full">
                    <div class="channel-search-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        x-model="searchQuery"
                        @focus="showSuggestions = true"
                        @blur="closeSuggestions()"
                        @keydown.escape="showSuggestions = false"
                        @keydown.enter="showSuggestions = false"
                        placeholder="楽曲名・アーティスト名・タイムスタンプで検索..."
                        aria-label="楽曲名・アーティスト名・タイムスタンプで検索"
                        maxlength="255"
                        autocomplete="off"
                        class="channel-search-input channel-search-input-with-icon" />
                    {{-- サジェストドロップダウン --}}
                    <div x-show="showSuggestions && filteredSuggestionsList.length > 0"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="channel-suggest-dropdown">
                        <template x-for="(suggestion, index) in filteredSuggestionsList" :key="index">
                            <button type="button"
                                    @mousedown.prevent="selectSuggestion(suggestion)"
                                    class="w-full px-3 py-2 text-left text-sm hover:bg-amber-50 dark:hover:bg-gray-700 cursor-pointer truncate dark:text-gray-100 transition-colors"
                                    x-text="suggestion"></button>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="activeTab === 'archives'">
                <div class="flex flex-row gap-2">
                    <select x-model="tsFlg" aria-label="タイムスタンプフィルター" class="channel-search-select">
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
                    class="channel-search-btn">
                    検索
                </button>
                <span class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap"
                      x-text="archives.total !== undefined ? `${archives.total}件` : ''"></span>
                <button
                    x-show="tsFlg === '2' && archives.total > 0"
                    type="button"
                    @click="copyVideoIdList()"
                    class="hidden sm:flex bg-purple-600 text-white px-3 py-2 rounded-lg hover:bg-purple-700 items-center gap-1 whitespace-nowrap text-sm transition-colors"
                    title="表示中のアーカイブのvideoIdリストをコピー（拡張機能での一括スキャン用）">
                    📋 IDコピー
                </button>
            </div>
        </template>
        <template x-if="activeTab === 'timestamps'">
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="searchQuery = ''"
                    class="channel-search-clear">
                    クリア
                </button>
                <span class="text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap"
                      x-text="timestamps.total !== undefined ? `${timestamps.total}件` : ''"></span>
                <button
                    type="button"
                    @click="downloadTimestamps()"
                    :disabled="loading || !timestamps.total"
                    :class="loading || !timestamps.total ? 'opacity-50 cursor-not-allowed' : ''"
                    class="hidden sm:flex bg-green-600 text-white px-3 py-2 rounded-lg hover:bg-green-700 items-center gap-1 whitespace-nowrap text-sm transition-colors"
                    title="全タイムスタンプをテキストファイルとしてダウンロード">
                    📥
                </button>
            </div>
        </template>
    </form>
</div>
