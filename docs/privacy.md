# Privacy

context-gate exists so that an assistant receives only the data a contract allows. Each value is classed, and each
call passes through a fixed pipeline before anything is returned.

## Data classes

Classes run from least to most sensitive: `public`, `internal`, `personal`, `secret`. A caller has a ceiling, and a
value is returned only when its class is at or below that ceiling. A value above the ceiling is dropped, unless a
stage replaces it with a pseudonym, bucket, date or count first.

- `public`: may be shown to any caller.
- `internal`: shown to callers whose ceiling is `internal` or higher.
- `personal`: names, contact details and similar. Shown only to a `personal` ceiling, or as a pseudonym.
- `secret`: credentials and keys. Never released; no token can carry this ceiling, and the stdio ceiling `secret` is
  refused.

Columns not listed in the classification catalog are unclassified and are dropped. See
[configuration.md](configuration.md#classification).

## Pipeline

Each call runs in this order:

1. Policy and contract: the tool must be enabled and visible to the caller, and its contract must resolve. A hidden
   tool is reported as not found.
2. Classify: each field is checked against the caller's ceiling.
3. Project: only the fields the contract lists are kept, in order. Nested data never passes through a projected field.
4. Relations: a related record replaces its foreign key, one level deep, shaped by the related contract and subject to
   the same ceiling, transform and projection.
5. Transform: personal values become pseudonyms, buckets, dates or aggregates. A pseudonym never shows the raw value.
6. Normalise: text is normalised so that split or invisible characters cannot hide a value.
7. Redact: text fields pass a regular-expression redaction layer. A field that fails redaction is dropped from every
   row, and the rest of the response is returned.
8. Canary check: a canary value found in the serialized rows blocks the response. The same check also runs before
   step 7, on the text fields as they arrive.
9. Quarantine: each text field is wrapped as untrusted content with its source name. Injection phrases set a flag. The
   stage never blocks on its own.
10. Budget: per-call truncation, the per-session and per-day byte limits, and the per-minute rate limit.

A refusal at any stage returns a refusal response with a reason code. The reason is recorded in the receipt; the
values are not.

## Transforms

- `Field::date()` accepts only `year` and `month` precision; any other value is refused when the contract is built.
- `Field::bucket()` needs 1 to 20 strictly ascending breaks, each adjacent gap at least 1% of the larger, so a bucket
  never narrows to a single raw value.
- User-written columns must use `Field::text()`: only text fields are wrapped as untrusted and redacted.

## Redaction limits

Redaction is best-effort regex matching for phone numbers (including local formats), emails, IBANs, card numbers and
bearer tokens. It is not a PII detector: names, addresses and free-form identifiers pass. The contract is the primary
protection; redaction is a second layer for text you chose to release.

## Canaries

Canary values are secrets you plant to detect leaks: a fake value in a test row, or a real value you never want to
leave. Configure them under `canaries.values`. A canary found in a response or in serialized output blocks the whole
response (reason `canary.hit`). Matching ignores letter case and folds common Latin, Cyrillic and Greek look-alike characters. The check runs twice: before redaction
(stage `canary.pre`, so a canary inside a text field is caught before it could be masked) and again on the serialized
rows. The canary value itself is never logged or returned.

Use fake values in examples and tests, for example `FAKE_FIXTURE_ONLY`.

## Receipts

Every call writes one receipt line to the `context-gate` log channel (stderr). A receipt holds the tool name, caller
id, outcome, reason code, rule hashes, dropped field names, detector names and byte counts. It holds no arguments, no
results, no values and no token values. Field names are kept; values are not.

When a call fails, one more metadata-only line goes to the same channel: the receipt reference, the exception class,
the file basename and the line number. The exception message is never written.

## Never sent

- Values above the caller's ceiling, including personal values of related records.
- Raw personal values where a pseudonym, bucket, date or count is configured.
- Nested data through a projected field.
- Config values. The config source reports keys, types and environment names only.
- Secrets, token values and canary values, in responses, receipts or logs.
- Any field the classification catalog does not list.
