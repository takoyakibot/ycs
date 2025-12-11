<?php

namespace App\Console\Commands;

use App\Services\AutoLinkService;
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
    protected $signature = 'app:refresh-archives
                            {--user-id= : 処理を実行するユーザーID}
                            {--auto-link : アーカイブ更新後に自動楽曲紐付けを実行}
                            {--auto-link-limit=100 : 自動紐付けの処理件数上限}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'daily archives update.';

    /**
     * Execute the console command.
     */
    public function handle(RefreshArchiveService $service, AutoLinkService $autoLinkService)
    {
        try {
            $userId = $this->option('user-id');

            // --user-id が指定されていない場合はエラー
            if (! $userId) {
                $this->error('Error: --user-id option is required.');
                $this->info('Available users with API keys:');

                $users = \App\Models\User::whereNotNull('api_key')->get(['id', 'name', 'email']);
                if ($users->isEmpty()) {
                    $this->warn('  No users with API keys found.');

                    return 1;
                }

                foreach ($users as $user) {
                    $this->line("  - ID: {$user->id}, Name: {$user->name}, Email: {$user->email}");
                }

                return 1;
            }

            // 単一ユーザーのチャンネルを更新
            $result = $this->refreshUserChannels($service, $userId);

            // --auto-link オプションが指定されている場合、自動楽曲紐付けを実行
            if ($result === 0 && $this->option('auto-link')) {
                $this->runAutoLink($autoLinkService);
            }

            return $result;
        } catch (Exception $e) {
            echo ' 更新失敗: '.$e->getMessage()."\n";

            return 1;
        }
    }

    /**
     * 単一ユーザーのチャンネルを更新
     */
    protected function refreshUserChannels(RefreshArchiveService $service, string $userId): int
    {
        $service->cliLogin($userId);

        $channelCount = $service->getChannelCountForUser((int) $userId);
        $count = 0;

        $this->info("User ID {$userId}: {$channelCount} channels to update");

        while ($count < 4000 && $channelCount > 0) {
            $channel = $service->getOldestUpdatedChannelForUser((int) $userId);
            if (! $channel) {
                break;
            }

            echo now().' 更新対象：'.$channel->title;
            $count += $service->refreshArchives($channel);
            $channelCount--;
            echo " 更新成功\n";
        }

        return 0;
    }

    /**
     * 自動楽曲紐付け処理を実行
     */
    protected function runAutoLink(AutoLinkService $autoLinkService): void
    {
        $limit = (int) $this->option('auto-link-limit');

        $this->newLine();
        $this->info('=== 自動楽曲紐付け処理 ===');
        $this->info(sprintf('処理件数上限: %d件', $limit));

        $startTime = microtime(true);

        $result = $autoLinkService->autoLinkUnlinkedTimestamps($limit, function ($message) {
            $this->line($message);
        });

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('=== 自動紐付け結果 ===');
        $this->table(
            ['項目', '件数'],
            [
                ['処理件数', $result['processed']],
                ['紐付け成功', $result['linked']],
                ['検索結果なし/エラー', $result['failed']],
                ['スキップ（類似曲あり）', $result['skipped']],
            ]
        );
        $this->info(sprintf('処理時間: %s秒', $duration));
    }
}
