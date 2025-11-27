<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\TimestampReport;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Utils\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TimestampService
{
    /**
     * チャンネルのタイムスタンプを取得（マッピング情報付き）
     *
     * @return array{
     *     data: Collection,
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     available_indexes: array
     * }
     */
    public function getTimestampsWithMapping(
        Channel $channel,
        int $perPage = 50,
        int $currentPage = 1,
        string $search = '',
        string $index = ''
    ): array {
        // タイムスタンプ取得（チャンネルフィルタ付き）
        $query = $this->buildTimestampQuery($channel, withArchive: true);

        // 検索条件の追加（タイムスタンプテキスト）
        if ($search) {
            $escapedSearch = addcslashes($search, '%_\\');
            $query->where('text', 'like', "%{$escapedSearch}%");
        }

        // 全件取得（ページネーション前）
        $allTimestamps = $query->get();

        // マッピング情報を取得
        $mappings = $this->fetchMappings($allTimestamps, $channel->channel_id);

        // 報告情報を取得
        $reportedTsItemIds = $this->fetchReportedIds($allTimestamps, $channel->channel_id);

        // 各タイムスタンプにマッピング情報を追加
        $timestampsWithMapping = $this->attachMappingInfo($allTimestamps, $mappings, $reportedTsItemIds);

        // 「楽曲ではない」タイムスタンプを除外
        $timestampsWithMapping = $this->excludeNonSongs($timestampsWithMapping);

        // ソート処理（楽曲名順）
        $timestampsWithMapping = $this->sortByTitle($timestampsWithMapping);

        // 利用可能な頭文字カテゴリを収集（フィルタリング前に行う）
        $availableIndexes = $this->collectAvailableIndexes($timestampsWithMapping);

        // 頭文字フィルタリング
        if ($index) {
            $timestampsWithMapping = $this->filterByIndex($timestampsWithMapping, $index);
        }

        // 手動でページネーション
        $total = $timestampsWithMapping->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($currentPage - 1) * $perPage;
        $items = $timestampsWithMapping->slice($offset, $perPage)->values();

        return [
            'data' => $items,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'available_indexes' => $availableIndexes,
        ];
    }

    /**
     * タイムスタンプ取得クエリのベースを構築
     */
    private function buildTimestampQuery(Channel $channel, bool $withArchive = false)
    {
        $query = TsItem::query();

        if ($withArchive) {
            $query->with(['archive']);
        }

        return $query
            ->whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id)
                    ->where('is_display', 1);
            })
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->where('is_display', 1);
    }

    /**
     * マッピング情報を一括取得
     */
    private function fetchMappings(Collection $timestamps, string $channelId): Collection
    {
        $normalizedTexts = $timestamps->map(function ($item) {
            return TextNormalizer::normalize($item->text);
        })->unique()->values()->toArray();

        try {
            return TimestampSongMapping::whereIn('normalized_text', $normalizedTexts)
                ->with('song')
                ->get()
                ->keyBy('normalized_text');
        } catch (\Exception $e) {
            Log::error('Failed to fetch song mappings in TimestampService', [
                'error' => $e->getMessage(),
                'channel_id' => $channelId,
            ]);

            return collect();
        }
    }

    /**
     * 未解決の報告があるタイムスタンプIDを取得
     */
    private function fetchReportedIds(Collection $timestamps, string $channelId): array
    {
        $tsItemIds = $timestamps->pluck('id')->toArray();

        if (empty($tsItemIds)) {
            return [];
        }

        try {
            return TimestampReport::whereIn('ts_item_id', $tsItemIds)
                ->where('status', 'pending')
                ->pluck('ts_item_id')
                ->unique()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to fetch timestamp reports', [
                'error' => $e->getMessage(),
                'channel_id' => $channelId,
            ]);

            return [];
        }
    }

    /**
     * 各タイムスタンプにマッピング情報を追加
     */
    private function attachMappingInfo(Collection $timestamps, Collection $mappings, array $reportedTsItemIds): Collection
    {
        return $timestamps->map(function ($item) use ($mappings, $reportedTsItemIds) {
            $normalizedText = TextNormalizer::normalize($item->text);
            $mapping = $mappings->get($normalizedText);

            return [
                'id' => $item->id,
                'ts_text' => $item->ts_text,
                'ts_num' => $item->ts_num,
                'text' => $item->text,
                'video_id' => $item->video_id,
                'archive' => [
                    'title' => $item->archive->title,
                    'published_at' => $item->archive->published_at,
                ],
                'mapping' => $mapping ? [
                    'song' => $mapping->song ? [
                        'title' => $mapping->song->title,
                        'artist' => $mapping->song->artist,
                        'spotify_track_id' => $this->validateSpotifyTrackId($mapping->song->spotify_track_id),
                    ] : null,
                    'is_not_song' => $mapping->is_not_song,
                ] : null,
                'has_pending_report' => in_array($item->id, $reportedTsItemIds),
            ];
        });
    }

    /**
     * 「楽曲ではない」タイムスタンプを除外
     */
    private function excludeNonSongs(Collection $timestamps): Collection
    {
        return $timestamps->filter(function ($ts) {
            return ! ($ts['mapping'] && $ts['mapping']['is_not_song']);
        })->values();
    }

    /**
     * タイトル順でソート
     */
    private function sortByTitle(Collection $timestamps): Collection
    {
        return $timestamps->sort(function ($a, $b) {
            $aTitle = $a['mapping']['song']['title'] ?? $a['text'] ?? '';
            $bTitle = $b['mapping']['song']['title'] ?? $b['text'] ?? '';

            return strcasecmp($aTitle, $bTitle);
        })->values();
    }

    /**
     * 利用可能な頭文字カテゴリを収集
     */
    private function collectAvailableIndexes(Collection $timestamps): array
    {
        $availableIndexes = [];

        foreach ($timestamps as $ts) {
            $title = $ts['mapping']['song']['title'] ?? $ts['text'] ?? '';
            $category = $this->getFirstCharCategory($title);

            if ($category && ! in_array($category, $availableIndexes)) {
                $availableIndexes[] = $category;
            }
        }

        return $availableIndexes;
    }

    /**
     * 頭文字でフィルタリング
     */
    private function filterByIndex(Collection $timestamps, string $index): Collection
    {
        return $timestamps->filter(function ($ts) use ($index) {
            $title = $ts['mapping']['song']['title'] ?? $ts['text'] ?? '';

            return $this->getFirstCharCategory($title) === $index;
        })->values();
    }

    /**
     * タイトルから頭文字カテゴリを取得
     */
    private function getFirstCharCategory(?string $title): ?string
    {
        if (empty($title)) {
            return null;
        }

        $firstChar = mb_substr($title, 0, 1, 'UTF-8');
        $firstChar = mb_strtoupper($firstChar, 'UTF-8');

        return $this->categorizeFirstChar($firstChar);
    }

    /**
     * 頭文字をカテゴリに分類
     */
    private function categorizeFirstChar(string $char): string
    {
        $upperChar = strtoupper($char);

        // アルファベット（ABCDE, FGHIJ, KLMNO, PQRST, UVWXYZ）
        if (preg_match('/^[A-E]$/', $upperChar)) {
            return 'ABCDE';
        }
        if (preg_match('/^[F-J]$/', $upperChar)) {
            return 'FGHIJ';
        }
        if (preg_match('/^[K-O]$/', $upperChar)) {
            return 'KLMNO';
        }
        if (preg_match('/^[P-T]$/', $upperChar)) {
            return 'PQRST';
        }
        if (preg_match('/^[U-Z]$/', $upperChar)) {
            return 'UVWXYZ';
        }

        // ひらがな・カタカナ（五十音行に分類）
        $kanaMap = [
            'あ' => ['あ', 'い', 'う', 'え', 'お', 'ア', 'イ', 'ウ', 'エ', 'オ'],
            'か' => ['か', 'き', 'く', 'け', 'こ', 'が', 'ぎ', 'ぐ', 'げ', 'ご',
                'カ', 'キ', 'ク', 'ケ', 'コ', 'ガ', 'ギ', 'グ', 'ゲ', 'ゴ'],
            'さ' => ['さ', 'し', 'す', 'せ', 'そ', 'ざ', 'じ', 'ず', 'ぜ', 'ぞ',
                'サ', 'シ', 'ス', 'セ', 'ソ', 'ザ', 'ジ', 'ズ', 'ゼ', 'ゾ'],
            'た' => ['た', 'ち', 'つ', 'て', 'と', 'だ', 'ぢ', 'づ', 'で', 'ど',
                'タ', 'チ', 'ツ', 'テ', 'ト', 'ダ', 'ヂ', 'ヅ', 'デ', 'ド'],
            'な' => ['な', 'に', 'ぬ', 'ね', 'の', 'ナ', 'ニ', 'ヌ', 'ネ', 'ノ'],
            'は' => ['は', 'ひ', 'ふ', 'へ', 'ほ', 'ば', 'び', 'ぶ', 'べ', 'ぼ',
                'ぱ', 'ぴ', 'ぷ', 'ぺ', 'ぽ',
                'ハ', 'ヒ', 'フ', 'ヘ', 'ホ', 'バ', 'ビ', 'ブ', 'ベ', 'ボ',
                'パ', 'ピ', 'プ', 'ペ', 'ポ'],
            'ま' => ['ま', 'み', 'む', 'め', 'も', 'マ', 'ミ', 'ム', 'メ', 'モ'],
            'や' => ['や', 'ゆ', 'よ', 'ヤ', 'ユ', 'ヨ'],
            'ら' => ['ら', 'り', 'る', 'れ', 'ろ', 'ラ', 'リ', 'ル', 'レ', 'ロ'],
            'わ' => ['わ', 'を', 'ん', 'ワ', 'ヲ', 'ン'],
        ];

        foreach ($kanaMap as $category => $chars) {
            if (in_array($char, $chars)) {
                return $category;
            }
        }

        // 数字（0-9）
        if (preg_match('/^[0-9]$/', $char)) {
            return '0-9';
        }

        // その他（記号など）
        return 'その他';
    }

    /**
     * Spotify Track IDの妥当性を検証
     */
    private function validateSpotifyTrackId(?string $trackId): ?string
    {
        if (! $trackId) {
            return null;
        }

        // Spotify track IDsは22文字の英数字
        if (preg_match('/^[a-zA-Z0-9]{22}$/', $trackId)) {
            return $trackId;
        }

        return null;
    }
}
