# Contract: `gate_receipts` table

Migration: `database/migrations/2026_11_01_000000_create_gate_receipts_table.php` (loaded by the provider,
publishable with the `context-gate-migrations` tag).

```php
Schema::create('gate_receipts', function (Blueprint $table): void {
    $table->string('id', 32)->primary();
    $table->dateTime('at');
    $table->dateTime('expires_at');
    $table->string('caller', 191);
    $table->string('tool', 191)->nullable();
    $table->string('decision', 16);
    $table->string('reason', 64)->nullable();
    $table->string('transport', 8)->nullable();
    $table->string('source', 32)->nullable();
    $table->boolean('simulated')->default(false);
    $table->boolean('truncated')->nullable();
    $table->json('record');
    $table->index(['caller', 'at']);
    $table->index(['reason', 'at']);
    $table->index('at');
    $table->index('expires_at');
});
```

Rules:

- One row per record, receipts and `sink.failed` notes alike.
- `record` holds the same record the log sink writes; the columns are copies for filtering.
- Inserted on the `context-gate-receipts` connection, never inside a host transaction (FR-019).
- A duplicate `id` is a sink failure.
- Readers always add `expires_at > now()`.
- `gate:receipts:prune` deletes `expires_at <= now()`; nothing else deletes or updates rows.
