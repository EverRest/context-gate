# Data Model: HTTP Access and Tokens

## Token (`gate_access_tokens`, Sanctum-shaped; owned by `GateTokenHolder`, table `gate_token_holders`)

| Field | Type | Rule |
|---|---|---|
| id | int | Used by `--revoke` |
| name | string | Holder name entered at issuance (FR-017), at most 255 characters; compared case-insensitively on MySQL |
| token | string | One-way hash only (R4) |
| abilities | list | `gate:read` plus exactly one `gate:ceiling:<class>` (FR-004) |
| expires_at | datetime UTC | Required; at most 90 days from issuance (FR-006) |
| last_used_at | datetime | Set by Sanctum |

## TokenIssuer (`gate_token_issuers`, new)

| Field | Type | Rule |
|---|---|---|
| token_id | FK → gate_access_tokens.id, nullable | One row per token; set to null when the token is revoked, so the issuer row is kept as an audit trail |
| issuer | string | Issuer name entered at issuance (FR-017) |
| created_at | datetime UTC | Issuance time |

## Ceiling

| Value | Allowed | Notes |
|---|---|---|
| public | yes | |
| internal | yes | |
| personal | yes | Personal fields still only as pseudonyms (spec 002) |
| secret | no | Not a ceiling (R3) |

## Ability allowlist

`gate:read`, `gate:ceiling:public`, `gate:ceiling:internal`, `gate:ceiling:personal`. Any other ability is refused
by `gate:token` (FR-007).

## Origin allowlist

| Field | Type | Rule |
|---|---|---|
| origins | list of strings | Exact match, scheme and host and port; empty list refuses all Origin headers |

## Issuance and revocation receipt

| Field | Type | Values |
|---|---|---|
| event | enum | `token.issued`, `token.revoked` |
| at | datetime UTC | |
| issuer | string | |
| holder | string | |
| ceiling | enum | Ceiling values above |
| expires_at | datetime UTC | Issued only |
| token_id | int | |

The token value never appears in a receipt (SC-003).

## Reason codes (adds to specs 001–004)

| Code | Status | Notes |
|---|---|---|
| `token.missing` | 401 | No bearer token |
| `token.invalid` | 401 | Unknown, expired or revoked token |
| `token.no_ceiling` | 401 | Zero or several ceiling abilities (R8) |
| `token.no_read` | 403 | No `gate:read` ability |
| `origin.denied` | 403 | Origin not allowlisted (R5) |
| `token.ability` | 401 | An ability outside the package set (e.g. `*`, application abilities) |
