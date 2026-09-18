<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('azure_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('object_id', 64);
            $table->string('user_type', 191);
            $table->string('user_id', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'object_id', 'user_type'], 'azure_identity_unique');
            $table->index(['user_type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('azure_identities');
    }
};
