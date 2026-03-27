<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('チャンネル設定') }}
        </h2>
    </x-slot>

    <div class="px-2 sm:px-6 py-4 sm:py-12">
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="p-2">
            <!-- チャンネル情報 + ナビゲーション（1行表示） -->
            <div class="flex items-center justify-center gap-4 flex-wrap">
                <a href="{{ url('https://youtube.com/@' . $channel->handle) }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 hover:opacity-80 text-gray-600 dark:text-gray-400">
                    <img src="{{ $channel->thumbnail ?? '' }}" alt="アイコン" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full">
                    <span class="font-bold text-sm sm:text-base">{{ $channel->title ?? '' }}</span>
                </a>

                <div class="h-6 w-px bg-gray-300 dark:bg-gray-600"></div>

                <div class="flex gap-2">
                    <a href="{{ route('manage.show', $channel->handle) }}" class="px-3 sm:px-4 py-1.5 sm:py-2 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg font-medium text-sm hover:opacity-80 transition-colors">
                        アーカイブ管理
                    </a>
                    <span class="px-3 sm:px-4 py-1.5 sm:py-2 bg-blue-500 text-white rounded-lg font-medium text-sm">
                        チャンネル設定
                    </span>
                </div>
            </div>
        </div>

        <div class="p-2 flex flex-col justify-self-center w-[100%] max-w-3xl gap-6 mt-4">
            <!-- 除外ワード管理セクション -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4">除外ワード管理</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    カバー曲（歌ってみた）動画のタイトルから除外するワードを設定します。<br>
                    VTuberの名前や二つ名など、楽曲名ではないテキストを登録することで、楽曲マッピングの精度が向上します。
                </p>

                <!-- 除外ワード追加フォーム -->
                <div class="flex items-center gap-2 mb-4">
                    <x-text-input type="text" id="newExcludedWord" placeholder="除外ワードを入力" class="flex-1" />
                    <x-primary-button id="addExcludedWordBtn" type="button">追加</x-primary-button>
                </div>
                <div id="excludedWordError" class="text-red-500 text-sm mb-4 hidden"></div>

                <!-- 除外ワード一覧 -->
                <div id="excludedWordsList" class="border dark:border-gray-700 rounded-lg overflow-hidden">
                    <div class="text-center text-gray-500 dark:text-gray-400 py-4">読み込み中...</div>
                </div>
            </div>

            <!-- 除去パターン管理セクション -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4">除去パターン管理</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    タイムスタンプテキストから除去する文字列パターンを設定します。<br>
                    装飾絵文字や記号など、楽曲名の一部ではない文字列を登録することで、紐付けの精度が向上します。<br>
                    例: 🎵, ♪, ▶, 【, 】
                </p>

                <!-- 除去パターン追加フォーム -->
                <div class="flex items-center gap-2 mb-4">
                    <x-text-input type="text" id="newStripPattern" placeholder="除去する文字列を入力" class="flex-1" />
                    <x-primary-button id="addStripPatternBtn" type="button">追加</x-primary-button>
                </div>
                <div id="stripPatternError" class="text-red-500 text-sm mb-4 hidden"></div>

                <!-- 除去パターン一覧 -->
                <div id="stripPatternsList" class="border dark:border-gray-700 rounded-lg overflow-hidden">
                    <div class="text-center text-gray-500 dark:text-gray-400 py-4">読み込み中...</div>
                </div>

                <!-- 再適用ボタン -->
                <div class="mt-4">
                    <x-primary-button id="reapplyStripPatternsBtn" type="button" class="bg-orange-600 hover:bg-orange-700">
                        既存タイムスタンプに再適用
                    </x-primary-button>
                    <span class="text-xs text-gray-500 dark:text-gray-400 ml-2">パターン変更後に実行すると、既存のタイムスタンプのnormalized_textが更新されます</span>
                </div>
                <div id="stripPatternMessage" class="text-sm mt-2 hidden"></div>
            </div>

            <!-- カバー曲プレビューセクション -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4">カバー曲抽出プレビュー</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    現在の除外ワード設定で、カバー曲がどのように抽出されるかを確認できます。
                </p>

                <div class="flex items-center gap-2 mb-4">
                    <x-primary-button id="loadPreviewBtn" type="button">プレビュー読み込み</x-primary-button>
                    <x-primary-button id="reprocessBtn" type="button" class="bg-orange-600 hover:bg-orange-700">紐付け再実行</x-primary-button>
                </div>
                <div id="previewMessage" class="text-sm mb-4 hidden"></div>

                <!-- プレビュー一覧 -->
                <div id="previewList" class="border dark:border-gray-700 rounded-lg overflow-hidden hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">元タイトル</th>
                                <th class="px-4 py-2 text-left">抽出結果</th>
                                <th class="px-4 py-2 text-left">マッピング状態</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                        </tbody>
                    </table>
                </div>
                <div id="noPreviewData" class="text-center text-gray-500 dark:text-gray-400 py-4 hidden">
                    カバー曲がありません
                </div>
            </div>
        </div>
    </div>

    <x-text-input type="hidden" id="cryptHandle" value="{{ $crypt_handle }}" />
</x-app-layout>

@vite('resources/js/manage/settings.js')
