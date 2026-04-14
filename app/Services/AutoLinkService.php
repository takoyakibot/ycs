<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoLinkService
{
    protected SpotifyService $spotifyService;

    /**
     * 1リクエストあたりの遅延時間（ミリ秒）
     */
    protected int $delayMs;

    /**
     * レート制限エラー時の待機時間（秒）
     */
    protected int $rateLimitWaitSeconds;

    /**
     * Spotify検索時の取得件数（逆検証用に複数件取得）
     */
    protected int $spotifySearchLimit;

    public function __construct(SpotifyService $spotifyService)
    {
        $this->spotifyService = $spotifyService;
        $this->delayMs = config('songs.auto_link.delay_ms', 500);
        $this->rateLimitWaitSeconds = config('songs.auto_link.rate_limit_wait_seconds', 60);
        $this->spotifySearchLimit = config('songs.auto_link.spotify_search_limit', 3);
    }

    /**
     * 未紐付けのタイムスタンプを自動でSpotify検索し、トップ結果を紐付ける
     *
     * @param  int  $limit  処理件数上限
     * @param  callable|null  $onProgress  進捗コールバック function(string $message): void
     * @return array{processed: int, linked: int, pending: int, failed: int, skipped: int}
     */
    public function autoLinkUnlinkedTimestamps(int $limit = 100, ?callable $onProgress = null): array
    {
        $result = [
            'processed' => 0,
            'linked' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        // 未紐付けのタイムスタンプを取得（ユニークなnormalized_textのみ）
        $unlinkedTexts = $this->getUnlinkedTexts($limit);

        if (empty($unlinkedTexts)) {
            $onProgress && $onProgress('未紐付けのタイムスタンプが見つかりませんでした。');

            return $result;
        }

        $onProgress && $onProgress(sprintf('%d件の未紐付けテキストを処理します。', count($unlinkedTexts)));

        foreach ($unlinkedTexts as $index => $item) {
            $result['processed']++;

            try {
                $linkResult = $this->processAutoLink($item['text'], $item['normalized_text']);

                if ($linkResult === 'linked') {
                    $result['linked']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 紐付け成功: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } elseif ($linkResult === 'pending') {
                    $result['pending']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 保留: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } elseif ($linkResult === 'skipped') {
                    $result['skipped']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] スキップ: %s', $index + 1, count($unlinkedTexts), $item['text']));
                } else {
                    $result['failed']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 検索結果なし: %s', $index + 1, count($unlinkedTexts), $item['text']));
                }

                // レート制限対策: 適切な遅延を入れる
                if ($index < count($unlinkedTexts) - 1) {
                    usleep($this->delayMs * 1000);
                }
            } catch (\Exception $e) {
                // レート制限エラーの場合は待機して同じアイテムを再処理
                if (str_contains($e->getMessage(), 'レート制限')) {
                    $this->log('warning', sprintf('レート制限に達しました。%d秒待機して再試行します。', $this->rateLimitWaitSeconds));
                    $onProgress && $onProgress(sprintf('レート制限に達しました。%d秒待機して再試行します...', $this->rateLimitWaitSeconds));
                    sleep($this->rateLimitWaitSeconds);

                    // 同じアイテムを再処理
                    try {
                        $linkResult = $this->processAutoLink($item['text'], $item['normalized_text']);

                        if ($linkResult === 'linked') {
                            $result['linked']++;
                            $onProgress && $onProgress(sprintf('[%d/%d] 紐付け成功（再試行）: %s', $index + 1, count($unlinkedTexts), $item['text']));
                        } elseif ($linkResult === 'pending') {
                            $result['pending']++;
                            $onProgress && $onProgress(sprintf('[%d/%d] 保留（再試行）: %s', $index + 1, count($unlinkedTexts), $item['text']));
                        } elseif ($linkResult === 'skipped') {
                            $result['skipped']++;
                            $onProgress && $onProgress(sprintf('[%d/%d] スキップ（再試行）: %s', $index + 1, count($unlinkedTexts), $item['text']));
                        } else {
                            $result['failed']++;
                            $onProgress && $onProgress(sprintf('[%d/%d] 検索結果なし（再試行）: %s', $index + 1, count($unlinkedTexts), $item['text']));
                        }

                        // 再試行成功後も遅延を入れる（連続レート制限を防止）
                        if ($index < count($unlinkedTexts) - 1) {
                            usleep($this->delayMs * 1000);
                        }

                        continue;
                    } catch (\Exception $retryException) {
                        // 再試行も失敗した場合はfailed扱い
                        $result['failed']++;
                        $this->log('error', sprintf('自動紐付けエラー（再試行失敗）: %s - %s', $item['text'], $retryException->getMessage()));
                        $onProgress && $onProgress(sprintf('[%d/%d] エラー（再試行失敗）: %s - %s', $index + 1, count($unlinkedTexts), $item['text'], $retryException->getMessage()));

                        continue;
                    }
                }

                $result['failed']++;
                $this->log('error', sprintf('自動紐付けエラー: %s - %s', $item['text'], $e->getMessage()));
                $onProgress && $onProgress(sprintf('[%d/%d] エラー: %s - %s', $index + 1, count($unlinkedTexts), $item['text'], $e->getMessage()));
            }
        }

        return $result;
    }

    /**
     * 未紐付けのテキスト一覧を取得
     *
     * @param  int  $limit  取得件数上限
     * @return array<array{text: string, normalized_text: string}>
     */
    protected function getUnlinkedTexts(int $limit): array
    {
        // normalized_textのみでグループ化し、textはMIN()で代表値を取得
        // これにより同じnormalized_textで異なるtextを持つケースでも重複排除される
        return TsItem::selectRaw('MIN(ts_items.text) as text, ts_items.normalized_text')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3') // 歌ってみた/カバー曲はノイズが多いため除外
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            })
            ->whereNull('timestamp_song_mappings.id')
            ->groupBy('ts_items.normalized_text')
            ->orderByRaw('MIN(ts_items.text) asc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'text' => $item->text,
                'normalized_text' => $item->normalized_text,
            ])
            ->toArray();
    }

    /**
     * 単一テキストの自動紐付け処理
     *
     * フロー:
     * ❶ 既存DB照合: タイムスタンプのnormalized_textからtitle部分を抽出し、
     *    songs.normalized_titleと照合。一致すればリンク。
     * ❷ Spotify検索 + 逆検証: Spotify上位N件の楽曲名をnormalizeして
     *    元のnormalized_textに含まれるか照合。一致すれば新規Song作成+リンク。
     *    一致しなければ未紐づけのまま。
     *
     * @return string 'linked'|'pending'|'skipped'|'not_found'
     */
    protected function processAutoLink(string $text, string $normalizedText): string
    {
        // ❶ 既存DB照合: normalized_textから楽曲名を抽出してsongsテーブルと照合
        $existingSong = $this->findSongByNormalizedText($normalizedText);
        if ($existingSong) {
            $this->createAutoLinkMapping($normalizedText, $existingSong->id);

            return 'linked';
        }

        // ❷ Spotify検索 + 逆検証
        $tracks = $this->spotifyService->searchWithAuth($text, $this->spotifySearchLimit);

        if (empty($tracks)) {
            return 'not_found';
        }

        // Spotify結果の楽曲名で逆検証: 元のnormalized_textに含まれるか確認
        $matchedTrack = $this->findMatchingTrack($tracks, $normalizedText);

        if (! $matchedTrack) {
            // 逆検証で一致しない → 楽曲ではない可能性が高い。未紐づけのまま
            return 'not_found';
        }

        $track = $matchedTrack;
        $spotifyTrackId = $track['id'];

        // Spotify Track IDで既存songを検索
        $existingSong = Song::where('spotify_track_id', $spotifyTrackId)->first();
        if ($existingSong) {
            $this->createAutoLinkMapping($normalizedText, $existingSong->id);

            return 'linked';
        }

        // 新規楽曲マスタを作成（タイムスタンプの情報を使用）
        $song = $this->createSongFromTimestamp($normalizedText, $track);
        $this->createAutoLinkMapping($normalizedText, $song->id);

        return 'linked';
    }

    /**
     * normalized_textから楽曲名を抽出し、既存songsテーブルと照合する
     *
     * extractSongInfo()で分割し、title部分とartist部分の両方で
     * songs.normalized_titleを検索する（順序が不定のため）
     */
    protected function findSongByNormalizedText(string $normalizedText): ?Song
    {
        // extractSongInfoはparts[0]をartist、parts[1]をtitleとして返すが、
        // 実際のタイムスタンプでは「曲名 / アーティスト名」「アーティスト名 / 曲名」の
        // 両パターンがあるため、両方のパートでsongs.normalized_titleを検索する
        $songInfo = TextNormalizer::extractSongInfo($normalizedText);

        $candidates = [];

        // parts[1]（title部分）でnormalized_titleを検索
        if (! empty($songInfo['title'])) {
            $song = Song::where('normalized_title', $songInfo['title'])->first();
            if ($song) {
                $candidates[] = $song;
            }
        }

        // parts[0]（artist部分）でもnormalized_titleを検索（順序が逆の場合に対応）
        if (! empty($songInfo['artist'])) {
            $song = Song::where('normalized_title', $songInfo['artist'])->first();
            if ($song && ! in_array($song->id, array_map(fn ($s) => $s->id, $candidates))) {
                $candidates[] = $song;
            }
        }

        // 区切りなしの場合：extractSongInfoがtitleに全体を入れるため、上のtitle検索で対応済み
        if (empty($songInfo['artist'])) {
            return $candidates[0] ?? null;
        }

        // 候補が1つならそのまま返す
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // 候補が複数ある場合、artist側も一致するものを優先
        foreach ($candidates as $candidate) {
            $normalizedArtist = $candidate->normalized_artist;
            if ($normalizedArtist === $songInfo['artist'] || $normalizedArtist === $songInfo['title']) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Spotify検索結果の楽曲名を元のnormalized_textと照合し、一致するトラックを返す
     *
     * 各trackのnameをnormalizeして、normalized_textに含まれるか確認する。
     */
    protected function findMatchingTrack(array $tracks, string $normalizedText): ?array
    {
        // normalized_textを分割（ループの外で1回だけ実行）
        // extractSongInfoはparts[0]をartist、parts[1]をtitleとして返すが、
        // 実際のタイムスタンプでは順序が不定なので両方で照合する
        $songInfo = TextNormalizer::extractSongInfo($normalizedText);

        foreach ($tracks as $track) {
            $normalizedTrackName = TextNormalizer::normalize($track['name']);

            if (empty($normalizedTrackName)) {
                continue;
            }

            // 完全一致
            if ($normalizedText === $normalizedTrackName) {
                return $track;
            }

            // 分割後のtitle部分と照合
            if (! empty($songInfo['title']) && $songInfo['title'] === $normalizedTrackName) {
                return $track;
            }

            // 分割後のartist部分と照合（artist/titleの順序が逆の場合に対応）
            if (! empty($songInfo['artist']) && $songInfo['artist'] === $normalizedTrackName) {
                return $track;
            }
        }

        return null;
    }

    /**
     * タイムスタンプの情報を使って楽曲マスタを作成または取得
     *
     * タイムスタンプのnormalized_textから楽曲名・アーティスト名を抽出して登録する。
     * Spotifyのトラック情報はspotify_track_idとspotify_dataとして保持する。
     * 正規化済みテキストで既存楽曲を検索し、重複登録を防止する。
     */
    protected function createSongFromTimestamp(string $normalizedText, array $track): Song
    {
        $songInfo = TextNormalizer::extractSongInfo($normalizedText);
        $title = $songInfo['title'] ?? $normalizedText;
        $artist = $songInfo['artist'] ?? '';

        // 正規化済みテキストで既存楽曲を検索（重複防止）
        $normalizedTitle = TextNormalizer::normalize($title);
        $normalizedArtist = TextNormalizer::normalize($artist);

        $existingSong = Song::where('normalized_title', $normalizedTitle)
            ->where('normalized_artist', $normalizedArtist)
            ->first();

        if ($existingSong) {
            return $existingSong;
        }

        return Song::firstOrCreate(
            [
                'title' => $title,
                'artist' => $artist,
            ],
            [
                'id' => Str::ulid(),
                'spotify_track_id' => $track['id'],
                'spotify_data' => $track,
            ]
        );
    }

    /**
     * 自動紐付けマッピングを作成
     *
     * updateOrCreateを使用してTOCTOU競合を防止
     * 新規作成時のidはモデルのcreatingイベントで自動設定される
     */
    protected function createAutoLinkMapping(string $normalizedText, string $songId): void
    {
        TimestampSongMapping::updateOrCreate(
            ['normalized_text' => $normalizedText],
            [
                'song_id' => $songId,
                'is_not_song' => false,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => false,
                'confidence' => 0.8, // 自動紐付けは0.8
            ]
        );
    }

    /**
     * 保留マッピングを作成
     *
     * updateOrCreateを使用してTOCTOU競合を防止
     * 新規作成時のidはモデルのcreatingイベントで自動設定される
     */
    protected function createPendingMapping(string $normalizedText, string $songId, float $similarity): void
    {
        TimestampSongMapping::updateOrCreate(
            ['normalized_text' => $normalizedText],
            [
                'song_id' => $songId,
                'is_not_song' => false,
                'status' => TimestampSongMapping::STATUS_PENDING,
                'is_manual' => false,
                'confidence' => $similarity,
            ]
        );
    }

    /**
     * ログ出力
     *
     * Log::log()を使用して静的解析・IDEサポートを改善
     */
    protected function log(string $level, string $message): void
    {
        Log::log($level, '[AutoLinkService] '.$message);
    }
}
