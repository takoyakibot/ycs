<x-app-layout>
    <div class="py-4" id="duplicates-app" x-data="duplicatesApp">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            {{-- ヘッダー --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-lg font-semibold">楽曲名寄せ</h3>
                        <div class="flex gap-2 text-sm">
                            <a href="{{ route('songs.index') }}" class="text-blue-600 hover:underline">正規化画面</a>
                            <a href="{{ route('songs.decompose') }}" class="text-blue-600 hover:underline">分解画面</a>
                        </div>
                    </div>

                    {{-- 検索 --}}
                    <div class="flex gap-2">
                        <input type="text"
                               x-model="search"
                               @keydown.enter="fetchDuplicates()"
                               placeholder="タイトルで検索..."
                               class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        <button @click="fetchDuplicates()"
                                :disabled="loading"
                                class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 disabled:opacity-50">
                            検索
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

            {{-- ローディング --}}
            <template x-if="loading">
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">読み込み中...</div>
            </template>

            {{-- 結果なし --}}
            <template x-if="!loading && groups.length === 0">
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">重複楽曲が見つかりませんでした</div>
            </template>

            {{-- 重複グループ一覧 --}}
            <template x-for="(group, gi) in groups" :key="gi">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
                        <div class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                            正規化タイトル: <span class="font-mono" x-text="group.normalized_title"></span>
                            <template x-if="group.normalized_artist">
                                <span> / <span class="font-mono" x-text="group.normalized_artist"></span></span>
                            </template>
                        </div>

                        <div class="space-y-2">
                            <template x-for="(song, si) in group.songs" :key="song.id">
                                <div class="flex items-center justify-between p-3 border border-gray-200 dark:border-gray-700 rounded-md">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate" x-text="song.title"></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400 truncate" x-text="song.artist || '(アーティスト未設定)'"></div>
                                        <div class="flex gap-3 mt-1 text-xs text-gray-400 dark:text-gray-500">
                                            <span>マッピング: <span class="font-medium" x-text="song.mappings_count"></span>件</span>
                                            <span>個別紐付: <span class="font-medium" x-text="song.ts_items_count"></span>件</span>
                                            <template x-if="song.spotify_track_id">
                                                <span class="text-green-600">Spotify連携済</span>
                                            </template>
                                        </div>
                                    </div>
                                    <button @click="merge(group, song)"
                                            :disabled="merging"
                                            class="ml-3 flex-shrink-0 px-3 py-1.5 bg-blue-600 text-white text-xs rounded-md hover:bg-blue-700 disabled:opacity-50"
                                            title="この楽曲を残して他を統合">
                                        これに統合
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    @vite(['resources/js/songs/duplicates.js'])
</x-app-layout>
