<?php

namespace App\Http\Controllers;

use App\Services\TimestampDecompositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json([
            'item' => [
                'id' => $item->id,
                'original_text' => $item->original_text,
                'parts' => $item->parts,
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
            'link_to_song' => 'boolean',
        ]);

        $result = $this->service->saveSelection(
            $validated['id'],
            $validated['title_indices'] ?? [],
            $validated['artist_indices'] ?? []
        );

        $decomposition = $result['decomposition'];
        $cascadedCount = $result['cascaded_count'];

        // 楽曲マスタへの紐付けも同時に行う場合
        $song = null;
        if ($request->boolean('link_to_song', true) && $decomposition->derived_title) {
            $song = $this->service->linkToSong($decomposition);
        }

        return response()->json([
            'success' => true,
            'decomposition' => [
                'id' => $decomposition->id,
                'derived_title' => $decomposition->derived_title,
                'derived_artist' => $decomposition->derived_artist,
                'status' => $decomposition->status,
            ],
            'song' => $song ? [
                'id' => $song->id,
                'title' => $song->title,
                'artist' => $song->artist,
            ] : null,
            'cascaded_count' => $cascadedCount,
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

        $result = $this->service->saveAsWholeTitle($validated['id']);
        $decomposition = $result['decomposition'];

        // 楽曲マスタへの紐付けも同時に行う場合
        $song = null;
        if ($request->boolean('link_to_song', true) && $decomposition->derived_title) {
            $song = $this->service->linkToSong($decomposition);
        }

        return response()->json([
            'success' => true,
            'decomposition' => [
                'id' => $decomposition->id,
                'derived_title' => $decomposition->derived_title,
                'derived_artist' => $decomposition->derived_artist,
                'status' => $decomposition->status,
            ],
            'song' => $song ? [
                'id' => $song->id,
                'title' => $song->title,
                'artist' => $song->artist,
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
     * 統計情報取得
     */
    public function statistics(): JsonResponse
    {
        return response()->json($this->service->getStatistics());
    }

    /**
     * 初回スキャン実行
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

    /**
     * 自動判定済みアイテムを一括で楽曲マスタに紐付け
     */
    public function bulkLink(): JsonResponse
    {
        $count = $this->service->bulkLinkAutoMatched();

        return response()->json([
            'success' => true,
            'linked_count' => $count,
            'statistics' => $this->service->getStatistics(),
        ]);
    }
}
