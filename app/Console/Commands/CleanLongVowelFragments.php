<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\User;
use App\Services\SongMergeService;
use App\Services\TimestampDecompositionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 長音（ー）の位置で切れた曲名の楽曲マスタを片付ける（#1015）
 *
 * 長音を区切り文字として扱っていた頃（#425 で修正済み）の分解で、「チューリングラブ」から
 * 「チュ」のような断片の楽曲マスタが作られた。当時のマッピング（normalized_text の ー が / になっている）は
 * タイムスタンプの再正規化で孤立しており、TS分解の記録だけが断片を指している。
 */
class CleanLongVowelFragments extends Command
{
    protected $signature = 'songs:clean-long-vowel-fragments
        {--apply : 実際に更新する（指定しない場合はドライラン）}
        {--user= : 操作ログに記録するユーザーID（省略時は最初のスーパー管理者）}';

    protected $description = '長音の位置で切れた曲名の楽曲マスタを、正しいマスタへ統合するか削除してTS分解を再選別に戻す（既定はドライラン）';

    public function handle(SongMergeService $mergeService, TimestampDecompositionService $decompositionService): int
    {
        $apply = (bool) $this->option('apply');
        $this->line('モード: '.($apply ? '<comment>APPLY（更新します）</comment>' : '<info>ドライラン（更新しません）</info>'));

        $userId = $this->resolveUserId();
        if ($userId === null) {
            $this->error('操作ログに記録するユーザーが見つかりません。--user で指定してください。');

            return self::FAILURE;
        }

        $plans = $this->findFragments()->map(fn (Song $song) => $this->plan($song));

        $this->table(
            ['断片の曲名', 'アーティスト', '対応', '統合先', 'TS分解', '孤立マッピング'],
            $plans->map(fn ($p) => [
                $p['song']->title,
                $p['song']->artist,
                $p['target'] ? '統合' : '削除・再選別',
                $p['target'] ? "{$p['target']->title} / {$p['target']->artist}" : '',
                $p['decompositions']->count(),
                $p['orphanMappings']->count(),
            ])
        );
        $merge = $plans->filter(fn ($p) => $p['target'] !== null)->count();
        $this->line("対象: <info>{$plans->count()}件</info>（統合 {$merge}件 / 削除・再選別 ".($plans->count() - $merge).'件）');

        if (! $apply) {
            $this->info('ドライランのため更新は行いません。--apply を付けて実行してください。');

            return self::SUCCESS;
        }

        foreach ($plans as $plan) {
            DB::transaction(function () use ($plan, $mergeService, $decompositionService, $userId) {
                // 孤立したマッピングは統合先へ移しても意味がないので先に消す
                TimestampSongMapping::whereIn('id', $plan['orphanMappings']->pluck('id'))->delete();

                if ($plan['target'] !== null) {
                    $mergeService->merge($plan['song']->id, $plan['target']->id, $userId);

                    return;
                }

                foreach ($plan['decompositions'] as $decomposition) {
                    $decomposed = $decompositionService->decompose($decomposition->original_text);
                    $decomposition->update([
                        'parts' => $decomposed['parts'],
                        'separator_count' => $decomposed['separator_count'],
                        'title_part_index' => null,
                        'artist_part_index' => null,
                        'derived_title' => null,
                        'derived_artist' => null,
                        'status' => TimestampDecomposition::STATUS_PENDING,
                        'song_id' => null,
                        'confidence' => null,
                        'cascade_group_id' => null,
                        'updated_by' => $userId,
                    ]);
                }

                // 紐付くものが無くなった断片は削除する（タグは外部キーの cascade で消える）
                if (! TimestampSongMapping::where('song_id', $plan['song']->id)->exists()
                    && ! DB::table('ts_items')->where('song_id', $plan['song']->id)->exists()) {
                    $plan['song']->delete();
                }
            });
        }

        $this->info("完了: {$plans->count()}件");

        return self::SUCCESS;
    }

    /**
     * 断片の楽曲マスタ
     *
     * - 紐付くマッピングの normalized_text で、曲名の直後に「/ + カタカナ」が続く（長音が / になっていた痕跡）
     * - そのマッピングに一致するタイムスタンプが現存しない
     * - 断片を指すTS分解の元テキストに「曲名 + ー」がある（曲名は正しくアーティスト側が切れているだけのものを除く）
     *
     * @return Collection<int, Song>
     */
    private function findFragments(): Collection
    {
        return TimestampSongMapping::with('song')
            ->whereNotNull('song_id')
            ->where('normalized_text', 'like', '%/%')
            ->get()
            ->filter(function (TimestampSongMapping $mapping) {
                $title = $mapping->song?->normalized_title;

                return $title !== null && $title !== ''
                    && preg_match('/'.preg_quote($title, '/').'\/[ァ-ヺー]/u', $mapping->normalized_text) === 1;
            })
            ->map(fn (TimestampSongMapping $mapping) => $mapping->song)
            ->unique('id')
            ->filter(fn (Song $song) => $this->orphanMappings($song)->count() === TimestampSongMapping::where('song_id', $song->id)->count()
                && ! DB::table('ts_items')->where('song_id', $song->id)->exists()
                && $this->decompositions($song)->contains(
                    fn ($d) => str_contains(TextNormalizer::normalize($d->original_text), $song->normalized_title.'ー')
                ))
            ->sortBy('title')
            ->values();
    }

    /**
     * @return array{song: Song, target: ?Song, decompositions: Collection, orphanMappings: Collection}
     */
    private function plan(Song $song): array
    {
        $decompositions = $this->decompositions($song);
        $texts = $decompositions->map(fn ($d) => TextNormalizer::normalize($d->original_text));

        // 正しい表記のマスタ: 曲名が「断片 + ー」で始まり、TS分解の元テキストに含まれるもの
        $candidates = Song::where('id', '!=', $song->id)
            ->where('normalized_title', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $song->normalized_title).'ー%')
            ->get()
            ->filter(fn (Song $candidate) => $texts->contains(fn ($text) => str_contains($text, $candidate->normalized_title)));

        $sameArtist = $candidates->filter(fn (Song $c) => $c->normalized_artist === $song->normalized_artist);
        $pool = $sameArtist->isNotEmpty() ? $sameArtist : $candidates;

        return [
            'song' => $song,
            'target' => $pool->count() === 1 ? $pool->first() : null,
            'decompositions' => $decompositions,
            'orphanMappings' => $this->orphanMappings($song),
        ];
    }

    private function decompositions(Song $song): Collection
    {
        return TimestampDecomposition::where('song_id', $song->id)->get();
    }

    private function orphanMappings(Song $song): Collection
    {
        return TimestampSongMapping::where('song_id', $song->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ts_items')
                ->whereColumn('ts_items.normalized_text', 'timestamp_song_mappings.normalized_text'))
            ->get();
    }

    private function resolveUserId(): ?int
    {
        if ($this->option('user') !== null) {
            return User::find((int) $this->option('user'))?->id;
        }

        return User::where('role', User::ROLE_SUPER_ADMIN)->orderBy('id')->value('id');
    }
}
