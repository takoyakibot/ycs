<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $count = DB::table('timestamp_song_mappings')
            ->where('is_manual', true)
            ->where('status', 'pending')
            ->update([
                'status' => 'linked',
                'updated_at' => now(),
            ]);

        Log::info('[Migration] Fixed mapping status inconsistency', [
            'updated_count' => $count,
        ]);
    }

    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for status inconsistency fix');
    }
};
