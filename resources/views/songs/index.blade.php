<x-app-layout>
    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- 検索エリア -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex flex-col sm:flex-row gap-4 mb-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-2">タイムスタンプ検索</label>
                            <input type="text" id="timestampSearch" placeholder="楽曲名やアーティスト名で検索..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-2">Spotify検索</label>
                            <div class="flex gap-2">
                                <input type="text" id="spotifySearch" placeholder="楽曲名 アーティスト名" class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <button id="searchSpotifyBtn" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                                    検索
                                </button>
                                <button id="clearSpotifySearchBtn" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                                    クリア
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button id="refreshTimestampsBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            タイムスタンプ更新
                        </button>
                        <button id="showSongsBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">
                            楽曲マスタ一覧
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- タイムスタンプ一覧 -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="text-lg font-semibold">タイムスタンプ一覧</h3>
                            <button id="unlinkedOnlyBtn" class="px-3 py-1 bg-gray-200 dark:bg-gray-700 text-sm rounded hover:bg-gray-300 dark:hover:bg-gray-600">
                                未連携のみ
                            </button>
                        </div>

                        <!-- 全選択・全選択解除ボタンと動画情報 -->
                        <div class="flex justify-between items-center gap-4 mb-3">
                            <div class="flex gap-2">
                                <button id="selectAllBtn" class="px-3 py-1 bg-blue-500 text-white text-sm rounded hover:bg-blue-600">
                                    全選択
                                </button>
                                <button id="deselectAllBtn" class="px-3 py-1 bg-gray-500 text-white text-sm rounded hover:bg-gray-600">
                                    全選択解除
                                </button>
                            </div>

                            <!-- 動画情報表示エリア -->
                            <div id="videoInfoArea" class="flex-1 flex items-center justify-end gap-2">
                                <div class="text-xs text-gray-600 dark:text-gray-400 cursor-pointer hover:text-blue-600 transition-colors transition-all duration-200 ease-in-out max-w-[300px]"
                                     id="videoTitle"
                                     title=""
                                     x-data="{ expanded: false }"
                                     :class="expanded ? '' : 'truncate'"
                                     :style="expanded ? 'max-width: none;' : ''"
                                     @click="expanded = !expanded"
                                     role="button"
                                     tabindex="0"
                                     :aria-expanded="expanded"
                                     aria-label="タイトルを展開/折りたたみ"
                                     @keydown.enter="expanded = !expanded"
                                     @keydown.space.prevent="expanded = !expanded">
                                </div>
                                <button id="videoLinkBtn" class="px-3 py-1 text-white text-xs rounded flex-shrink-0 flex items-center gap-1 transition-colors bg-gray-400 cursor-not-allowed" disabled aria-label="動画を開く" aria-disabled="true">
                                    ▶ 動画を開く
                                </button>
                            </div>
                        </div>

                        <div id="timestampsList" class="space-y-1 max-h-[500px] overflow-y-auto">
                            <!-- タイムスタンプリストがここに表示される -->
                        </div>
                        <div id="timestampPagination" class="mt-4 flex justify-center gap-2">
                            <!-- ページネーション -->
                        </div>
                    </div>
                </div>

                <!-- 楽曲情報・Spotify検索結果 -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold">楽曲情報</h3>
                            <button id="clearSelectionBtn" class="px-3 py-1 bg-gray-500 text-white text-sm rounded hover:bg-gray-600">
                                選択解除
                            </button>
                        </div>

                        <!-- 選択中のタイムスタンプ表示 -->
                        <div id="selectedTimestamp" class="mb-3 p-3 bg-blue-50 dark:bg-gray-700 rounded">
                            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">
                                <span id="selectedCount">未選択</span>
                            </div>
                            <div id="selectedText" class="font-medium break-words overflow-hidden" style="word-break: break-word; overflow-wrap: break-word;">タイムスタンプを選択してください</div>
                            <div id="selectedNormalized" class="text-xs text-gray-500 dark:text-gray-400 mt-1 break-words" style="word-break: break-word; overflow-wrap: break-word;"></div>
                        </div>

                        <!-- Spotify選択楽曲情報表示 -->
                        <div id="spotifySelected" class="mb-3 p-3 bg-green-50 dark:bg-gray-700 rounded hidden">
                            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">Spotify選択楽曲</div>
                            <div id="spotifySelectedInfo" class="text-sm truncate" title=""></div>
                        </div>

                        <!-- タブ -->
                        <div class="mb-4">
                            <nav class="flex space-x-4 border-b border-gray-200 dark:border-gray-700">
                                <button id="spotifyTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-green-500 text-green-600 -mb-px">
                                    Spotify検索
                                </button>
                                <button id="manualTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 -mb-px">
                                    手動登録
                                </button>
                                <button id="songsTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 -mb-px">
                                    楽曲マスタ
                                </button>
                            </nav>
                        </div>

                        <!-- Spotify検索結果 -->
                        <div id="spotifyResults" class="tab-content">
                            <div id="spotifyTracks" class="space-y-2 max-h-64 overflow-y-auto">
                                <p class="text-gray-500 dark:text-gray-400 text-sm">検索ボタンをクリックして楽曲を検索してください</p>
                            </div>
                        </div>

                        <!-- 手動登録フォーム -->
                        <div id="manualForm" class="tab-content hidden">
                            <form id="createSongForm" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">楽曲名 *</label>
                                    <input type="text" id="songTitle" name="title" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-2">アーティスト名 *</label>
                                    <input type="text" id="songArtist" name="artist" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                        楽曲マスタ作成
                                    </button>
                                    <button type="button" id="clearManualFormBtn" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                                        クリア
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- 楽曲マスタ一覧 -->
                        <div id="songsList" class="tab-content hidden">
                            <div class="mb-3">
                                <input type="text" id="songsSearch" placeholder="楽曲名やアーティスト名で検索..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div id="songsResults" class="space-y-2 max-h-64 overflow-y-auto">
                                <!-- 楽曲マスタリストがここに表示される -->
                            </div>
                        </div>

                        <!-- アクションボタン -->
                        <div class="mt-6 space-y-2">
                            <button id="linkSongBtn" class="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                選択した楽曲と紐づける
                            </button>
                            <button id="markAsNotSongBtn" class="w-full px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                楽曲ではないとマークする
                            </button>
                            <button id="unmarkAsNotSongBtn" class="w-full px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                楽曲ではないを解除
                            </button>
                            <button id="unlinkBtn" class="w-full px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                紐づけを解除
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ローディングモーダル -->
    <div id="loadingModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg">
            <div class="flex items-center">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mr-3"></div>
                <span class="text-gray-900 dark:text-gray-100">処理中...</span>
            </div>
        </div>
    </div>
</x-app-layout>

@vite('resources/js/songs/normalize.js')
