<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/songs/artist-rename.js')
    </x-slot>

    <div class="py-4" x-data="artistRenameApp">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- ヘッダー --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-semibold">アーティスト名一括変換</h3>
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
                        変換前のアーティスト名（例: maaya sakamoto）が付いた楽曲マスタを、まとめて変換後の名前（例: 坂本真綾）に統一します。
                        変換後の名前で既に同じ曲名のマスタが存在する場合は、そちらへタイムスタンプの紐付けを移行し、変換元のマスタを削除します。
                    </p>

                    <div class="flex flex-wrap gap-2 mb-3">
                        <div class="relative flex-1 min-w-[200px]">
                            <input type="text"
                                   x-model="renameFrom"
                                   @input="updateFromSuggestions()"
                                   @focus="updateFromSuggestions()"
                                   @click.outside="showFromSuggestions = false"
                                   @keydown="handleFromKeydown($event)"
                                   placeholder="変換前のアーティスト名（完全一致）"
                                   autocomplete="off"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <div x-show="showFromSuggestions"
                                 x-cloak
                                 class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto">
                                <template x-for="(artist, index) in fromSuggestions" :key="artist">
                                    <div @click="selectFromSuggestion(artist)"
                                         :class="index === fromHighlightIndex ? 'bg-blue-100 dark:bg-blue-900' : 'hover:bg-gray-100 dark:hover:bg-gray-600'"
                                         class="px-3 py-2 cursor-pointer text-sm text-gray-900 dark:text-gray-100"
                                         x-text="artist"></div>
                                </template>
                            </div>
                        </div>
                        <span class="self-center text-gray-400">→</span>
                        <div class="relative flex-1 min-w-[200px]">
                            <input type="text"
                                   x-model="renameTo"
                                   @input="updateToSuggestions()"
                                   @focus="updateToSuggestions()"
                                   @click.outside="showToSuggestions = false"
                                   @keydown="handleToKeydown($event)"
                                   placeholder="変換後のアーティスト名"
                                   autocomplete="off"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <div x-show="showToSuggestions"
                                 x-cloak
                                 class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto">
                                <template x-for="(artist, index) in toSuggestions" :key="artist">
                                    <div @click="selectToSuggestion(artist)"
                                         :class="index === toHighlightIndex ? 'bg-blue-100 dark:bg-blue-900' : 'hover:bg-gray-100 dark:hover:bg-gray-600'"
                                         class="px-3 py-2 cursor-pointer text-sm text-gray-900 dark:text-gray-100"
                                         x-text="artist"></div>
                                </template>
                            </div>
                        </div>
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
        </div>
    </div>

</x-app-layout>
