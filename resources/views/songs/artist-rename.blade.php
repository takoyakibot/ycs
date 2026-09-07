<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/songs/artist-rename.js')
    </x-slot>

    <div class="py-4" x-data="artistRenameApp">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            {{-- ヘッダー --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-semibold">アーティスト名の変更</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        楽曲マスタに登録されているアーティスト名を一括で変更します。表記ゆれの統一や誤りの修正に使います。
                    </p>
                </div>
            </div>

            {{-- メッセージ --}}
            <template x-if="message">
                <div class="mb-4 p-3 rounded-md text-sm"
                     :class="messageType === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                     x-text="message"></div>
            </template>

            <div class="flex flex-col lg:flex-row gap-4">
                {{-- 左: アーティスト一覧 --}}
                <div class="lg:w-2/5 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
                        <div class="flex gap-2 mb-3">
                            <div class="relative flex-1">
                                <input type="text"
                                       x-model="search"
                                       @input="applyFilter()"
                                       @keydown.enter="applyFilter()"
                                       placeholder="アーティスト名で絞り込み..."
                                       class="w-full px-3 py-2 pr-8 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                <button x-show="search"
                                        @click="search = ''; applyFilter()"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                        aria-label="クリア">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                        </div>

                        <template x-if="loading">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">読み込み中...</div>
                        </template>

                        <template x-if="!loading && filteredArtists.length === 0">
                            <div class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">該当するアーティストがありません</div>
                        </template>

                        <div x-show="!loading && filteredArtists.length > 0" class="space-y-0.5 max-h-[70vh] overflow-y-auto">
                            <template x-for="artist in filteredArtists" :key="artist.name">
                                <button @click="selectArtist(artist.name)"
                                        class="w-full text-left px-3 py-1.5 rounded text-sm flex items-center justify-between transition-colors"
                                        :class="selectedArtist === artist.name
                                            ? 'bg-blue-100 dark:bg-blue-900/30 font-medium'
                                            : 'hover:bg-gray-100 dark:hover:bg-gray-700'">
                                    <span class="truncate" x-text="artist.name"></span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0 ml-2 tabular-nums" x-text="artist.count + '曲'"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- 右: 詳細と変更操作 --}}
                <div class="lg:w-3/5 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
                        <template x-if="!selectedArtist">
                            <div class="text-center py-12 text-gray-400 dark:text-gray-500 text-sm">
                                アーティストを選択すると楽曲の一覧と変更フォームが表示されます
                            </div>
                        </template>

                        <template x-if="selectedArtist">
                            <div>
                                {{-- 選択中のアーティストの楽曲一覧 --}}
                                <div class="mb-4">
                                    <h4 class="text-sm font-medium mb-2">
                                        <span x-text="selectedArtist"></span>
                                        <span class="text-gray-400 dark:text-gray-500 font-normal">の楽曲</span>
                                    </h4>
                                    <template x-if="songsLoading">
                                        <div class="text-xs text-gray-400 py-2">読み込み中...</div>
                                    </template>
                                    <div x-show="!songsLoading && selectedSongs.length > 0" class="space-y-0.5 max-h-[25vh] overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-md p-2">
                                        <template x-for="song in selectedSongs" :key="song.id">
                                            <div class="text-sm py-0.5 px-1 text-gray-700 dark:text-gray-300 truncate" x-text="song.title"></div>
                                        </template>
                                    </div>
                                    <div x-show="!songsLoading && selectedSongs.length === 0" class="text-xs text-gray-400 py-2">楽曲がありません</div>
                                </div>

                                {{-- 変更フォーム --}}
                                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                    <h4 class="text-sm font-medium mb-3">アーティスト名の変更</h4>

                                    <div class="flex items-center gap-3 mb-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">変更前</div>
                                            <div class="px-3 py-2 text-sm bg-gray-100 dark:bg-gray-700 rounded-md truncate" x-text="selectedArtist"></div>
                                        </div>

                                        <div class="flex-shrink-0 pt-5 text-gray-400">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                        </div>

                                        <div class="flex-1 min-w-0 relative" @click.outside="showSuggestions = false">
                                            <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">変更後</div>
                                            <input type="text"
                                                   x-model="renameTo"
                                                   @input="updateSuggestions()"
                                                   @focus="updateSuggestions()"
                                                   @keydown.arrow-down.prevent="highlightIndex = Math.min(highlightIndex + 1, suggestions.length - 1); showSuggestions = true"
                                                   @keydown.arrow-up.prevent="highlightIndex = Math.max(highlightIndex - 1, -1)"
                                                   @keydown.enter.prevent="highlightIndex >= 0 && showSuggestions ? selectSuggestion(suggestions[highlightIndex]) : preview()"
                                                   @keydown.escape="showSuggestions = false"
                                                   placeholder="新しいアーティスト名"
                                                   autocomplete="off"
                                                   class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <div x-show="showSuggestions && suggestions.length > 0"
                                                 class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                                <template x-for="(s, i) in suggestions" :key="s">
                                                    <div @click="selectSuggestion(s)"
                                                         class="px-3 py-2 cursor-pointer text-sm text-gray-900 dark:text-gray-100"
                                                         :class="i === highlightIndex ? 'bg-blue-100 dark:bg-blue-900' : 'hover:bg-gray-100 dark:hover:bg-gray-600'"
                                                         x-text="s"></div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>

                                    <button @click="preview()"
                                            :disabled="previewing || !renameTo.trim()"
                                            class="px-4 py-2 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
                                        <span x-show="!previewing">プレビュー</span>
                                        <span x-show="previewing">確認中...</span>
                                    </button>

                                    {{-- プレビュー結果 --}}
                                    <template x-if="previewResult">
                                        <div class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                            <div class="flex items-center gap-4 mb-3">
                                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                                    リネーム: <span class="font-medium" x-text="previewResult.rename_count"></span>件
                                                </span>
                                                <span x-show="previewResult.merge_count > 0" class="text-sm text-orange-600 dark:text-orange-400">
                                                    統合: <span class="font-medium" x-text="previewResult.merge_count"></span>件
                                                </span>
                                            </div>

                                            <div class="space-y-1 max-h-[20vh] overflow-y-auto mb-3">
                                                <template x-for="item in previewResult.plan" :key="item.song_id">
                                                    <div class="flex items-center justify-between p-2 border border-gray-200 dark:border-gray-700 rounded text-sm">
                                                        <span class="truncate" x-text="item.title"></span>
                                                        <span class="text-xs px-2 py-0.5 rounded flex-shrink-0 ml-2"
                                                              :class="item.action === 'merge'
                                                                  ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
                                                                  : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                                                              x-text="item.action === 'merge' ? '統合' : 'リネーム'"></span>
                                                    </div>
                                                </template>
                                            </div>

                                            <button @click="execute()"
                                                    :disabled="executing"
                                                    class="px-4 py-2 text-sm bg-orange-600 text-white rounded-md hover:bg-orange-700 disabled:opacity-50">
                                                <span x-show="!executing">実行する</span>
                                                <span x-show="executing">実行中...</span>
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
