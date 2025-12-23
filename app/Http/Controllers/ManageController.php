<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Helpers\TextNormalizer;
use App\Http\Requests\EditTimestampsRequest;
use App\Http\Requests\FetchCommentsRequest;
use App\Http\Requests\ToggleDisplayRequest;
use App\Jobs\RefreshChannelArchivesJob;
use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\ChannelExcludedWord;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\CoverSongTitleExtractorService;
use App\Services\GetArchiveService;
use App\Services\ImageService;
use App\Services\RefreshArchiveService;
use App\Services\VideoAnalyzerService;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    protected $youtubeService;

    protected $imageService;

    protected $refreshArchiveService;

    protected $getArchiveService;

    protected $coverSongTitleExtractorService;

    protected $videoAnalyzerService;

    public function __construct(
        YouTubeService $youtubeService,
        ImageService $imageService,
        RefreshArchiveService $refreshArchiveService,
        GetArchiveService $getArchiveService,
        CoverSongTitleExtractorService $coverSongTitleExtractorService,
        VideoAnalyzerService $videoAnalyzerService
    ) {
        $this->youtubeService = $youtubeService;
        $this->imageService = $imageService;
        $this->refreshArchiveService = $refreshArchiveService;
        $this->getArchiveService = $getArchiveService;
        $this->coverSongTitleExtractorService = $coverSongTitleExtractorService;
        $this->videoAnalyzerService = $videoAnalyzerService;
    }

    /**
     * ユーザーがチャンネルにアクセスできるか判定
     * スーパー管理者は全チャンネルにアクセス可能
     */
    private function canAccessChannel(Channel $channel): bool
    {
        $user = Auth::user();

        return $user->isSuperAdmin() || $channel->user_id === $user->id;
    }

    public function index()
    {
        // APIキーが登録済みかチェック
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        return view('manage.index', compact('api_key_flg'));
    }

    public function show($id)
    {
        // APIキー未登録の場合はチャンネル管理に戻す（スーパー管理者は除く）
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';
        // ハンドルが存在しない場合はチャンネル管理に戻す
        $channel = Channel::where('handle', $id)->first();
        if ((! $api_key_flg && ! $user->isSuperAdmin()) || ! $channel) {
            return redirect()->route('manage.index');
        }

        // アクセス権チェック（所有者またはスーパー管理者）
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.show', compact('channel', 'crypt_handle'));
    }

    public function fetchChannel(Request $request)
    {
        $user = Auth::user();

        // スーパー管理者は全チャンネルを取得、それ以外は自分のチャンネルのみ
        if ($user->isSuperAdmin()) {
            $channels = Channel::with('user:id,name')->get();
        } else {
            $channels = $user->channels()->get();
        }

        return response()->json($channels);
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'handle' => 'required|string|regex:/^[a-zA-Z0-9_]+$/|unique:channels,handle',
        ]);

        try {
            $channel = $this->youtubeService->getChannelByHandle($request->handle);
        } catch (Exception $e) {
            Log::error('YouTube API error in addChannel', [
                'handle' => $request->handle,
                'error' => $e->getMessage(),
            ]);
            // サービス層からのユーザーフレンドリーなメッセージをそのまま使用
            throw $e;
        }
        if (! $channel || ! isset($channel['title']) || ! $channel['title']) {
            throw new NotFoundException('チャンネルが存在しません');
        }

        try {
            // DB::transaction + unique constraint で安全に処理
            DB::transaction(function () use ($request, $channel) {
                Channel::create([
                    'handle' => $request->handle,
                    'channel_id' => $channel['channel_id'],
                    'title' => $channel['title'],
                    'thumbnail' => $channel['thumbnail'],
                    'user_id' => Auth::id(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // unique constraint 違反を検出
            if ($e->getCode() === '23000') {
                throw new Exception('このチャンネルは既に他のユーザーによって登録されています');
            }
            throw $e;
        }

        return response()->json('チャンネルを登録しました');
    }

    public function fetchArchives(string $id, Request $request)
    {
        // アクセス権チェック（所有者またはスーパー管理者）
        $handle = Crypt::decryptString($id);
        $channel = Channel::where('handle', $handle)->firstOrFail();
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $archives = $this->getArchiveService->getArchivesForManage(
            $id,
            (string) $request->query('search', ''),
            (string) $request->query('visible', ''),
            (string) $request->query('ts', '')
        )
            ->appends($request->query());

        // ts_itemsのマッピング状態を付加
        $this->appendMappingStatus($archives);

        return response()->json($archives);
    }

    /**
     * アーカイブ一覧のts_itemsにマッピング状態を付加
     */
    private function appendMappingStatus($archives): void
    {
        // 全ts_itemsのnormalized_textを収集
        $normalizedTexts = collect();
        foreach ($archives->items() as $archive) {
            foreach ($archive->tsItems as $tsItem) {
                if ($tsItem->normalized_text) {
                    $normalizedTexts->push($tsItem->normalized_text);
                }
            }
        }

        if ($normalizedTexts->isEmpty()) {
            return;
        }

        // マッピング情報を一括取得
        $mappings = TimestampSongMapping::with('song')
            ->whereIn('normalized_text', $normalizedTexts->unique())
            ->get()
            ->keyBy('normalized_text');

        // 各ts_itemにマッピング情報を付加
        foreach ($archives->items() as $archive) {
            foreach ($archive->tsItems as $tsItem) {
                $mapping = $mappings->get($tsItem->normalized_text);
                $tsItem->mapping_status = $this->getMappingStatus($mapping);
            }
        }
    }

    /**
     * マッピング状態を判定して返す
     *
     * @return array{status: string, label: string, song_info: string|null}
     */
    private function getMappingStatus($mapping): array
    {
        if (! $mapping) {
            return [
                'status' => 'unlinked',
                'label' => '未紐付',
                'song_info' => null,
            ];
        }

        if ($mapping->is_not_song) {
            return [
                'status' => 'not_song',
                'label' => '楽曲ではない',
                'song_info' => null,
            ];
        }

        if ($mapping->song) {
            $prefix = $mapping->is_manual ? '' : '[自動] ';
            $songInfo = $prefix.$mapping->song->title.' / '.$mapping->song->artist;

            return [
                'status' => $mapping->is_manual ? 'linked' : 'auto_linked',
                'label' => '紐付済',
                'song_info' => $songInfo,
            ];
        }

        return [
            'status' => 'unlinked',
            'label' => '未紐付',
            'song_info' => null,
        ];
    }

    public function addArchives(Request $request)
    {
        $request->validate([
            'handle' => 'required|string',
        ]);
        $handle = Crypt::decryptString($request->handle);

        $channel = Channel::where('handle', $handle)->firstOrFail();

        // アクセス権チェック（所有者またはスーパー管理者）
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        // キュー設定に応じて同期/非同期で実行
        // sync: 同期実行（従来動作）, database/redis等: 非同期実行
        $queueConnection = config('queue.default');

        if ($queueConnection === 'sync') {
            // 同期実行（従来動作）
            $this->refreshArchiveService->refreshArchives($channel);

            return response()->json([
                'message' => 'アーカイブを登録しました',
                'async' => false,
            ]);
        }

        // 非同期実行
        RefreshChannelArchivesJob::dispatch($channel, Auth::id());

        return response()->json([
            'message' => 'アーカイブの更新処理をキューに登録しました。完了までしばらくお待ちください。',
            'async' => true,
        ]);
    }

    // 動画の表示非表示切り替え
    // comment_id = null の場合に動画と判断する
    public function toggleDisplay(ToggleDisplayRequest $request)
    {
        $validated = $request->validated();

        $newDisplay = DB::transaction(function () use ($validated) {
            $new_display = ($validated['is_display'] === '1') ? '0' : '1';
            // archivesとchange_listを更新
            // Archive::where('id', $validated['id'])->update(['is_display' => $new_display]);
            // return response()->json($new_display);
            $archive = Archive::findOrFail($validated['id']);
            $archive->is_display = $new_display;
            $archive->save();
            ChangeList::updateOrCreate(
                [
                    'channel_id' => $archive->channel_id,
                    'video_id' => $archive->video_id,
                    'comment_id' => null,
                ],
                ['is_display' => $new_display]
            );

            return (string) $new_display;
        });

        return response()->json($newDisplay);
    }

    public function fetchComments(FetchCommentsRequest $request)
    {
        $validated = $request->validated();
        $videoId = Archive::findOrFail($validated['id'], ['video_id'])->video_id;
        DB::transaction(function () use ($videoId) {
            // コメント欄のタイムスタンプのみ再取得
            // 概要欄のタイムスタンプはrefreshArchives()でアーカイブ更新時に自動取得される
            $this->refreshArchiveService->refreshTimeStampsFromComments($videoId);
        });
        $ts_items = TsItem::where('video_id', $videoId)->orderBy('comment_id')->get();

        return response()->json($ts_items);
    }

    /**
     * 画面からのリクエストでタイムスタンプの表示非表示を編集する
     * ひとつの動画に対してのタイムスタンプの表示非表示を、コメント単位で設定する（タイムスタンプをコメント単位にまとめるのは、画面側で実施している）
     * デフォルト状態から変わっていない内容は登録しないとか考えたかったけど無駄に複雑になりそうなのでやめよう
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function editTimestamps(EditTimestampsRequest $request)
    {
        $validatedData = $request->validated();
        if (empty($validatedData)) {
            throw new \InvalidArgumentException('タイムスタンプデータが空です');
        }
        DB::transaction(function () use ($validatedData) {
            // リクエストで渡されたタイムスタンプIDに紐づくarchiveを取得
            $firstItemId = $validatedData[0]['id'];
            $tsItem = TsItem::where('id', $firstItemId)
                ->with(['archive'])->first();
            if (! $tsItem) {
                throw new NotFoundException('tsItem is not found');
            }

            // 取得したarchiveからchannelIdとvideoIdを取得
            $channelId = $tsItem->archive->channel_id;
            $videoId = $tsItem->video_id;
            if (! $channelId || ! $videoId) {
                throw new NotFoundException('channelId or videoId is not found');
            }

            // 変更リストの削除 videoIdが一致し、commentIdがnull以外のものを削除
            // タイムスタンプの編集なので動画（commentId=null）は除き、洗替のために削除する
            // ちなみにcommentId=videoIdのレコードは概要欄のもの
            ChangeList::where('video_id', $videoId)
                ->whereNotNull('comment_id')
                ->delete();

            // ts_item単位の設定保存時に必要なts_text, ts_numを取得するため、
            // 対象のTsItemをあらかじめ全件ロード
            $tsItemIds = collect($validatedData)->pluck('id')->toArray();
            $tsItemsById = TsItem::whereIn('id', $tsItemIds)
                ->get()
                ->keyBy('id');

            // is_display=1 と is_display=0 でグループ化してバッチ更新
            $displayItemIds = collect($validatedData)->where('is_display', '1')->pluck('id')->toArray();
            $hideItemIds = collect($validatedData)->where('is_display', '0')->pluck('id')->toArray();

            if (! empty($displayItemIds)) {
                TsItem::whereIn('id', $displayItemIds)->update(['is_display' => '1']);
            }
            if (! empty($hideItemIds)) {
                TsItem::whereIn('id', $hideItemIds)->update(['is_display' => '0']);
            }

            // コメントごとにグループ化
            $groupedByComment = collect($validatedData)->groupBy('comment_id');

            foreach ($groupedByComment as $commentId => $items) {
                // コメント内の全タイムスタンプのis_displayを取得
                $displayValues = $items->pluck('is_display')->unique();

                if ($displayValues->count() === 1) {
                    // 全て同じ設定→コメント単位でchange_listを作成
                    ChangeList::create([
                        'channel_id' => $channelId,
                        'video_id' => $videoId,
                        'comment_id' => $commentId,
                        'ts_item_id' => null,
                        'is_display' => $displayValues->first(),
                    ]);
                } else {
                    // 異なる設定がある→タイムスタンプ単位でchange_listを作成
                    foreach ($items as $item) {
                        $tsItemModel = $tsItemsById->get($item['id']);
                        ChangeList::create([
                            'channel_id' => $channelId,
                            'video_id' => $videoId,
                            'comment_id' => $commentId,
                            'ts_item_id' => $item['id'],
                            'is_display' => $item['is_display'],
                            // アーカイブ更新時にts_item_idで照合できるようにts_text, ts_numを保存
                            'ts_text' => $tsItemModel?->ts_text,
                            'ts_num' => $tsItemModel?->ts_num,
                        ]);
                    }
                }
            }
        });

        return response()->json(['message' => 'タイムスタンプの編集が完了しました']);
    }

    /**
     * チャンネル設定画面を表示
     */
    public function settings(string $id)
    {
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        $channel = Channel::where('handle', $id)->first();
        if ((! $api_key_flg && ! $user->isSuperAdmin()) || ! $channel) {
            return redirect()->route('manage.index');
        }

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.settings', compact('channel', 'crypt_handle'));
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

        $excludedWords = $channel->excludedWords()->orderBy('word')->get();

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
                'mapping' => $mapping ? $this->getMappingStatus($mapping) : [
                    'status' => 'unlinked',
                    'label' => '未紐付',
                    'song_info' => null,
                ],
            ];
        })->values();

        return response()->json($previews);
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

        DB::transaction(function () use ($channel, &$processedCount) {
            // 1. チャンネルのカバー曲ts_items（type='3'）を取得
            $coverTsItems = TsItem::whereHas('archive', function ($q) use ($channel) {
                $q->where('channel_id', $channel->channel_id);
            })
                ->where('type', '3')
                ->with('archive')
                ->get();

            // 2. 古い normalized_text を収集（自動紐付けリセット用）
            $oldNormalizedTexts = $coverTsItems->pluck('normalized_text')->unique()->toArray();

            // 3. 各ts_itemのtextを再抽出
            foreach ($coverTsItems as $tsItem) {
                $archive = $tsItem->archive;
                if (! $archive) {
                    continue;
                }

                // 不正なUTF-8文字を除去してからタイトルを処理
                $sanitizedTitle = mb_convert_encoding($archive->title ?? '', 'UTF-8', 'UTF-8');
                $newText = $this->coverSongTitleExtractorService->extract($sanitizedTitle, $channel->channel_id);
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
            // 新しい normalized_text に対応するマッピングがなければ、自動紐付けが再実行される
            // ここでは明示的に自動紐付けを削除（手動紐付けは保持）
            TimestampSongMapping::whereIn('normalized_text', $oldNormalizedTexts)
                ->where('is_manual', false)
                ->delete();
        });

        return response()->json([
            'message' => "カバー曲紐付けを再処理しました（{$processedCount}件更新）",
            'processed_count' => $processedCount,
        ]);
    }
}
