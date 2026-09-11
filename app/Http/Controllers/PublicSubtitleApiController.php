<?php

namespace App\Http\Controllers;

use App\Services\SubtitleFingerprintService;
use App\Services\SubtitleMatchingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicSubtitleApiController extends Controller
{
    public function __construct(
        private SubtitleMatchingService $matchingService,
    ) {}

    /**
     * 公開字幕照合API
     *
     * クライアントから60秒窓のテキストを受け取り、サーバー側でトライグラムを生成して照合する。
     * 認証不要・読み取り専用・IPスロットル。
     */
    public function match(Request $request)
    {
        $validated = $request->validate([
            'subtitle_text' => ['required', 'string', 'max:10000'],
            'duration_sec' => ['sometimes', 'integer', 'in:60'],
        ]);

        $text = $validated['subtitle_text'];
        $durationSec = $validated['duration_sec'] ?? SubtitleFingerprintService::WINDOW_DURATION_SEC;

        $normalized = SubtitleFingerprintService::normalizeForFingerprint($text);
        $trigrams = SubtitleFingerprintService::generateTrigrams($normalized);

        if (count($trigrams) < SubtitleFingerprintService::MIN_TRIGRAM_COUNT) {
            return response()->json([
                'candidates' => [],
                'trigram_count' => count($trigrams),
                'message' => 'テキストが短すぎます（トライグラム不足）',
            ]);
        }

        try {
            $candidates = $this->matchingService->getCandidateSongsForTrigrams(
                $trigrams,
                $durationSec,
            );

            $filtered = array_map(fn ($c) => [
                'song_id' => $c['song_id'],
                'song_title' => $c['song_title'],
                'song_artist' => $c['song_artist'],
                'text' => $c['text'],
                'similarity' => $c['similarity'],
                'match_count' => $c['match_count'],
            ], $candidates);

            return response()->json([
                'candidates' => array_values($filtered),
                'trigram_count' => count($trigrams),
            ]);
        } catch (Exception $e) {
            Log::error('公開字幕照合エラー', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => '照合に失敗しました'], 500);
        }
    }
}
