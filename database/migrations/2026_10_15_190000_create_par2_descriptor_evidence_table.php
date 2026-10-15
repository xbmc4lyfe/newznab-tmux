<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('par2_descriptor_evidence', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->string('release_guid', 40);
            $table->char('packet_checksum', 32);
            $table->char('recovery_set_id', 32);
            $table->char('file_id', 32);
            $table->char('full_md5', 32);
            $table->char('hash16k', 32);
            $table->string('file_size', 20);
            $table->longText('raw_filename_base64');
            $table->unsignedTinyInteger('descriptor_proof_version');
            $table->string('classification', 32)->default('unknown');
            $table->unsignedTinyInteger('classification_version')->default(0);
            $table->string('classification_provenance', 32)->default('unclassified');
            $table->unique(['releases_id', 'release_guid', 'packet_checksum'], 'par2_descriptor_identity');
            $table->foreign('releases_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('par2_descriptor_evidence');
    }
};
