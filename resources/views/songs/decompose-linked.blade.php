@php
    $filters = [
        '' => 'すべて',
        'linked' => '紐付け済み',
        'unlinked' => '未紐付け',
        'empty_artist' => '⚠ アーティスト名が空',
        'changed' => '現在と異なる',
    ];
@endphp

<x-app-layout>
    <div class="py-4">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-4">
                            <h3 class="text-lg font-semibold">自動判定の紐付け内容</h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                {{ number_format($decompositions->total()) }}件
                            </span>
                        </div>
                        <a href="{{ route('songs.decompose') }}"
                           class="px-3 py-1 bg-gray-500 text-white text-sm rounded hover:bg-gray-600">
                            分解・選別に戻る
                        </a>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($filters as $value => $label)
                            @php $active = $filter === ($value === '' ? null : $value); @endphp
                            <a href="{{ route('songs.decompose.linked', $value === '' ? [] : ['filter' => $value]) }}"
                               class="px-3 py-1 text-sm rounded border {{ $active
                                   ? 'bg-purple-600 text-white border-purple-600'
                                   : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        紐付けが誤っている場合はタイムスタンプ正規化画面で修正してください。
                        「判定時」列はTS分解時の自動判定の結果で、正規化画面での変更は反映されません。
                        「現在」列は正規化画面での紐付け状態を表示しています。
                    </p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    @if ($decompositions->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">
                            {{-- 出し分けは total() で決める。currentPage() で分けると、総件数が
                                 0 に減ったあとに範囲外ページを再読込したとき
                                 （filter=unlinked を開いたまま別タブで一括紐付けすると起きる）
                                 「他のページには行がある」と読める文言だけが残る。 --}}
                            @if ($decompositions->total() === 0)
                                該当するアイテムがありません。
                            @else
                                このページには表示する行がありません。
                            @endif
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1060px] text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                        <th class="py-2 pr-4 font-medium">元テキスト</th>
                                        <th class="py-2 pr-4 font-medium">判定時</th>
                                        <th class="py-2 pr-4 font-medium">現在</th>
                                        <th class="py-2 pr-4 font-medium whitespace-nowrap">確信度</th>
                                        <th class="py-2 font-medium whitespace-nowrap">日時</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($decompositions as $decomposition)
                                        @php
                                            $title = $decomposition->song ? $decomposition->song->title : $decomposition->derived_title;
                                            $artist = $decomposition->song ? $decomposition->song->artist : $decomposition->derived_artist;
                                            $titleIsEmpty = $title === null || trim($title) === '';
                                            $artistIsEmpty = $artist === null || trim($artist) === '';

                                            $mapping = $decomposition->currentMapping;
                                            $currentSong = $mapping?->song;
                                            $isNotSong = $mapping?->is_not_song;
                                            $hasMapping = $mapping !== null;
                                            $isChanged = $decomposition->isMappingChanged();
                                        @endphp
                                        <tr class="border-b border-gray-100 dark:border-gray-700 {{ $isChanged ? 'bg-yellow-50 dark:bg-yellow-900/20' : ($titleIsEmpty || $artistIsEmpty ? 'bg-amber-50 dark:bg-amber-900/20' : '') }}">
                                            <td class="py-2 pr-4 break-all">
                                                <div class="flex items-start gap-2">
                                                    <span>{{ $decomposition->original_text }}</span>
                                                    <button type="button"
                                                            data-copy-text="{{ $decomposition->original_text }}"
                                                            class="shrink-0 px-2 py-0.5 text-xs border border-gray-300 dark:border-gray-600 rounded text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                                                            title="元テキストをコピー（正規化画面の検索に貼り付けられます）">
                                                        コピー
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="py-2 pr-4 break-all">
                                                @if ($titleIsEmpty)
                                                    <span class="text-amber-700 dark:text-amber-400 font-medium">⚠ 曲名なし</span>
                                                @else
                                                    <span class="text-blue-600 dark:text-blue-400">{{ $title }}</span>
                                                @endif
                                                <span class="text-gray-400">/</span>
                                                @if ($artistIsEmpty)
                                                    <span class="text-amber-700 dark:text-amber-400 font-medium">⚠ 未設定</span>
                                                @else
                                                    <span class="text-green-600 dark:text-green-400">{{ $artist }}</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-4 break-all">
                                                @if ($isNotSong)
                                                    <span class="text-orange-600 dark:text-orange-400">楽曲ではない</span>
                                                @elseif (! $hasMapping)
                                                    <span class="text-gray-400 dark:text-gray-500">マッピングなし</span>
                                                @elseif ($currentSong)
                                                    <span class="text-blue-600 dark:text-blue-400">{{ $currentSong->title }}</span>
                                                    <span class="text-gray-400">/</span>
                                                    <span class="text-green-600 dark:text-green-400">{{ $currentSong->artist ?: '(未設定)' }}</span>
                                                @else
                                                    <span class="text-gray-500 dark:text-gray-400">未紐付け</span>
                                                @endif
                                                @if ($isChanged)
                                                    <span class="ml-1 px-1.5 py-0.5 text-xs rounded bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200">変更あり</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-4 whitespace-nowrap">
                                                {{ $decomposition->confidence === null ? '-' : round($decomposition->confidence * 100).'%' }}
                                            </td>
                                            <td class="py-2 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                                <x-datetime :value="$decomposition->updated_at" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                    @endif

                    {{-- ページャは isEmpty() の分岐の外に置くこと。内側に入れると、範囲外ページ
                         （paginator は hasPages() = true で1ページ目へのリンクを持っている）で
                         ページャごと捨てられる。 --}}
                    @if ($decompositions->total() > 0)
                        <div class="mt-4">
                            {{ $decompositions->withQueryString()->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-copy-text]');
            if (!button) return;

            // 連続クリックでも元の文言に戻せるよう、最初の文言を保持しておく
            if (!button.dataset.copyLabel) {
                button.dataset.copyLabel = button.textContent.trim();
            }

            // 表示を戻すタイマーはボタンごとに1つだけにする
            const scheduleRestore = () => {
                clearTimeout(Number(button.dataset.copyTimerId));
                button.dataset.copyTimerId = setTimeout(() => {
                    button.textContent = button.dataset.copyLabel;
                }, 1500);
            };

            const notify = (message) => {
                button.textContent = message;
                scheduleRestore();
            };

            // HTTPなど非セキュアな環境では clipboard API が使えない
            if (!navigator.clipboard) {
                notify('コピーできません');
                return;
            }

            navigator.clipboard.writeText(button.dataset.copyText)
                .then(() => notify('コピーしました'))
                .catch(() => notify('コピーできません'));
        });
    </script>
</x-app-layout>
