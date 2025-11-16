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

            if ($userId) {
                // 単一ユーザーのチャンネルを更新
                return $this->refreshUserChannels($service, $userId);
            } else {
                // 全ユーザーのチャンネルを順番に更新
                return $this->refreshAllUsersChannels($service);
            }
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
     * 全ユーザーのチャンネルを順番に更新
     */
    protected function refreshAllUsersChannels(RefreshArchiveService $service): int
    {
        $users = \App\Models\User::whereNotNull('api_key')->get();

        if ($users->isEmpty()) {
            $this->error('Error: No user with API key found.');

            return 1;
        }

        $this->info("Found {$users->count()} users with API keys");

        foreach ($users as $user) {
            $this->info("\n=== Processing user: {$user->name} (ID: {$user->id}) ===");

            $service->cliLogin($user->id);

            $channelCount = $service->getChannelCountForUser($user->id);
            $count = 0;

            $this->info("User has {$channelCount} channels");

            while ($count < 4000 && $channelCount > 0) {
                $channel = $service->getOldestUpdatedChannelForUser($user->id);
                if (! $channel) {
                    break;
                }

                echo now().' 更新対象：'.$channel->title;
                $count += $service->refreshArchives($channel);
                $channelCount--;
                echo " 更新成功\n";
            }

            $this->info("User {$user->name} completed: {$count} videos updated");
        }

        return 0;
    }
}
