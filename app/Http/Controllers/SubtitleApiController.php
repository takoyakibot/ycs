<?php

namespace App\Http\Controllers;

use App\Helpers\QueryHelper;
use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Models\Song;
use App\Models\TsItem;
use App\Models\VideoSubtitle;
use App\Services\SubtitleFingerprintService;
use App\Services\SubtitleMatchingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SubtitleApiController extends Controller
{
    use ManageAccessControl;

    public function __construct(
        private SubtitleFingerprintService $fingerprintService,
        private SubtitleMatchingService $matchingService,
    ) {}

    /**
     * 字幕データを保存（Chrome拡張から自動送信）
     * UPSERT: 同じvideo_id + language_code + kindなら更新
     */
    public function store(Request $request)
    {
        $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'language_code' => ['required', 'string', 'regex:/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]+)*$/', 'max:20'],
            'kind' => ['present', 'nullable', 'string', Rule::in(['asr', ''])],
            'subtitles' => ['required', 'array', 'min:1', 'max:50000'],
            'subtitles.*.start' => ['required', 'numeric', 'min:0', 'max:604800'],
            'subtitles.*.duration' => ['required', 'numeric', 'min:0', 'max:600'],
            'subtitles.*.text' => ['nullable', 'string', 'max:1000'],
        ]);

        $videoId = $request->input('video_id');
        $languageCode = $request->input('language_code');
        $kind = $request->input('kind') ?? '';
        $subtitles = $request->input('subtitles');

        $archive = Archive::where('video_id', $videoId)->first();
        if ($archive) {
            $channel = $archive->channel;
            if (! $channel || ! $this->canAccessChannel($channel)) {
                return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
            }
        }

        try {
            $subtitle = VideoSubtitle::updateOrCreate(
                [
                    'video_id' => $videoId,
                    'language_code' => $languageCode,
                    'kind' => $kind,
                ],
                [
                    'subtitle_data' => $subtitles,
                    'segment_count' => count($subtitles),
                ]
            );

            $isNew = $subtitle->wasRecentlyCreated;

            if ($archive && $archive->subtitles_unavailable_at !== null) {
                $archive->update(['subtitles_unavailable_at' => null]);
            }

            // フィンガープリントを自動生成
            $fingerprintCount = $this->fingerprintService->generateFingerprintsForVideo($videoId);

            Log::info('字幕データ保存成功', [
                'video_id' => $videoId,
                'language_code' => $languageCode,
                'kind' => $kind,
                'segment_count' => count($subtitles),
                'is_new' => $isNew,
                'fingerprints_generated' => $fingerprintCount,
            ]);

            return response()->json([
                'id' => $subtitle->id,
                'segment_count' => $subtitle->segment_count,
                'is_new' => $isNew,
                'fingerprints_generated' => $fingerprintCount,
            ]);
        } catch (Exception $e) {
            Log::error('字幕データ保存エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => '字幕データの保存に失敗しました'], 500);
        }
    }

    /**
     * 字幕データを取得
     */
    public function show(Request $request)
    {
        $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
        ]);

        $videoId = $request->input('video_id');

        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        $subtitles = VideoSubtitle::where('video_id', $videoId)->get();

        return response()->json([
            'video_id' => $videoId,
            'subtitles' => $subtitles->map(fn (VideoSubtitle $s) => [
                'id' => $s->id,
                'language_code' => $s->language_code,
                'kind' => $s->kind,
                'segment_count' => $s->segment_count,
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ]),
        ]);
    }

    /**
     * 字幕未取得アーカイブの一覧を取得（Chrome拡張の一括取得スキャン用）
     *
     * アクセス可能なチャンネルの表示中アーカイブのうち、
     * 表示中のタイムスタンプがあり字幕が未保存のものを返す
     */
    public function subtitleTargets(Request $request)
    {
        $targets = $this->accessibleDisplayedArchives($request->user())
            ->whereHas('tsItemsDisplay')
            ->whereNotIn('video_id', VideoSubtitle::query()->select('video_id'))
            ->whereNull('subtitles_unavailable_at')
            ->orderByDesc('published_at')
            ->limit(500)
            ->get(['video_id', 'title']);

        return $this->targetsResponse($targets);
    }

    /**
     * 字幕が存在しないことを記録（Chrome拡張から報告）
     *
     * 字幕一括取得スキャンの対象から除外するためのフラグ。
     * 後から字幕が付いた場合はstore()で自動的にクリアされる
     */
    public function markSubtitlesUnavailable(Request $request)
    {
        $validated = $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
        ]);

        $archive = Archive::where('video_id', $validated['video_id'])->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        $archive->update(['subtitles_unavailable_at' => now()]);

        return response()->json(['video_id' => $archive->video_id, 'subtitles_unavailable_at' => $archive->subtitles_unavailable_at]);
    }

    /**
     * 音量リストスキャン対象のアーカイブ一覧を取得（Chrome拡張用）
     *
     * 音量スキャンはタイムスタンプ作成の支援が目的のため、
     * 表示中のタイムスタンプが1件もない表示中アーカイブを返す
     */
    public function scanTargets(Request $request)
    {
        $targets = $this->accessibleDisplayedArchives($request->user())
            ->whereDoesntHave('tsItemsDisplay')
            ->orderByDesc('published_at')
            ->limit(500)
            ->get(['video_id', 'title']);

        return $this->targetsResponse($targets);
    }

    /**
     * 動画のタイムスタンプ作成状況を返す（リストスキャン用）
     */
    public function timestampStatus(Request $request)
    {
        $validated = $request->validate([
            'video_ids' => ['required', 'string', 'max:6000'],
        ]);

        $videoIds = array_unique(array_filter(
            array_map('trim', explode(',', $validated['video_ids'])),
            fn ($id) => preg_match('/^[A-Za-z0-9_-]{11}$/', $id)
        ));

        if (empty($videoIds)) {
            return response()->json(['statuses' => []]);
        }

        $accessibleVideoIds = $this->accessibleDisplayedArchives($request->user())
            ->whereIn('video_id', $videoIds)
            ->pluck('video_id');

        $counts = \App\Models\TsItem::whereIn('video_id', $accessibleVideoIds)
            ->where('is_display', '1')
            ->groupBy('video_id')
            ->selectRaw('video_id, count(*) as count')
            ->pluck('count', 'video_id');

        $statuses = [];
        foreach ($videoIds as $id) {
            $statuses[$id] = $counts->get($id, 0);
        }

        return response()->json(['statuses' => $statuses]);
    }

    /**
     * アクセス可能なチャンネルの表示中アーカイブのベースクエリ
     * （一般管理者は自分のチャンネル、スーパー管理者は全チャンネル）
     */
    private function accessibleDisplayedArchives($user): \Illuminate\Database\Eloquent\Builder
    {
        $channelIds = \App\Models\Channel::query()
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->pluck('channel_id');

        return Archive::whereIn('channel_id', $channelIds)
            ->where('is_display', true);
    }

    private function targetsResponse($targets)
    {
        return response()->json([
            'count' => $targets->count(),
            'targets' => $targets->map(fn ($a) => [
                'video_id' => $a->video_id,
                'title' => $a->title,
            ])->values(),
        ]);
    }

    /**
     * 再生位置から楽曲候補を取得（Chrome拡張のマーカー用）
     *
     * ts_itemが未作成のローカルマーカーに対して、保存済み字幕から
     * 指定位置の窓を切り出してフィンガープリント照合を行う
     */
    public function matchByPosition(Request $request)
    {
        $validated = $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'sec' => ['required', 'integer', 'min:0', 'max:86400'],
            'threshold' => ['sometimes', 'numeric', 'min:0.01', 'max:1.0'],
        ]);

        $archive = Archive::where('video_id', $validated['video_id'])->first();
        if ($archive) {
            $channel = $archive->channel;
            if (! $channel || ! $this->canAccessChannel($channel)) {
                return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
            }
        }

        try {
            $threshold = isset($validated['threshold'])
                ? (float) $validated['threshold']
                : SubtitleMatchingService::DEFAULT_THRESHOLD;

            $result = $this->matchingService->getCandidateSongsForPosition(
                $validated['video_id'],
                $validated['sec'],
                $threshold
            );

            return response()->json(array_merge([
                'video_id' => $validated['video_id'],
                'sec' => $validated['sec'],
                'threshold' => $threshold,
            ], $result));
        } catch (Exception $e) {
            Log::error('再生位置の楽曲マッチングエラー', [
                'video_id' => $validated['video_id'],
                'sec' => $validated['sec'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => '楽曲マッチングに失敗しました'], 500);
        }
    }

    /**
     * 曲名サジェスト（Chrome拡張のタイムスタンプエディタ用）
     *
     * songsテーブルとts_itemsテーブルの両方から検索し、
     * songsを優先しつつts_itemsで補完する
     */
    public function songSuggest(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = $validated['q'];
        $limit = 10;
        $results = [];

        // 1. songsテーブルから検索（title / artist）
        $songQuery = Song::query();
        QueryHelper::applyFuzzySearch($songQuery, $query, ['normalized_title', 'normalized_artist']);
        $songs = $songQuery->limit($limit)->get(['id', 'title', 'artist']);

        if ($songs->isNotEmpty()) {
            $songIds = $songs->pluck('id')->all();
            $tsCounts = TsItem::query()
                ->join('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
                ->whereIn('timestamp_song_mappings.song_id', $songIds)
                ->where('ts_items.is_display', '1')
                ->where(function ($q) {
                    $q->whereNull('timestamp_song_mappings.is_not_song')
                        ->orWhere('timestamp_song_mappings.is_not_song', false);
                })
                ->groupBy('timestamp_song_mappings.song_id')
                ->pluck(\DB::raw('COUNT(*)'), 'timestamp_song_mappings.song_id');
        }

        foreach ($songs as $song) {
            $display = $song->artist ? "{$song->title} / {$song->artist}" : $song->title;
            $entry = [
                'text' => $display,
                'source' => 'song',
            ];
            $count = $tsCounts[$song->id] ?? 0;
            if ($count > 0) {
                $entry['ts_count'] = $count;
            }
            $results[] = $entry;
        }

        // 2. ts_itemsテーブルから補完（マスタにない表記を拾う）
        $remaining = $limit - count($results);
        if ($remaining > 0) {
            $songTexts = array_map(fn ($r) => $r['text'], $results);

            $tsQuery = TsItem::query()
                ->select('ts_items.text', 'songs.title as song_title', 'songs.artist as song_artist', \DB::raw('COUNT(*) as ts_count'))
                ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
                ->leftJoin('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
                ->where('ts_items.is_display', '1')
                ->where(function ($q) {
                    $q->whereNull('timestamp_song_mappings.is_not_song')
                        ->orWhere('timestamp_song_mappings.is_not_song', false);
                });

            $escaped = QueryHelper::escapeLikeString($query);
            $tsQuery->where('ts_items.text', 'like', "%{$escaped}%");

            $tsItems = $tsQuery
                ->groupBy('ts_items.text', 'songs.title', 'songs.artist')
                ->limit($limit * 3)
                ->get();

            foreach ($tsItems as $item) {
                if (count($results) >= $limit) {
                    break;
                }
                $display = $item->text;
                // songsのヒットと重複しないか確認
                if (in_array($display, $songTexts, true)) {
                    continue;
                }
                // マッピング先のsongの「曲名 / アーティスト」とも重複チェック
                if ($item->song_title) {
                    $songDisplay = $item->song_artist
                        ? "{$item->song_title} / {$item->song_artist}"
                        : $item->song_title;
                    if (in_array($songDisplay, $songTexts, true)) {
                        continue;
                    }
                }
                $entry = [
                    'text' => $display,
                    'source' => 'ts_item',
                ];
                if ($item->ts_count > 1) {
                    $entry['ts_count'] = (int) $item->ts_count;
                }
                $results[] = $entry;
                $songTexts[] = $display;
            }
        }

        return response()->json(['suggestions' => $results]);
    }

    /**
     * 楽曲候補を取得（字幕フィンガープリントベースのマッチング）
     */
    public function matchCandidates(string $tsItemId)
    {
        // ts_itemの存在確認とアクセス権チェック
        $tsItem = \App\Models\TsItem::find($tsItemId);
        if (! $tsItem) {
            return response()->json(['message' => '指定されたタイムスタンプが見つかりません'], 404);
        }

        $archive = Archive::where('video_id', $tsItem->video_id)->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        try {
            $result = $this->matchingService->getCandidateSongs($tsItemId);

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('楽曲マッチングエラー', [
                'ts_item_id' => $tsItemId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => '楽曲マッチングに失敗しました'], 500);
        }
    }
}
