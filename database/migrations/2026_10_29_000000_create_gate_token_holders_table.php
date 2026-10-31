<?php

declare(strict_types=1);

/*
 * Spec 005, gate_token_holders: the owner of the gate's tokens, one row per holder name (gate:token --holder).
 *
 * Deliberately not the host application's users table: a gate token must never authenticate as a host user. The
 * package service provider registers this migration with loadMigrationsFrom.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('gate_token_holders', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_token_holders');
    }
};
