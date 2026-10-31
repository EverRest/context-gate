# Quickstart: Context Firewall

Validation guide for spec 002. Requires spec 001 to be working.

## 1. Catalog and contract

Set `classification` in `config/context-gate.php` with at least `orders.status` as `internal`, `orders.customer_id`
as `personal`, `orders.notes` as `personal`:

```php
'classification' => [
    'orders' => [
        'status' => 'internal',
        'customer_id' => 'personal',
        'notes' => 'personal',
    ],
],
```

Create one contract that lists `status` and `customer_id` only.

Expected: `php artisan gate:check` exits 0.

## 2. Projection and unclassified fields (User Story 1)

Remove `notes` from the catalog and list it in the contract. Expected: `gate:check` exits 1 and names
`orders.notes`.

## 3. Secret and personal rules (User Story 2)

Add `password` (class `secret`) to a contract. Expected: loading fails with a message naming the field.
Add `customer_id` without a pseudonym form. Expected: loading fails with a message naming the field.

## 4. Pseudonyms (User Story 3)

Call the same tool twice in one process; the `customer_id` pseudonym matches. Restart the process; the
pseudonym differs. Send an unknown pseudonym as an argument; expected `Request refused.` and receipt
`pseudonym.unknown`.

## 5. Canary (User Story 5)

Set `CONTEXT_GATE_CANARY_EMAIL=canary-7f3a@example.invalid`. Place that value in a fixture the tool returns.
Expected: `Request failed. Reference: r_...`; log channel contains `canary.hit`; the canary value is not in
the log.

## 6. Budgets (User Story 7)

Make 61 calls within one minute from the same caller. Expected: the 61st returns `Request refused.` with
`rate.limited` in the receipt. Return a 20 KB fixture; expected `truncated: true`.

## 7. Rule hashes (User Story 8)

Change the catalog and call again. Expected: `rules.classification` differs between the two receipts.

## 8. Gates

```bash
composer test && composer stan && composer style
```

Expected: exit code 0 for each.
