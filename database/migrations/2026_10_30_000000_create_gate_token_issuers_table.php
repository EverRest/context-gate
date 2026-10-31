<?php

declare(strict_types=1);

/*
 * Spec 005, gate_token_issuers: one issuer row per token, written by ContextGate\Token\TokenIssuer.
 *
 * Registered by the package service provider (loadMigrationsFrom). The foreign key references gate_access_tokens, so
 * that migration (2026_10_29) must run first; the 2026_10_30 timestamp keeps this one after it.
 *
 * token_id is nullable and set to null when the token is deleted (revocation), so the issuer record outlives the token.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('gate_token_issuers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('token_id')->nullable()->unique();
            $table->foreign('token_id')->references('id')->on('gate_access_tokens')->nullOnDelete();
            $table->string('issuer', 120);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_token_issuers');
    }
};
