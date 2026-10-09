<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Article identity of a release's NZB (sha1 of its sorted segment Message-IDs), used to keep
     * NZB imports idempotent when release dedupe is disabled.
     */
    public function up(): void
    {
        if (Schema::hasColumn('releases', 'article_fingerprint')) {
            return;
        }

        Schema::table('releases', function (Blueprint $table) {
            $table->char('article_fingerprint', 40)->nullable();
            $table->index('article_fingerprint', 'ix_releases_article_fingerprint');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('releases', 'article_fingerprint')) {
            return;
        }

        Schema::table('releases', function (Blueprint $table) {
            $table->dropIndex('ix_releases_article_fingerprint');
            $table->dropColumn('article_fingerprint');
        });
    }
};
