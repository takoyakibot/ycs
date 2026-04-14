<?php

namespace App\Http\Controllers;

use App\Helpers\TextNormalizer;
use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Models\Channel;
use App\Models\ChannelExcludedWord;
use App\Models\ChannelStripPattern;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\CoverSongTitleExtractorService;
use App\Services\VideoAnalyzerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ManageSettingsApiController extends Controller
{
    use ManageAccessControl;

    protected $coverSongTitleExtractorService;

    protected $videoAnalyzerService;

    public function __construct(
        CoverSongTitleExtractorService $coverSongTitleExtractorService,
        VideoAnalyzerService $videoAnalyzerService
    ) {
        $this->coverSongTitleExtractorService = $coverSongTitleExtractorService;
        $this->videoAnalyzerService = $videoAnalyzerService;
    }

    /**
     * 除外ワード一覧を取得
     */
    public function fetchExcludedWords(string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $excludedWords = $channel->excludedWords()
            ->orderByRaw('LENGTH(word) DESC')
            ->orderBy('word')
            ->get();

        return response()->json($excludedWords);
    }

    /**
     * 除外ワードを追加
     */
    public function addExcludedWord(Request $request, string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $validated = $request->validate([
            'word' => 'required|string|max:255',
        ]);

        // 重複チェック
        $exists = ChannelExcludedWord::where('channel_id', $channel->channel_id)
            ->where('word', $validated['word'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => '既に登録されています'], 422);
        }

        $excludedWord = ChannelExcludedWord::create([
            'channel_id' => $channel->channel_id,
            'word' => $validated['word'],
        ]);

        return response()->json($excludedWord, 201);
    }

    /**
     * 除外ワードを削除
     */
    public function deleteExcludedWord(string $id, string $wordId)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $excludedWord = ChannelExcludedWord::where('id', $wordId)
            ->where('channel_id', $channel->channel_id)
            ->firstOrFail();

        $excludedWord->delete();

        return response()->json(['message' => '削除しました']);
    }

    /**
     * 除去パターン一覧を取得
     */
    public function fetchStripPatterns(string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $patterns = $channel->stripPatterns()->orderBy('pattern')->get();

        return response()->json($patterns);
    }

    /**
     * 除去パターンを追加
     */
    public function addStripPattern(Request $request, string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $validated = $request->validate([
            'pattern' => ['required', 'string', 'max:255', 'regex:/\S/'],
        ]);

        try {
            $pattern = ChannelStripPattern::create([
                'channel_id' => $channel->channel_id,
                'pattern' => $validated['pattern'],
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json(['message' => '既に登録されています'], 422);
        }

        return response()->json($pattern, 201);
    }

    /**
     * 除去パターンを削除
     */
    public function deleteStripPattern(string $id, string $patternId)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $pattern = ChannelStripPattern::where('id', $patternId)
            ->where('channel_id', $channel->channel_id)
            ->firstOrFail();

        $pattern->delete();

        return response()->json(['message' => '削除しました']);
    }

    /**
     * 除去パターンをすべてのts_itemsに再適用（非同期ジョブとして実行）
     */
    public function reapplyStripPatterns(string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $stripPatterns = $channel->stripPatterns()->pluck('pattern')->toArray();

        \App\Jobs\ReapplyStripPatternsJob::dispatch($channel, $stripPatterns);

        return response()->json([
            'message' => '除去パターンの再適用をバックグラウンドで開始しました。完了までしばらくお待ちください。',
        ]);
    }

    /**
     * カバー曲抽出プレビュー
     * 現在の除外ワード設定で、カバー曲がどのように抽出されるかをプレビュー
     */
    public function previewCoverSongs(string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        // カバー曲動画を取得
        $archives = Archive::where('channel_id', $channel->channel_id)
            ->get()
            ->filter(fn ($archive) => $this->videoAnalyzerService->isCoverSong(
                mb_convert_encoding($archive->title ?? '', 'UTF-8', 'UTF-8')
            ));

        // 各動画について、抽出結果をプレビュー
        $previews = $archives->map(function ($archive) use ($channel) {
            // 不正なUTF-8文字を除去
            $originalTitle = mb_convert_encoding($archive->title ?? '', 'UTF-8', 'UTF-8');
            $extractedText = $this->coverSongTitleExtractorService->extract($originalTitle, $channel->channel_id);
            $extractedText = mb_convert_encoding($extractedText, 'UTF-8', 'UTF-8');
            $normalizedText = TextNormalizer::normalize($extractedText);

            // 現在のマッピング状態を取得
            $mapping = TimestampSongMapping::where('normalized_text', $normalizedText)
                ->with('song')
                ->first();

            return [
                'video_id' => $archive->video_id,
                'original_title' => $originalTitle,
                'extracted_text' => $extractedText,
                'normalized_text' => $normalizedText,
                'mapping' => MappingStatusHelper::get($mapping),
            ];
        })->values();

        return response()->json($previews, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * カバー曲紐付け再処理
     * チャンネルのカバー曲ts_itemsを再生成し、自動紐付けをリセット
     */
    public function reprocessCoverSongs(string $id)
    {
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $processedCount = 0;
        $currentVideoId = null;

        try {
            DB::transaction(function () use ($channel, &$processedCount, &$currentVideoId) {
                // 1. チャンネルのカバー曲ts_items（type='3'）を取得
                $coverTsItems = TsItem::whereHas('archive', function ($q) use ($channel) {
                    $q->where('channel_id', $channel->channel_id);
                })
                    ->where('type', '3')
                    ->with('archive')
                    ->get();

                \Log::info('reprocessCoverSongs: 処理開始', [
                    'channel_id' => $channel->channel_id,
                    'cover_count' => $coverTsItems->count(),
                ]);

                // 2. 古い normalized_text を収集（自動紐付けリセット用）
                $oldNormalizedTexts = $coverTsItems->pluck('normalized_text')->unique()->toArray();

                // 3. 各ts_itemのtextを再抽出
                foreach ($coverTsItems as $tsItem) {
                    $archive = $tsItem->archive;
                    if (! $archive) {
                        continue;
                    }

                    $currentVideoId = $archive->video_id;

                    // 不正なUTF-8文字を除去してからタイトルを処理
                    $sanitizedTitle = mb_convert_encoding($archive->title ?? '', 'UTF-8', 'UTF-8');
                    $newText = $this->coverSongTitleExtractorService->extract($sanitizedTitle, $channel->channel_id);
                    // 抽出結果もサニタイズ
                    $newText = mb_convert_encoding($newText, 'UTF-8', 'UTF-8');
                    $newNormalizedText = TextNormalizer::normalize($newText);

                    // 変更がある場合のみ更新
                    if ($tsItem->text !== $newText || $tsItem->normalized_text !== $newNormalizedText) {
                        $tsItem->text = $newText;
                        $tsItem->normalized_text = $newNormalizedText;
                        $tsItem->save();
                        $processedCount++;
                    }
                }

                // 4. 自動紐付け（is_manual=false）をリセット
                TimestampSongMapping::whereIn('normalized_text', $oldNormalizedTexts)
                    ->where('is_manual', false)
                    ->delete();

                \Log::info('reprocessCoverSongs: 処理完了', [
                    'processed_count' => $processedCount,
                ]);
            });

            return response()->json([
                'message' => "カバー曲紐付けを再処理しました（{$processedCount}件更新）",
                'processed_count' => $processedCount,
            ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Exception $e) {
            \Log::error('reprocessCoverSongs: エラー発生', [
                'channel_id' => $channel->channel_id,
                'current_video_id' => $currentVideoId,
                'error_class' => get_class($e),
                'error_message' => mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8'),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => '処理中にエラーが発生しました。ログを確認してください。',
                'error' => true,
            ], 500, [], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
}
