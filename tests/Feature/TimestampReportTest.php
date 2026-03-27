<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\TimestampReport;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimestampReportTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Archive $archive;

    private TsItem $tsItem;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // テスト用のユーザーを作成（スーパー管理者権限付き）
        $this->user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        // テスト用のチャンネルとアーカイブを作成
        $this->channel = Channel::create([
            'handle' => 'test-channel',
            'channel_id' => 'UC123456789',
            'title' => 'Test Channel',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'user_id' => $this->user->id,
        ]);

        $this->archive = Archive::create([
            'id' => 'dQw4w9WgXcQ',
            'channel_id' => 'UC123456789',
            'video_id' => 'dQw4w9WgXcQ',
            'title' => 'Test Archive',
            'thumbnail' => 'https://example.com/video.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // テスト用のタイムスタンプを作成
        $this->tsItem = TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'type' => '1',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'text' => 'Test Song',
            'is_display' => true,
        ]);
    }

    /**
     * ゲストユーザーが報告を作成できることをテスト
     */
    public function test_guest_can_create_report(): void
    {
        RateLimiter::clear('timestamp-report:127.0.0.1');

        $response = $this->postJson('/api/timestamp-reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'comment' => 'テストコメント',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => '報告を受け付けました。ご協力ありがとうございます。',
            ])
            ->assertJsonStructure(['report_id']);

        $this->assertDatabaseHas('timestamp_reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'comment' => 'テストコメント',
            'status' => 'pending',
        ]);
    }

    /**
     * コメントなしで報告を作成できることをテスト
     */
    public function test_can_create_report_without_comment(): void
    {
        RateLimiter::clear('timestamp-report:127.0.0.1');

        $response = $this->postJson('/api/timestamp-reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'not_song',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('timestamp_reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'not_song',
            'comment' => null,
        ]);
    }

    /**
     * 必須フィールドがない場合バリデーションエラーになることをテスト
     */
    public function test_validation_fails_without_required_fields(): void
    {
        RateLimiter::clear('timestamp-report:127.0.0.1');

        $response = $this->postJson('/api/timestamp-reports', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['video_id', 'ts_text', 'ts_num', 'report_type']);
    }

    /**
     * 存在しないts_itemでエラーになることをテスト
     */
    public function test_validation_fails_with_invalid_ts_item(): void
    {
        RateLimiter::clear('timestamp-report:127.0.0.1');

        $response = $this->postJson('/api/timestamp-reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '99:99',
            'ts_num' => 9999,
            'report_type' => 'wrong_song',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => '報告対象のタイムスタンプが見つかりません。',
            ]);
    }

    /**
     * レートリミットが適用されることをテスト
     */
    public function test_rate_limiting_is_applied(): void
    {
        RateLimiter::clear('timestamp-report:127.0.0.1');

        // 5回の報告を送信（制限内）
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/timestamp-reports', [
                'video_id' => 'dQw4w9WgXcQ',
                'ts_text' => '1:23',
                'ts_num' => 83,
                'report_type' => 'wrong_song',
            ]);
            $response->assertStatus(201);
        }

        // 6回目の報告はレートリミットエラー
        $response = $this->postJson('/api/timestamp-reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
        ]);

        $response->assertStatus(429)
            ->assertJsonStructure(['message']);
    }

    /**
     * 管理者が報告一覧を取得できることをテスト
     */
    public function test_authenticated_user_can_view_reports_list(): void
    {
        // 報告を作成
        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'comment' => 'テスト',
            'reporter_ip' => '127.0.0.1',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/timestamp-reports');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'video_id',
                        'ts_text',
                        'ts_num',
                        'report_type',
                        'comment',
                        'status',
                        'created_at',
                    ],
                ],
                'current_page',
                'last_page',
                'total',
            ]);
    }

    /**
     * 未認証ユーザーは報告一覧を取得できないことをテスト
     */
    public function test_guest_cannot_view_reports_list(): void
    {
        $response = $this->getJson('/api/manage/timestamp-reports');

        $response->assertStatus(401);
    }

    /**
     * ステータスでフィルタリングできることをテスト
     */
    public function test_can_filter_reports_by_status(): void
    {
        // pending報告を作成
        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'status' => 'pending',
            'reporter_ip' => '127.0.0.1',
        ]);

        // resolved報告を作成
        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'not_song',
            'status' => 'resolved',
            'resolved_at' => now(),
            'reporter_ip' => '127.0.0.1',
        ]);

        // pendingでフィルター
        $response = $this->actingAs($this->user)
            ->getJson('/api/manage/timestamp-reports?status=pending');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('pending', $response->json('data.0.status'));
    }

    /**
     * 報告を解決済みにできることをテスト
     */
    public function test_can_resolve_report(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/manage/timestamp-reports/{$report->id}/resolve");

        $response->assertStatus(200)
            ->assertJson([
                'message' => '報告を解決済みにしました。',
            ]);

        $this->assertDatabaseHas('timestamp_reports', [
            'id' => $report->id,
            'status' => 'resolved',
        ]);

        $report->refresh();
        $this->assertNotNull($report->resolved_at);
    }

    /**
     * 未認証ユーザーは報告を解決できないことをテスト
     */
    public function test_guest_cannot_resolve_report(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        $response = $this->patchJson("/api/manage/timestamp-reports/{$report->id}/resolve");

        $response->assertStatus(401);
    }

    /**
     * 報告の詳細を取得できることをテスト
     */
    public function test_can_view_report_detail(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'comment' => 'テストコメント',
            'reporter_ip' => '127.0.0.1',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/manage/timestamp-reports/{$report->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $report->id,
                'video_id' => 'dQw4w9WgXcQ',
                'ts_text' => '1:23',
                'ts_num' => 83,
                'report_type' => 'wrong_song',
                'comment' => 'テストコメント',
            ]);
    }

    /**
     * 未認証ユーザーは報告の詳細を取得できないことをテスト
     */
    public function test_guest_cannot_view_report_detail(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        $response = $this->getJson("/api/manage/timestamp-reports/{$report->id}");

        $response->assertStatus(401);
    }

    /**
     * IPアドレスが記録されることをテスト
     */
    public function test_reporter_ip_is_recorded(): void
    {
        RateLimiter::clear('timestamp-report:192.168.1.1');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->postJson('/api/timestamp-reports', [
                'video_id' => 'dQw4w9WgXcQ',
                'ts_text' => '1:23',
                'ts_num' => 83,
                'report_type' => 'wrong_song',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('timestamp_reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'reporter_ip' => '192.168.1.1',
        ]);
    }

    /**
     * コメントが1000文字を超えるとバリデーションエラーになることをテスト
     */
    public function test_validation_fails_with_too_long_comment(): void
    {
        RateLimiter::clear('timestamp-report:127.0.0.1');

        $longComment = str_repeat('あ', 1001);

        $response = $this->postJson('/api/timestamp-reports', [
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'comment' => $longComment,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);
    }

    /**
     * 報告はts_itemが削除されても維持されることをテスト（複合キーで紐付けのため）
     * 不要な報告の削除はRefreshArchiveServiceで行う
     */
    public function test_reports_persist_when_ts_item_is_deleted(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        $reportId = $report->id;

        // TsItemを削除
        $this->tsItem->delete();

        // 報告は維持されている（外部キー制約がないため）
        $this->assertDatabaseHas('timestamp_reports', [
            'id' => $reportId,
        ]);
    }

    /**
     * pendingスコープが正しく動作することをテスト
     */
    public function test_pending_scope_works(): void
    {
        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'status' => 'pending',
            'reporter_ip' => '127.0.0.1',
        ]);

        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'not_song',
            'status' => 'resolved',
            'resolved_at' => now(),
            'reporter_ip' => '127.0.0.1',
        ]);

        $pendingReports = TimestampReport::pending()->get();

        $this->assertCount(1, $pendingReports);
        $this->assertEquals('pending', $pendingReports->first()->status);
    }

    /**
     * resolvedスコープが正しく動作することをテスト
     */
    public function test_resolved_scope_works(): void
    {
        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'status' => 'pending',
            'reporter_ip' => '127.0.0.1',
        ]);

        TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'not_song',
            'status' => 'resolved',
            'resolved_at' => now(),
            'reporter_ip' => '127.0.0.1',
        ]);

        $resolvedReports = TimestampReport::resolved()->get();

        $this->assertCount(1, $resolvedReports);
        $this->assertEquals('resolved', $resolvedReports->first()->status);
    }

    /**
     * 認証済みユーザーが報告管理画面にアクセスできることをテスト
     */
    public function test_authenticated_user_can_access_manage_page(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/manage/reports');

        $response->assertStatus(200);
        $response->assertViewIs('manage.reports');
    }

    /**
     * 未認証ユーザーは報告管理画面にアクセスできないことをテスト
     */
    public function test_guest_cannot_access_manage_page(): void
    {
        $response = $this->get('/manage/reports');

        $response->assertRedirect('/login');
    }

    /**
     * 報告に対応するts_itemを取得できることをテスト
     */
    public function test_can_get_ts_item_from_report(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '1:23',
            'ts_num' => 83,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        $tsItem = $report->tsItem;

        $this->assertNotNull($tsItem);
        $this->assertEquals($this->tsItem->id, $tsItem->id);
        $this->assertEquals('Test Song', $tsItem->text);
    }

    /**
     * ts_itemが存在しない場合はnullが返ることをテスト
     */
    public function test_ts_item_returns_null_when_not_exists(): void
    {
        $report = TimestampReport::create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_text' => '99:99',
            'ts_num' => 9999,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        $tsItem = $report->tsItem;

        $this->assertNull($tsItem);
    }
}
