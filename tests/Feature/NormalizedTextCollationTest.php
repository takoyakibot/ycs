<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * normalized_text の照合順序に関するテスト
 *
 * utf8mb4_unicode_ci は補助面（絵文字）に重みを持たず「🎵A」と「🎶A」を同値と扱うため、
 * バイト完全一致で判定するアプリ側とDB側で結果がずれ、
 * 絵文字で装飾されたタイムスタンプが「紐付けても未紐付のまま」になる不具合が発生していた。
 */
class NormalizedTextCollationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    /**
     * normalized_text カラムがバイト比較の照合順序であることを確認（MySQLのみ）
     */
    public function test_normalized_text_columns_use_binary_collation(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('照合順序の検証はMySQLでのみ実施する');
        }

        $tables = ['ts_items', 'timestamp_song_mappings', 'timestamp_decompositions'];

        foreach ($tables as $table) {
            $column = DB::selectOne(
                'SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'normalized_text']
            );

            $this->assertNotNull($column, "{$table}.normalized_text が存在しない");
            $this->assertEquals(
                'utf8mb4_bin',
                $column->COLLATION_NAME,
                "{$table}.normalized_text はバイト比較の照合順序である必要がある"
            );
        }
    }

    /**
     * 絵文字だけが異なるテキストがDB上で別レコードとして扱われること
     */
    public function test_mappings_with_different_emoji_are_distinct(): void
    {
        $song = Song::factory()->create();

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => '🎵イエスタデイ / official髭男dism',
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'confidence' => 1.0,
        ]);

        $response = $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => '🎶イエスタデイ / official髭男dism',
            'song_id' => $song->id,
        ]);

        $response->assertStatus(200);

        // 既存レコードが上書きされず、絵文字ごとに別レコードが作られること
        $this->assertEquals(2, TimestampSongMapping::count());
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => '🎶イエスタデイ / official髭男dism',
            'song_id' => $song->id,
        ]);
    }

    /**
     * 絵文字で装飾されたタイムスタンプが、紐付け後に「紐付済」として表示されること
     */
    public function test_emoji_decorated_timestamp_is_shown_as_linked_after_link(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);
        $song = Song::factory()->create();

        // 装飾違いの同一楽曲タイムスタンプ（絵文字の有無・種類が異なる）
        $texts = [
            'イエスタデイ / Official髭男dism',
            '🎵イエスタデイ / Official髭男dism',
            '🎶イエスタデイ / Official髭男dism',
        ];

        foreach ($texts as $text) {
            TsItem::factory()->create([
                'video_id' => $archive->video_id,
                'text' => $text,
                'is_display' => 1,
            ]);
        }

        // 先に別の装飾を紐付けておく（照合順序が曖昧だとこのレコードが上書きされる）
        $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => '🎵イエスタデイ / official髭男dism',
            'song_id' => $song->id,
        ])->assertStatus(200);

        // 対象の装飾違いを紐付ける
        $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => '🎶イエスタデイ / official髭男dism',
            'song_id' => $song->id,
        ])->assertStatus(200);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'per_page' => 50,
        ]));

        $response->assertStatus(200);

        $items = collect($response->json('data'))->keyBy('normalized_text');

        $this->assertNotNull(
            $items->get('🎶イエスタデイ / official髭男dism')['song'] ?? null,
            '絵文字付きタイムスタンプが紐付済として表示されること'
        );
        $this->assertNotNull(
            $items->get('🎵イエスタデイ / official髭男dism')['song'] ?? null,
            '先に紐付けた絵文字付きタイムスタンプの紐付けが維持されること'
        );
        // 装飾なしは別マッピングのため未紐付のまま
        $this->assertNull(
            $items->get('イエスタデイ / official髭男dism')['song'] ?? null,
            '装飾なしテキストは別マッピングとして扱われること'
        );
    }
}
