<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Article identity of a release's NZB ({@see \App\Services\Nzb\NzbArticleFingerprint}), used to keep
     * NZB imports idempotent when release dedupe is disabled.
     */
    public function up(): void
    {
        // Column and index are checked independently so a re-run repairs a partial state.
        if (! Schema::hasColumn('releases', 'article_fingerprint')) {
            Schema::table('releases', function (Blueprint $table) {
                $table->char('article_fingerprint', 40)->nullable();
            });
        }

        if (! Schema::hasIndex('releases', 'ix_releases_article_fingerprint')) {
            Schema::table('releases', function (Blueprint $table) {
                $table->index('article_fingerprint', 'ix_releases_article_fingerprint');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('releases', 'article_fingerprint')) {
            return;
        }

        if (Schema::hasIndex('releases', 'ix_releases_article_fingerprint')) {
            Schema::table('releases', function (Blueprint $table) {
                $table->dropIndex('ix_releases_article_fingerprint');
            });
        }

        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('article_fingerprint');
        });
    }
};
