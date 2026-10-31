<?php

declare(strict_types=1);

/*
 * Spec 005, gate_access_tokens: the gate's own Sanctum token store (ContextGate\Token\GateAccessToken), with the same
 * columns as Sanctum's personal_access_tokens.
 *
 * It is separate from personal_access_tokens on purpose: the host application's auth:sanctum guard reads that table,
 * so a gate token stored there would authenticate the host's routes. The package service provider registers this
 * migration with loadMigrationsFrom.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('gate_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_access_tokens');
    }
};
