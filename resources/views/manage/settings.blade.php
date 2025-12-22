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
            <h2 class="text-gray-500 sm:flex items-center justify-center gap-4 hidden">
                <a href="{{ url('https://youtube.com/@' . $channel->handle) }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4 hover:opacity-80">
                    <img src="{{ $channel->thumbnail ?? '' }}" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold text-black dark:text-gray-200">{{ $channel->title ?? '' }}</span>
                </a>
            </h2>
            <h2 class="text-gray-500 justify-self-center sm:hidden">
                <a href="{{ url('https://youtube.com/@' . $channel->handle) }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-4">
                    <img src="{{ $channel->thumbnail }}" alt="アイコン" class="w-20 h-20 rounded-full">
                    <span class="text-lg font-bold text-black">{{ $channel->title }}</span>
                </a>
            </h2>

            <!-- ナビゲーション -->
            <div class="flex items-center justify-center gap-4 mt-4">
                <a href="{{ route('manage.show', $channel->handle) }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                    アーカイブ管理
                </a>
                <span class="text-gray-400">|</span>
                <span class="font-bold text-gray-800 dark:text-gray-200">チャンネル設定</span>
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
