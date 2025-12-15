<x-app-layout>
    <x-slot name="alpine_script">
        @vite('resources/js/manage/admins.js')
    </x-slot>

    <x-slot name="header">
        <h2 class="font-semibold sm:text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('管理者管理') }}
        </h2>
    </x-slot>

    <div class="py-6" x-data="adminManagement">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <!-- 管理者追加フォーム -->
                    <div class="mb-6 p-4 border dark:border-gray-700 rounded-lg">
                        <h3 class="text-lg font-medium mb-4">管理者を追加</h3>
                        <form @submit.prevent="addAdmin" class="flex flex-col sm:flex-row gap-4">
                            <div class="flex-1">
                                <input type="email"
                                       x-model="newAdminEmail"
                                       placeholder="メールアドレスを入力"
                                       class="w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                       required>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    ※ 先にGoogleログインしているユーザーのみ登録できます
                                </p>
                            </div>
                            <button type="submit"
                                    :disabled="processing"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white rounded-md transition-colors whitespace-nowrap">
                                <span x-show="!processing">追加</span>
                                <span x-show="processing">処理中...</span>
                            </button>
                        </form>
                    </div>

                    <!-- 成功メッセージ -->
                    <div x-show="successMessage" x-transition
                         class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                        <span x-text="successMessage"></span>
                    </div>

                    <!-- エラーメッセージ -->
                    <div x-show="error" x-transition
                         class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                        <span x-text="error"></span>
                    </div>

                    <!-- ローディング -->
                    <div x-show="loading" class="text-center py-8">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900 dark:border-gray-100"></div>
                        <p class="mt-2 text-gray-500">読み込み中...</p>
                    </div>

                    <!-- 管理者一覧 -->
                    <div x-show="!loading">
                        <h3 class="text-lg font-medium mb-4">管理者一覧</h3>
                        <div x-show="admins.length > 0" class="space-y-3">
                            <template x-for="admin in admins" :key="admin.id">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 border dark:border-gray-700 rounded-lg gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium" x-text="admin.name"></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400" x-text="admin.email"></div>
                                        <div class="text-xs text-gray-400 mt-1">
                                            <span>登録日: </span><span x-text="formatDate(admin.created_at)"></span>
                                            <span class="ml-2">チャンネル数: </span><span x-text="admin.channels_count"></span>
                                        </div>
                                    </div>
                                    <button @click="removeAdmin(admin.id, admin.name)"
                                            :disabled="removingId === admin.id"
                                            class="px-3 py-1.5 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white text-sm rounded transition-colors whitespace-nowrap">
                                        <span x-show="removingId !== admin.id">権限を削除</span>
                                        <span x-show="removingId === admin.id">処理中...</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <div x-show="admins.length === 0" class="text-center py-8 text-gray-500">
                            登録されている管理者はいません
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
