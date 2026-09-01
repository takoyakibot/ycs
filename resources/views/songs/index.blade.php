<x-app-layout>
    <div class="py-2">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- 左カラム: タイムスタンプ一覧（検索含む） -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="text-lg font-semibold">タイムスタンプ一覧</h3>
                        </div>

                        <!-- 検索 -->
                        <div class="flex gap-2 mb-3">
                            <input type="text" id="timestampSearch" placeholder="タイムスタンプを検索..." class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button id="clearTimestampSearchBtn" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                                クリア
                            </button>
                        </div>

                        <!-- 全選択・選択解除ボタンと絞り込み -->
                        <div class="flex flex-col gap-2 mb-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-2 flex-wrap">
                                <button id="selectAllBtn" class="px-3 py-1 bg-blue-500 text-white text-sm rounded hover:bg-blue-600 disabled:bg-gray-400 disabled:cursor-not-allowed">
                                    全選択
                                </button>
                                <button id="deselectAllBtn" class="px-3 py-1 bg-gray-500 text-white text-sm rounded hover:bg-gray-600">
                                    選択解除
                                </button>
                                <span class="text-gray-300 dark:text-gray-600 mx-1">|</span>
                                <!-- 絞り込みボタン -->
                                <div class="flex gap-1 flex-wrap" id="filterButtons">
                                    <button data-filter="active" class="filter-btn px-3 py-1 text-sm rounded bg-blue-600 text-white">
                                        有効
                                    </button>
                                    <button data-filter="all" class="filter-btn px-3 py-1 text-sm rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                        全
                                    </button>
                                    <button data-filter="unlinked" class="filter-btn px-3 py-1 text-sm rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                        未紐付
                                    </button>
                                    <button data-filter="linked" class="filter-btn px-3 py-1 text-sm rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                        紐付済
                                    </button>
                                    <button data-filter="auto_linked" class="filter-btn px-3 py-1 text-sm rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                        自動
                                    </button>
                                    <button data-filter="pending" class="filter-btn px-3 py-1 text-sm rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                        保留
                                    </button>
                                    <button data-filter="not_song" class="filter-btn px-3 py-1 text-sm rounded bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600">
                                        非楽曲
                                    </button>
                                </div>
                            </div>

                            <!-- 楽曲フィルター表示エリア -->
                            <div id="songFilterArea" class="hidden flex items-center gap-2 p-2 bg-purple-100 dark:bg-purple-900 rounded border border-purple-300 dark:border-purple-700">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-purple-600 dark:text-purple-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                </svg>
                                <span class="text-xs text-purple-700 dark:text-purple-300 flex-shrink-0">楽曲絞り込み:</span>
                                <span id="songFilterText" class="text-xs font-medium text-purple-800 dark:text-purple-200 truncate flex-1" title=""></span>
                                <button id="clearSongFilterBtn" class="px-2 py-1 text-xs bg-purple-500 hover:bg-purple-600 text-white rounded flex-shrink-0 transition-colors">
                                    解除
                                </button>
                            </div>

                            <!-- 動画情報表示エリア -->
                            <div id="videoInfoArea" class="flex items-center gap-2">
                                <div class="text-xs text-gray-600 dark:text-gray-400 cursor-pointer hover:text-blue-600 transition-colors transition-all duration-200 ease-in-out flex-1"
                                     id="videoTitle"
                                     title=""
                                     x-data="{ expanded: false }"
                                     :class="expanded ? '' : 'truncate'"
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

                <!-- 右カラム: 楽曲情報・Spotify検索結果 -->
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-4 text-gray-900 dark:text-gray-100">
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
                            <div id="selectedLinkedSongContainer" class="flex items-center gap-2 mt-1">
                                <div id="selectedLinkedSong" class="text-xs break-words hidden" style="word-break: break-word; overflow-wrap: break-word;"></div>
                                <button id="selectedConfirmBtn" class="px-2 py-1 text-xs bg-yellow-500 hover:bg-yellow-600 text-white rounded transition-colors hidden flex-shrink-0">
                                    確定
                                </button>
                            </div>
                        </div>

                        <!-- Spotify選択楽曲情報表示 -->
                        <div id="spotifySelected" class="mb-3 p-3 bg-green-50 dark:bg-gray-700 rounded hidden">
                            <div class="text-sm text-gray-600 dark:text-gray-400 mb-1">Spotify選択楽曲</div>
                            <div id="spotifySelectedInfo" class="text-sm truncate" title=""></div>
                        </div>

                        <!-- タブ -->
                        <div class="mb-4">
                            <nav class="flex space-x-4 overflow-x-auto whitespace-nowrap border-b border-gray-200 dark:border-gray-700">
                                <button id="spotifyTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 {{ $spotifyEnabled ? 'border-green-500 text-green-600' : 'border-transparent text-gray-400 cursor-not-allowed' }} -mb-px" {{ $spotifyEnabled ? '' : 'disabled' }}>
                                    Spotify検索{{ $spotifyEnabled ? '' : '（無効）' }}
                                </button>
                                <button id="manualTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 {{ $spotifyEnabled ? 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' : 'border-green-500 text-green-600' }} -mb-px">
                                    手動登録
                                </button>
                                <button id="songsTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 -mb-px">
                                    楽曲マスタ
                                </button>
                                <button id="candidatesTab" class="tab-button px-3 py-2 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 -mb-px">
                                    候補
                                </button>
                            </nav>
                        </div>

                        <!-- Spotify検索結果 -->
                        <div id="spotifyResults" class="tab-content {{ $spotifyEnabled ? '' : 'hidden' }}">
                            @if($spotifyEnabled)
                                <div class="flex gap-2 mb-3">
                                    <input type="text" id="spotifySearch" placeholder="楽曲名 アーティスト名" class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <button id="searchSpotifyBtn" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                                        検索
                                    </button>
                                    <button id="clearSpotifySearchBtn" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                                        クリア
                                    </button>
                                </div>
                                <div id="spotifyTracks" class="space-y-2 max-h-64 overflow-y-auto">
                                    <p class="text-gray-500 dark:text-gray-400 text-sm">検索ボタンをクリックして楽曲を検索してください</p>
                                </div>
                            @else
                                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md">
                                    <p class="text-sm text-yellow-700 dark:text-yellow-400">Spotify API連携は現在無効になっています。手動登録または楽曲マスタ検索をご利用ください。</p>
                                </div>
                                {{-- JS互換用の非表示要素 --}}
                                <input type="hidden" id="spotifySearch">
                                <button id="searchSpotifyBtn" class="hidden"></button>
                                <button id="clearSpotifySearchBtn" class="hidden"></button>
                                <div id="spotifyTracks" class="hidden"></div>
                            @endif
                        </div>

                        <!-- 手動登録フォーム -->
                        <div id="manualForm" class="tab-content {{ $spotifyEnabled ? 'hidden' : '' }}">
                            <form id="createSongForm" class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">楽曲名 *</label>
                                    <input type="text" id="songTitle" name="title" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-2">アーティスト名 *</label>
                                    <input type="text" id="songArtist" name="artist" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-2">動画URL（任意）</label>
                                    <input type="text" id="songVideoUrl" name="video_url" placeholder="YouTube または ニコニコ動画のURL" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                            <div class="flex gap-2 mb-2">
                                <input type="text" id="songsSearch" placeholder="楽曲名やアーティスト名で検索（タイムスタンプの貼り付けも可）" class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <button id="clearSongsSearchBtn" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                                    クリア
                                </button>
                                <div id="songsCount" class="text-sm text-gray-600 dark:text-gray-400 flex items-center">
                                    <!-- JavaScriptで動的に更新 -->
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1 mb-2">
                                <button type="button" data-review-status="" class="song-review-filter px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300">全て</button>
                                <button type="button" data-review-status="needs_review" class="song-review-filter px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700">要確認</button>
                                <button type="button" data-review-status="safe" class="song-review-filter px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700">安全</button>

                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">検索方式:</span>
                                <button type="button" data-search-mode="fuzzy" class="song-search-mode px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" title="「/」「-」などの区切り文字を無視し、単語ごとに検索します。タイムスタンプをそのまま貼り付けて検索できます。">あいまい</button>
                                <button type="button" data-search-mode="exact" class="song-search-mode px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" title="入力した文字列をそのまま検索します。記号を含めて厳密に絞り込みたい場合に使用します。">完全一致</button>
                            </div>
                            <p id="songSearchModeHint" class="text-xs text-gray-500 dark:text-gray-400 mb-3"></p>
                            <div id="songsResults" class="space-y-2 max-h-64 overflow-y-auto">
                                <!-- 楽曲マスタリストがここに表示される -->
                            </div>
                        </div>

                        <!-- 選択したタイムスタンプに対する候補 -->
                        <div id="candidatesList" class="tab-content hidden">
                            <div id="candidateNotice" class="text-sm text-gray-500 dark:text-gray-400 mb-3"></div>

                            <div id="candidateTextArea" class="mb-3 hidden">
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">テキストを選択して検索語に追加できます</div>
                                <div id="candidateOriginalText" class="p-2 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded text-sm select-text cursor-text break-all leading-relaxed"></div>
                            </div>

                            <div id="candidateKeywordsArea" class="mb-3 hidden">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">検索語</span>
                                    <button id="candidateKeywordsClear" type="button" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">クリア</button>
                                </div>
                                <div id="candidateKeywords" class="flex flex-wrap gap-1.5"></div>
                            </div>

                            <div id="candidateResults" class="space-y-2 max-h-64 overflow-y-auto"></div>
                        </div>

                        <!-- アクションボタン -->
                        <div class="mt-6 space-y-2">
                            <button id="linkSongBtn" class="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                選択した楽曲と紐づける
                            </button>
                            <button id="markAsPendingBtn" class="w-full px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 disabled:bg-gray-400 disabled:cursor-not-allowed" disabled>
                                保留にする
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

    <!-- 楽曲編集モーダル -->
    <div id="editSongModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg w-full max-w-md mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">楽曲マスタ編集</h3>
                <button id="closeEditModalBtn" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form id="editSongForm" class="space-y-4">
                <input type="hidden" id="editSongId" name="id">
                <div>
                    <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">楽曲名 *</label>
                    <input type="text" id="editSongTitle" name="title" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">アーティスト名 *</label>
                    <input type="text" id="editSongArtist" name="artist" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">動画URL（任意）</label>
                    <div class="flex gap-2">
                        <input type="text" id="editSongVideoUrl" name="video_url" placeholder="YouTube または ニコニコ動画のURL" class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="button" id="fetchDurationBtn" class="px-3 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 whitespace-nowrap">
                            秒数取得
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">※ YouTubeはAPI使用（クォータ消費）、ニコニコ動画は無料API使用</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">楽曲の長さ</label>
                    <div class="flex items-center gap-2">
                        <input type="number" id="editSongDurationSeconds" name="duration_seconds" placeholder="秒" min="0" class="w-24 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <span class="text-gray-500 dark:text-gray-400">秒</span>
                        <span class="text-gray-400">=</span>
                        <input type="number" id="editSongDurationMs" name="duration_ms" placeholder="ミリ秒" min="0" class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <span id="editSongDurationFormatted" class="text-sm text-gray-500 dark:text-gray-400 min-w-[80px]"></span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">※ 秒またはミリ秒で入力可能（片方を入力すると自動変換）</p>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        保存
                    </button>
                    <button type="button" id="cancelEditBtn" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600">
                        キャンセル
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

<script>
    window.spotifyEnabled = @json($spotifyEnabled);
</script>
@vite('resources/js/songs/normalize.js')
