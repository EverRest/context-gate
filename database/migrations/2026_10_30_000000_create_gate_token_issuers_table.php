<?php

declare(strict_types=1);

/*
 * Spec 005, gate_token_issuers: one issuer row per token, written by ContextGate\Token\TokenIssuer.
 *
 * This migration is registered by the package service provider in spec 005 US1 (ContextGateServiceProvider), not yet.
 * Until then the tests apply it directly. The foreign key references personal_access_tokens, so the Sanctum
 * migration must run first; the 2026 timestamp keeps it after Sanctum's 2019 migration.
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
            $table->foreign('token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
            $table->string('issuer', 120);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_token_issuers');
    }
};
