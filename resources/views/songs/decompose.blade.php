<x-app-layout>
    <div class="py-4">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <!-- 統計情報 -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <h3 class="text-lg font-semibold">タイムスタンプ分解・選別</h3>
                            <div id="statistics" class="flex gap-4 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">
                                    残り: <span id="statPending" class="font-medium text-orange-600">-</span>件
                                </span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    処理済: <span id="statSelected" class="font-medium text-green-600">-</span>件
                                </span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    自動: <span id="statAutoMatched" class="font-medium text-blue-600">-</span>件
                                </span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    スキップ: <span id="statSkipped" class="font-medium text-gray-600">-</span>件
                                </span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button id="scanBtn" class="px-3 py-1 bg-purple-600 text-white text-sm rounded hover:bg-purple-700">
                                スキャン
                            </button>
                            <button id="bulkLinkBtn" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                自動判定を一括紐付け
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- カード表示エリア -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <!-- カード未ロード時 -->
                    <div id="noCard" class="text-center py-8">
                        <p class="text-gray-500 dark:text-gray-400 mb-4">処理待ちのアイテムがありません</p>
                        <p class="text-sm text-gray-400 dark:text-gray-500">「スキャン」ボタンをクリックして新しいタイムスタンプをスキャンしてください</p>
                    </div>

                    <!-- カード表示 -->
                    <div id="cardArea" class="hidden">
                        <!-- 元テキスト -->
                        <div class="mb-6">
                            <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">元テキスト</div>
                            <div id="originalText" class="text-xl font-medium break-words p-3 bg-gray-50 dark:bg-gray-700 rounded"></div>
                        </div>

                        <!-- パーツ選択 -->
                        <div class="mb-6">
                            <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">パーツを選択（数字キー: アーティスト, Shift+数字: 楽曲名）</div>
                            <div id="partsContainer" class="flex flex-wrap gap-2">
                                <!-- パーツボタンがここに表示される -->
                            </div>
                        </div>

                        <!-- 選択プレビュー -->
                        <div class="mb-6 p-4 bg-blue-50 dark:bg-gray-700 rounded">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">楽曲名</div>
                                    <div id="previewTitle" class="font-medium text-blue-600 dark:text-blue-400 min-h-[1.5rem]">-</div>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">アーティスト名</div>
                                    <div id="previewArtist" class="font-medium text-green-600 dark:text-green-400 min-h-[1.5rem]">-</div>
                                </div>
                            </div>
                        </div>

                        <!-- 確信度表示（自動判定がある場合） -->
                        <div id="confidenceArea" class="mb-6 hidden">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-500 dark:text-gray-400">自動判定確信度:</span>
                                <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-600 rounded">
                                    <div id="confidenceBar" class="h-2 bg-blue-500 rounded" style="width: 0%"></div>
                                </div>
                                <span id="confidenceValue" class="text-sm font-medium">0%</span>
                            </div>
                        </div>

                        <!-- アクションボタン -->
                        <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex gap-2">
                                <button id="undoBtn" class="px-4 py-2 bg-orange-500 text-white rounded hover:bg-orange-600 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                    戻る <span class="text-xs opacity-75">(Z)</span>
                                </button>
                                <button id="skipBtn" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">
                                    スキップ <span class="text-xs opacity-75">(S)</span>
                                </button>
                            </div>
                            <div class="flex gap-2">
                                <button id="wholeTitleBtn" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                                    全体を楽曲名 <span class="text-xs opacity-75">(A)</span>
                                </button>
                                <button id="resetBtn" class="px-4 py-2 bg-yellow-500 text-white rounded hover:bg-yellow-600">
                                    リセット <span class="text-xs opacity-75">(R)</span>
                                </button>
                                <button id="confirmBtn" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                    確定 <span class="text-xs opacity-75">(Enter)</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- キーボードショートカット説明 -->
            <div class="mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium">キーボードショートカット:</span>
                        <span class="ml-4">1-9: アーティスト選択</span>
                        <span class="ml-4">Shift+1-9 または Q,W,E...: 楽曲名選択</span>
                        <span class="ml-4">A: 全体を楽曲名</span>
                        <span class="ml-4">Enter: 確定</span>
                        <span class="ml-4">S: スキップ</span>
                        <span class="ml-4">R: リセット</span>
                        <span class="ml-4">Z: 戻る</span>
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

    <!-- メッセージトースト -->
    <div id="toast" class="fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg transform transition-all duration-300 translate-y-20 opacity-0 z-50">
        <span id="toastMessage"></span>
    </div>
</x-app-layout>

@vite('resources/js/songs/decompose.js')
