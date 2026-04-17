<?php

namespace App\Console\Commands;

use App\Models\Song;
use App\Services\TimestampExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewSongStatus extends Command
{
    protected $signature = 'songs:review-status
        {--chunk=200 : チャンクサイズ}
        {--dry-run : 実際の更新を行わず、判定結果のみ表示}';

    protected $description = '全Songレコードの信頼度を判定し、review_status (safe/needs_review) を設定（装飾検出にstrip_pattern_templatesを使用）';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        $decorationPatterns = collect(config('strip_pattern_templates', []))
            ->map(fn ($t) => ['pattern' => $t['pattern'], 'is_regex' => $t['is_regex']])
            ->toArray();

        $stats = [Song::REVIEW_STATUS_SAFE => 0, Song::REVIEW_STATUS_NEEDS_REVIEW => 0, 'total' => 0];

        Song::with('mappings')->chunk($chunkSize, function ($songs) use ($decorationPatterns, $dryRun, &$stats) {
            $safeIds = [];
            $needsReviewIds = [];

            foreach ($songs as $song) {
                $stats['total']++;
                $status = $this->evaluateSong($song, $decorationPatterns);
                $stats[$status]++;

                if ($status === Song::REVIEW_STATUS_SAFE) {
                    $safeIds[] = $song->id;
                } else {
                    $needsReviewIds[] = $song->id;
                }

                if ($this->getOutput()->isVerbose()) {
                    $this->line("[{$status}] {$song->title} / {$song->artist}");
                }
            }

            if (! $dryRun) {
                if (! empty($safeIds)) {
                    DB::table('songs')->whereIn('id', $safeIds)
                        ->update(['review_status' => Song::REVIEW_STATUS_SAFE, 'updated_at' => now()]);
                }
                if (! empty($needsReviewIds)) {
                    DB::table('songs')->whereIn('id', $needsReviewIds)
                        ->update(['review_status' => Song::REVIEW_STATUS_NEEDS_REVIEW, 'updated_at' => now()]);
                }
            }
        });

        $safe = $stats[Song::REVIEW_STATUS_SAFE];
        $needsReview = $stats[Song::REVIEW_STATUS_NEEDS_REVIEW];
        $this->info("判定完了: 合計 {$stats['total']}件 (safe: {$safe}, needs_review: {$needsReview})");

        if ($dryRun) {
            $this->warn('--dry-run: 実際の更新は行われていません');
        }

        return Command::SUCCESS;
    }

    private function evaluateSong(Song $song, array $decorationPatterns): string
    {
        // 1. 装飾検出: title/artist にテンプレートパターンがヒットするか
        if ($this->hasDecoration($song, $decorationPatterns)) {
            return Song::REVIEW_STATUS_NEEDS_REVIEW;
        }

        // 2. 一致チェック: マッピングが存在し、normalized_text にtitle/artistが含まれるか
        $mappedTexts = $song->mappings->pluck('normalized_text')->toArray();

        if (empty($mappedTexts)) {
            return Song::REVIEW_STATUS_NEEDS_REVIEW;
        }

        $normalizedTitle = $song->normalized_title ?? '';
        $normalizedArtist = $song->normalized_artist ?? '';

        foreach ($mappedTexts as $text) {
            if ($normalizedTitle !== '' && str_contains($text, $normalizedTitle)) {
                return Song::REVIEW_STATUS_SAFE;
            }
            if ($normalizedArtist !== '' && str_contains($text, $normalizedArtist)) {
                return Song::REVIEW_STATUS_SAFE;
            }
        }

        return Song::REVIEW_STATUS_NEEDS_REVIEW;
    }

    private function hasDecoration(Song $song, array $decorationPatterns): bool
    {
        if (empty($decorationPatterns)) {
            return false;
        }

        if ($song->title) {
            $after = TimestampExtractorService::applyStripPatterns($song->title, $decorationPatterns);
            if ($after !== $song->title) {
                return true;
            }
        }

        if ($song->artist) {
            $after = TimestampExtractorService::applyStripPatterns($song->artist, $decorationPatterns);
            if ($after !== $song->artist) {
                return true;
            }
        }

        return false;
    }
}
