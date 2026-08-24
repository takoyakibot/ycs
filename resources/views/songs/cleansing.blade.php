<x-app-layout>
    <div class="py-4" id="cleansing-app" x-data="cleansingApp">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- ヘッダー --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">楽曲マスタクレンジング</h3>
                        <div class="flex gap-2 text-sm">
                            <a href="{{ route('songs.index') }}" class="text-blue-600 hover:underline">正規化画面</a>
                            <a href="{{ route('songs.duplicates') }}" class="text-blue-600 hover:underline">名寄せ画面</a>
                            <a href="{{ route('songs.decompose') }}" class="text-blue-600 hover:underline">分解画面</a>
                        </div>
                    </div>

                    {{-- タブ --}}
                    <div class="flex gap-1 mt-3 border-b border-gray-200 dark:border-gray-700">
                        <button @click="switchTab('rename')"
                                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px"
                                :class="tab === 'rename' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                            アーティスト名一括変換
                        </button>
                        <button @click="switchTab('groups')"
                                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px"
                                :class="tab === 'groups' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'">
                            同名異表記グループ
                        </button>
                    </div>
                </div>
            </div>

            {{-- メッセージ --}}
            <template x-if="message">
                <div class="mb-4 p-3 rounded-md text-sm"
                     :class="messageType === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                     x-text="message"></div>
            </template>

            {{-- アーティスト名一括変換 --}}
            <div x-show="tab === 'rename'" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        変換前のアーティスト名（例: maaya sakamoto）が付いた楽曲マスタを、まとめて変換後の名前（例: 坂本真綾）に統一します。
                        変換後の名前で既に同じ曲名のマスタが存在する場合は、そちらへタイムスタンプの紐付けを移行し、変換元のマスタを削除します。
                    </p>

                    <div class="flex flex-wrap gap-2 mb-3">
                        <input type="text"
                               x-model="renameFrom"
                               placeholder="変換前のアーティスト名（完全一致）"
                               class="flex-1 min-w-[200px] px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <span class="self-center text-gray-400">→</span>
                        <input type="text"
                               x-model="renameTo"
                               @keydown.enter="previewRename()"
                               placeholder="変換後のアーティスト名"
                               class="flex-1 min-w-[200px] px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <button @click="previewRename()"
                                :disabled="renamePreviewing || !renameFrom.trim() || !renameTo.trim()"
                                class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50">
                            プレビュー
                        </button>
                    </div>

                    <template x-if="renamePreviewing">
                        <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">確認中...</div>
                    </template>

                    <template x-if="renamePlan && renamePlan.plan.length > 0">
                        <div>
                            <div class="mb-2 text-sm text-gray-600 dark:text-gray-400">
                                リネームのみ: <span x-text="renamePlan.rename_count"></span>件 /
                                統合が必要: <span x-text="renamePlan.merge_count"></span>件
                            </div>
                            <div class="space-y-1 max-h-[50vh] overflow-y-auto mb-3">
                                <template x-for="item in renamePlan.plan" :key="item.song_id">
                                    <div class="flex items-center justify-between p-2 border border-gray-200 dark:border-gray-700 rounded-md text-sm">
                                        <span x-text="item.title"></span>
                                        <span class="text-xs px-2 py-0.5 rounded"
                                              :class="item.action === 'merge' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                              x-text="item.action === 'merge' ? '統合' : 'リネーム'"></span>
                                    </div>
                                </template>
                            </div>
                            <button @click="executeRename()"
                                    :disabled="renameExecuting"
                                    class="px-4 py-2 bg-orange-600 text-white text-sm rounded-md hover:bg-orange-700 disabled:opacity-50">
                                <span x-show="!renameExecuting">実行する</span>
                                <span x-show="renameExecuting">実行中...</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- 同名異表記グループ --}}
            <div x-show="tab === 'groups'" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
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
                                                class="px-3 py-1 text-xs rounded-md bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                            保留
                                        </button>
                                        <button @click="reviewGroup(group, 'distinct')"
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
                                                <div class="flex gap-2 mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                                    <span>MP: <span x-text="song.mappings_count"></span></span>
                                                    <span>TS: <span x-text="song.ts_items_count"></span></span>
                                                    <template x-if="song.spotify_track_id">
                                                        <span class="text-green-600">Spotify</span>
                                                    </template>
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

    @vite(['resources/js/songs/cleansing.js'])
</x-app-layout>
