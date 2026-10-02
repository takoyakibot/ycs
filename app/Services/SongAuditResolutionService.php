<?php

namespace App\Services;

use App\Exceptions\SongAuditResolutionException;
use App\Models\Song;
use App\Models\SongAudit;
use App\Models\TimestampSongMapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 点検結果を人が確認して適用・却下する
 */
class SongAuditResolutionService
{
    private const DUPLICATE_MESSAGE = '同じ曲名・アーティストの楽曲マスタが既にあります。正規化画面の「楽曲の統合」で統合してください。';

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
        $title = trim($title);
        $artist = trim($artist);

        $this->inLockedTransaction($audit, SongAudit::TARGET_SONG, function (SongAudit $audit) use ($title, $artist, $userId) {
            $song = Song::with('tags')->lockForUpdate()->find($audit->target_id);
            if ($song === null) {
                throw new SongAuditResolutionException('対象の楽曲マスタが削除されています。');
            }
            $this->assertUnchanged($audit, $song);

            if ($title === $song->title && $artist === $song->artist) {
                throw new SongAuditResolutionException('変更内容がありません。');
            }

            $duplicate = Song::where('title', $title)->where('artist', $artist)->where('id', '!=', $song->id)->exists();
            if ($duplicate) {
                throw new SongAuditResolutionException(self::DUPLICATE_MESSAGE);
            }

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
     *
     * @return Song 付け替え先（照合順序により入力と大文字小文字などが異なる場合があるため、表示に使う）
     */
    public function applyMappingLink(SongAudit $audit, string $title, string $artist, int $userId): Song
    {
        return $this->inLockedTransaction($audit, SongAudit::TARGET_MAPPING, function (SongAudit $audit) use ($title, $artist, $userId) {
            $mapping = $this->findUnchangedMapping($audit);

            $song = Song::where('title', trim($title))->where('artist', trim($artist))->first();
            if ($song === null) {
                throw new SongAuditResolutionException('該当する楽曲マスタがありません。先に楽曲マスタを登録してください。');
            }
            if ($song->id === $mapping->song_id) {
                throw new SongAuditResolutionException('現在と同じ楽曲マスタです。');
            }

            $this->mappingService->linkTimestamp($mapping->normalized_text, $song->id, $userId);
            $audit->update(['resolution' => SongAudit::RESOLUTION_APPLIED]);

            return $song;
        });
    }

    /**
     * 紐付けの修正を適用する（「楽曲ではない」にする）
     */
    public function applyMappingNotSong(SongAudit $audit, int $userId): void
    {
        $this->inLockedTransaction($audit, SongAudit::TARGET_MAPPING, function (SongAudit $audit) use ($userId) {
            $mapping = $this->findUnchangedMapping($audit);
            if ($mapping->is_not_song) {
                throw new SongAuditResolutionException('すでに「楽曲ではない」になっています。');
            }

            $this->mappingService->markAsNotSong($mapping->normalized_text, $userId);
            $audit->update(['resolution' => SongAudit::RESOLUTION_APPLIED]);
        });
    }

    public function reject(SongAudit $audit): void
    {
        $this->inLockedTransaction($audit, null, function (SongAudit $audit) {
            $audit->update(['resolution' => SongAudit::RESOLUTION_REJECTED]);
        });
    }

    /**
     * 「問題なし」の判定を人が「要修正」に変更する
     *
     * 人は画面で現在の内容を見て判断しているため、fingerprint も現在の内容で取り直す。
     */
    public function markNeedsFix(SongAudit $audit, string $reason, int $userId): void
    {
        DB::transaction(function () use ($audit, $reason, $userId) {
            $audit = SongAudit::lockForUpdate()->find($audit->id);
            if ($audit === null || $audit->verdict !== SongAudit::VERDICT_OK) {
                throw new SongAuditResolutionException('「問題なし」の判定ではありません。');
            }

            $target = $audit->target_type === SongAudit::TARGET_SONG
                ? Song::with('tags')->find($audit->target_id)
                : TimestampSongMapping::with('song')->find($audit->target_id);
            if ($target === null) {
                throw new SongAuditResolutionException('対象が削除されています。');
            }

            $audit->update([
                'fingerprint' => $this->auditService->fingerprint($audit->target_type, $target),
                'verdict' => SongAudit::VERDICT_NEEDS_FIX,
                'reason' => $reason,
                'suggestion' => null,
                'judged_by' => 'user:'.$userId,
                'judged_at' => now(),
                'resolution' => SongAudit::RESOLUTION_PENDING,
            ]);
        });
    }

    /**
     * 点検結果の行をロックして読み直し、未対応の「要修正」であることを確かめてから処理する
     *
     * 同じ行を2つのタブから同時に適用しても、2回目は「未対応ではない」で止まる。
     */
    private function inLockedTransaction(SongAudit $audit, ?string $type, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($audit, $type, $callback) {
                $locked = SongAudit::lockForUpdate()->find($audit->id);
                if ($locked === null) {
                    throw new SongAuditResolutionException('点検結果が削除されています。');
                }
                $this->assertPendingNeedsFix($locked, $type ?? $locked->target_type);

                return $callback($locked);
            });
        } catch (UniqueConstraintViolationException) {
            // 重複チェックの後に別の経路で同名の楽曲が作られた場合
            throw new SongAuditResolutionException(self::DUPLICATE_MESSAGE);
        }
    }

    private function findUnchangedMapping(SongAudit $audit): TimestampSongMapping
    {
        $mapping = TimestampSongMapping::with('song')->lockForUpdate()->find($audit->target_id);
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
