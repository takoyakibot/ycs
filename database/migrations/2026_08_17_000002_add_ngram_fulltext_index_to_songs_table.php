<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 照合キーに対する n-gram 全文検索インデックスを追加する。
     *
     * 現在の照合は楽曲マスタの照合キーを全件メモリに載せた総当たりで行っている。
     * バッチ処理では十分な速度だが、画面から対話的に候補を出す経路では
     * リクエストごとに全件を読み込むことになるため、将来的に候補の絞り込みが必要になる。
     * その際に使うインデックスを先に用意しておく。
     *
     * インデックスを追加するだけで、検索経路はまだ切り替えていない。
     * 切り替えるかどうかは実データでの効果を見てから判断する。
     *
     * 日本語は単語境界が無いため、通常の全文検索インデックスでは分割できない。
     * MySQL の ngram パーサは文字数単位（既定2文字）で分割するため、
     * 空白を含まない照合キーでも検索できる。
     *
     * ngram パーサは MySQL 固有のため、テストで使用する SQLite では作成しない。
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE songs ADD FULLTEXT INDEX songs_match_key_title_fulltext (match_key_title) WITH PARSER ngram');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE songs DROP INDEX songs_match_key_title_fulltext');
    }
};
