<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\SpectralScanData;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyzeSpectralCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createSpectralData(string $videoId, array $spectral, float $duration = 100.0): SpectralScanData
    {
        return SpectralScanData::create([
            'id' => Str::ulid(),
            'video_id' => $videoId,
            'sampling_interval' => 2,
            'duration' => $duration,
            'spectral_data' => $spectral,
        ]);
    }

    private function createDisplayedArchiveWithTs(string $videoId, array $tsItems): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $channel = Channel::factory()->create(['user_id' => $user->id]);
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => $videoId,
            'is_display' => true,
        ]);

        foreach ($tsItems as $ts) {
            TsItem::factory()->create([
                'video_id' => $videoId,
                'ts_num' => $ts['ts_num'],
                'text' => $ts['text'],
                'is_display' => '1',
            ]);
        }
    }

    public function test_outputs_table_with_spectral_analysis(): void
    {
        $spectral = array_fill(0, 50, ['flatness' => 0.3, 'voiceBandRatio' => 0.6]);
        $this->createSpectralData('dQw4w9WgXcQ', $spectral);
        $this->createDisplayedArchiveWithTs('dQw4w9WgXcQ', [
            ['ts_num' => 10, 'text' => 'テスト曲'],
        ]);

        $this->artisan('analyze:spectral', ['video_id' => 'dQw4w9WgXcQ'])
            ->assertExitCode(0)
            ->expectsOutputToContain('テスト曲');
    }

    public function test_skips_hidden_archive(): void
    {
        $spectral = array_fill(0, 10, ['flatness' => 0.5, 'voiceBandRatio' => 0.5]);
        $this->createSpectralData('dQw4w9WgXcQ', $spectral);

        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $channel = Channel::factory()->create(['user_id' => $user->id]);
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
            'is_display' => false,
        ]);

        $this->artisan('analyze:spectral', ['video_id' => 'dQw4w9WgXcQ'])
            ->assertExitCode(0)
            ->expectsOutputToContain('非表示アーカイブ');
    }

    public function test_marks_confirmed_mappings(): void
    {
        $spectral = array_fill(0, 50, ['flatness' => 0.2, 'voiceBandRatio' => 0.7]);
        $this->createSpectralData('dQw4w9WgXcQ', $spectral);
        $this->createDisplayedArchiveWithTs('dQw4w9WgXcQ', [
            ['ts_num' => 20, 'text' => '確定曲'],
        ]);

        $tsItem = TsItem::where('text', '確定曲')->first();
        $song = Song::factory()->create(['title' => '確定曲', 'artist' => 'テスト']);
        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $tsItem->normalized_text,
            'song_id' => $song->id,
            'is_not_song' => false,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => true,
        ]);

        $this->artisan('analyze:spectral', ['video_id' => 'dQw4w9WgXcQ'])
            ->assertExitCode(0)
            ->expectsOutputToContain('○');
    }

    public function test_handles_null_spectral_entries(): void
    {
        $spectral = [null, null, ['flatness' => 0.4, 'voiceBandRatio' => 0.5], null, null];
        $this->createSpectralData('dQw4w9WgXcQ', $spectral, 10.0);
        $this->createDisplayedArchiveWithTs('dQw4w9WgXcQ', [
            ['ts_num' => 5, 'text' => 'nullあり曲'],
        ]);

        $this->artisan('analyze:spectral', ['video_id' => 'dQw4w9WgXcQ'])
            ->assertExitCode(0)
            ->expectsOutputToContain('nullあり曲');
    }

    public function test_no_data_shows_warning(): void
    {
        $this->artisan('analyze:spectral', ['video_id' => 'xxxxxxxxxxx'])
            ->assertExitCode(0)
            ->expectsOutputToContain('スペクトルデータが見つかりません');
    }

    public function test_skips_partial_spectral_entry(): void
    {
        $spectral = [
            ['flatness' => 0.3],
            ['flatness' => 0.4, 'voiceBandRatio' => 0.6],
            null,
        ];
        $this->createSpectralData('dQw4w9WgXcQ', $spectral, 6.0);
        $this->createDisplayedArchiveWithTs('dQw4w9WgXcQ', [
            ['ts_num' => 2, 'text' => '部分データ曲'],
        ]);

        $this->artisan('analyze:spectral', ['video_id' => 'dQw4w9WgXcQ'])
            ->assertExitCode(0)
            ->expectsOutputToContain('部分データ曲');
    }
}
