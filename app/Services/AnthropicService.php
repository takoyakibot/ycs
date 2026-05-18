<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Anthropic Messages API の薄いクライアント。
 *
 * - APIキーは config/services.php の anthropic.api_key を参照
 * - Messages API（/v1/messages）のみ対応
 * - JSON 形式での回答を期待する用途を想定
 */
class AnthropicService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    private string $apiKey;

    private string $defaultModel;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.api_key', '');
        $this->defaultModel = (string) config('services.anthropic.highlight_model', 'claude-haiku-4-5-20251001');
        $this->timeout = (int) config('services.anthropic.timeout', 60);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Messages API を呼び出してテキスト応答を取得する。
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{model?: string, max_tokens?: int, system?: string, temperature?: float}  $options
     * @return string アシスタント応答のテキスト連結
     */
    public function complete(array $messages, array $options = []): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Anthropic APIキーが設定されていません');
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => $messages,
        ];

        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::error('Anthropic API接続失敗', ['error' => $e->getMessage()]);
            throw new RuntimeException('Anthropic API への接続に失敗しました', 0, $e);
        }

        if (! $response->successful()) {
            Log::error('Anthropic API エラー応答', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            throw new RuntimeException("Anthropic API がエラーを返しました (HTTP {$response->status()})");
        }

        $json = $response->json();
        $content = $json['content'] ?? [];
        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return trim($text);
    }
}
