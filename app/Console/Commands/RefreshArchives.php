<?php
namespace App\Console\Commands;

use App\Services\RefreshArchiveService;
use Exception;
use Illuminate\Console\Command;

class RefreshArchives extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-archives {--user-id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'daily archives update.';

    /**
     * Execute the console command.
     */
    public function handle(RefreshArchiveService $service)
    {
        try {
            // 受け取ったIDで偽装ログイン
            $userId = $this->option('user-id');
            $service->cliLogin($userId);

            // 一番古いアーカイブを取得し、そのチャンネルの情報を再作成する
            $channel = $service->getOldestUpdatedChannel();
            echo now() . ' 更新対象：' . $channel->title;
            $service->refreshArchives($channel);

            echo " 更新成功\n";
            return 0;
        } catch (Exception $e) {
            echo " 更新失敗: " . $e->getMessage() . "\n";
            return 1;
        }

    }
}
