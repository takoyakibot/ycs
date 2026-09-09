<?php

namespace App\Console\Commands;

use App\Models\SpectralScanData;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Console\Command;

class AnalyzeSpectralCommand extends Command
{
    protected $signature = 'analyze:spectral {video_id?}';

    protected $description = 'スペクトルデータと楽曲開始位置を照合して特徴量の傾向を分析';

    public function handle(): int
    {
        $query = SpectralScanData::query();
        if ($videoId = $this->argument('video_id')) {
            $query->where('video_id', $videoId);
        }

        $records = $query->get();
        if ($records->isEmpty()) {
            $this->warn('スペクトルデータが見つかりません');

            return self::SUCCESS;
        }

        $this->info("分析対象: {$records->count()}件");
        $this->newLine();

        $rows = [];

        foreach ($records as $record) {
            $spectral = $record->spectral_data;
            $dataLength = count($spectral);
            if ($dataLength === 0 || $record->duration <= 0) {
                continue;
            }

            $tsItems = TsItem::where('video_id', $record->video_id)
                ->where('is_display', '1')
                ->get();

            if ($tsItems->isEmpty()) {
                $this->line("  {$record->video_id}: 表示中のタイムスタンプなし（スキップ）");

                continue;
            }

            $confirmedTexts = TimestampSongMapping::where('status', 'linked')
                ->where('is_manual', true)
                ->pluck('normalized_text')
                ->toArray();

            $songPositions = [];
            foreach ($tsItems as $ts) {
                $isConfirmed = in_array($ts->normalized_text, $confirmedTexts);
                $index = (int) floor($ts->ts_num / ($record->duration / $dataLength));
                $index = min($index, $dataLength - 1);
                $songPositions[] = [
                    'text' => $ts->text,
                    'ts_num' => $ts->ts_num,
                    'index' => $index,
                    'confirmed' => $isConfirmed,
                ];
            }

            $songIndices = array_column($songPositions, 'index');

            foreach ($songPositions as $pos) {
                $songStats = $this->averageAround($spectral, $pos['index'], 5);
                $nonSongStats = $this->averageNonSong($spectral, $songIndices, $dataLength);

                $rows[] = [
                    $record->video_id,
                    mb_strimwidth($pos['text'], 0, 30, '…'),
                    $this->formatTime($pos['ts_num']),
                    $pos['confirmed'] ? '○' : '',
                    $songStats['flatness'] !== null ? number_format($songStats['flatness'], 3) : '-',
                    $songStats['voiceBandRatio'] !== null ? number_format($songStats['voiceBandRatio'], 3) : '-',
                    $nonSongStats['flatness'] !== null ? number_format($nonSongStats['flatness'], 3) : '-',
                    $nonSongStats['voiceBandRatio'] !== null ? number_format($nonSongStats['voiceBandRatio'], 3) : '-',
                ];
            }
        }

        if (empty($rows)) {
            $this->warn('分析対象のタイムスタンプが見つかりません');

            return self::SUCCESS;
        }

        $this->table(
            ['動画ID', '曲名', '位置', '確定', '歌flatness', '歌voiceBand', '非歌flatness', '非歌voiceBand'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function averageAround(array $spectral, int $center, int $radius): array
    {
        $flatnessSum = 0;
        $voiceBandSum = 0;
        $count = 0;

        for ($i = max(0, $center - $radius); $i <= min(count($spectral) - 1, $center + $radius); $i++) {
            if ($spectral[$i] !== null && isset($spectral[$i]['flatness'])) {
                $flatnessSum += $spectral[$i]['flatness'];
                $voiceBandSum += $spectral[$i]['voiceBandRatio'];
                $count++;
            }
        }

        return [
            'flatness' => $count > 0 ? $flatnessSum / $count : null,
            'voiceBandRatio' => $count > 0 ? $voiceBandSum / $count : null,
        ];
    }

    private function averageNonSong(array $spectral, array $songIndices, int $dataLength): array
    {
        $flatnessSum = 0;
        $voiceBandSum = 0;
        $count = 0;
        $margin = 10;

        for ($i = 0; $i < $dataLength; $i++) {
            $nearSong = false;
            foreach ($songIndices as $si) {
                if (abs($i - $si) <= $margin) {
                    $nearSong = true;
                    break;
                }
            }
            if ($nearSong) {
                continue;
            }
            if ($spectral[$i] !== null && isset($spectral[$i]['flatness'])) {
                $flatnessSum += $spectral[$i]['flatness'];
                $voiceBandSum += $spectral[$i]['voiceBandRatio'];
                $count++;
            }
        }

        return [
            'flatness' => $count > 0 ? $flatnessSum / $count : null,
            'voiceBandRatio' => $count > 0 ? $voiceBandSum / $count : null,
        ];
    }

    private function formatTime(float $seconds): string
    {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds % 3600) / 60);
        $s = (int) floor($seconds % 60);

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }
}
