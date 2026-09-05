<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/songs/title-groups.js')
    </x-slot>

    <div class="py-4" x-data="titleGroupsApp">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- ヘッダー --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-semibold">同名異表記グループ</h3>
                </div>
            </div>

            {{-- メッセージ --}}
            <template x-if="message">
                <div class="mb-4 p-3 rounded-md text-sm"
                     :class="messageType === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                     x-text="message"></div>
            </template>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        同じ曲名で複数のアーティスト表記のマスタが存在するグループです。同じ曲であれば選択してマージ、
                        別の曲であれば「別の曲」、判断がつかない場合は「保留」を選んでください。
                    </p>

                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <input type="text"
                               x-model="groupSearch"
                               @keydown.enter="fetchTitleGroups()"
                               placeholder="タイトルで絞り込み..."
                               class="flex-1 min-w-[200px] px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <button @click="fetchTitleGroups()"
                                class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700">
                            検索
                        </button>
                        <div class="flex gap-1 ml-auto">
                            <button @click="setFilter('active')"
                                    class="px-3 py-1 text-sm rounded"
                                    :class="groupFilter === 'active' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600'">
                                未処理
                            </button>
                            <button @click="setFilter('pending')"
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
                        <template x-for="group in groups" :key="groupKey(group)">
                            <div class="border border-gray-200 dark:border-gray-700 rounded-md p-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="font-medium text-sm" x-text="group.normalized_title"></div>
                                    <div class="flex gap-2">
                                        <button @click="mergeGroup(group)"
                                                :disabled="!canMerge(groupKey(group)) || merging[groupKey(group)]"
                                                class="px-3 py-1 text-xs rounded-md bg-orange-600 text-white hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed">
                                            <span x-show="!merging[groupKey(group)]">同じ曲としてマージ</span>
                                            <span x-show="merging[groupKey(group)]">マージ中...</span>
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
                                </div>

                                <div class="space-y-1">
                                    <template x-for="song in group.songs" :key="song.id">
                                        <div class="flex items-center gap-2 p-2 border rounded-md transition-colors"
                                             :class="{
                                                 'border-blue-500 bg-blue-50 dark:bg-blue-900/20': isSelected(groupKey(group), song.id),
                                                 'border-orange-500 bg-orange-50 dark:bg-orange-900/20 ring-2 ring-orange-300': targetId[groupKey(group)] === song.id,
                                                 'border-gray-200 dark:border-gray-700': !isSelected(groupKey(group), song.id)
                                             }">
                                            <input type="checkbox"
                                                   :checked="isSelected(groupKey(group), song.id)"
                                                   @change="toggleSelect(groupKey(group), song.id)"
                                                   class="w-4 h-4 flex-shrink-0 text-blue-600 rounded focus:ring-blue-500">

                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-medium truncate" x-text="song.title"></div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="song.artist || '(アーティスト未設定)'"></div>
                                                <div class="flex items-center gap-2 mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                                    <span><span x-text="song.ts_items_count"></span>回歌唱</span>
                                                    <a :href="'https://www.youtube.com/results?search_query=' + encodeURIComponent(song.title + ' ' + (song.artist || ''))"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                       @click.stop
                                                       title="YouTubeで検索">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 inline" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                                        </svg>
                                                    </a>
                                                </div>
                                            </div>

                                            <button x-show="isSelected(groupKey(group), song.id)"
                                                    @click="setTarget(groupKey(group), song.id)"
                                                    class="flex-shrink-0 px-2 py-1 text-xs rounded-md transition-colors"
                                                    :class="targetId[groupKey(group)] === song.id
                                                        ? 'bg-orange-600 text-white'
                                                        : 'bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 hover:bg-orange-100 dark:hover:bg-orange-900/30'"
                                                    x-text="targetId[groupKey(group)] === song.id ? 'マージ先' : '残す'">
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-app-layout>
