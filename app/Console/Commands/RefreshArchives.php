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
            return $this->refreshUserChannels($service, $userId);
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
}
