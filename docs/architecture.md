# Condominium ID Generation, Management & QR Verification System
## Consolidated Architecture Document — Revision 2

Status: Architecture finalized. No application code written yet. This document
is the source of truth for the build described in `docs/implementation-plan.md`.
If something here looks under-specified or wrong when you read it in a fresh
chat, that's a signal to raise it, not to let a new chat quietly fill the gap
with its own default.

Revision 2 closes ten gaps found in review: the missing `users` table, photo
storage, Superadmin bootstrap and lockout, soft-delete semantics, the
reconciliation dashboard, photo-serving contradictions, database selection, QR
payload sizing, the collision-retry filter, and number formatting. Decisions
that were deliberately reversed from Revision 1 are marked **[changed in R2]**
so a reader of the old document isn't left guessing.

---

## 1. Context & Scale

- ~200–1,000 condominium units, a few thousand people total (residents + employees)
- LAN/VPN only — never exposed to the public internet
- 3–10 admins, occasional concurrent access to the same unit
- Priorities, in order: security, data integrity, maintainability, simplicity,
  reliability, performance. Do not over-engineer.

---

## 2. Technology Stack

- **Backend**: Laravel (current stable), PHP (current stable)
- **Frontend**: Blade + Livewire — no SPA framework. At this scale (LAN,
  moderate traffic, small team) a separate Vue/React build adds a build
  pipeline and state-management surface with no corresponding benefit.
- **Database**: **PostgreSQL (current stable)** — separate LXC from the app.
  **[changed in R2 — was "MySQL or PostgreSQL, either is fine"]**
- **Auth**: Laravel's built-in auth (Breeze or Fortify scaffolding),
  **session-based only — no Sanctum, no API tokens.** Livewire runs on the
  session; there is no API surface to protect and no second auth path to audit.
- **Roles/permissions**: Laravel Policies/Gates — no external package needed
  for three flat roles (Superadmin/Admin/Reader); a permissions package
  (Spatie etc.) would be justified if roles became more granular later, not now.
- **QR generation**: `simple-qrcode` (or equivalent) for generating QR images
- **QR scanning**: browser-based JS library (e.g. `html5-qrcode`) — works on
  desktop webcams and mobile browsers, no native app required
- **Image processing**: Intervention Image (wraps GD/Imagick)
- **ID rendering**: server-side compositing via Intervention Image/Imagick —
  see §10
- **No encryption layer for QR payloads.** **[changed in R2]** The `Crypt`
  facade is not used for QR codes; see §8 for the reasoning.

### PostgreSQL-specific conventions

Settling the database resolves several previously ambiguous choices:

- JSON columns (`templates.field_positions`, `audit_logs.previous_value` /
  `new_value`, `security_events.detail`) use **`jsonb`**, not `json` or `text`.
  Indexable, and Laravel's `array` cast handles it transparently.
- Enumerated values (`gender`, `type`, `status`, `role`, etc.) are
  **varchar with a check constraint**, not native Postgres enum types.
  Postgres enums require `ALTER TYPE` to modify and are awkward to reverse in
  migrations; a check constraint is a plain migration.
- `lockForUpdate()` maps to `SELECT ... FOR UPDATE` and behaves as §5 assumes.
  The fixed lock ordering in §5 is still required: Postgres detects deadlocks
  and kills one transaction rather than hanging, but a killed transaction is
  still a failed operation.
- Unique violations raise SQLSTATE `23505`, surfaced by Laravel as
  `Illuminate\Database\UniqueConstraintViolationException`. See §6.

---

## 3. Database Schema

### `users`
Authentication and authorization only. A `users` row is a **login**, not a
person record. Never hard-deleted.

| column | notes |
|---|---|
| id | |
| person_id | nullable, unique FK → `people`. Informational link only; never consulted for authorization |
| username | unique — the login identifier |
| name | display name shown in audit trails and UI |
| password | bcrypt (Laravel default) |
| role | `superadmin` \| `admin` \| `reader` — single column, exactly one role per user, no pivot table |
| must_change_password | boolean, default false — set true when a Superadmin issues a temporary password |
| is_active | boolean — disable an account without deleting it |
| last_login_at | nullable |
| remember_token | |
| deleted_at | soft delete, Superadmin-only |
| timestamps | |

**Username, not email, is the login identifier.** There is no `email` column
and no mail server. Breeze/Fortify scaffold email-based login and email-based
password reset, both of which assume a working mail path; this deviates from
that default deliberately.

**The password-reset scaffolding must be stripped during setup, not merely
left unused.** The `password_reset_tokens` table, the reset routes, and the
`CanResetPassword` contract all key on email and will fail to boot without it.
Recovery is: a Superadmin sets a temporary password, `must_change_password`
forces a change at next login, and both events are written to `audit_logs`.

**`role` is a plain column.** One role per user. A Reader who needs admin
rights has their role changed, recorded as `role_changed`.

**Why `person_id` exists.** Readers are typically guards, and guards are
employees — the same human is both a `people` row with an employee card and a
`users` row with a login. Without the link, terminating that employee revokes
their card while their login stays live and nothing connects the two. It
remains nullable because a Superadmin may be an outside IT contractor with no
`people` row at all. Contact details for an admin, if ever needed, live on the
linked `people` row.

### `people`
Permanent, never hard-deleted. A human being, independent of any unit or card.

**Identity**

| column | type | notes |
|---|---|---|
| id | bigint | |
| user_id_number | char(8) | unique, random, permanent (§6) |
| first_name | string | **printed** |
| middle_name | string, nullable | **printed** — whether it prints in full or as an initial is a template decision (§10), not a schema one |
| last_name | string | **printed** |
| suffix | string, nullable | Jr., Sr., III. **printed** |
| photo_path | string, nullable | UUID filename on the private disk. **printed**. Replaceable — see below |

**Personal**

| column | type | notes |
|---|---|---|
| date_of_birth | date, nullable | not printed |
| place_of_birth | string, nullable | not printed |
| gender | varchar + check | `male` \| `female` \| `prefer_not_to_say`. Not printed |

**Contact**

| column | type | notes |
|---|---|---|
| home_address | text, nullable | physical home address, distinct from the person's unit. Single text field — nothing in this system queries or aggregates on address, so structure buys nothing. Not printed |
| mobile_number | string, nullable | not printed |
| landline_number | string, nullable | not printed |
| email | string, nullable | contact only. **Unrelated to `users`**, which has no email column. This is a phone-book entry; the system never sends mail |

**Emergency contact**

| column | type | notes |
|---|---|---|
| emergency_contact_name | string, nullable | not printed |
| emergency_contact_number | string, nullable | not printed |
| emergency_contact_relation | string, nullable | not printed |

**Record**

| column | type | notes |
|---|---|---|
| notes | text, nullable | free-form admin notes |
| deleted_at | timestamp, nullable | soft delete, Superadmin-only (§13) |
| timestamps | | |

Design notes:

- **Split name fields, not a single `name`.** Sorting, searching, and template
  field-mapping all need the parts separately. A computed `full_name`
  accessor covers display.
- **No `type` or `role` on `people`.** Whether someone is an owner, tenant, or
  employee is a property of their relationships and cards, not of the person.
  An employee who buys a unit needs no change here.
- **Photo is single and replaceable.** One current photo per person. Replacing
  it validates, crops 1:1, compresses, writes a new UUID file, updates the
  column, and unlinks the old file. Because the photo is printed, replacing it
  triggers mandatory reissue — see §9.
- **Emergency contact is three flat columns**, not a related table. One
  contact per person covers the realistic case.
- **Fields deliberately not collected:** nationality, civil status, vehicle or
  plate numbers. None is used by anything in this document. Personal data a
  system never reads is a liability rather than an asset, particularly under
  the Data Privacy Act. Add when a template or procedure actually needs it.

### `units`

| column | notes |
|---|---|
| id | |
| building, tower, floor, unit_number | numbering scheme configurable, not hard-coded |
| deleted_at | soft delete, Superadmin-only (§13) |
| timestamps | |

There is no `is_active` column. A vacant unit is not a deleted unit — vacancy
is a filter over relationships, not a stored state.

### `person_unit_relationships`
Tracks a person's tie to a unit over time. A new row per relationship period —
never overwritten.

| column | notes |
|---|---|
| id | |
| person_id | FK |
| unit_id | FK |
| type | `owner` \| `tenant` |
| start_date | actual start of the relationship |
| contract_end_date | nullable. Scheduled end per the lease. Null = perpetual until the owner says otherwise. **Informational only** — never drives capacity, validity, or card status |
| ended_at | nullable. Actual termination, set by an admin. **Null = currently active.** Sole source of truth |
| timestamps | |

**[changed in R2 — R1 had a single `end_date` column.]** The two facts diverge
in the normal case: a lease can run past its term without renewal, end early,
or be extended. One column cannot hold both "when the contract says this ends"
and "whether this person currently lives here," and the second question is the
one every other part of the system asks.

Consistent with §7: dates describe the contract, admin action determines state.
A fixed-term lease that passes `contract_end_date` stays active until someone
closes it. **Every active-relationship query is `whereNull('ended_at')`.**

A sentinel value (e.g. a date 99 years out) was considered and rejected: it is
indistinguishable from a genuine long-term ground lease, it is a magic number
every future query must know about, and it still forces date arithmetic to
answer "is this active," which §7 forbids.

### `id_cards`
The physical/digital credential. Never deleted; every change is a new row or a
status transition, never an overwrite of history.

| column | notes |
|---|---|
| id | |
| person_id | FK |
| unit_id | nullable FK — null for employee IDs (or a catch-all "staff" unit, admin's choice, no schema difference either way) |
| control_number | char(8), unique, random (§6) |
| type | `owner` \| `tenant` \| `employee` |
| status | `active` \| `lost` \| `revoked` \| `expired` \| `replaced` |
| replacement_reason | nullable: `lost` \| `type_change` \| `unit_transfer` \| `photo_change` \| `name_change` \| `employment_change` |
| replaces_id_card_id | nullable, self-referential FK — links replacement to the card it replaced |
| template_id | FK — which template version was active at issue time (provenance only, see §10) |
| position | nullable string — employee job title (only when type = employee). **printed** |
| department | nullable string — employee department (only when type = employee). **printed** |
| issued_at | |
| timestamps | |

**No `deleted_at`.** **[changed in R2]** §4 already provides a terminal state
for "this card should not exist anymore" (`revoked`). A second, parallel notion
of deletion adds only the chance of the two disagreeing, and deleting a card
would break the `replaces_id_card_id` chain that §4's history guarantee depends
on.

**No `expires_at`.** Expiration is an admin-triggered status change, never
time-derived. See §7.

**No stored validity boolean.** `status = 'active'` is the single source of
truth. Where an `isValid()` helper is convenient it is computed, never stored:

```php
public function isValid(): bool
{
    return $this->status === 'active';
}
```

**No `qr_token`.** The QR encodes the control number directly — see §8.

### `templates`

| column | notes |
|---|---|
| id | |
| id_type | `owner` \| `tenant` \| `employee` — each type has its own template(s) |
| name | |
| background_path | private disk, not public webroot |
| width_px, height_px | portrait; configurable, not hard-coded |
| field_positions | jsonb: field name → x/y/width/height/font |
| is_active | which template renders for new previews of this id_type |
| timestamps | |

### `audit_logs`
Business event trail. Immutable at the application layer (Approach A now —
Eloquent guard blocks `update`/`delete`; DB-level grant/trigger enforcement
[Approach B] is a noted future upgrade, not built yet). Readable by
Superadmin/Admin only; not accessible to Readers.

| column | notes |
|---|---|
| id | |
| occurred_at | |
| user_id | **nullable** — null for console-originated actions (§12) |
| user_role | role at time of action, or `console` for Artisan-originated events |
| action | see the action list below |
| subject_type / subject_id | polymorphic — which record was affected |
| previous_value / new_value | jsonb, where applicable |
| ip_address | nullable — null for console actions |

Action vocabulary (not exhaustive, but these are fixed):

`person_created`, `person_data_updated`, `photo_updated`, `person_deleted`,
`person_restored`, `unit_created`, `unit_deleted`, `unit_restored`,
`deletion_blocked`, `relationship_opened`, `relationship_closed`, `id_issued`,
`id_revoked`, `id_expired`, `id_marked_lost`, `id_replaced`,
`control_number_retired`, `role_changed`, `password_reset`,
`superadmin_created`, `superadmin_disabled`, `superadmin_password_reset`,
`superadmin_created_via_console`, `superadmin_password_reset_via_console`

Superadmin-tier actions are kept as distinct action names rather than folded
into the generic `role_changed` / `password_reset` values so the
highest-privilege events in the system stay trivially greppable.

### `security_events`
Failed/attempted-action trail — logged always. Same immutability and
read-access rules as `audit_logs`. Kept as a **separate table** so the business
trail stays clean and readable while this one absorbs higher-volume noise.

| column | notes |
|---|---|
| id | |
| occurred_at | |
| user_id | nullable — may be unauthenticated (failed login) |
| event_type | `login_failed`, `authorization_denied`, `qr_verify_miss` |
| detail | jsonb — route attempted, control number attempted, etc. |
| ip_address | |

---

## 4. ID Lifecycle & Status Model

```
                 ┌────────┐
   issued ──────▶│ active │
                 └───┬────┘
                     │
      ┌──────────────┼───────────────┬─────────────────┐
      ▼              ▼               ▼                 ▼
  marked LOST   admin REVOKES   entitlement      printed data
      │              │           LAPSES           changes / type
      │              │               │            change / transfer
      ▼              ▼               ▼                 ▼
  status=lost   status=revoked  status=expired   status=replaced
      │                                            (new card issued,
      ▼                                             replaces_id_card_id
  new replacement                                   set on the new row)
  card issued
  (replacement_reason=lost)
```

**Key rule: nothing is ever overwritten.** A "replacement" is always a new
`id_cards` row linked via `replaces_id_card_id`; the old row's status changes,
its data does not.

**`expired` vs `revoked`** — the distinction is meaningful and should be
preserved in reporting:

- **`expired`** — the entitlement lapsed. The person is no longer an owner or
  tenant of the unit named on the card. Set by an admin closing a relationship
  (§5 cascade) or expiring a card directly.
- **`revoked`** — an admin deliberately withdrew the card from someone who is
  still entitled to one. A disciplinary or security action.

Both are **purely admin-triggered**. There is no scheduled job, no date
comparison, and no staleness window anywhere in this system.
`status = 'active'` is the single source of truth everywhere, full stop.

---

## 5. Issuance, Capacity & Concurrency

### 5.1 Card type and unit selection

A person may hold several active relationships at once — owning multiple units,
or owning one and renting another. A card names exactly one unit, so issuance
resolves it deterministically:

1. **Owner outranks tenant.** If the person holds any active `owner`
   relationship, the card is `type = 'owner'`. Tenant relationships are
   consulted only when no active owner relationship exists.
2. **Within the winning type, the unit is the one with the earliest
   `start_date`**, ties broken by lowest `unit_id`.
3. **The admin may override the unit at issuance**, choosing any active
   relationship of the winning type. The override does not cross types — an
   owner cannot be issued a tenant card while an owner relationship is open.
4. **The chosen unit is recorded on the card** and never recomputed. If the
   underlying relationship later ends, the card is replaced, not silently
   repointed.

"Active" throughout means `ended_at IS NULL`. `contract_end_date` plays no part.

A person holds **one** owner/tenant card, not one per relationship. Employee
cards bypass this section entirely: type is `employee` regardless of any
relationship, `unit_id` may be null, and the card never counts toward a cap.

### 5.2 Six-active-ID cap

Applies only to `type IN ('owner','tenant')`. Employee IDs never count toward
it. An owner of three units consumes one slot in the unit their card names;
their other units are untouched.

**Chosen approach:** pessimistic row lock on the `Unit` (or, for transfers,
**both** units involved) for the duration of the transaction, combined with
optimistic unique-constraint + retry for control-number generation (a separate,
global-uniqueness problem that a per-unit lock doesn't address).

```php
DB::transaction(function () use ($person, $unit, $type) {
    $lockedUnit = Unit::where('id', $unit->id)->lockForUpdate()->first();

    $activeCount = IdCard::where('unit_id', $lockedUnit->id)
        ->where('status', 'active')
        ->whereIn('type', ['owner', 'tenant'])
        ->count();

    if ($activeCount >= 6) {
        throw new UnitAtCapacityException($lockedUnit);
    }

    return $this->createWithControlNumber([...]);
});
```

**Unit transfers lock both units, in a fixed ascending-ID order**, regardless of
transfer direction. This is the one non-obvious correctness requirement in the
whole system: without a consistent lock order, two people swapping units in
opposite directions at the same instant can deadlock. Locking in a fixed order
makes deadlock structurally impossible rather than merely unlikely.

The same fixed ordering applies to any multi-unit transaction, including a
mandatory reissue (§9) for a person holding cards in more than one unit.

**Retire-then-check ordering** for conversions, transfers, and reissues: the old
card is marked `replaced` *before* the new capacity count is taken, inside the
same transaction — otherwise a unit sitting at exactly 6/6 would wrongly reject
its own occupant's replacement.

### 5.3 Relationship closure cascade

**[new in R2]** Closing a relationship expires the matching card in the same
transaction. Specifically: every card where `unit_id` matches the closed
relationship's unit, `type IN ('owner','tenant')`, and `status = 'active'`
becomes `status = 'expired'`. Employee cards are never affected.

The confirmation screen names every card that will be expired before the admin
commits. Where the person retains another active relationship — an owner of
several units closing one of them — the screen states that a replacement is
required and offers to issue it in the same flow, resolved by §5.1's
tie-breaker. If the admin skips that step, the person surfaces on the
reconciliation dashboard (§14, Query B) and stays there until the card exists.

This is admin-triggered, not scheduled: closing a relationship is a deliberate
act. §7's prohibition on time-derived state holds.

### 5.4 Reconciliation

See §14. The dashboard exists because the system deliberately refuses to derive
state from dates, which means divergence between record and reality is expected
rather than exceptional, and needs to be visible.

---

## 6. ID Number Generation

- **User ID Number** (person-level, permanent): random 8-digit, DB-unique,
  retry-on-collision. Randomized deliberately — sequential numbering would
  reveal roughly how many people are in the system and their join order.
- **Control Number** (card-level): same generation pattern, separate unique
  column. The two numbers live in different tables and are not required to
  avoid each other's values.

### Format

**[clarified in R2]** Both are **`char(8)`, zero-padded, drawn from the full
range `00000000`–`99999999`.**

Integer storage is the trap: `00451234` becomes `451234`, prints as six digits,
and the QR encodes six characters. A number that renders differently depending
on which code path formatted it is a verification failure waiting to happen.
String storage makes the stored value and the printed value identical by
construction. `char` over `varchar` because the length is genuinely fixed.

Leading zeros are permitted. Excluding them to make numbers "look right"
discards 10% of the keyspace for nothing and creates two rules where one
suffices.

```php
function randomEightDigits(): string
{
    return str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
}
```

`random_int` rather than `rand`/`mt_rand` — it is CSPRNG-backed, and §6's
rationale for randomizing is a security rationale, so the generator should be
too.

Admin-entered lookups validate as exactly 8 digits and apply zero-padding
before querying, so a guard typing `451234` from a worn card still resolves.

### Uniqueness and collision handling

Uniqueness is enforced at the **database level**, with **explicitly named
constraints** so error matching is against something we control rather than
Laravel's generated names:

```php
$table->char('control_number', 8)->unique('uq_id_cards_control_number');
$table->char('user_id_number', 8)->unique('uq_people_user_id_number');
```

plus a check constraint that the value matches `^[0-9]{8}$`.

```php
retry(5, function () use ($attributes) {
    return IdCard::create([...$attributes, 'control_number' => randomEightDigits()]);
}, 0, function ($e) {
    return $e instanceof UniqueConstraintViolationException
        && str_contains($e->getMessage(), 'uq_id_cards_control_number');
});
```

**[changed in R2 — R1 matched only on the message string.]** The class check
confirms the error is actually a unique violation (SQLSTATE `23505`); the string
check then confirms it was *this* constraint. A unique violation on a different
constraint must never be retried, because retrying regenerates the control
number and does nothing about the real conflict.

Three points worth stating so they aren't re-tuned later:

- **Zero delay between attempts.** A collision is an independent redraw, not
  contention. Backoff would only slow it down.
- **Five attempts is not a tuning parameter.** At 5,000 records against 10⁸
  values, a single insert collides roughly 1 time in 20,000. Five consecutive
  collisions means something is wrong — a keyspace far smaller than assumed, or
  a broken generator — and failing loudly is correct.
- **Capacity and validation errors are never retried.**

---

## 7. Revocation & Expiration

Both are **admin-triggered only** — there is no time-based or scheduled logic
anywhere in this system. `expires_at` does not exist as a column; it is neither
stored, printed, nor computed. There is no scheduler, no queue worker, and no
cron entry in the app LXC.

```php
public function revoke(IdCard $card, string $reason, User $actor): void { ... }
public function expire(IdCard $card, User $actor): void { ... }
```

Structurally identical — the only difference is the resulting `status` value and
the audit label. Both require a logged actor and reason. See §4 for when each
applies.

The single place a date is compared to today is Query A of the reconciliation
dashboard (§14), which produces a list for a human and changes nothing.

---

## 8. QR Code & Verification

**[changed in R2 — R1 encrypted the payload with `Crypt::encryptString()`.]**

- **QR payload is the control number in plaintext**, exactly as stored: 8
  numeric characters, zero-padded.
- **Verify flow:** Reader scans → app reads the control number → looks up
  `id_cards` → returns current status, the person's photo, and identifying
  info. No decryption step.
- **A card that is not `active` displays its true status.** A readable QR is
  never evidence of validity.
- **A control number that doesn't resolve** writes `qr_verify_miss` to
  `security_events` with the attempted value.

### Why plaintext

The encrypted design was sound in intent but failed on physics.
`Crypt::encryptString('12345678')` produces base64 of a JSON object holding
`iv`, `value`, `mac`, and `tag` — roughly 230–250 characters, or 30x the payload
in overhead. At error-correction level M that is QR version 11–12, about 61–65
modules per side. On an 18–22mm printed square that is ~0.3mm per module.
Phone cameras manage that in good light held square; cheap USB webcams at a
guardhouse counter, at an angle, against a card that has been in a wallet for a
year, do not. The failure is not clean — it is slow scans and retries, which
trains guards to wave people through.

Eight numeric characters is QR **version 1**, about 21 modules per side: the
smallest and most robust code the format produces.

The encryption was also protecting little. §8 of R1 already conceded that the
authenticated verify page is what protects the lookup. Encryption only stopped
someone decoding the QR offline to read a control number that is **printed on
the card face anyway**.

### The trade being accepted, stated plainly

> A photographed or copied QR yields a control number that is valid input to
> the verify endpoint. Anyone with a Reader account can look it up. This is not
> a new exposure — the number is printed on the card — and it grants nothing an
> authenticated Reader could not obtain by typing the number. The protection
> against a copied card is that verification returns the **photo on file**,
> which the guard compares against the person standing there. Guard training
> therefore matters more than payload opacity: a scan that returns `active` but
> shows a different face is a failed verification.

`APP_KEY` loss no longer breaks any issued card. The backup requirement moves to
§12 as an ordinary Laravel concern (sessions, any future encrypted columns)
rather than a QR-specific catastrophe.

---

## 9. Photos, Printed Data & Mandatory Reissue

### 9.1 Storage

- Photos are cropped to 1:1 at upload/capture time (once, not per-render),
  validated (type, MIME sniff, ≤1MB, dimensions), compressed, and stored on a
  **private disk** with no public symlink and no direct static URL.
- Filenames are random (UUID), not derived from `person_id` or control number,
  so a leaked or guessed filename alone is useless without the authenticated
  route in front of it.
- One photo per person, on `people.photo_path`. Replacing it unlinks the old
  file.

### 9.2 Serving

**[changed in R2 — R1 specified two contradictory mechanisms.]**

> **Serving:** every photo is streamed through a single authenticated route
> with a policy check on every request. No public disk, no symlink, no signed
> URLs, no direct static path. Chosen over short-lived signed URLs for having
> one code path to audit and no custom token-expiry logic to get wrong.
>
> **Reader scope:** a Reader may retrieve a photo only for a person they have
> verified in the current session within the last 60 seconds. The verification
> is recorded server-side at scan time; the route consults that record, not the
> request. A Reader therefore has no standalone photo-lookup capability and
> cannot replay a URL after the fact. Denials write `authorization_denied` to
> `security_events`.

The scan record lives in **session storage** — a map of person ID to timestamp,
written by the verify controller. No schema is needed at this scale; naming it
here prevents it being reinvented as a table.

Base64-embedding the photo in the verify response was considered and rejected:
it creates a second code path that bypasses the policy entirely, prevents
caching, and does not actually prevent hoarding. What limits a Reader to one
photo per scan is the session rule, regardless of transport.

### 9.3 Printed fields and mandatory reissue

**A printed card is immutable.** If anything printed on it changes, a new card
is printed.

**The printed-field list is closed:**

| field | lives on |
|---|---|
| photo | `people.photo_path` |
| first / middle / last name, suffix | `people` |
| user ID number | `people` (never changes) |
| control number | `id_cards` (new card by definition) |
| unit | `id_cards.unit_id` |
| type | `id_cards.type` |
| position, department | `id_cards` |

A change to anything else — date of birth, place of birth, gender, address,
contact numbers, emergency contact, notes — is a plain correction with no card
consequence.

**The flow, when an admin changes a printed field for a person holding one or
more active cards:**

1. Before committing, the UI names every affected active card — control number,
   type, unit — since one person may hold several (an employee who is also an
   owner holds two).
2. The admin **confirms or cancels. There is no third option.** Cancelling
   abandons the edit entirely; confirming performs both the data change and the
   reissue.
3. On confirm, inside a single `DB::transaction`: the person record updates,
   each affected card is marked `replaced` with the matching
   `replacement_reason`, and a new card is issued for each via the normal path.
4. Both the data change and every resulting reissue are written to
   `audit_logs`, within the same transaction, so the trail shows cause and
   effect together.

Retire-then-check ordering (§5.2) applies, or a unit at 6/6 rejects its own
occupant's reissue. Multi-unit reissues use the fixed ascending-unit-ID lock
order.

**Policy note for the operations manual:** any change to printed data means a
new physical card. The old card must be surrendered when the new one is
collected. The system marks it `replaced` regardless, so an unsurrendered card
fails verification — but physical collection is what stops two plausible-looking
cards circulating.

**Known operational consequence:** correcting a typo in a name burns a card. If
cards are expensive or slow to produce, admins will be tempted to leave small
errors uncorrected. The fix, if it becomes a problem, is a process decision
(batch corrections, a grace period at initial data entry), not an architectural
one.

---

## 10. Template Rendering (on hold)

Design decided, implementation deferred pending designer input.

- Server-side compositing (Intervention Image/Imagick) chosen over
  headless-browser rendering — lighter operational footprint, no browser-engine
  dependency to patch, appropriate for occasional single-card rendering rather
  than bulk batch output.
- Names auto-shrink to fit their field box.
- Photos are always placed as pre-cropped 1:1 images (cropping already happened
  at upload time — see §9).
- Portrait orientation; dimensions configurable per template, not hard-coded.

**No historical-reprint capability, and none is planned.** **[changed in R2]** A
card's printed appearance is fixed at issuance; if anything on it must change,
the card is replaced and a new one is printed (§9). `id_cards.template_id` is
retained purely as a **provenance record** — it answers "which template produced
this card" for audit purposes, not "re-render this card exactly."

---

## 11. Roles & Permissions

Three flat roles, enforced server-side via Policies — never merely hidden in the
UI.

| | Superadmin | Admin | Reader |
|---|---|---|---|
| CRUD person/unit records | ✓ | ✓ | ✗ |
| Open/close unit relationships | ✓ | ✓ | ✗ |
| Issue owner/tenant IDs | ✓ | ✓ | ✗ |
| **Issue employee IDs** | ✓ | ✗ | ✗ |
| Mark lost/revoke/expire | ✓ | ✓ | ✗ |
| Manage templates | ✓ | ✗ | ✗ |
| View audit logs / security events | ✓ | ✓ | ✗ |
| View reconciliation dashboard | ✓ | ✓ | ✗ |
| Scan QR / view verify result + photo | ✓ | ✓ | ✓ (scan-scoped only) |
| Manage Admin/Reader accounts | ✓ | ✗ | ✗ |
| **Manage Superadmin accounts** | ✓ | ✗ | ✗ |
| Reset lower-role passwords | ✓ | ✗ | ✗ |
| Soft-delete records | ✓ | ✗ | ✗ |

### Superadmin tier

**[changed in R2 — R1 forbade Superadmins from managing each other, which left
the tier unrecoverable and the first account uncreatable.]**

- **Exactly two Superadmin accounts are created at system initialization**,
  before any other data exists. From that point the tier is self-managing
  through the GUI.
- **Enforced invariant: at least two active Superadmins at all times.** This is
  a hard check in code, not a policy note. The application refuses any action —
  disable, soft-delete, role change, deactivation — that would leave fewer than
  two. The check runs inside the same transaction with the Superadmin rows
  locked; without the lock, two admins acting simultaneously can each pass a
  count-of-three check and land on one.
- **A Superadmin cannot act on their own account** for role change or disable.
  Self-demotion is the fastest accidental path to a one-member tier and has no
  legitimate use — ask the other Superadmin.
- **Superadmins can impersonate each other.** A can reset B's password and log
  in as B, with actions attributed to B. `must_change_password` means B
  discovers this at next login, and `audit_logs` records A performing the reset,
  so it is detectable but not preventable. With 2–4 trusted admins on a LAN this
  is the normal trade; it is recorded here as a known property, not a surprise.
- **Console break-glass exists** for the case where the tier is empty or all
  Superadmins are locked out — see §12.

### Other notes

- Employee ID issuance is Superadmin-only — a carve-out beyond the original
  spec's general "Admins can generate IDs," specifically because employee
  records sit outside the unit-based structure the rest of the system is built
  around.
- **Soft-deletion of `people` and `units` is a Superadmin action with no Admin
  equivalent — there is no request or delegation mechanism.** An Admin needing a
  record removed asks a Superadmin, who performs the deletion and is recorded as
  the actor. Deletion is refused while the record has any active relationship or
  active card (§13), which makes it a deliberate multi-step act rather than a
  single click.
- 3 consecutive failed logins → UI prompts the user to contact a Superadmin. No
  automatic lockout, no cooldown timer. Every attempt (successful or not) writes
  to `security_events`.

---

## 12. Deployment

```
LAN/VPN clients (never public)
        │
        ▼
Reverse Proxy (Caddy or Nginx — TLS termination here, not in Laravel)
        │
        ▼
Laravel App — LXC #1
        │
        ▼
PostgreSQL — LXC #2 (sibling container)
```

- **Two sibling LXCs**, not Docker, not a single combined container. Native disk
  I/O for the database and independent Proxmox backup/snapshot schedules for app
  vs. data are the deciding factors — Docker's main advantage (reproducible
  multi-environment images) isn't a real requirement for a single self-hosted
  instance.
- DHCP with a router-side reservation for a predictable IP; no hard-coded IP in
  Laravel config. Domain name via internal DNS pointed at the reverse proxy, not
  the raw IP.
- Laravel's `TrustProxies` middleware configured for the reverse proxy so
  `APP_URL` and generated URLs are correct behind upstream TLS termination.
- **No scheduler, no queue worker, no cron entry.** Nothing in this system runs
  unattended. If a future feature appears to need one, that is a signal to
  re-read §7.
- **Backups:** `pg_dump -Fc` + `storage/app/private` (photos, templates) +
  `.env` on a scheduled job, stored off the LXC itself. Restoration should be
  **tested**, not just performed — a backup that has never been restored isn't
  verified.

### Bootstrap runbook (Phase 3)

After migrations, run the create command **twice**, producing two Superadmin
accounts with two generated temporary passwords, ideally handed to two different
people.

**No seeder ships a default account.** A `DatabaseSeeder` with a hardcoded
`admin`/`password` is the single most common way a system like this is
compromised, and on a LAN it will survive for years unnoticed.

### Console commands (break-glass only)

| command | purpose |
|---|---|
| `id:superadmin-create {username}` | Bootstrap, and recovery if the tier is ever empty |
| `id:superadmin-reset {username}` | Recovery when all Superadmins are locked out simultaneously |
| `id:superadmin-list` | Read-only diagnosis before break-glass |

Rules binding all three:

- **Passwords are never command arguments** — they land in shell history and the
  process list. The command generates one, prints it to stdout once, and sets
  `must_change_password`.
- **No first-run restriction on `create`** — gating it to an empty users table
  would reinstate the original unrecoverability problem.
- **No `Artisan::call()` from any HTTP route, ever.** These must be unreachable
  from the web tier.
- Each writes to `audit_logs` with `user_id = null`, `user_role = 'console'`,
  `ip_address = null`, and `new_value` carrying `{"os_user": "...", "hostname":
  "..."}` so the trail records which shell session did it.

**Residual risk, stated explicitly:** console access is equivalent to full
control of the system. This was already true at the database layer — anyone who
can reach the DB can rewrite it — so the commands grant no new authority; they
only make the operation correct (proper hashing, validation, audit trail)
instead of raw SQL performed under pressure. But it means **SSH key management
and Proxmox console access are part of this system's security boundary.**

---

## 13. Soft-Delete Semantics

**[new in R2.]** The failure mode to design against is Laravel's global
soft-delete scope: once `SoftDeletes` is on `Unit`, `Unit::find($id)` silently
returns null for a deleted unit, but `IdCard::where('unit_id', $id)` still
returns its cards, because the scope lives on the parent model. A deleted unit's
cards would keep scanning as valid while the unit vanished from every dropdown.

### The rule: deletion is guarded, and never cascades

**Deleting a `unit`** is refused while it has any active relationship
(`ended_at IS NULL`) or any active card. The error names them. The admin ends the
relationships and revokes the cards, then deletes. A deleted unit therefore has
no live dependents by construction, and §5's capacity count never sees it.

**Deleting a `person`** is refused while they hold any active relationship or
active card. Same resolution path. This matters most for verification: a
soft-deleted person must never have a scannable card, and the guard makes that
structurally true rather than a rule to remember.

**`id_cards` are never deleted** — the column does not exist. See §3.

**`users` keep `deleted_at`**, subject to the §11 two-Superadmin invariant.

### What deletion means

A soft-deleted record is **hidden from all normal queries and every UI
selector**, but remains fully readable in audit contexts. It cannot be edited
and cannot receive new cards or relationships. Its historical rows stay intact
and still resolve — a card issued to a person deleted last year still shows that
person's name in the audit trail.

**Restore is Superadmin-only and audit-logged.** Restoring a unit does not
restore or revalidate any card; those were closed before deletion and stay
closed.

### Query discipline

`withTrashed()` is used deliberately and only in:

- Audit log rendering, which must resolve subjects deleted after the fact
- Relationship history for a person or unit
- The `replaces_id_card_id` chain

Everything else — issuance, capacity counting, verification, dropdowns, reports,
the reconciliation dashboard — uses the default scope and must never see deleted
records.

Blocked deletion attempts write `deletion_blocked` to `audit_logs` with the
blocking dependents in `detail`. This is a validation failure, not an
authorization one, so it does not belong in `security_events`. Repeated blocked
attempts on the same record signal an admin trying to force something through.

---

## 14. Reconciliation Dashboard

**[new in R2.]** The system deliberately refuses to derive state from dates
(§7) and refuses to act on its own. That is correct, and it means divergence
between the record and reality is expected rather than exceptional. This screen
is where divergence becomes visible instead of accumulating silently.

Superadmin and Admin only. A standing screen linked from the main navigation,
not a report to be run. **Read-only** — every item links to the screen that
resolves it.

**Query A — Leases past their contract end date**
Relationships where `contract_end_date < today` and `ended_at IS NULL`. The lease
term has elapsed with no admin action: either it was renewed and the record needs
updating, or the tenancy ended and nobody closed it.

*This is the only date-to-today comparison anywhere in the system, and it
produces a list for a human — never a state change.* Renewal or departure is a
judgment the date cannot make. Auto-closing on this date was considered and
rejected: renewals are agreed verbally and recorded late, so a resident's card
would stop working at the gate on the anniversary with no admin present and no
way for the guard to distinguish that from a genuine expiry.

*Action: extend `contract_end_date`, or close the relationship — which cascades
through §5.3.*

**Query B — Persons with an active relationship and no active card**
Per person, not per relationship. Catches never-issued cards, and catches
multi-unit owners whose card was expired by a §5.3 cascade and not yet replaced.
A multi-unit owner correctly holding one card does not appear.

*Action: issue a card, or confirm the omission is intentional.*

**Query C — Units at the six-card cap**
Informational. Surfaces the constraint before an admin hits
`UnitAtCapacityException` mid-transaction.

### Design constraints

- **No counters, badges, or notifications.** There is no mail server, and a
  badge reading "12 issues" invites clearing the list rather than reading it.
- **No bulk operations, no "resolve all."** Each divergence is a decision.
- **Empty is the normal state.** Items sitting here for weeks is a process
  problem the dashboard makes visible, which is its purpose.
- **Viewing writes nothing to `audit_logs`.** Reading a list is not a business
  event; the actions taken from it log through their own paths.

### The gap this cannot close

Query A is a backstop only for **fixed-term** leases. A perpetual lease
(`contract_end_date IS NULL`) that ends in practice and is never closed in the
system is invisible to every query here, and the resident's card scans green
indefinitely.

This is not solvable in software without time-derived state, which §7 rules out
for good reasons. It is a **process dependency**, and belongs in the operations
manual: *move-out must trigger closing the relationship, which the system then
cascades to the card.*

---

## 15. Explicitly Deferred (not forgotten — just not now)

- **Audit log DB-layer immutability** (Approach B: revoke `UPDATE`/`DELETE`
  grants on `audit_logs`/`security_events` for the app's DB user, plus DB
  triggers as a second layer). Build the app-layer guard now; add this in the
  security review phase (Phase 13), not before.
- **Visual/WYSIWYG template editor.** Field positions are hand-set numeric
  values for now, not a drag-and-drop canvas. Revisit the rendering-engine
  choice (§10) only if this is built.
- **Print-ready output** (high-DPI rendering, physical print pipeline) —
  preview-quality rendering only for the initial build.
- **Deletion request queue.** An Admin cannot delete records and must ask a
  Superadmin (§11). A formal request-and-approval workflow was considered and
  rejected as over-engineering for a 3–10 admin operation where requester and
  approver work together and the audit log already records who deleted what.
- **Proxmox VE Helper Script packaging.** Once Phase 2's manual LXC deploy is
  proven, wrap it as a `community-scripts.github.io`/tteck-style helper script
  so other condo administrators can stand up their own instance with a single
  Proxmox `bash -c` install command instead of following the deploy runbook by
  hand. Depends on Phase 2 being stable and forward-only; not a Phase 0–1
  concern beyond keeping the deploy steps script-friendly.

---

## 16. Original Spec Edge Cases — Resolution Map

| Edge case | Resolved by |
|---|---|
| Tenant → Owner conversion | §5 — retire-then-check, same-unit |
| Unit-to-unit transfer | §5 — dual-unit lock, fixed order |
| Historical IDs preserved | §4 — status transitions only, no overwrites |
| Lost ID replaced | §4/§5 — `replacement_reason = 'lost'`, `replaces_id_card_id` chain |
| Revoked/expired ID scanned | §8 — verify page always shows true current status |
| Unit at 6 active IDs | §5 — locked count-then-insert |
| Two admins racing a 7th ID | §5 — `lockForUpdate()` serializes per-unit |
| Employee who is also a resident | Two independent `id_cards` rows; employee row never touches the 6-cap |
| Multiple historical unit relationships | §3 — new row per period, `ended_at` closes old ones |
| Control number collision | §6 — DB-unique + typed retry, a normal handled case |
| Accidental deletion of historical records | §13 — guarded soft deletes, Superadmin-only, audit-logged |
| Unauthorized photo access | §9 — private disk, authenticated route, Reader scan-scoped to 60s |
| Reader attempting admin functions | §11 — server-side Policy enforcement; logged to `security_events` |
| Old QR scanned after replacement | §8 — status looked up live, never inferred from the QR |
| Template changed after IDs issued | §10 — no reprint capability; `template_id` is provenance only |
| **Person owns multiple units** | §5.1 — owner priority, earliest `start_date`, lowest `unit_id`, admin override |
| **Owner who also rents another unit** | §5.1 — owner relationship wins; the tenancy yields no card |
| **Tenant moves out, card left active** | §5.3 — closure cascades to `expired` in the same transaction |
| **Owner sells the unit named on their card but owns others** | §5.3 — cascade expires the card; §14 Query B surfaces the reissue |
| **Lease term elapses with no admin action** | §14 Query A — prompts a human; nothing changes state on its own |
| **Printed data changed (photo, name, position)** | §9.3 — mandatory reissue in one transaction, no decline path |
| **Superadmin lockout / departure** | §11/§12 — two-account minimum, GUI management, console break-glass |
| **Deleted unit's cards still scanning** | §13 — deletion refused while live dependents exist |
| **QR unreadable on worn cards at the gate** | §8 — plaintext control number, QR version 1 |

---

*End of architecture document, Revision 2. Build order and per-phase definitions
of done live in `docs/implementation-plan.md`; the non-negotiable invariants an
agent must hold every session live in `CLAUDE.md` at the repo root.*
