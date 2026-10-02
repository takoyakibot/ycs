<?php

namespace App\Http\Controllers;

use App\Exceptions\SongAuditResolutionException;
use App\Models\Song;
use App\Models\SongAudit;
use App\Models\TimestampSongMapping;
use App\Services\SongAuditResolutionService;
use App\Services\SongAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 楽曲マスタ・紐付けの点検結果（song_audits）を確認・対応する画面
 */
class SongAuditController extends Controller
{
    public const TABS = [
        'needs_fix' => '要修正',
        'ok' => '問題なし',
        'resolved' => '対応済み',
    ];

    public function __construct(
        private SongAuditService $auditService,
        private SongAuditResolutionService $resolutionService,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab');
        $tab = is_string($tab) && array_key_exists($tab, self::TABS) ? $tab : 'needs_fix';

        $query = SongAudit::query();
        match ($tab) {
            'needs_fix' => $query->where('verdict', SongAudit::VERDICT_NEEDS_FIX)
                ->where('resolution', SongAudit::RESOLUTION_PENDING),
            'ok' => $query->where('verdict', SongAudit::VERDICT_OK),
            'resolved' => $query->whereIn('resolution', [SongAudit::RESOLUTION_APPLIED, SongAudit::RESOLUTION_REJECTED]),
        };
        $audits = $query->orderByDesc('judged_at')->orderBy('id')->paginate(50)->withQueryString();

        return view('songs.audits', [
            'tab' => $tab,
            'audits' => $audits,
            'rows' => $this->buildRows($audits->getCollection()),
            'counts' => [
                'needs_fix' => SongAudit::where('verdict', SongAudit::VERDICT_NEEDS_FIX)
                    ->where('resolution', SongAudit::RESOLUTION_PENDING)->count(),
                'ok' => SongAudit::where('verdict', SongAudit::VERDICT_OK)->count(),
            ],
        ]);
    }

    public function apply(Request $request, SongAudit $audit): RedirectResponse
    {
        $userId = Auth::id();

        return $this->resolve(function () use ($request, $audit, $userId) {
            if ($audit->target_type === SongAudit::TARGET_SONG) {
                $validated = $request->validate([
                    'title' => ['required', 'string', 'max:255'],
                    'artist' => ['required', 'string', 'max:255'],
                ]);
                $this->resolutionService->applySong($audit, $validated['title'], $validated['artist'], $userId);

                return;
            }

            $validated = $request->validate([
                'action' => ['required', 'in:link,not_song'],
                'title' => ['required_if:action,link', 'nullable', 'string', 'max:255'],
                'artist' => ['required_if:action,link', 'nullable', 'string', 'max:255'],
            ]);
            if ($validated['action'] === 'not_song') {
                $this->resolutionService->applyMappingNotSong($audit, $userId);

                return '「楽曲ではない」にしました。';
            }

            $song = $this->resolutionService->applyMappingLink($audit, $validated['title'], $validated['artist'], $userId);

            return "「{$song->title} / {$song->artist}」に付け替えました。";
        }, '修正を適用しました。');
    }

    public function reject(SongAudit $audit): RedirectResponse
    {
        return $this->resolve(function () use ($audit) {
            $this->resolutionService->reject($audit);
        }, '却下しました。');
    }

    public function markNeedsFix(Request $request, SongAudit $audit): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->resolve(
            function () use ($audit, $validated) {
                $this->resolutionService->markNeedsFix($audit, $validated['reason'], Auth::id());
            },
            '要修正に変更しました。'
        );
    }

    private function resolve(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $message = $action();
        } catch (SongAuditResolutionException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', is_string($message) ? $message : $successMessage);
    }

    /**
     * 表示用に、対象の現在の内容と「判定後に変わったか」を添える
     *
     * @return array<string, array{target: mixed, changed: bool, texts: array<int, string>}>
     */
    private function buildRows($audits): array
    {
        $songIds = $audits->where('target_type', SongAudit::TARGET_SONG)->pluck('target_id');
        $mappingIds = $audits->where('target_type', SongAudit::TARGET_MAPPING)->pluck('target_id');

        $songs = Song::with('tags')->whereIn('id', $songIds)->get()->keyBy('id');
        $mappings = TimestampSongMapping::with('song')->whereIn('id', $mappingIds)->get()->keyBy('id');

        // 紐付けの元テキストの例（normalized_text だけでは判断しにくいため）
        $originals = DB::table('ts_items')
            ->whereIn('normalized_text', $mappings->pluck('normalized_text'))
            ->select(['normalized_text', 'text'])
            ->distinct()
            ->orderBy('text')
            ->get()
            ->groupBy('normalized_text')
            ->map(fn ($rows) => $rows->pluck('text')->take(3)->all());

        $rows = [];
        foreach ($audits as $audit) {
            $target = $audit->target_type === SongAudit::TARGET_SONG
                ? $songs->get($audit->target_id)
                : $mappings->get($audit->target_id);

            $rows[$audit->id] = [
                'target' => $target,
                'changed' => $target !== null
                    && $this->auditService->fingerprint($audit->target_type, $target) !== $audit->fingerprint,
                'texts' => $target instanceof TimestampSongMapping ? ($originals[$target->normalized_text] ?? []) : [],
            ];
        }

        return $rows;
    }
}
