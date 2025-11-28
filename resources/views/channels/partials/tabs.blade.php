{{-- タブUI（モバイル専用） --}}
<div class="mb-4 sm:hidden">
    <nav class="flex space-x-4 border-b border-gray-200 dark:border-gray-700">
        <button @click="activeTab = 'timestamps'"
                :class="activeTab === 'timestamps' ? 'border-green-500 text-green-600 dark:text-green-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px hover:text-gray-700 dark:hover:text-gray-300">
            タイムスタンプ
        </button>
        <button @click="activeTab = 'archives'"
                :class="activeTab === 'archives' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px hover:text-gray-700 dark:hover:text-gray-300">
            アーカイブ
        </button>
    </nav>
</div>
