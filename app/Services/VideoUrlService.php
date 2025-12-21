<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoUrlService
{
    protected YouTubeApiService $youtubeApiService;

    public function __construct(YouTubeApiService $youtubeApiService)
    {
        $this->youtubeApiService = $youtubeApiService;
    }

    /**
     * URLからプラットフォームを判定
     *
     * @return string|null 'youtube', 'niconico', or null
     */
    public function detectPlatform(string $url): ?string
    {
        // YouTube判定
        if (preg_match('/(?:youtube\.com|youtu\.be)/', $url)) {
            return 'youtube';
        }

        // ニコニコ動画判定
        if (preg_match('/(?:nicovideo\.jp|nico\.ms)/', $url)) {
            return 'niconico';
        }

        return null;
    }

    /**
     * URLから動画の長さ（ミリ秒）を取得
     *
     * @return array{duration_ms: int|null, video_id: string|null, platform: string|null, error: string|null}
     */
    public function getVideoDuration(string $url): array
    {
        $platform = $this->detectPlatform($url);

        if (! $platform) {
            return [
                'duration_ms' => null,
                'video_id' => null,
                'platform' => null,
                'error' => '対応していないURLです。YouTube または ニコニコ動画のURLを入力してください。',
            ];
        }

        try {
            if ($platform === 'youtube') {
                return $this->getYoutubeDuration($url);
            } elseif ($platform === 'niconico') {
                return $this->getNiconicoDuration($url);
            }
        } catch (Exception $e) {
            Log::error('VideoUrlService: Failed to get duration', [
                'url' => $url,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return [
                'duration_ms' => null,
                'video_id' => null,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'duration_ms' => null,
            'video_id' => null,
            'platform' => $platform,
            'error' => '動画情報を取得できませんでした。',
        ];
    }

    /**
     * YouTubeから動画の長さを取得
     */
    protected function getYoutubeDuration(string $url): array
    {
        $videoId = $this->youtubeApiService->extractVideoId($url);

        if (! $videoId) {
            return [
                'duration_ms' => null,
                'video_id' => null,
                'platform' => 'youtube',
                'error' => '有効なYouTube URLではありません。',
            ];
        }

        $durationMs = $this->youtubeApiService->getVideoDuration($videoId);

        return [
            'duration_ms' => $durationMs,
            'video_id' => $videoId,
            'platform' => 'youtube',
            'error' => $durationMs === null ? '動画情報を取得できませんでした。' : null,
        ];
    }

    /**
     * ニコニコ動画から動画の長さを取得
     */
    protected function getNiconicoDuration(string $url): array
    {
        $videoId = $this->extractNiconicoVideoId($url);

        if (! $videoId) {
            return [
                'duration_ms' => null,
                'video_id' => null,
                'platform' => 'niconico',
                'error' => '有効なニコニコ動画URLではありません。',
            ];
        }

        // スナップショット検索APIを使用（公式API）
        $result = $this->getNiconicoDurationFromSnapshot($videoId);

        if ($result['duration_ms'] !== null) {
            return $result;
        }

        // フォールバック: Watch API v3_guestを試す
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Frontend-Id' => '6',
                    'X-Frontend-Version' => '0',
                    'User-Agent' => config('app.name').' (Video Duration Fetcher)',
                ])->get("https://www.nicovideo.jp/api/watch/v3_guest/{$videoId}", [
                    'actionTrackId' => uniqid(),
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $duration = $data['data']['video']['duration'] ?? null;

                if ($duration !== null) {
                    return [
                        'duration_ms' => (int) $duration * 1000,
                        'video_id' => $videoId,
                        'platform' => 'niconico',
                        'error' => null,
                    ];
                }
            }
        } catch (Exception $e) {
            Log::debug('Niconico Watch API error (fallback failed)', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'duration_ms' => null,
            'video_id' => $videoId,
            'platform' => 'niconico',
            'error' => 'ニコニコ動画の情報を取得できませんでした。手動で秒数を入力してください。',
        ];
    }

    /**
     * スナップショット検索APIでニコニコ動画の長さを取得
     */
    protected function getNiconicoDurationFromSnapshot(string $videoId): array
    {
        try {
            $response = Http::timeout(10)->get('https://api.search.nicovideo.jp/api/v2/snapshot/video/contents/search', [
                'q' => $videoId,
                'targets' => 'contentId',
                'fields' => 'contentId,lengthSeconds',
                '_sort' => '-viewCounter',
                '_limit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data['data'][0]['lengthSeconds'])) {
                    return [
                        'duration_ms' => (int) $data['data'][0]['lengthSeconds'] * 1000,
                        'video_id' => $videoId,
                        'platform' => 'niconico',
                        'error' => null,
                    ];
                }
            }
        } catch (Exception $e) {
            Log::debug('Niconico Snapshot API error', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'duration_ms' => null,
            'video_id' => $videoId,
            'platform' => 'niconico',
            'error' => null,
        ];
    }

    /**
     * ニコニコ動画URLから動画IDを抽出
     */
    protected function extractNiconicoVideoId(string $url): ?string
    {
        // 対応フォーマット:
        // https://www.nicovideo.jp/watch/sm12345678
        // https://nico.ms/sm12345678
        // https://nicovideo.jp/watch/so12345678
        // https://www.nicovideo.jp/watch/nm12345678
        $pattern = '/(?:nicovideo\.jp\/watch\/|nico\.ms\/)([a-z]{2}\d+)/';

        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
