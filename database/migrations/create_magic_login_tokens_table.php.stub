<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->tableName(), function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable');
            $table->string('token_hash', 64)->unique();
            $table->string('panel_id', 64)->index();
            $table->string('guard', 64);
            $table->boolean('remember')->default(false);
            $table->string('requested_ip', 45)->nullable();
            $table->string('requested_user_agent', 512)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName());
    }

    private function tableName(): string
    {
        return config('filament-magic-login.storage.table', 'magic_login_tokens');
    }
};
