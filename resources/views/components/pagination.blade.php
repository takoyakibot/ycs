@props(['total' => null, 'currentPage' => null, 'lastPage' => null])

<!-- pagination.blade.php -->
<div id="paginationButtons" class="flex gap-2 justify-center items-center w-[100%]">
    <!-- 前へボタン -->
    <button class="pagination-button prev" aria-label="Previous page">
        <
    </button>

    @if($total !== null)
    <!-- 件数表示 -->
    <div class="flex">
        <p class="text-sm text-gray-600 dark:text-gray-400 px-4">
            <span x-text="archives.total || {{ $total }}"></span>件
            @if($currentPage !== null && $lastPage !== null)
            <template x-if="archives.current_page && archives.last_page && archives.last_page > 1">
                <span>
                    (ページ <span x-text="archives.current_page || {{ $currentPage }}"></span> / <span x-text="archives.last_page || {{ $lastPage }}"></span>)
                </span>
            </template>
            @endif
        </p>
    </div>
    @endif

    <!-- 次へボタン -->
    <button class="pagination-button next" aria-label="Next page">
        >
    </button>
</div>
