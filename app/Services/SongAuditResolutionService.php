<?php

namespace App\Services;

use App\Exceptions\SongAuditResolutionException;
use App\Models\Song;
use App\Models\SongAudit;
use App\Models\TimestampSongMapping;
use Illuminate\Support\Facades\DB;

/**
 * 点検結果を人が確認して適用・却下する
 */
class SongAuditResolutionService
{
    public function __construct(
        private SongAuditService $auditService,
        private SongMappingService $mappingService,
    ) {}

    /**
     * 楽曲マスタの修正を適用する
     *
     * 通常の楽曲更新と同じく Song::update を通す（正規化カラム・review_status の更新は保存フックが行う）。
     * アーティスト名が変わる場合は、旧アーティスト名と同じタグも揃える。
     */
    public function applySong(SongAudit $audit, string $title, string $artist, int $userId): void
    {
        $this->assertPendingNeedsFix($audit, SongAudit::TARGET_SONG);

        $song = Song::with('tags')->find($audit->target_id);
        if ($song === null) {
            throw new SongAuditResolutionException('対象の楽曲マスタが削除されています。');
        }
        $this->assertUnchanged($audit, $song);

        $title = trim($title);
        $artist = trim($artist);
        if ($title === $song->title && $artist === $song->artist) {
            throw new SongAuditResolutionException('変更内容がありません。');
        }

        $duplicate = Song::where('title', $title)->where('artist', $artist)->where('id', '!=', $song->id)->exists();
        if ($duplicate) {
            throw new SongAuditResolutionException('同じ曲名・アーティストの楽曲マスタが既にあります。正規化画面の「楽曲の統合」で統合してください。');
        }

        DB::transaction(function () use ($audit, $song, $title, $artist, $userId) {
            $oldArtist = $song->artist;
            $song->update(['title' => $title, 'artist' => $artist, 'updated_by' => $userId]);

            if ($oldArtist !== null && $oldArtist !== '' && $oldArtist !== $artist) {
                $song->syncArtistTags($oldArtist, $artist);
            }

            $audit->update(['resolution' => SongAudit::RESOLUTION_APPLIED]);
        });
    }

    /**
     * 紐付けの修正を適用する（別の楽曲マスタへの付け替え）
     */
    public function applyMappingLink(SongAudit $audit, string $title, string $artist, int $userId): void
    {
        $mapping = $this->findUnchangedMapping($audit);

        $song = Song::where('title', trim($title))->where('artist', trim($artist))->first();
        if ($song === null) {
            throw new SongAuditResolutionException('該当する楽曲マスタがありません。先に楽曲マスタを登録してください。');
        }
        if ($song->id === $mapping->song_id) {
            throw new SongAuditResolutionException('現在と同じ楽曲マスタです。');
        }

        DB::transaction(function () use ($audit, $mapping, $song, $userId) {
            $this->mappingService->linkTimestamp($mapping->normalized_text, $song->id, $userId);
            $audit->update(['resolution' => SongAudit::RESOLUTION_APPLIED]);
        });
    }

    /**
     * 紐付けの修正を適用する（「楽曲ではない」にする）
     */
    public function applyMappingNotSong(SongAudit $audit, int $userId): void
    {
        $mapping = $this->findUnchangedMapping($audit);

        DB::transaction(function () use ($audit, $mapping, $userId) {
            $this->mappingService->markAsNotSong($mapping->normalized_text, $userId);
            $audit->update(['resolution' => SongAudit::RESOLUTION_APPLIED]);
        });
    }

    public function reject(SongAudit $audit): void
    {
        $this->assertPendingNeedsFix($audit, $audit->target_type);

        $audit->update(['resolution' => SongAudit::RESOLUTION_REJECTED]);
    }

    /**
     * 「問題なし」の判定を人が「要修正」に変更する
     */
    public function markNeedsFix(SongAudit $audit, string $reason, int $userId): void
    {
        if ($audit->verdict !== SongAudit::VERDICT_OK) {
            throw new SongAuditResolutionException('「問題なし」の判定ではありません。');
        }

        $audit->update([
            'verdict' => SongAudit::VERDICT_NEEDS_FIX,
            'reason' => $reason,
            'suggestion' => null,
            'judged_by' => 'user:'.$userId,
            'judged_at' => now(),
            'resolution' => SongAudit::RESOLUTION_PENDING,
        ]);
    }

    private function findUnchangedMapping(SongAudit $audit): TimestampSongMapping
    {
        $this->assertPendingNeedsFix($audit, SongAudit::TARGET_MAPPING);

        $mapping = TimestampSongMapping::with('song')->find($audit->target_id);
        if ($mapping === null) {
            throw new SongAuditResolutionException('対象の紐付けが削除されています。');
        }
        $this->assertUnchanged($audit, $mapping);

        return $mapping;
    }

    private function assertPendingNeedsFix(SongAudit $audit, string $type): void
    {
        if ($audit->target_type !== $type) {
            throw new SongAuditResolutionException('対象の種別が一致しません。');
        }
        if ($audit->verdict !== SongAudit::VERDICT_NEEDS_FIX || $audit->resolution !== SongAudit::RESOLUTION_PENDING) {
            throw new SongAuditResolutionException('未対応の「要修正」ではありません。');
        }
    }

    private function assertUnchanged(SongAudit $audit, Song|TimestampSongMapping $target): void
    {
        if ($this->auditService->fingerprint($audit->target_type, $target) !== $audit->fingerprint) {
            throw new SongAuditResolutionException('判定時から対象の内容が変わっているため適用しませんでした。内容を確認し、必要なら正規化画面で修正してください。');
        }
    }
}
