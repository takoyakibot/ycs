{{-- 報告モーダル --}}
<div x-show="showReportModal"
     x-cloak
     class="fixed inset-0 z-50 overflow-y-auto"
     role="dialog"
     aria-modal="true"
     aria-labelledby="report-modal-title"
     @click.self="showReportModal = false"
     @keydown.escape.window="showReportModal = false">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        {{-- 背景オーバーレイ --}}
        <div x-show="showReportModal"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75 dark:bg-gray-900 dark:bg-opacity-75"
             @click="showReportModal = false"></div>

        {{-- モーダルコンテンツ --}}
        <div x-show="showReportModal"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">

            <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <h3 id="report-modal-title" class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    タイムスタンプの報告
                </h3>

                <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded text-sm">
                    <div class="text-gray-700 dark:text-gray-300" x-text="reportTarget?.text || ''"></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1" x-text="reportTarget?.ts_text || ''"></div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        報告の種類を選択してください
                    </label>
                    <select x-model="reportType"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300">
                        <option value="">選択してください</option>
                        <option value="wrong_song">表示される楽曲名が違う</option>
                        <option value="not_song">楽曲ではない</option>
                        <option value="not_timestamp">タイムスタンプではない</option>
                        <option value="problem">問題がある</option>
                        <option value="other">その他</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        詳細（任意）
                    </label>
                    <textarea x-model="reportComment"
                              rows="3"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-300"
                              placeholder="詳細な情報があれば記入してください"></textarea>
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                <button @click="submitReport()"
                        :disabled="!reportType"
                        :class="!reportType ? 'opacity-50 cursor-not-allowed' : 'hover:bg-red-700'"
                        class="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    報告する
                </button>
                <button @click="showReportModal = false"
                        class="w-full sm:w-auto mt-3 sm:mt-0 px-4 py-2 bg-white dark:bg-gray-600 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-500 rounded-md hover:bg-gray-50 dark:hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                    キャンセル
                </button>
            </div>
        </div>
    </div>
</div>
