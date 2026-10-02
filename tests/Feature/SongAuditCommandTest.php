<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Song;
use App\Models\SongAudit;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\SongAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SongAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Artisan::output() で出力を読むため、コンソール出力のモックを外す
        $this->withoutMockingConsoleOutput();
    }

    private function export(string $type, int $limit = 50): array
    {
        Artisan::call('song-audit:export', ['type' => $type, '--limit' => $limit]);

        return json_decode(Artisan::output(), true);
    }

    private function import(array $items, array $options = []): int
    {
        $path = tempnam(sys_get_temp_dir(), 'song-audit');
        file_put_contents($path, json_encode($items, JSON_UNESCAPED_UNICODE));

        try {
            return Artisan::call('song-audit:import', array_merge(['file' => $path], $options));
        } finally {
            unlink($path);
        }
    }

    private function createLinkedTimestamp(Song $song, string $text): TimestampSongMapping
    {
        $archive = Archive::factory()->create();
        $tsItem = TsItem::factory()->create(['video_id' => $archive->video_id, 'text' => $text]);

        return TimestampSongMapping::create([
            'normalized_text' => $tsItem->normalized_text,
            'song_id' => $song->id,
        ]);
    }

    private function judgement(array $exported, array $overrides = []): array
    {
        return array_merge([
            'type' => $exported['type'],
            'id' => $exported['id'],
            'fingerprint' => $exported['fingerprint'],
            'verdict' => SongAudit::VERDICT_OK,
        ], $overrides);
    }

    public function test_export_song_includes_tags_and_timestamp_examples(): void
    {
        $song = Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);
        $this->createLinkedTimestamp($song, '夜に駆ける / YOASOBI');

        $exported = $this->export(SongAudit::TARGET_SONG);

        $this->assertCount(1, $exported);
        $this->assertSame($song->id, $exported[0]['id']);
        $this->assertSame('夜に駆ける', $exported[0]['title']);
        $this->assertSame('YOASOBI', $exported[0]['artist']);
        $this->assertSame(['YOASOBI'], $exported[0]['tags']);
        $this->assertSame(['夜に駆ける / YOASOBI'], $exported[0]['timestamp_examples']);
        $this->assertSame(40, strlen($exported[0]['fingerprint']));
    }

    public function test_export_mapping_includes_original_text_and_song(): void
    {
        $song = Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);
        $mapping = $this->createLinkedTimestamp($song, '夜に駆ける（YOASOBI）');

        $exported = $this->export(SongAudit::TARGET_MAPPING);

        $this->assertCount(1, $exported);
        $this->assertSame((string) $mapping->id, $exported[0]['id']);
        $this->assertSame(['夜に駆ける（YOASOBI）'], $exported[0]['original_texts']);
        $this->assertSame(['id' => $song->id, 'title' => '夜に駆ける', 'artist' => 'YOASOBI'], $exported[0]['song']);
    }

    public function test_export_mapping_excludes_unlinked_and_not_song(): void
    {
        TimestampSongMapping::create(['normalized_text' => 'unlinked', 'song_id' => null]);
        TimestampSongMapping::create(['normalized_text' => 'not song', 'song_id' => null, 'is_not_song' => true]);

        $this->assertSame([], $this->export(SongAudit::TARGET_MAPPING));
    }

    public function test_export_respects_limit(): void
    {
        Song::factory()->count(3)->create();

        $this->assertCount(2, $this->export(SongAudit::TARGET_SONG, 2));
    }

    public function test_export_skips_judged_and_returns_again_after_update(): void
    {
        $judged = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $other = Song::factory()->create(['title' => 'B', 'artist' => 'Y']);

        $exported = collect($this->export(SongAudit::TARGET_SONG))->keyBy('id');
        $this->assertSame(0, $this->import([$this->judgement($exported[$judged->id])]));

        $this->assertSame([$other->id], array_column($this->export(SongAudit::TARGET_SONG), 'id'));

        $judged->update(['title' => 'A2']);

        $this->assertEqualsCanonicalizing(
            [$judged->id, $other->id],
            array_column($this->export(SongAudit::TARGET_SONG), 'id')
        );
    }

    public function test_export_fills_limit_after_skipping_judged_targets(): void
    {
        $songs = Song::factory()->count(3)->create()->sortBy('id')->values();
        $first = $this->export(SongAudit::TARGET_SONG, 1)[0];
        $this->import([$this->judgement($first)]);

        $this->assertCount(2, $this->export(SongAudit::TARGET_SONG, 2));
        $this->assertNotContains($songs[0]->id, array_column($this->export(SongAudit::TARGET_SONG, 2), 'id'));
    }

    public function test_import_registers_needs_fix_with_suggestion(): void
    {
        $song = Song::factory()->create(['title' => '夜に駆ける（cover）', 'artist' => 'YOASOBI']);
        $exported = $this->export(SongAudit::TARGET_SONG)[0];

        $exitCode = $this->import([$this->judgement($exported, [
            'verdict' => SongAudit::VERDICT_NEEDS_FIX,
            'reason' => '曲名に（cover）が混入している',
            'suggestion' => ['title' => '夜に駆ける'],
        ])]);

        $this->assertSame(0, $exitCode);
        $audit = SongAudit::sole();
        $this->assertSame(SongAudit::TARGET_SONG, $audit->target_type);
        $this->assertSame($song->id, $audit->target_id);
        $this->assertSame(SongAudit::VERDICT_NEEDS_FIX, $audit->verdict);
        $this->assertSame(['title' => '夜に駆ける'], $audit->suggestion);
        $this->assertSame('claude', $audit->judged_by);
        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->resolution);
    }

    public function test_import_does_not_modify_songs_or_mappings(): void
    {
        $song = Song::factory()->create(['title' => '夜に駆ける（cover）', 'artist' => 'YOASOBI']);
        $mapping = $this->createLinkedTimestamp($song, '夜に駆ける');

        $this->import([
            $this->judgement($this->export(SongAudit::TARGET_SONG)[0], [
                'verdict' => SongAudit::VERDICT_NEEDS_FIX,
                'reason' => '曲名に補足が混入',
                'suggestion' => ['title' => '夜に駆ける'],
            ]),
            $this->judgement($this->export(SongAudit::TARGET_MAPPING)[0], [
                'verdict' => SongAudit::VERDICT_NEEDS_FIX,
                'reason' => '楽曲ではない',
                'suggestion' => ['is_not_song' => true],
            ]),
        ]);

        $this->assertSame(2, SongAudit::count());
        $this->assertSame('夜に駆ける（cover）', $song->fresh()->title);
        $this->assertSame($song->id, $mapping->fresh()->song_id);
        $this->assertFalse($mapping->fresh()->is_not_song);
    }

    public function test_import_rejects_stale_fingerprint(): void
    {
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $exported = $this->export(SongAudit::TARGET_SONG)[0];
        $song->update(['artist' => 'Y']);

        $exitCode = $this->import([$this->judgement($exported)]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, SongAudit::count());
        $this->assertStringContainsString('更新されています', Artisan::output());
    }

    public function test_import_treats_unlinked_mapping_as_stale(): void
    {
        $song = Song::factory()->create();
        $mapping = $this->createLinkedTimestamp($song, 'テキスト');
        $exported = $this->export(SongAudit::TARGET_MAPPING)[0];
        $mapping->update(['song_id' => null]);

        $this->import([$this->judgement($exported)]);

        $this->assertSame(0, SongAudit::count());
        $this->assertStringContainsString('更新されています', Artisan::output());
    }

    public function test_import_mapping_fingerprint_changes_when_linked_song_is_renamed(): void
    {
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $this->createLinkedTimestamp($song, 'テキスト');
        $exported = $this->export(SongAudit::TARGET_MAPPING)[0];
        $song->update(['title' => 'B']);

        $this->import([$this->judgement($exported)]);

        $this->assertSame(0, SongAudit::count());
    }

    public function test_import_validates_each_item_and_registers_valid_ones(): void
    {
        $song = Song::factory()->create();
        $exported = $this->export(SongAudit::TARGET_SONG)[0];

        $exitCode = $this->import([
            $this->judgement($exported),
            $this->judgement($exported, ['verdict' => 'maybe']),
            $this->judgement($exported, ['id' => '01NOTEXIST0000000000000000']),
            $this->judgement($exported, ['verdict' => SongAudit::VERDICT_NEEDS_FIX]),
            $this->judgement($exported, ['suggestion' => ['title' => str_repeat('あ', 256)]]),
            $this->judgement($exported, ['suggestion' => ['song_id' => 'x']]),
            $this->judgement($exported, ['suggestion' => ['is_not_song' => true]]),
            'not an object',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, SongAudit::count());
        $this->assertSame($song->id, SongAudit::sole()->target_id);

        $output = Artisan::output();
        foreach (['#2', '#3', '#4', '#5', '#6', '#7', '#8'] as $label) {
            $this->assertStringContainsString($label, $output);
        }
        $this->assertStringContainsString('エラー: 7件', $output);
    }

    public function test_import_overwrites_previous_judgement_and_resets_resolution(): void
    {
        Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $exported = $this->export(SongAudit::TARGET_SONG)[0];
        $this->import([$this->judgement($exported, ['verdict' => SongAudit::VERDICT_NEEDS_FIX, 'reason' => '理由'])]);
        SongAudit::query()->update(['resolution' => SongAudit::RESOLUTION_REJECTED]);

        $this->import([$this->judgement($exported)], ['--judged-by' => 'user:1']);

        $audit = SongAudit::sole();
        $this->assertSame(SongAudit::VERDICT_OK, $audit->verdict);
        $this->assertSame('user:1', $audit->judged_by);
        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->resolution);
    }

    public function test_import_dry_run_does_not_register(): void
    {
        Song::factory()->create();
        $exported = $this->export(SongAudit::TARGET_SONG)[0];

        $this->assertSame(0, $this->import([$this->judgement($exported)], ['--dry-run' => true]));
        $this->assertSame(0, SongAudit::count());
        $this->assertStringContainsString('登録可能: 1件', Artisan::output());
    }

    public function test_import_rejects_non_list_json(): void
    {
        $this->assertSame(1, $this->import(['type' => 'song']));
        $this->assertSame(1, Artisan::call('song-audit:import', ['file' => '/nonexistent/file.json']));
    }

    public function test_export_rejects_invalid_arguments(): void
    {
        $this->assertSame(1, Artisan::call('song-audit:export', ['type' => 'archive']));
        $this->assertSame(1, Artisan::call('song-audit:export', ['type' => 'song', '--limit' => 0]));
    }

    public function test_song_fingerprint_ignores_tag_order(): void
    {
        $service = app(SongAuditService::class);
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $song->tags()->delete();
        $song->tags()->create(['value' => 'b']);
        $song->tags()->create(['value' => 'a']);
        $before = $service->fingerprint(SongAudit::TARGET_SONG, $song->fresh('tags'));

        $song->tags()->delete();
        $song->tags()->create(['value' => 'a']);
        $song->tags()->create(['value' => 'b']);

        $this->assertSame($before, $service->fingerprint(SongAudit::TARGET_SONG, $song->fresh('tags')));
    }
}
