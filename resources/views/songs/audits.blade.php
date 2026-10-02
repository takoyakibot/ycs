@php
    use App\Http\Controllers\SongAuditController;
    use App\Models\SongAudit;

    $inputClass = 'w-full px-2 py-1 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded focus:outline-none focus:ring-2 focus:ring-blue-500';
    $resolutionLabels = [
        SongAudit::RESOLUTION_APPLIED => '適用済み',
        SongAudit::RESOLUTION_REJECTED => '却下',
    ];
@endphp

<x-app-layout>
    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center gap-4">
                        <h3 class="text-lg font-semibold">点検結果</h3>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ number_format($audits->total()) }}件</span>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (SongAuditController::TABS as $value => $label)
                            <a href="{{ route('songs.audits.index', ['tab' => $value]) }}"
                               class="px-3 py-1 text-sm rounded border {{ $tab === $value
                                   ? 'bg-purple-600 text-white border-purple-600'
                                   : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                                {{ $label }}
                                @isset($counts[$value])
                                    <span class="ml-1 text-xs">({{ number_format($counts[$value]) }})</span>
                                @endisset
                            </a>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Claude Code 等が点検した結果です。修正案は入力欄で手直ししてから適用できます。
                        判定時から対象の内容が変わっているものは適用できません（次回の点検で改めて判定されます）。
                    </p>
                </div>
            </div>

            @if (session('success'))
                <div class="mb-4 p-3 rounded-md text-sm bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="mb-4 p-3 rounded-md text-sm bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 p-3 rounded-md text-sm bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">{{ $errors->first() }}</div>
            @endif

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 text-gray-900 dark:text-gray-100">
                    @if ($audits->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">該当する点検結果がありません。</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($audits as $audit)
                                @php
                                    $row = $rows[$audit->id];
                                    $target = $row['target'];
                                    $isSong = $audit->target_type === SongAudit::TARGET_SONG;
                                    $suggestion = $audit->suggestion ?? [];
                                    $useOld = old('audit_id') === $audit->id;
                                    $canApply = $target !== null && ! $row['changed'];
                                    if ($isSong) {
                                        $defaultTitle = $suggestion['title'] ?? $target?->title;
                                        $defaultArtist = $suggestion['artist'] ?? $target?->artist;
                                    } else {
                                        $defaultTitle = $suggestion['title'] ?? $target?->song?->title;
                                        $defaultArtist = $suggestion['artist'] ?? $target?->song?->artist;
                                    }
                                    $defaultAction = ($suggestion['is_not_song'] ?? false) ? 'not_song' : 'link';
                                @endphp
                                <div class="border border-gray-200 dark:border-gray-700 rounded p-3 {{ $row['changed'] || $target === null ? 'bg-gray-50 dark:bg-gray-900/40' : '' }}"
                                     data-audit-id="{{ $audit->id }}">
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="px-1.5 py-0.5 rounded {{ $isSong ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' }}">
                                            {{ $isSong ? '楽曲マスタ' : '紐付け' }}
                                        </span>
                                        <span>判定: {{ $audit->judged_by }}</span>
                                        <x-datetime :value="$audit->judged_at" />
                                        @if ($target === null)
                                            <span class="px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200">対象が削除されています</span>
                                        @elseif ($row['changed'])
                                            <span class="px-1.5 py-0.5 rounded bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200">判定後に変更あり</span>
                                        @endif
                                        @isset($resolutionLabels[$audit->resolution])
                                            <span class="px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200">{{ $resolutionLabels[$audit->resolution] }}</span>
                                        @endisset
                                    </div>

                                    {{-- 対象の現在の内容 --}}
                                    <div class="mt-2 text-sm break-all">
                                        @if ($isSong && $target)
                                            <span class="text-blue-600 dark:text-blue-400">{{ $target->title }}</span>
                                            <span class="text-gray-400">/</span>
                                            <span class="text-green-600 dark:text-green-400">{{ $target->artist ?: '(未設定)' }}</span>
                                            @if ($target->tags->isNotEmpty())
                                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">タグ: {{ $target->tags->pluck('value')->join(', ') }}</span>
                                            @endif
                                        @elseif (! $isSong && $target)
                                            <span>{{ implode(' ｜ ', $row['texts'] ?: [$target->normalized_text]) }}</span>
                                            <span class="text-gray-400 mx-1">→</span>
                                            @if ($target->is_not_song)
                                                <span class="text-orange-600 dark:text-orange-400">楽曲ではない</span>
                                            @elseif ($target->song)
                                                <span class="text-blue-600 dark:text-blue-400">{{ $target->song->title }}</span>
                                                <span class="text-gray-400">/</span>
                                                <span class="text-green-600 dark:text-green-400">{{ $target->song->artist ?: '(未設定)' }}</span>
                                            @else
                                                <span class="text-gray-500">未紐付け</span>
                                            @endif
                                        @endif
                                    </div>

                                    @if ($audit->reason)
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">理由: {{ $audit->reason }}</p>
                                    @endif
                                    @if (! empty($suggestion))
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            修正案:
                                            @if ($suggestion['is_not_song'] ?? false) 楽曲ではない @endif
                                            @isset($suggestion['title']) 曲名「{{ $suggestion['title'] }}」 @endisset
                                            @isset($suggestion['artist']) アーティスト「{{ $suggestion['artist'] }}」 @endisset
                                        </p>
                                    @endif

                                    @if ($tab === 'needs_fix')
                                        <div class="mt-2 flex flex-wrap items-end gap-2">
                                            <form method="POST" action="{{ route('songs.audits.apply', $audit) }}" class="flex flex-wrap items-end gap-2 flex-1 min-w-[300px]">
                                                @csrf
                                                <input type="hidden" name="audit_id" value="{{ $audit->id }}">
                                                @unless ($isSong)
                                                    <select name="action" class="{{ $inputClass }} w-auto" @disabled(! $canApply)>
                                                        <option value="link" @selected(($useOld ? old('action') : $defaultAction) === 'link')>別の楽曲に付け替え</option>
                                                        <option value="not_song" @selected(($useOld ? old('action') : $defaultAction) === 'not_song')>楽曲ではない</option>
                                                    </select>
                                                @endunless
                                                <label class="flex-1 min-w-[140px] text-xs text-gray-500 dark:text-gray-400">
                                                    曲名
                                                    <input type="text" name="title" value="{{ $useOld ? old('title') : $defaultTitle }}" class="{{ $inputClass }}" @disabled(! $canApply)>
                                                </label>
                                                <label class="flex-1 min-w-[140px] text-xs text-gray-500 dark:text-gray-400">
                                                    アーティスト
                                                    <input type="text" name="artist" value="{{ $useOld ? old('artist') : $defaultArtist }}" class="{{ $inputClass }}" @disabled(! $canApply)>
                                                </label>
                                                <button type="submit" class="px-3 py-1 text-sm rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50" @disabled(! $canApply)>
                                                    適用
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('songs.audits.reject', $audit) }}">
                                                @csrf
                                                <button type="submit" class="px-3 py-1 text-sm rounded bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-400 dark:hover:bg-gray-500">
                                                    却下
                                                </button>
                                            </form>
                                        </div>
                                    @elseif ($tab === 'ok')
                                        <form method="POST" action="{{ route('songs.audits.needsFix', $audit) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                            @csrf
                                            <input type="hidden" name="audit_id" value="{{ $audit->id }}">
                                            <input type="text" name="reason" value="{{ $useOld ? old('reason') : '' }}" placeholder="要修正とする理由" class="{{ $inputClass }} flex-1 min-w-[200px]">
                                            <button type="submit" class="px-3 py-1 text-sm rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                                                要修正に変更
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($audits->total() > 0)
                        <div class="mt-4">{{ $audits->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
