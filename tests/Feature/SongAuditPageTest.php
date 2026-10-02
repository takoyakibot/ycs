<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\SongAudit;
use App\Models\TimestampSongMapping;
use App\Models\User;
use App\Services\SongAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongAuditPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function audit(Song|TimestampSongMapping $target, array $attributes = []): SongAudit
    {
        $type = $target instanceof Song ? SongAudit::TARGET_SONG : SongAudit::TARGET_MAPPING;
        $target = $target instanceof Song ? $target->fresh('tags') : $target->fresh('song');

        return SongAudit::create(array_merge([
            'target_type' => $type,
            'target_id' => (string) $target->id,
            'fingerprint' => app(SongAuditService::class)->fingerprint($type, $target),
            'verdict' => SongAudit::VERDICT_NEEDS_FIX,
            'reason' => '理由',
            'judged_by' => 'claude',
            'judged_at' => now(),
        ], $attributes));
    }

    private function mapping(Song $song, string $text = 'テキスト'): TimestampSongMapping
    {
        return TimestampSongMapping::create(['normalized_text' => $text, 'song_id' => $song->id]);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('songs.audits.index'))->assertRedirect(route('login'));
    }

    public function test_needs_fix_tab_lists_pending_needs_fix_only(): void
    {
        $this->audit(Song::factory()->create(['title' => '要修正の曲']), ['suggestion' => ['title' => '修正案の曲名']]);
        $this->audit(Song::factory()->create(['title' => '問題なしの曲']), ['verdict' => SongAudit::VERDICT_OK, 'reason' => null]);
        $this->audit(Song::factory()->create(['title' => '却下済みの曲']), ['resolution' => SongAudit::RESOLUTION_REJECTED]);

        $this->actingAs($this->user)->get(route('songs.audits.index'))
            ->assertOk()
            ->assertSee('要修正の曲')
            ->assertSee('value="修正案の曲名"', false)
            ->assertDontSee('問題なしの曲')
            ->assertDontSee('却下済みの曲');
    }

    public function test_ok_and_resolved_tabs(): void
    {
        $this->audit(Song::factory()->create(['title' => '問題なしの曲']), ['verdict' => SongAudit::VERDICT_OK, 'reason' => null]);
        $this->audit(Song::factory()->create(['title' => '却下済みの曲']), ['resolution' => SongAudit::RESOLUTION_REJECTED]);

        $this->actingAs($this->user)->get(route('songs.audits.index', ['tab' => 'ok']))
            ->assertOk()->assertSee('問題なしの曲')->assertSee('要修正に変更')->assertDontSee('却下済みの曲');

        $this->actingAs($this->user)->get(route('songs.audits.index', ['tab' => 'resolved']))
            ->assertOk()->assertSee('却下済みの曲')->assertDontSee('問題なしの曲');
    }

    public function test_shows_changed_badge_and_disables_apply(): void
    {
        $song = Song::factory()->create(['title' => 'A']);
        $this->audit($song);
        $song->update(['title' => 'B']);

        $this->actingAs($this->user)->get(route('songs.audits.index'))
            ->assertOk()
            ->assertSee('判定後に変更あり');
    }

    public function test_apply_song_updates_title_artist_and_syncs_tags(): void
    {
        $song = Song::factory()->create(['title' => '夜に駆ける（cover）', 'artist' => 'yoasobi']);
        $audit = $this->audit($song);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['title' => ' 夜に駆ける ', 'artist' => 'YOASOBI'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $song->refresh();
        $this->assertSame('夜に駆ける', $song->title);
        $this->assertSame('YOASOBI', $song->artist);
        $this->assertSame('夜に駆ける', $song->normalized_title);
        $this->assertNull($song->review_status);
        $this->assertSame($this->user->id, (int) $song->updated_by);
        $this->assertSame(['YOASOBI'], $song->tags()->pluck('value')->all());
        $this->assertSame(SongAudit::RESOLUTION_APPLIED, $audit->fresh()->resolution);
    }

    public function test_apply_song_refuses_when_target_changed_since_judgement(): void
    {
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $audit = $this->audit($song);
        $song->update(['artist' => 'Y']);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['title' => 'B', 'artist' => 'Y'])
            ->assertSessionHas('error');

        $this->assertSame('A', $song->fresh()->title);
        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->fresh()->resolution);
    }

    public function test_apply_song_refuses_duplicate_title_artist(): void
    {
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);
        $song = Song::factory()->create(['title' => '夜に駆ける（cover）', 'artist' => 'YOASOBI']);
        $audit = $this->audit($song);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['title' => '夜に駆ける', 'artist' => 'YOASOBI'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, '統合'));

        $this->assertSame('夜に駆ける（cover）', $song->fresh()->title);
        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->fresh()->resolution);
    }

    public function test_apply_song_refuses_no_change(): void
    {
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $audit = $this->audit($song);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['title' => 'A', 'artist' => 'X'])
            ->assertSessionHas('error');

        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->fresh()->resolution);
    }

    public function test_apply_song_refuses_when_song_deleted(): void
    {
        $song = Song::factory()->create();
        $audit = $this->audit($song);
        $song->delete();

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['title' => 'B', 'artist' => 'Y'])
            ->assertSessionHas('error');

        $this->actingAs($this->user)->get(route('songs.audits.index'))
            ->assertOk()->assertSee('対象が削除されています');
    }

    public function test_apply_mapping_relinks_to_existing_song(): void
    {
        $wrong = Song::factory()->create(['title' => '別の曲', 'artist' => 'X']);
        $correct = Song::factory()->create(['title' => '正しい曲', 'artist' => 'Y']);
        $mapping = $this->mapping($wrong);
        $audit = $this->audit($mapping);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'link', 'title' => '正しい曲', 'artist' => 'Y'])
            ->assertSessionHas('success');

        $mapping->refresh();
        $this->assertSame($correct->id, $mapping->song_id);
        $this->assertTrue($mapping->is_manual);
        $this->assertSame(SongAudit::RESOLUTION_APPLIED, $audit->fresh()->resolution);
    }

    public function test_apply_mapping_refuses_unknown_song(): void
    {
        $song = Song::factory()->create();
        $mapping = $this->mapping($song);
        $audit = $this->audit($mapping);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'link', 'title' => '存在しない曲', 'artist' => 'Z'])
            ->assertSessionHas('error');

        $this->assertSame($song->id, $mapping->fresh()->song_id);
    }

    public function test_apply_mapping_marks_not_song(): void
    {
        $song = Song::factory()->create();
        $mapping = $this->mapping($song);
        $audit = $this->audit($mapping, ['suggestion' => ['is_not_song' => true]]);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'not_song'])
            ->assertSessionHas('success');

        $mapping->refresh();
        $this->assertTrue($mapping->is_not_song);
        $this->assertNull($mapping->song_id);
        $this->assertSame(SongAudit::RESOLUTION_APPLIED, $audit->fresh()->resolution);
    }

    public function test_apply_mapping_refuses_when_mapping_changed(): void
    {
        $song = Song::factory()->create();
        $other = Song::factory()->create();
        $mapping = $this->mapping($song);
        $audit = $this->audit($mapping);
        $mapping->update(['song_id' => $other->id]);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'not_song'])
            ->assertSessionHas('error');

        $this->assertFalse($mapping->fresh()->is_not_song);
    }

    public function test_apply_is_refused_for_resolved_or_ok_audits(): void
    {
        $song = Song::factory()->create(['title' => 'A']);
        $rejected = $this->audit($song, ['resolution' => SongAudit::RESOLUTION_REJECTED]);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $rejected), ['title' => 'B', 'artist' => 'X'])
            ->assertSessionHas('error');

        $this->assertSame('A', $song->fresh()->title);
    }

    public function test_reject_marks_rejected_without_changing_target(): void
    {
        $song = Song::factory()->create(['title' => 'A']);
        $audit = $this->audit($song, ['suggestion' => ['title' => 'B']]);

        $this->actingAs($this->user)
            ->post(route('songs.audits.reject', $audit))
            ->assertSessionHas('success');

        $this->assertSame(SongAudit::RESOLUTION_REJECTED, $audit->fresh()->resolution);
        $this->assertSame('A', $song->fresh()->title);
    }

    public function test_mark_needs_fix_changes_ok_verdict(): void
    {
        $audit = $this->audit(Song::factory()->create(), ['verdict' => SongAudit::VERDICT_OK, 'reason' => null]);

        $this->actingAs($this->user)
            ->post(route('songs.audits.needsFix', $audit), ['reason' => '曲名に補足が混入'])
            ->assertSessionHas('success');

        $audit->refresh();
        $this->assertSame(SongAudit::VERDICT_NEEDS_FIX, $audit->verdict);
        $this->assertSame('曲名に補足が混入', $audit->reason);
        $this->assertSame('user:'.$this->user->id, $audit->judged_by);
        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->resolution);
    }

    public function test_apply_mapping_link_requires_title_and_artist(): void
    {
        $mapping = $this->mapping(Song::factory()->create());
        $audit = $this->audit($mapping);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'link', 'title' => '', 'artist' => ''])
            ->assertSessionHasErrors(['title', 'artist']);
    }

    public function test_apply_mapping_link_shows_linked_song_in_message(): void
    {
        $mapping = $this->mapping(Song::factory()->create(['title' => '別の曲', 'artist' => 'X']));
        Song::factory()->create(['title' => '正しい曲', 'artist' => 'Y']);
        $audit = $this->audit($mapping);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'link', 'title' => '正しい曲', 'artist' => 'Y'])
            ->assertSessionHas('success', '「正しい曲 / Y」に付け替えました。');
    }

    public function test_apply_mapping_not_song_refuses_when_already_not_song(): void
    {
        $mapping = TimestampSongMapping::create(['normalized_text' => 'テキスト', 'song_id' => null, 'is_not_song' => true]);
        $audit = $this->audit($mapping);

        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['action' => 'not_song'])
            ->assertSessionHas('error');

        $this->assertSame(SongAudit::RESOLUTION_PENDING, $audit->fresh()->resolution);
    }

    public function test_second_apply_is_refused(): void
    {
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $audit = $this->audit($song);

        $this->actingAs($this->user)->post(route('songs.audits.apply', $audit), ['title' => 'B', 'artist' => 'X'])
            ->assertSessionHas('success');
        $this->actingAs($this->user)->post(route('songs.audits.apply', $audit), ['title' => 'C', 'artist' => 'X'])
            ->assertSessionHas('error');

        $this->assertSame('B', $song->fresh()->title);
    }

    public function test_mark_needs_fix_takes_fingerprint_of_current_content(): void
    {
        $song = Song::factory()->create(['title' => 'A', 'artist' => 'X']);
        $audit = $this->audit($song, ['verdict' => SongAudit::VERDICT_OK, 'reason' => null]);
        $song->update(['title' => 'A（cover）']);

        $this->actingAs($this->user)
            ->post(route('songs.audits.needsFix', $audit), ['reason' => '補足が混入'])
            ->assertSessionHas('success');

        // 人が見た現在の内容に対する判定なので、そのまま適用できる
        $this->actingAs($this->user)
            ->post(route('songs.audits.apply', $audit), ['title' => 'A', 'artist' => 'X'])
            ->assertSessionHas('success');
        $this->assertSame('A', $song->fresh()->title);
    }

    public function test_mark_needs_fix_requires_reason(): void
    {
        $audit = $this->audit(Song::factory()->create(), ['verdict' => SongAudit::VERDICT_OK, 'reason' => null]);

        $this->actingAs($this->user)
            ->post(route('songs.audits.needsFix', $audit), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SongAudit::VERDICT_OK, $audit->fresh()->verdict);
    }
}
