# Research: OpenAPI Source and Developer Tooling

## R1. Document loading

- **Decision**: Local files are read with the same path policy as docs (spec 003, `DocsPathPolicy`) for
  paths inside the project; `.yaml`, `.yml` and `.json` extensions only. URLs go through the fetch design (R2).
  Size check before parsing: 2 MB cap (FR-010); a file over the cap is refused with `source.too_large`.
- **Parser**: `symfony/yaml` for YAML and `json_decode` with depth 64 for JSON. Symfony YAML is already
  a common Laravel dependency; it is added as a `suggest` dependency only if the team has not already got it.
  Decision: use `symfony/yaml` as a required dependency for this feature (justified in the Constitution Check).
- **Validation**: version field must start with `3.`; Swagger 2.0 refused with `openapi.version` (edge case).

## R2. Network fetch: security design

Steps for one URL request (FR-005 to FR-009):

1. Parse the URL. Require scheme `https` (plain `http` only when the host is `localhost` in tests). Reject
   userinfo, non-default ports not in the allowlist entry, and fragments.
2. Check the host against the configured allowlist (exact match, lowercase). Empty allowlist refuses all
   URLs (FR-005).
3. Resolve the host once with `dns_get_record` for `A` and `AAAA`. Build the address list.
4. Check every address against the refusal ranges: IPv4 `10/8`, `172.16/12`, `192.168/16`, `127/8`,
   `169.254/16` (link-local and metadata `169.254.169.254`), `0/8`, `100.64/10`, `224/4`; IPv6 `::1`, `fc00::/7`,
   `fe80::/10`, `::ffff:0:0/96` mapped addresses checked as IPv4, `::/128`. Any refused address refuses the
   whole request (FR-006).
5. Pin: connect to the first accepted address with the original host name for TLS SNI and certificate
   verification (`CURLOPT_RESOLVE` with `host:443:address`). The HTTP client is configured so that the second
   resolution never happens during the request (FR-007).
6. Redirects disabled (`allow_redirects` false). A 3xx response is an error `source.redirect` (FR-008).
7. Timeout 5 seconds for connect and total (FR-009). Response read in chunks, stopping at 2 MB (FR-010).
8. The response is parsed only if the status is 200 and the content type is JSON or YAML.

- **Rebinding test**: a test resolver returns a public address on the first lookup and `127.0.0.1` on a
  second; the request must use the first address and must not connect to loopback. Written as a unit test
  with an injected resolver and a fake transport that records the connect address.
- **Rationale**: research checks are done before the connection and the connect address is fixed, so a second
  lookup cannot change the target.
- **Alternatives considered**: checking after connection (too late); allowing redirects with re-checks
  (adds a second resolution, rejected).

## R3. Examples

- **Decision**: default `examples: drop` removes `example`, `examples`, `x-example` and string `default`
  values from the document before the source sees it (D29). `examples: redacted` keeps them and sends each
  value through the spec 002 normalizer, redactor, canary check and quarantine (FR-003).
- **Rationale**: examples are often copied from real responses; the default must be safe.

## R4. External references

- **Decision**: `$ref` values that do not start with `#/` are listed as `ref.external` without content (FR-011).
- **Recursion**: schema references are expanded once; a repeated reference is shown as `{"$ref": "<name>"}`
  (edge case).

## R5. gate:scan

- **Decision**: reads routes (spec 003 RoutesSource) and models (SchemaSource) and writes a draft report to
  `storage/context-gate/scan.json` (or stdout with `--json`). It enables nothing and writes no configuration
  (FR-012).
- **Rationale**: a report is reviewable; no configuration change happens automatically.

## R6. gate:classify

- **Decision**: reads schema columns through SchemaSource and writes `app/Gate/classification.php` as a draft.
  Name-based suggestions use a fixed list: `email`, `phone`, `mobile`, `password`, `token`, `secret`, `iban`,
  `card`, `address`, `birth`, `ssn`. A suggestion gives `personal // suggested` (or `secret // suggested` for
  password, token and secret names). Columns without a suggestion are `secret // needs decision` (FR-013).
- **Overwrite**: refused without `--force` (FR-014). The draft header says it enables nothing until the
  markers are removed and the file is saved.
- **Rationale**: D14. A missing decision fails closed.

## R7. gate:preview

- **Decision**: runs a tool through the full spec 002 pipeline with sample arguments and prints the exact
  serialized response plus the dropped list. The command calls the same code path as a real tool call, with
  a flag that prevents delivery to any client (FR-015, FR-016). It writes a receipt with `source: preview`.
- **Byte-for-byte**: the printed text is the JSON exactly as the server would return it (SC-004).

## R8. gate:canary

- **Decision**: generates values with `random_bytes`: an email shape `canary-<8 hex>@example.invalid` and a
  token shape `CANARY_<24 hex>`. Prints them with suggested locations (docs, OpenAPI example, config). Writes
  no file (FR-017, SC-005).
- **Rationale**: canary values must not look like real secrets to secret scanners; `example.invalid` is a
  reserved domain, and the `CANARY_` prefix makes them easy to recognize.

## R9. No value output

- **Decision**: commands print only names, classes, counts and placeholders (FR-018). A test runs each command on
  a fixture with seeded values and asserts none appears in stdout or stderr.
