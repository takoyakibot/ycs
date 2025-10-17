<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\GetArchiveService;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    protected $getArchiveService;

    public function __construct(GetArchiveService $getArchiveService)
    {
        $this->getArchiveService = $getArchiveService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // チャンネル情報を取得して表示
        $page = config('utils.page');
        $channels = Channel::paginate($page)->toArray();

        return view('channels.index', compact('channels'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // チャンネル情報を取得して表示
        $channel = Channel::where('handle', $id)->firstOrFail();

        return view('channels.show', compact('channel'));
    }

    public function fetchArchives(string $id, Request $request)
    {
        $archives = $this->getArchiveService->getArchives(
            $id,
            (string) $request->query('baramutsu', ''),
            (string) $request->query('visible', ''),
            (string) $request->query('ts', '')
        )
            ->appends($request->query());

        return response()->json($archives);
    }
}
