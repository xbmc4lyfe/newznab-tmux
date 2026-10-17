<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Found and declared article and file counts of a release published before its upload was
     * complete ("236 of 934 articles found"); all stay null for complete releases.
     */
    public function up(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            if (! Schema::hasColumn('releases', 'articles_found')) {
                $table->unsignedInteger('articles_found')->nullable();
            }
            if (! Schema::hasColumn('releases', 'articles_expected')) {
                $table->unsignedInteger('articles_expected')->nullable();
            }
            if (! Schema::hasColumn('releases', 'files_found')) {
                $table->unsignedInteger('files_found')->nullable();
            }
            if (! Schema::hasColumn('releases', 'files_expected')) {
                $table->unsignedInteger('files_expected')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('releases', static function (Blueprint $table): void {
            $table->dropColumn(['articles_found', 'articles_expected', 'files_found', 'files_expected']);
        });
    }
};
