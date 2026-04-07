<?php

namespace App\Jobs;

use App\Helpers\TextNormalizer;
use App\Models\Channel;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\TimestampExtractorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReapplyStripPatternsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ジョブのタイムアウト（5分） */
    public int $timeout = 300;

    /** リトライ回数 */
    public int $tries = 1;

    public function __construct(
        public Channel $channel,
        public array $stripPatterns,
    ) {}

    public function handle(): void
    {
        Log::info('ReapplyStripPatternsJob started', [
            'channel_id' => $this->channel->channel_id,
            'strip_patterns_count' => count($this->stripPatterns),
        ]);

        try {
            $updatedCount = 0;

            DB::transaction(function () use (&$updatedCount) {
                $oldNormalizedTexts = [];

                TsItem::whereHas('archive', function ($q) {
                    $q->where('channel_id', $this->channel->channel_id);
                })
                    ->chunk(200, function ($tsItems) use (&$updatedCount, &$oldNormalizedTexts) {
                        foreach ($tsItems as $tsItem) {
                            $rawText = $tsItem->attributes['text'] ?? null;
                            if (! $rawText) {
                                continue;
                            }

                            $textForNormalize = ! empty($this->stripPatterns)
                                ? TimestampExtractorService::applyStripPatterns($rawText, $this->stripPatterns)
                                : $rawText;
                            $newNormalized = TextNormalizer::normalize($textForNormalize);

                            if ($newNormalized === '' && trim($textForNormalize) !== '') {
                                $newNormalized = mb_strtolower(trim($textForNormalize), 'UTF-8');
                            }

                            if ($tsItem->normalized_text !== $newNormalized) {
                                $oldNormalizedTexts[] = $tsItem->normalized_text;

                                DB::table('ts_items')
                                    ->where('id', $tsItem->id)
                                    ->update([
                                        'normalized_text' => $newNormalized,
                                        'updated_at' => now(),
                                    ]);
                                $updatedCount++;
                            }
                        }
                    });

                if (! empty($oldNormalizedTexts)) {
                    $oldNormalizedTexts = array_unique($oldNormalizedTexts);
                    TimestampSongMapping::whereIn('normalized_text', $oldNormalizedTexts)
                        ->where('is_manual', false)
                        ->delete();
                }
            });

            Log::info('ReapplyStripPatternsJob completed', [
                'channel_id' => $this->channel->channel_id,
                'updated_count' => $updatedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('ReapplyStripPatternsJob failed', [
                'channel_id' => $this->channel->channel_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ReapplyStripPatternsJob permanently failed', [
            'channel_id' => $this->channel->channel_id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
