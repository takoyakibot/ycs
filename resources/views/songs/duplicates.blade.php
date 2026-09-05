<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/songs/duplicates.js')
    </x-slot>

    <div class="py-4" id="duplicates-app" x-data="duplicatesApp">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- ヘッダー --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-semibold">楽曲名寄せ</h3>
                </div>
            </div>

            {{-- メッセージ --}}
            <template x-if="message">
                <div class="mb-4 p-3 rounded-md text-sm"
                     :class="messageType === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                     x-text="message"></div>
            </template>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {{-- 左ペイン: 重複グループ --}}
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
                        <h4 class="font-semibold mb-2">重複検出グループ</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                            同じ曲名（正規化後）で複数のマスタが存在するグループです。
                            同じ曲であればマージ、別の曲であれば「別の曲」、判断がつかない場合は「保留」を選んでください。
                        </p>

                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <input type="text"
                                   x-model="groupSearch"
                                   @keydown.enter="fetchDuplicates()"
                                   placeholder="タイトルで絞り込み..."
                                   class="flex-1 min-w-[150px] px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <button @click="fetchDuplicates()"
                                    class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700">
                                検索
                            </button>
                            <div class="flex gap-1 ml-auto">
                                <button @click="setGroupFilter('active')"
                                        class="px-3 py-1 text-sm rounded"
                                        :class="groupFilter === 'active' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'">
                                    未処理
                                </button>
                                <button @click="setGroupFilter('pending')"
                                        class="px-3 py-1 text-sm rounded"
                                        :class="groupFilter === 'pending' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'">
                                    保留中
                                </button>
                            </div>
                        </div>

                        <template x-if="groupsLoading">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">読み込み中...</div>
                        </template>

                        <template x-if="!groupsLoading && groups.length === 0">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">対象のグループはありません</div>
                        </template>

                        <div class="space-y-3 max-h-[70vh] overflow-y-auto">
                            <template x-for="group in groups" :key="group.song_ids_hash">
                                <div class="border border-gray-200 dark:border-gray-700 rounded-md p-3 flex gap-4"
                                     :class="activeGroupHash === group.song_ids_hash ? 'ring-2 ring-blue-400' : ''">
                                    <button @click="selectGroup(group)" class="flex-shrink-0 font-medium text-sm pt-1 w-36 truncate text-left hover:text-blue-600 dark:hover:text-blue-400" x-text="group.normalized_title" :title="group.normalized_title"></button>

                                    <div class="flex flex-col gap-1 flex-shrink-0 pt-0.5">
                                        <button @click="mergeGroup(group)"
                                                :disabled="!canMergeGroup(group.song_ids_hash) || groupMerging[group.song_ids_hash]"
                                                class="px-3 py-1 text-xs rounded-md bg-orange-600 text-white hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span x-show="!groupMerging[group.song_ids_hash]">マージ</span>
                                            <span x-show="groupMerging[group.song_ids_hash]">マージ中...</span>
                                        </button>
                                        <button @click="reviewGroup(group, 'pending')"
                                                title="判断を保留し、「保留中」フィルターで後から見直せます"
                                                class="px-3 py-1 text-xs rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                            保留
                                        </button>
                                        <button @click="reviewGroup(group, 'distinct')"
                                                title="別の曲として記録し、今後このグループを候補に表示しません"
                                                class="px-3 py-1 text-xs rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                            別の曲
                                        </button>
                                    </div>

                                    <div class="flex-1 min-w-0 space-y-1">
                                        <template x-for="song in group.songs" :key="song.id">
                                            <div class="flex items-center gap-2 px-2 py-1 border rounded transition-colors"
                                                 :class="{
                                                     'border-blue-500 bg-blue-50 dark:bg-blue-900/20': isGroupSelected(group.song_ids_hash, song.id),
                                                     'border-orange-500 bg-orange-50 dark:bg-orange-900/20 ring-2 ring-orange-300': groupTargetId[group.song_ids_hash] === song.id,
                                                     'border-gray-200 dark:border-gray-700': !isGroupSelected(group.song_ids_hash, song.id)
                                                 }">
                                                <input type="checkbox"
                                                       :checked="isGroupSelected(group.song_ids_hash, song.id)"
                                                       @change="toggleGroupSelect(group.song_ids_hash, song.id)"
                                                       class="w-4 h-4 flex-shrink-0 text-blue-600 rounded focus:ring-blue-500">

                                                <button x-show="isGroupSelected(group.song_ids_hash, song.id)"
                                                        @click="setGroupTarget(group.song_ids_hash, song.id)"
                                                        class="flex-shrink-0 px-2 py-1 text-xs rounded-md transition-colors"
                                                        :class="groupTargetId[group.song_ids_hash] === song.id
                                                            ? 'bg-orange-600 text-white'
                                                            : 'bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 hover:bg-orange-100 dark:hover:bg-orange-900/30'"
                                                        x-text="groupTargetId[group.song_ids_hash] === song.id ? 'マージ先' : '残す'">
                                                </button>

                                                <span class="text-sm truncate" x-text="song.title"></span>
                                                <span class="text-sm text-gray-500 dark:text-gray-400 truncate" x-text="song.artist || '(アーティスト未設定)'"></span>

                                                <span class="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0 tabular-nums ml-auto">TS: <span x-text="song.ts_items_count"></span></span>

                                                <a :href="'https://www.youtube.com/results?search_query=' + encodeURIComponent(song.title + ' ' + (song.artist || ''))"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="flex-shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                   @click.stop
                                                   title="YouTubeで検索">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                                    </svg>
                                                </a>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- 右ペイン: 検索・選択・マージ --}}
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
                        <h4 class="font-semibold mb-3">名寄せ候補</h4>

                        {{-- 検索欄 --}}
                        <div class="flex gap-2 mb-3">
                            <div class="relative flex-1">
                                <input type="text"
                                       x-model="search"
                                       @keydown.enter="doSearch()"
                                       placeholder="タイトル・アーティストで検索..."
                                       class="w-full px-3 py-2 pr-8 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                <button x-show="search"
                                        x-cloak
                                        @click="clearSearch()"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                        aria-label="クリア">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                            <button @click="doSearch()"
                                    :disabled="searchLoading"
                                    class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50">
                                検索
                            </button>
                        </div>

                        {{-- マージボタン --}}
                        <div x-show="selectedIds.length >= 2" class="mb-3 p-2 bg-blue-50 dark:bg-blue-900/20 rounded-md">
                            <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">
                                <span x-text="selectedIds.length"></span>件選択中
                                <template x-if="targetId">
                                    <span> (マージ先を選択済み)</span>
                                </template>
                            </div>
                            <button @click="doMerge()"
                                    :disabled="!canMerge || merging"
                                    class="px-4 py-1.5 bg-orange-600 text-white text-sm rounded-md hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                <span x-show="!merging">選択した楽曲をマージ</span>
                                <span x-show="merging">マージ中...</span>
                            </button>
                            <span x-show="selectedIds.length >= 2 && !targetId" class="text-xs text-orange-600 ml-2">マージ先を選んでください</span>
                        </div>

                        {{-- 検索結果 --}}
                        <template x-if="searchLoading">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">検索中...</div>
                        </template>

                        <template x-if="!searchLoading && searchResults.length === 0 && search">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">該当する楽曲がありません</div>
                        </template>

                        <template x-if="!searchLoading && !search">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">左のグループをクリックするか、検索してください</div>
                        </template>

                        <div class="space-y-1 max-h-[60vh] overflow-y-auto">
                            <template x-for="song in searchResults" :key="song.id">
                                <div class="flex items-center gap-2 p-2 border rounded-md transition-colors"
                                     :class="{
                                         'border-blue-500 bg-blue-50 dark:bg-blue-900/20': isSelected(song.id),
                                         'border-orange-500 bg-orange-50 dark:bg-orange-900/20 ring-2 ring-orange-300': targetId === song.id,
                                         'border-gray-200 dark:border-gray-700': !isSelected(song.id)
                                     }">
                                    {{-- チェックボックス --}}
                                    <input type="checkbox"
                                           :checked="isSelected(song.id)"
                                           @change="toggleSelect(song.id)"
                                           class="w-4 h-4 flex-shrink-0 text-blue-600 rounded focus:ring-blue-500">

                                    {{-- 楽曲情報 --}}
                                    <span class="text-sm truncate" x-text="song.title"></span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400 truncate" x-text="song.artist || '(アーティスト未設定)'"></span>

                                    <template x-if="song.distinct_review">
                                        <span class="text-xs text-amber-600 flex-shrink-0">別の曲判定あり</span>
                                    </template>

                                    <span class="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0 tabular-nums ml-auto">TS: <span x-text="song.ts_items_count"></span></span>

                                    <a :href="'https://www.youtube.com/results?search_query=' + encodeURIComponent(song.title + ' ' + (song.artist || ''))"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="flex-shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                       @click.stop
                                       title="YouTubeで検索">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                        </svg>
                                    </a>

                                    {{-- マージ先ボタン --}}
                                    <button x-show="isSelected(song.id)"
                                            @click="setTarget(song.id)"
                                            class="flex-shrink-0 px-2 py-1 text-xs rounded-md transition-colors"
                                            :class="targetId === song.id
                                                ? 'bg-orange-600 text-white'
                                                : 'bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 hover:bg-orange-100 dark:hover:bg-orange-900/30'"
                                            x-text="targetId === song.id ? 'マージ先' : '残す'">
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>
