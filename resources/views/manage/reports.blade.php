<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/manage/reports.js')
    </x-slot>

    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('タイムスタンプ報告管理') }}
        </h2>
    </x-slot>

    <div class="py-6" x-data="reportManagement">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <!-- フィルター -->
                    <div class="mb-4 flex flex-wrap gap-4 items-center">
                        <div class="flex items-center gap-2">
                            <label class="text-sm font-medium">ステータス:</label>
                            <select x-model="statusFilter" @change="fetchReports(1)"
                                    class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm">
                                <option value="">すべて</option>
                                <option value="pending">未対応</option>
                                <option value="resolved">対応済み</option>
                            </select>
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            <span x-text="'全 ' + total + ' 件'"></span>
                        </div>
                    </div>

                    <!-- ローディング -->
                    <div x-show="loading" class="text-center py-8">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        <p class="mt-2 text-gray-500">読み込み中...</p>
                    </div>

                    <!-- エラー -->
                    <div x-show="error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <span x-text="error"></span>
                    </div>

                    <!-- 報告一覧 -->
                    <div x-show="!loading && reports.length > 0" class="space-y-4">
                        <template x-for="report in reports" :key="report.id">
                            <div class="border dark:border-gray-700 rounded-lg p-4"
                                 :class="report.status === 'resolved' ? 'bg-gray-50 dark:bg-gray-700/50' : 'bg-white dark:bg-gray-800'">
                                <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                                    <!-- 報告情報 -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="px-2 py-1 text-xs rounded-full"
                                                  :class="report.status === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'"
                                                  x-text="report.status === 'pending' ? '未対応' : '対応済み'"></span>
                                            <span class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-600 rounded-full" x-text="getReportTypeLabel(report.report_type)"></span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="formatDate(report.created_at)"></span>
                                        </div>

                                        <!-- タイムスタンプ情報 -->
                                        <div class="mb-2">
                                            <span class="text-sm font-medium">タイムスタンプ:</span>
                                            <span class="text-sm" x-text="report.ts_item?.text || '(削除済み)'"></span>
                                        </div>

                                        <!-- アーカイブ情報 -->
                                        <div class="mb-2 text-sm text-gray-600 dark:text-gray-400">
                                            <span>アーカイブ:</span>
                                            <a :href="'https://youtu.be/' + report.video_id + '?t=' + (report.ts_item?.ts_num || 0) + 's'"
                                               target="_blank"
                                               class="text-blue-600 hover:underline"
                                               x-text="report.ts_item?.archive?.title || report.video_id"></a>
                                        </div>

                                        <!-- 楽曲マッピング情報 -->
                                        <div class="mb-2 text-sm" x-show="report.ts_item">
                                            <span class="font-medium">YCS紐付け:</span>
                                            <template x-if="report.song_mapping?.is_not_song">
                                                <span class="text-gray-500 dark:text-gray-400">「楽曲ではない」に設定済み</span>
                                            </template>
                                            <template x-if="report.song_mapping && !report.song_mapping.is_not_song && report.song_mapping.song_title">
                                                <span>
                                                    <span class="text-green-600 dark:text-green-400" x-text="report.song_mapping.song_title"></span>
                                                    <span x-show="report.song_mapping?.song_artist" class="text-gray-500 dark:text-gray-400">
                                                        / <span x-text="report.song_mapping.song_artist"></span>
                                                    </span>
                                                    <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full"
                                                          :class="report.song_mapping?.is_manual ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-600 dark:text-gray-300'"
                                                          x-text="report.song_mapping?.is_manual ? '手動' : '自動'"></span>
                                                    <span x-show="report.song_mapping?.status === 'pending'"
                                                          class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">保留</span>
                                                </span>
                                            </template>
                                            <template x-if="!report.song_mapping || (!report.song_mapping.is_not_song && !report.song_mapping.song_title)">
                                                <span class="text-orange-500 dark:text-orange-400">未紐付け</span>
                                            </template>
                                        </div>

                                        <!-- コメント -->
                                        <div x-show="report.comment" class="mb-2">
                                            <span class="text-sm font-medium">コメント:</span>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 whitespace-pre-wrap" x-text="report.comment"></p>
                                        </div>

                                        <!-- 報告者IP（デバッグ用、本番では非表示にしても良い） -->
                                        <div class="text-xs text-gray-400">
                                            <span>報告者IP: </span><span x-text="report.reporter_ip"></span>
                                        </div>
                                    </div>

                                    <!-- アクションボタン -->
                                    <div class="flex flex-wrap gap-2 lg:flex-col lg:items-end">
                                        <!-- 対応済みにする -->
                                        <button x-show="report.status === 'pending'"
                                                @click="resolveReport(report.id)"
                                                :disabled="processingId === report.id"
                                                class="px-3 py-1.5 bg-green-600 hover:bg-green-700 disabled:bg-green-400 text-white text-sm rounded transition-colors">
                                            <span x-show="processingId !== report.id">対応済みにする</span>
                                            <span x-show="processingId === report.id">処理中...</span>
                                        </button>

                                        <!-- 楽曲ではない判定 -->
                                        <button x-show="report.status === 'pending' && report.ts_item"
                                                @click="markAsNotSong(report)"
                                                :disabled="processingId === report.id"
                                                class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 disabled:bg-gray-400 text-white text-sm rounded transition-colors">
                                            「楽曲ではない」に設定
                                        </button>

                                        <!-- YouTubeで確認 -->
                                        <a :href="'https://youtu.be/' + report.video_id + '?t=' + (report.ts_item?.ts_num || 0) + 's'"
                                           target="_blank"
                                           class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm rounded transition-colors inline-flex items-center gap-1">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                            </svg>
                                            確認
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- 報告なし -->
                    <div x-show="!loading && reports.length === 0" class="text-center py-8 text-gray-500">
                        報告はありません
                    </div>

                    <!-- ページネーション -->
                    <div x-show="lastPage > 1" class="mt-6 flex justify-center gap-2">
                        <button @click="fetchReports(currentPage - 1)"
                                :disabled="currentPage <= 1"
                                class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded disabled:opacity-50">
                            前へ
                        </button>
                        <span class="px-4 py-2 text-sm">
                            <span x-text="currentPage"></span> / <span x-text="lastPage"></span>
                        </span>
                        <button @click="fetchReports(currentPage + 1)"
                                :disabled="currentPage >= lastPage"
                                class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded disabled:opacity-50">
                            次へ
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
