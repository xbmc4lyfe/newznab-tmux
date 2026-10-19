<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Found and declared article and file counts of a release published before its upload was
     * complete ("236 of 934 articles found"); all stay null for complete releases. A partial whose
     * inventory is still retained may be upgraded in place by late articles; late_article_candidate_id
     * names the candidate holding that inventory, and no worker reads its NZB until it clears for good.
     * Article counts sum every file's 32-bit part total, so they are 64-bit.
     */
    public function up(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            if (! Schema::hasColumn('releases', 'articles_found')) {
                $table->unsignedBigInteger('articles_found')->nullable();
            }
            if (! Schema::hasColumn('releases', 'articles_expected')) {
                $table->unsignedBigInteger('articles_expected')->nullable();
            }
            if (! Schema::hasColumn('releases', 'files_found')) {
                $table->unsignedInteger('files_found')->nullable();
            }
            if (! Schema::hasColumn('releases', 'files_expected')) {
                $table->unsignedInteger('files_expected')->nullable();
            }
            if (! Schema::hasColumn('releases', 'late_article_candidate_id')) {
                $table->unsignedBigInteger('late_article_candidate_id')->nullable();
            }
        });
        // A separate statement keeps the column additions instant; the sweep finds held releases by it.
        if (! Schema::hasIndex('releases', 'releases_late_article_candidate_id_index')) {
            Schema::table('releases', static function (Blueprint $table): void {
                $table->index('late_article_candidate_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->dropIndex(['late_article_candidate_id']);
        });
        Schema::table('releases', static function (Blueprint $table): void {
            $table->dropColumn(['articles_found', 'articles_expected', 'files_found', 'files_expected', 'late_article_candidate_id']);
        });
    }
};
