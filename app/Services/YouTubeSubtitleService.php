<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouTube字幕取得サービス
 *
 * InnerTube APIを使用してYouTube動画の字幕（自動生成含む）を取得する。
 * YouTube Data API v3のquotaを消費しない。
 */
class YouTubeSubtitleService
{
    /** InnerTube Player APIエンドポイント */
    private const INNERTUBE_PLAYER_URL = 'https://www.youtube.com/youtubei/v1/player?prettyPrint=false';

    /**
     * InnerTubeクライアントバージョン
     * YouTubeがバージョン検証を強化した場合、更新が必要になる可能性がある
     */
    private const CLIENT_VERSION = '2.20240101.00.00';

    /** HTTPタイムアウト（秒） */
    private const HTTP_TIMEOUT = 15;

    /** User-Agent */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** 字幕URL許可ドメイン */
    private const ALLOWED_SUBTITLE_HOSTS = [
        '.youtube.com',
        '.googlevideo.com',
    ];

    /**
     * 動画IDから利用可能な字幕トラック一覧を取得
     *
     * @param  string  $videoId  YouTube動画ID（11文字）
     * @return array 字幕トラック配列。各要素: {languageCode, name, kind, baseUrl, isTranslatable}
     *
     * @throws Exception 動画が存在しない場合やAPI通信エラー時
     */
    public function getCaptionTracks(string $videoId): array
    {
        $response = $this->callInnerTubePlayer($videoId);

        // 動画の再生可能状態をチェック
        $playabilityStatus = $response['playabilityStatus']['status'] ?? null;
        if ($playabilityStatus !== 'OK') {
            $reason = $response['playabilityStatus']['reason'] ?? '不明なエラー';
            throw new Exception("動画を取得できません: {$reason}");
        }

        $captionTracks = $response['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? [];

        return array_map(function ($track) {
            return [
                'languageCode' => $track['languageCode'] ?? '',
                'name' => $track['name']['simpleText'] ?? '',
                'kind' => $track['kind'] ?? '',
                'baseUrl' => $track['baseUrl'] ?? '',
                'isTranslatable' => $track['isTranslatable'] ?? false,
            ];
        }, $captionTracks);
    }

    /**
     * 動画IDと言語コードから字幕テキストを取得
     *
     * @param  string  $videoId  YouTube動画ID
     * @param  string  $languageCode  言語コード（例: 'ja', 'en'）。デフォルト: 'ja'
     * @param  bool  $preferManual  手動字幕を優先するか。デフォルト: true
     * @return array 字幕エントリ配列。各要素: {start, duration, text}
     *
     * @throws Exception 字幕が見つからない場合やAPI通信エラー時
     */
    public function getSubtitles(string $videoId, string $languageCode = 'ja', bool $preferManual = true): array
    {
        $tracks = $this->getCaptionTracks($videoId);

        if (empty($tracks)) {
            throw new Exception('この動画には字幕がありません');
        }

        // 指定言語のトラックを検索
        $track = $this->findBestTrack($tracks, $languageCode, $preferManual);

        if (! $track) {
            $availableLanguages = implode(', ', array_unique(array_column($tracks, 'languageCode')));
            throw new Exception("言語「{$languageCode}」の字幕が見つかりません。利用可能な言語: {$availableLanguages}");
        }

        return $this->fetchSubtitleXml($track['baseUrl']);
    }

    /**
     * 最適な字幕トラックを選択
     *
     * @param  array  $tracks  字幕トラック一覧
     * @param  string  $languageCode  言語コード
     * @param  bool  $preferManual  手動字幕優先フラグ
     * @return array|null 選択されたトラック、見つからない場合null
     */
    private function findBestTrack(array $tracks, string $languageCode, bool $preferManual): ?array
    {
        $manualTrack = null;
        $autoTrack = null;

        foreach ($tracks as $track) {
            if ($track['languageCode'] !== $languageCode) {
                continue;
            }

            if ($track['kind'] === 'asr') {
                $autoTrack = $track;
            } else {
                $manualTrack = $track;
            }
        }

        if ($preferManual) {
            return $manualTrack ?? $autoTrack;
        }

        return $autoTrack ?? $manualTrack;
    }

    /**
     * 字幕URLのホストがYouTubeのドメインであるかを検証
     *
     * @param  string  $url  検証対象URL
     * @return bool 許可されたドメインの場合true
     */
    private function isAllowedSubtitleUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null) {
            return false;
        }

        foreach (self::ALLOWED_SUBTITLE_HOSTS as $allowed) {
            if (str_ends_with($host, $allowed) || $host === ltrim($allowed, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * InnerTube Player APIを呼び出し
     *
     * @param  string  $videoId  動画ID
     * @return array レスポンスJSON
     *
     * @throws Exception API通信エラー時
     */
    private function callInnerTubePlayer(string $videoId): array
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ])
                ->timeout(self::HTTP_TIMEOUT)
                ->post(self::INNERTUBE_PLAYER_URL, [
                    'context' => [
                        'client' => [
                            'clientName' => 'WEB',
                            'clientVersion' => self::CLIENT_VERSION,
                        ],
                    ],
                    'videoId' => $videoId,
                ]);
        } catch (Exception $e) {
            Log::error('YouTubeSubtitleService: InnerTube API通信エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('YouTube字幕APIへの通信に失敗しました');
        }

        if (! $response->successful()) {
            throw new Exception("YouTube字幕APIへの通信に失敗しました（HTTP {$response->status()}）");
        }

        return $response->json();
    }

    /**
     * 字幕XMLを取得してパース
     *
     * @param  string  $url  字幕XML URL（baseUrl）
     * @return array 字幕エントリ配列。各要素: {start, duration, text}
     *
     * @throws Exception URL検証エラー、XML取得・パースエラー時
     */
    private function fetchSubtitleXml(string $url): array
    {
        // SSRF防止: 許可されたドメインのみアクセスを許可
        if (! $this->isAllowedSubtitleUrl($url)) {
            Log::warning('YouTubeSubtitleService: 許可されていない字幕URLへのアクセスを拒否', [
                'url' => $url,
            ]);
            throw new Exception('字幕URLが不正です');
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
            ])
                ->timeout(self::HTTP_TIMEOUT)
                ->get($url);
        } catch (Exception $e) {
            Log::error('YouTubeSubtitleService: 字幕XML取得エラー', [
                'error' => $e->getMessage(),
            ]);
            throw new Exception('字幕データの取得に失敗しました');
        }

        if (! $response->successful()) {
            throw new Exception("字幕データの取得に失敗しました（HTTP {$response->status()}）");
        }

        return $this->parseSubtitleXml($response->body());
    }

    /**
     * 字幕XMLをパース
     *
     * @param  string  $xmlContent  XML文字列
     * @return array 字幕エントリ配列
     *
     * @throws Exception XMLパースエラー時
     */
    private function parseSubtitleXml(string $xmlContent): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            // XXE防止: 外部エンティティの読み込みを無効化
            $xml = simplexml_load_string($xmlContent, \SimpleXMLElement::class, LIBXML_NONET);

            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $errorMsg = ! empty($errors) ? $errors[0]->message : '不明なXMLエラー';
                throw new Exception("字幕XMLのパースに失敗しました: {$errorMsg}");
            }

            $subtitles = [];
            foreach ($xml->text as $entry) {
                $subtitles[] = [
                    'start' => (float) ($entry['start'] ?? 0),
                    'duration' => (float) ($entry['dur'] ?? 0),
                    'text' => html_entity_decode((string) $entry, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }

            return $subtitles;
        } finally {
            libxml_use_internal_errors($previousLibxmlState);
        }
    }
}
