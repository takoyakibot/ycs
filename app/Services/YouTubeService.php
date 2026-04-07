<?php

namespace App\Services;

/**
 * YouTube Data API v3 サービス
 *
 * 各専門サービスを統合して、アーカイブとタイムスタンプの取得を管理
 */
class YouTubeService
{
    protected YouTubeApiService $youtubeApiService;

    protected TimestampExtractorService $timestampExtractorService;

    protected VideoAnalyzerService $videoAnalyzerService;

    protected ArchiveProcessorService $archiveProcessorService;

    public function __construct(
        YouTubeApiService $youtubeApiService,
        TimestampExtractorService $timestampExtractorService,
        VideoAnalyzerService $videoAnalyzerService,
        ArchiveProcessorService $archiveProcessorService
    ) {
        $this->youtubeApiService = $youtubeApiService;
        $this->timestampExtractorService = $timestampExtractorService;
        $this->videoAnalyzerService = $videoAnalyzerService;
        $this->archiveProcessorService = $archiveProcessorService;
    }

    /**
     * ハンドル名からチャンネル情報を取得
     *
     * @param  string  $handle  チャンネルハンドル
     * @return array|null チャンネル情報、見つからない場合null
     */
    public function getChannelByHandle(string $handle): ?array
    {
        return $this->youtubeApiService->getChannelByHandle($handle);
    }

    /**
     * アーカイブとタイムスタンプを取得
     *
     * @param  string  $channelId  チャンネルID
     * @param  array  $stripPatterns  除去パターン文字列の配列（チャンネル設定）
     * @return array アーカイブ配列（タイムスタンプ付き）
     */
    public function getArchivesAndTsItems(string $channelId, array $stripPatterns = []): array
    {
        $archives = $this->youtubeApiService->getArchives($channelId);
        $rtnArchives = [];

        foreach ($archives as &$archive) {
            // 概要欄に存在するタイムスタンプをts_itemsとして取得する
            // 概要欄なので、comment_idにvideo_idを設定している
            // typeがあるんだからいいじゃないかという気がするがchangeListの管理方法とズレているためこんなことになっている
            $archive['ts_items'] = $this->timestampExtractorService->extractTimestamps(
                $archive['video_id'],
                '1', // description
                $archive['description'],
                $archive['video_id'],
                $stripPatterns,
            );

            // 歌枠またはカバー曲の場合は一旦表示にする
            $isSingingStream = $this->videoAnalyzerService->isSingingStream($archive['title']);
            $isCoverSong = $this->videoAnalyzerService->isCoverSong($archive['title']);
            $archive['is_display'] = $isSingingStream || $isCoverSong;

            // コメントを個別取得のみにする場合はここをコメントアウト
            // 以下の場合にコメントを検索する
            // 概要欄にタイムスタンプが1件以下（過去のコピペなどで0:00:00が残っている場合がある）
            // かつ、歌枠の場合（カバー曲は0:00タイムスタンプのみで十分なためコメント取得は不要）
            if ((empty($archive['ts_items']) || count($archive['ts_items']) <= 1) && $isSingingStream) {
                $commentTsItems = $this->getTimeStampsFromComments($archive['video_id'], $stripPatterns);
                foreach ($commentTsItems as $tsItem) {
                    $archive['ts_items'][] = $tsItem;
                }
            }

            $this->archiveProcessorService->updateDisplayTsItems($archive['ts_items']);

            $rtnArchives[] = $archive;
        }

        return $rtnArchives;
    }

    /**
     * コメントからタイムスタンプを取得
     *
     * @param  string  $videoId  動画ID
     * @param  array  $stripPatterns  除去パターン文字列の配列（チャンネル設定）
     * @return array タイムスタンプ配列
     */
    public function getTimeStampsFromComments(string $videoId, array $stripPatterns = []): array
    {
        $comments = $this->youtubeApiService->getComments($videoId);

        $rtnTsItems = [];
        foreach ($comments as $comment) {
            $tsItems = $this->timestampExtractorService->extractTimestamps(
                $videoId,
                '2', // comment
                $comment['description'],
                $comment['id'],
                $stripPatterns,
            );
            foreach ($tsItems as $tsItem) {
                $rtnTsItems[] = $tsItem;
            }
        }

        return $rtnTsItems;
    }
}
