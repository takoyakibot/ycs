<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TsItem;
use App\Models\User;
use App\Models\VideoSubtitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 拡張向け: 字幕未取得アーカイブ一覧API（#598）
 */
class ExtensionSubtitleTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);
    }

    private function requestTargets(?User $user = null): \Illuminate\Testing\TestResponse
    {
        $token = ($user ?? $this->user)->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/extension/subtitle-targets');
    }

    /**
     * 表示中のts_itemを持つアーカイブを作成する
     */
    private function createArchiveWithTsItem(string $videoId, array $archiveAttrs = []): Archive
    {
        $archive = Archive::factory()->create(array_merge([
            'channel_id' => $this->channel->channel_id,
            'video_id' => $videoId,
            'is_display' => true,
        ], $archiveAttrs));

        TsItem::factory()->create([
            'video_id' => $videoId,
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        return $archive;
    }

    public function test_returns_archives_with_timestamps_and_no_subtitles(): void
    {
        $this->createArchiveWithTsItem('target00001');

        $response = $this->requestTargets();

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('targets.0.video_id', 'target00001');
        $this->assertNotNull($response->json('targets.0.title'));
    }

    public function test_excludes_archives_with_stored_subtitles(): void
    {
        $this->createArchiveWithTsItem('hassubtitle');
        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'hassubtitle',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [['start' => 0, 'duration' => 1, 'text' => 'テスト']],
            'segment_count' => 1,
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_excludes_archives_without_displayed_timestamps(): void
    {
        // ts_itemなし
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'notimestamp',
            'is_display' => true,
        ]);

        // 非表示のts_itemのみ
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'hiddentsonly',
            'is_display' => true,
        ]);
        TsItem::factory()->create([
            'video_id' => 'hiddentsonly',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '0',
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_excludes_hidden_archives(): void
    {
        $this->createArchiveWithTsItem('hiddenvideo', ['is_display' => false]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_scopes_to_own_channels_for_regular_admin(): void
    {
        $this->createArchiveWithTsItem('ownvideo001');

        // 他ユーザーのチャンネルのアーカイブ
        $otherChannel = Channel::factory()->create();
        Archive::factory()->create([
            'channel_id' => $otherChannel->channel_id,
            'video_id' => 'othervideo1',
            'is_display' => true,
        ]);
        TsItem::factory()->create([
            'video_id' => 'othervideo1',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        $response = $this->requestTargets();

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('targets.0.video_id', 'ownvideo001');
    }

    public function test_super_admin_sees_all_channels(): void
    {
        $superAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->createArchiveWithTsItem('ownvideo001');

        $response = $this->requestTargets($superAdmin);

        $response->assertStatus(200)->assertJsonPath('count', 1);
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/extension/subtitle-targets');

        $response->assertStatus(401);
    }

    public function test_excludes_archives_flagged_as_subtitles_unavailable(): void
    {
        $archive = $this->createArchiveWithTsItem('nosubsvideo');
        $archive->update(['subtitles_unavailable_at' => now()]);

        $response = $this->requestTargets();

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_mark_unavailable_flags_archive(): void
    {
        $archive = $this->createArchiveWithTsItem('target00001');
        $token = $this->user->createToken('extension')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/extension/subtitles/unavailable', ['video_id' => 'target00001']);

        $response->assertStatus(200);
        $this->assertNotNull($archive->fresh()->subtitles_unavailable_at);

        // フラグ後は対象一覧から消える
        $this->requestTargets()->assertJsonPath('count', 0);
    }

    public function test_mark_unavailable_denied_for_other_users_channel(): void
    {
        $this->createArchiveWithTsItem('target00001');

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);
        $token = $otherUser->createToken('extension')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/extension/subtitles/unavailable', ['video_id' => 'target00001']);

        $response->assertStatus(403);
    }

    public function test_mark_unavailable_returns_404_for_unknown_video(): void
    {
        $token = $this->user->createToken('extension')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/extension/subtitles/unavailable', ['video_id' => 'xxxxxxxxxxx']);

        $response->assertStatus(404);
    }

    public function test_storing_subtitles_clears_unavailable_flag(): void
    {
        // 「字幕なし」と記録した後に字幕が付いたケース。保存で自動回復する
        $archive = $this->createArchiveWithTsItem('recovervid1');
        $archive->update(['subtitles_unavailable_at' => now()]);

        $token = $this->user->createToken('extension')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'recovervid1',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [['start' => 0, 'duration' => 1, 'text' => 'テスト']],
            ]);

        $response->assertStatus(200);
        $this->assertNull($archive->fresh()->subtitles_unavailable_at);
    }
}
