<?php

namespace App\Http\Controllers;

use App\Helpers\SupplementStripper;
use App\Services\TimestampDecompositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TimestampDecompositionController extends Controller
{
    public function __construct(
        private TimestampDecompositionService $service
    ) {}

    /**
     * 分解・選別画面を表示
     */
    public function index(): View
    {
        return view('songs.decompose');
    }

    /**
     * 自動判定されたアイテムの一覧を表示
     *
     * スキャン時の完全一致による自動紐付けで何が紐付いたのかを確認するための画面。
     * 修正はタイムスタンプ正規化画面で行うため、この画面は参照のみ。
     *
     * 紐付け状態は timestamp_decompositions.song_id を根拠にしている。
     * 正規化画面の解除・付け替えは timestamp_song_mappings しか更新しないため、
     * そこで直した内容はこの画面に反映されない（追随する経路は無い。Issue #660）。
     */
    public function linked(Request $request): View
    {
        $filter = $request->query('filter');
        $filter = in_array($filter, ['linked', 'unlinked', 'empty_artist'], true) ? $filter : null;

        return view('songs.decompose-linked', [
            'decompositions' => $this->service->getAutoMatchedList($filter),
            'filter' => $filter,
        ]);
    }

    /**
     * 次の未処理タイムスタンプを取得
     */
    public function next(): JsonResponse
    {
        $item = $this->service->getNextPending();

        if (! $item) {
            return response()->json([
                'item' => null,
                'message' => '処理待ちのアイテムがありません',
            ]);
        }

        $parts = $item->parts ?? [];

        return response()->json([
            'item' => [
                'id' => $item->id,
                'original_text' => $item->original_text,
                'parts' => $parts,
                // 補足を除去した候補。パーツと同じ並び・同じ要素数を保つ
                'cleaned_parts' => SupplementStripper::stripParts($parts),
                'separator_count' => $item->separator_count,
                'title_part_index' => $item->title_part_index,
                'artist_part_index' => $item->artist_part_index,
                'confidence' => $item->confidence,
            ],
        ]);
    }

    /**
     * 選別結果を保存
     */
    public function select(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|string',
        ]);

        $decompositionModel = \App\Models\TimestampDecomposition::findOrFail($request->input('id'));
        $maxIndex = count($decompositionModel->parts) - 1;

        $validated = $request->validate([
            'id' => 'required|string',
            // 複数選択対応：配列で受け取る
            'title_indices' => ['nullable', 'array'],
            'title_indices.*' => ['integer', 'min:0', 'max:'.$maxIndex],
            'artist_indices' => ['nullable', 'array'],
            'artist_indices.*' => ['integer', 'min:0', 'max:'.$maxIndex],
            // 補足除去候補の確定・画面上での微調整で送られる確定値
            'title' => ['nullable', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'link_to_song' => 'boolean',
        ]);

        // 送られてきたキーだけを上書き対象にする（未送信と空文字を区別する）
        $overrides = [];
        foreach (['title', 'artist'] as $key) {
            if ($request->has($key)) {
                $overrides[$key] = $validated[$key] ?? '';
            }
        }

        // トランザクションで囲んでエラー時にロールバックする
        $data = DB::transaction(function () use ($validated, $request, $overrides) {
            $result = $this->service->saveSelection(
                $validated['id'],
                $validated['title_indices'] ?? [],
                $validated['artist_indices'] ?? [],
                $overrides
            );

            $decomposition = $result['decomposition'];

            // 楽曲マスタへの紐付けも同時に行う場合
            $song = null;
            if ($request->boolean('link_to_song', true) && $decomposition->derived_title) {
                $song = $this->service->linkToSong($decomposition);
            }

            return [
                'decomposition' => $decomposition,
                'song' => $song,
            ];
        });

        return response()->json([
            'success' => true,
            'decomposition' => [
                'id' => $data['decomposition']->id,
                'derived_title' => $data['decomposition']->derived_title,
                'derived_artist' => $data['decomposition']->derived_artist,
                'status' => $data['decomposition']->status,
            ],
            'song' => $data['song'] ? [
                'id' => $data['song']->id,
                'title' => $data['song']->title,
                'artist' => $data['song']->artist,
            ] : null,
        ]);
    }

    /**
     * 全体を楽曲名として保存（分割しない）
     */
    public function saveAsWholeTitle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'link_to_song' => 'boolean',
        ]);

        // トランザクションで囲んでエラー時にロールバックする
        $data = DB::transaction(function () use ($validated, $request) {
            $result = $this->service->saveAsWholeTitle($validated['id']);
            $decomposition = $result['decomposition'];

            // 楽曲マスタへの紐付けも同時に行う場合
            $song = null;
            if ($request->boolean('link_to_song', true) && $decomposition->derived_title) {
                $song = $this->service->linkToSong($decomposition);
            }

            return [
                'decomposition' => $decomposition,
                'song' => $song,
            ];
        });

        return response()->json([
            'success' => true,
            'decomposition' => [
                'id' => $data['decomposition']->id,
                'derived_title' => $data['decomposition']->derived_title,
                'derived_artist' => $data['decomposition']->derived_artist,
                'status' => $data['decomposition']->status,
            ],
            'song' => $data['song'] ? [
                'id' => $data['song']->id,
                'title' => $data['song']->title,
                'artist' => $data['song']->artist,
            ] : null,
        ]);
    }

    /**
     * スキップ
     */
    public function skip(string $id): JsonResponse
    {
        $this->service->markAsSkipped($id);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * 操作を取り消し（undo）
     */
    public function undo(string $id): JsonResponse
    {
        $result = $this->service->undoAction($id);

        return response()->json([
            'success' => true,
            'undone_count' => $result['undone_count'],
        ]);
    }

    /**
     * 統計情報取得
     */
    public function statistics(): JsonResponse
    {
        return response()->json($this->service->getStatistics());
    }

    /**
     * 初回スキャン実行
     *
     * スキャン時にマスタと完全一致するものはその場で楽曲マスタへの紐付けまで行う
     * （TimestampDecompositionService::createDecomposition() 参照）。
     */
    public function scan(): JsonResponse
    {
        $count = $this->service->scanAndDecompose();

        return response()->json([
            'success' => true,
            'scanned_count' => $count,
            'statistics' => $this->service->getStatistics(),
        ]);
    }
}
