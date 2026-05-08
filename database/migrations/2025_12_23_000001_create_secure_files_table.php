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
        $userModel = config('artisanpack.secure-uploads.user_model')
            ?? config('artisanpack.security.user_model')
            ?? config('auth.providers.users.model', 'App\\Models\\User');

        $userTable = (new $userModel())->getTable();

        Schema::create('secure_files', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->uuid('identifier')->unique();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('disk')->default('local');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('hash', 64); // SHA-256
            $table->foreignId('uploaded_by')->nullable()->constrained($userTable)->nullOnDelete();
            $table->string('scan_status')->default('pending'); // pending, clean, infected, error
            $table->string('threat_name')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->nullableMorphs('fileable'); // For model attachments
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('hash');
            $table->index('scan_status');
            $table->index('uploaded_by');
            $table->index('mime_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secure_files');
    }
};
