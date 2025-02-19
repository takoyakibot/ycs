<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //ts_itemsにcomment_idを追加
        Schema::table('ts_items', function (Blueprint $table) {
            $table->unsignedBigInteger('comment_id')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //comment_idを削除
        Schema::table('ts_items', function (Blueprint $table) {
            $table->dropColumn('comment_id');
        });
    }
};
