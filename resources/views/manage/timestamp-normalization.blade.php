<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('タイムスタンプ正規化') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- 検索エリア -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex flex-col sm:flex-row gap-4 mb-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-2">タイムスタンプ検索</label>
                            <input type="text" id="timestampSearch" placeholder="楽曲名やアーティスト名で検索..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="flex-1">
                            <label class="block text-sm font-medium mb-2">Spotify検索</label>
                            <div class="flex gap-2">
                                <input type="text" id="spotifySearch" placeholder="楽曲名 アーティスト名" class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <button id="searchSpotifyBtn" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                                    検索
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button id="refreshTimestampsBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            タイムスタンプ更新
                        </button>
                        <button id="showSongsBtn" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500">
                            楽曲マスタ一覧
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- タイムスタンプ一覧 -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <h3 class="text-lg font-semibold mb-4">タイムスタンプ一覧</h3>
                        <div id="timestampsList" class="space-y-3 max-h-96 overflow-y-auto">
                            <!-- タイムスタンプリストがここに表示される -->
                        </div>
                        <div id="timestampPagination" class="mt-4">
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
                        <div id="selectedTimestamp" class="mb-4 p-3 bg-gray-100 dark:bg-gray-700 rounded hidden">
                            <!-- 選択されたタイムスタンプ情報 -->
                        </div>

                        <!-- タブ -->
                        <div class="mb-4">
                            <nav class="flex space-x-8">
                                <button id="spotifyTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-green-500 text-green-600">
                                    Spotify検索
                                </button>
                                <button id="manualTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                                    手動登録
                                </button>
                                <button id="songsTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
                                    楽曲マスタ
                                </button>
                            </nav>
                        </div>

                        <!-- Spotify検索結果 -->
                        <div id="spotifyResults" class="tab-content">
                            <div id="spotifyTracks" class="space-y-3 max-h-64 overflow-y-auto">
                                <!-- Spotify検索結果がここに表示される -->
                            </div>
                        </div>

                        <!-- 手動登録フォーム -->
                        <div id="manualForm" class="tab-content hidden">
                            <form id="createSongForm" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">楽曲名 *</label>
                                    <input type="text" id="songTitle" name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-2">アーティスト名 *</label>
                                    <input type="text" id="songArtist" name="artist" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    楽曲マスタ作成
                                </button>
                            </form>
                        </div>

                        <!-- 楽曲マスタ一覧 -->
                        <div id="songsList" class="tab-content hidden">
                            <div class="mb-3">
                                <input type="text" id="songsSearch" placeholder="楽曲名やアーティスト名で検索..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div id="songsResults" class="space-y-3 max-h-64 overflow-y-auto">
                                <!-- 楽曲マスタリストがここに表示される -->
                            </div>
                            <div id="songsPagination" class="mt-4">
                                <!-- ページネーション -->
                            </div>
                        </div>

                        <!-- アクションボタン -->
                        <div class="mt-6 space-y-2">
                            <button id="linkSongBtn" class="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                選択した楽曲と紐づける
                            </button>
                            <button id="markAsNotSongBtn" class="w-full px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                楽曲ではないとマークする
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ローディングモーダル -->
    <div id="loadingModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white p-6 rounded-lg">
            <div class="flex items-center">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mr-3"></div>
                <span>処理中...</span>
            </div>
        </div>
    </div>
</x-app-layout>

@vite('resources/js/manage/timestamp-normalization.js')