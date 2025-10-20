<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ __('ログ詳細') }}
                </h2>
                <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">{{ $filename }}</p>
            </div>
            <div class="flex space-x-2">
                <a href="{{ route('logs.download', $filename) }}" 
                   class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm">
                    ダウンロード
                </a>
                <a href="{{ route('logs.index') }}" 
                   class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                    一覧に戻る
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- ページネーション（上部） -->
            @if($pagination['last_page'] > 1)
                <div class="flex justify-center mb-4">
                    <nav class="flex items-center space-x-2">
                        @if($pagination['has_prev'])
                            <a href="{{ route('logs.show', [$filename, 'page' => $pagination['current_page'] - 1]) }}" 
                               class="px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                前へ
                            </a>
                        @endif
                        
                        <span class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                            {{ $pagination['current_page'] }} / {{ $pagination['last_page'] }} ページ
                            (全 {{ number_format($pagination['total']) }} 行)
                        </span>
                        
                        @if($pagination['has_next'])
                            <a href="{{ route('logs.show', [$filename, 'page' => $pagination['current_page'] + 1]) }}" 
                               class="px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                次へ
                            </a>
                        @endif
                    </nav>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="bg-gray-900 text-white p-4 font-mono text-sm overflow-x-auto">
                    @if(count($parsedLines) > 0)
                        @foreach($parsedLines as $logLine)
                            <div class="flex hover:bg-gray-800 group">
                                <div class="w-12 text-gray-400 text-right pr-4 select-none flex-shrink-0">
                                    {{ $logLine['number'] }}
                                </div>
                                <div class="flex-1 
                                    @if($logLine['level'] === 'error') text-red-400
                                    @elseif($logLine['level'] === 'warning') text-yellow-400
                                    @elseif($logLine['level'] === 'info') text-blue-400
                                    @elseif($logLine['level'] === 'debug') text-gray-400
                                    @else text-white
                                    @endif
                                ">
                                    <span class="whitespace-pre-wrap break-all">{{ $logLine['content'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <div class="text-center text-gray-400 py-8">
                            ログファイルが空です
                        </div>
                    @endif
                </div>
            </div>

            <!-- ページネーション（下部） -->
            @if($pagination['last_page'] > 1)
                <div class="flex justify-center mt-4">
                    <nav class="flex items-center space-x-2">
                        @if($pagination['has_prev'])
                            <a href="{{ route('logs.show', [$filename, 'page' => $pagination['current_page'] - 1]) }}" 
                               class="px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                前へ
                            </a>
                        @endif
                        
                        <span class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                            {{ $pagination['current_page'] }} / {{ $pagination['last_page'] }} ページ
                        </span>
                        
                        @if($pagination['has_next'])
                            <a href="{{ route('logs.show', [$filename, 'page' => $pagination['current_page'] + 1]) }}" 
                               class="px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                次へ
                            </a>
                        @endif
                    </nav>
                </div>
            @endif

            <div class="mt-6 text-sm text-gray-600 dark:text-gray-400 bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm">
                <p class="font-semibold mb-2">ログの見方:</p>
                <ul class="list-disc list-inside space-y-1">
                    <li><span class="text-red-400 font-mono">赤色</span>: エラーレベル</li>
                    <li><span class="text-yellow-400 font-mono">黄色</span>: 警告レベル</li>
                    <li><span class="text-blue-400 font-mono">青色</span>: 情報レベル</li>
                    <li><span class="text-gray-400 font-mono">グレー</span>: デバッグレベル</li>
                    <li>最新のログが上に表示されます（{{ $pagination['per_page'] }}行ずつ表示）</li>
                </ul>
            </div>
        </div>
    </div>

    <style>
        /* スクロールバーのスタイリング */
        .bg-gray-900::-webkit-scrollbar {
            width: 8px;
        }
        .bg-gray-900::-webkit-scrollbar-track {
            background: #374151;
        }
        .bg-gray-900::-webkit-scrollbar-thumb {
            background: #6B7280;
            border-radius: 4px;
        }
        .bg-gray-900::-webkit-scrollbar-thumb:hover {
            background: #9CA3AF;
        }
    </style>
</x-app-layout>