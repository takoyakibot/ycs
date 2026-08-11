<?php

namespace App\Console\Commands;

use App\Models\VideoSubtitle;
use App\Services\SubtitleFingerprintService;
use Illuminate\Console\Command;

class GenerateSubtitleFingerprints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subtitle-fingerprints:generate
                            {--video= : 対象のvideo_id（省略時は字幕が保存されている全動画）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '保存済み字幕からフィンガープリントを生成する（既存分は現行の窓の長さで作り直す）';

    public function __construct(private SubtitleFingerprintService $fingerprintService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $videoId = $this->option('video');

        $videoIds = VideoSubtitle::query()
            ->when($videoId, fn ($query) => $query->where('video_id', $videoId))
            ->distinct()
            ->orderBy('video_id')
            ->pluck('video_id');

        if ($videoIds->isEmpty()) {
            $this->info('対象の字幕データがありません。');

            return self::SUCCESS;
        }

        $this->info('対象動画: '.$videoIds->count().'件');
        $this->line('窓の長さ: '.SubtitleFingerprintService::WINDOW_DURATION_SEC.'秒 / '
            .'最小トライグラム数: '.SubtitleFingerprintService::MIN_TRIGRAM_COUNT);

        $bar = $this->output->createProgressBar($videoIds->count());
        $bar->start();

        $generated = 0;
        foreach ($videoIds as $id) {
            $generated += $this->fingerprintService->generateFingerprintsForVideo($id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("フィンガープリント生成: {$generated}件");

        return self::SUCCESS;
    }
}
