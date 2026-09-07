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
| person_id | nullable FK → `people`, unique **among live rows only** (a partial index scoped to `deleted_at IS NULL` — a plain unique constraint would permanently block re-linking a person to a new account once their old one is soft-deleted). Informational link only; never consulted for authorization |
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

**A password changes in exactly two ways, and there is no third.**
**[changed — an earlier draft also allowed a voluntary, current-password-known
change from the profile page.]**

1. **Mandatory rotation.** `must_change_password` forces the account through a
   dedicated form before any other route is reachable (`EnsurePasswordIsCurrent`).
   That form does **not** ask for the current password. Reaching it already
   proves possession of the account — either a Superadmin-issued temporary
   password or the wizard's own operator-chosen one just authenticated this
   session (§12), and re-typing a password the user did not choose back to the
   system verifies nothing an authenticated session doesn't already guarantee.
   On success the session is **logged out** and the browser sent to the login
   page with a flashed confirmation, rather than continuing on to the
   dashboard — this is the first real proof the new password works, and
   confirming it immediately, by using it to log back in, is worth the one
   extra step.
2. **A Superadmin resets another account's (or their own) password** from the
   Users screen. Generates a new temporary password, shown once, and sets
   `must_change_password` — the same shape as account creation, and the same
   flow that leads back into path 1.

**There is no voluntary, current-password-known change.** A user who simply
wants to pick a new password asks a Superadmin, the same as any other
recovery. This was a deliberate removal, not an oversight: keeping a third
path alongside the two above means two places decide whether a password
change is legitimate instead of one, and reasoning about "how could this
account's password have changed" would have to check three call sites instead
of two. Self-service password *rotation* (changing what you already know) is a
convenience this system does not need at 3–10 admins on a LAN; self-service
password *recovery* (forgetting it) was never in scope — see "Username, not
email" above.

**`role` is a plain column.** One role per user. A Reader who needs admin
rights has their role changed, recorded as `role_changed`.

**Why `person_id` exists.** Readers are typically guards, and guards are
employees — the same human is both a `people` row with an employee card and a
`users` row with a login. Without the link, terminating that employee revokes
their card while their login stays live and nothing connects the two. It
remains nullable because a Superadmin may be an outside IT contractor with no
`people` row at all. Contact details for an admin, if ever needed, live on the
linked `people` row.

**`person_id` must reference a `natural` party, never a company** (§3). A login
belongs to a human being; the link's entire purpose — tying an employee's card
to their login — is meaningless for a company, and a wrong value produces an
audit trail that reads as though a corporation signed in.

**Enforced in the application, not the schema, and deliberately so.** A CHECK
constraint cannot express this: CHECK may only reference columns of the same row
in the same table, and `entity_type` lives on `people`. The declarative
alternatives are a composite foreign key against a redundant
`users.person_entity_type` column, or a trigger — both real options, both
rejected here as disproportionate. This column is informational and **never
consulted for authorization**, so a wrong value has no security consequence,
and §3's audit-log immutability sets the precedent: an application-layer guard
now (Approach A) for an invariant that matters more than this one, with
DB-level enforcement deferred to the security review (Phase 13) if it is ever
warranted.

### `people`
Permanent, never hard-deleted. A **party** to a unit — in almost every case a
human being, and sometimes a company. Independent of any unit or card.

**[changed — was "a human being."]** Condominium units are routinely owned by
corporations, and that owner still has to be recorded and reachable. Rather than
a second table with its own relationships, capacity rules and deletion guards —
duplicating everything in §5 and §13 for a handful of rows — `people` carries
both kinds, distinguished by `entity_type`. The table name is now slightly wrong
and is kept anyway: renaming a shipped table to gain a word costs more than the
inaccuracy does.

**Identity**

| column | type | notes |
|---|---|---|
| id | bigint | |
| user_id_number | char(8), **required** | unique, random, permanent (§6). Minted for **every** party, companies included — see below |
| entity_type | varchar + check, **required** | `natural` \| `company`. Default `natural`. Immutable after creation — see below |
| first_name | string, nullable | **printed**. Required when `entity_type = 'natural'` |
| middle_name | string, nullable | **printed** — whether it prints in full or as an initial is a template decision (§10), not a schema one |
| last_name | string, nullable | **printed**. Required when `entity_type = 'natural'` |
| suffix | string, nullable | Jr., Sr., III. **printed** |
| legal_name | string, nullable | The company's registered name, as one string. Required when `entity_type = 'company'`, and null otherwise. Never printed — a company is never issued a card |
| photo_path | string, nullable | UUID filename on the private disk. **printed**. Replaceable — see below. **Required to issue a card, not to exist as a record** — see "Profile completeness" below |

**Personal**

| column | type | notes |
|---|---|---|
| date_of_birth | date, nullable | not printed |
| place_of_birth | string, nullable | not printed |
| gender | varchar + check, nullable | `male` \| `female` \| `prefer_not_to_say`. Not printed. `prefer_not_to_say` is the disclosure-refused value, distinct from null, which means not yet collected |

**Contact**

| column | type | notes |
|---|---|---|
| home_address | text, nullable | physical home address, distinct from the person's unit. Single text field — nothing in this system queries or aggregates on address, so structure buys nothing. Not printed |
| mobile_number | string, nullable | not printed. **Required for a primary unit owner** |
| landline_number | string, nullable | not printed |
| email | string, nullable | contact only. **Unrelated to `users`**, which has no email column. This is a phone-book entry; the system never sends mail. **Required for a primary unit owner** |

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

**Two kinds of party, one table.**

**[new]** `entity_type` decides which name columns apply, and a check constraint
enforces the pair — `natural` requires `first_name` and `last_name` with
`legal_name` null; `company` requires `legal_name` with the person-name columns
null. Neither kind can be half-filled, and no row can be both.

- **`display_name()` is the only thing the UI ever calls.** It returns
  `legal_name` for a company and the composed person name for a natural person.
  Nothing outside the model layer branches on `entity_type` to render a name; a
  screen that does has reimplemented this accessor badly.
- **A company is never cardable.** It has no photo, no face to compare at a
  gate, and no printed name — so it can never reach the cardable tier below,
  and issuance refuses it by kind, not by missing fields. The error says so:
  "a company cannot be issued an ID card," not "photo is required."
- **A company can be a primary unit owner**, and this is the case the kind
  exists for. It reaches the contactable tier the same way anyone does: a
  mobile number and an email, which for a company are its representative's.
- **A company holds no tenancy.** `type = 'tenant'` relationships are for
  natural persons; a corporate lease is recorded against the company as owner
  or against the occupying individuals as tenants, never as a company tenant
  who would then be expected to carry a card.
- **`entity_type` is immutable after creation.** A company does not become a
  person. Where one was recorded by mistake, the row is soft-deleted under §13
  and the correct one created — which keeps the audit trail honest about what
  was believed when.
- **Sorting and search use `display_name()`**, so a company sorts among people
  by its registered name. Sorting natural persons by `last_name` remains
  available wherever a screen wants it; companies sort last in that ordering,
  by null.
- **A company still gets a `user_id_number`.** It is never printed — a company
  holds no card — but the column stays `NOT NULL` for every party, so
  generation and collision-retry (§6) need no branch and every row has exactly
  one stable identifier to search or quote. Enough of this table is now
  conditional on kind; the permanent identifier is deliberately not.

**Profile completeness — three tiers, one table.**

**[changed — most contact fields were unconditionally required.]** A `people`
row is created at three different levels of detail depending on what the person
is for, and the column-level `NOT NULL` set is therefore the *smallest* of the
three. Completeness beyond that is enforced by the operation that needs it, at
the moment it needs it — not by the schema, and never retroactively against
rows already stored:

| tier | who | required |
|---|---|---|
| **Minimal** | co-owners and tenants who are recorded but not carded | a name for the kind: `first_name` + `last_name`, or `legal_name` |
| **Contactable** | the **primary unit owner** of any unit (§3 `person_unit_relationships`), company or not | the above + `mobile_number` + `email` |
| **Cardable** | anyone an ID card is issued to. **`natural` only** — a company can never reach this tier | the above + `photo_path`, plus whatever the printed-field list (§9) needs |

The three are cumulative, and the check is always "does this person satisfy the
tier for what is being attempted," never "has this person been upgraded." A
minimal person becomes contactable by being given a phone number and an email
and being made primary owner; nothing migrates, and no status column records
which tier a row sits in — the tier is a property of the operation, computed
from the columns, exactly like `isValid()` (§3 `id_cards`).

Consequences worth stating plainly, because each one is a place this could be
misread:

- **A person can exist with no photo.** Card issuance (§5) is what demands one,
  and refuses without it. This reverses the earlier "a person record cannot
  exist without a photo already uploaded."
- **Demoting a primary owner does not strip their contact details**, and
  promoting someone to primary owner refuses until theirs are present.
- **Null gender means "not collected"**; `prefer_not_to_say` means "asked and
  declined." Collapsing the two would lose a real distinction.

Design notes:

- **Split name fields for natural persons, not a single `name`.** Sorting,
  searching, and template field-mapping all need the parts separately, and the
  printed-field list (§9) names them individually. A computed `full_name`
  accessor covers display. **This is why a company's single `legal_name` is a
  separate column rather than the person columns pressed into service** —
  stuffing "Acme Holdings Inc." into `last_name` would put a company name into
  the printed-field list and into mandatory reissue (§9.3), neither of which
  should ever apply to it.
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

**[changed — 3 structured columns, fixed shape `ABBCC`.]** A unit code has a
fixed shape: `A` = building code (a single letter, nullable — omitted from
the code entirely when null), `BB` = a 2-character floor code, `CC` = a
2-digit unit number. `BB` and `CC` are always stored left-padded to 2
characters with `0` — a floor entered as `M` stores as `0M`, a unit entered
as `6` stores as `06`. Unlike the numbering scheme in earlier drafts of this
document, this shape is fixed, not admin-configurable: the three parts are
separate columns, not a single opaque string, precisely because the app
needs to pad and validate each part individually.

| column | notes |
|---|---|
| id | |
| building_code | `char(1)`, nullable. Uppercased on write. Omitted from the composed code when null |
| floor_code | `char(2)`, always left-padded to 2 characters with `0` and uppercased on write (`App\Models\Unit`'s mutator) |
| unit_number | `char(2)`, always left-padded to 2 digits with `0` on write |
| deleted_at | soft delete, Superadmin-only (§13) |
| timestamps | |

**No stored `unit_code` column.** The full code is composed on demand via
`Unit::unitCode()` (`building_code . floor_code . unit_number`, building code
omitted when null) — never persisted, so there is nothing to keep in sync
when a part changes.

**Uniqueness is on the triple**, not any single column: `(building_code,
floor_code, unit_number)`. A plain composite unique constraint doesn't work
because Postgres treats `NULL <> NULL`, which would let two units with no
building code but the same floor/unit both exist — the unique index
COALESCEs `building_code` to `''` so "no building" is a real, deduplicated
value rather than a uniqueness loophole.

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
| is_primary_owner | boolean, default false. True on exactly one active relationship per unit — see below |
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

#### The primary unit owner

**[new]** Every unit has **exactly one primary unit owner** at all times: the
person accountable for the unit, and the one the administration contacts about
it. It is a property of a relationship, not of a person — the same human can be
primary owner of one unit and an ordinary co-owner of another.

- **`is_primary_owner` is true on exactly one active relationship per unit.**
  Enforced by a partial unique index over `unit_id` scoped to
  `is_primary_owner IS TRUE AND ended_at IS NULL`, so the database — not
  application discipline — is what makes two primaries impossible.
- **The primary owner's relationship `type` is always `owner`.** A tenant
  cannot be primary owner; a check constraint enforces the pair.
- **A unit cannot be created without one** (§5.4), and cannot be left without
  one. "At least one" is enforced in the application, inside the same
  transaction as whatever would otherwise remove it — Postgres has no clean
  way to express "this row must have a partner" without deferred constraints.
- **Transferring the role** flips `is_primary_owner` on two active
  relationship rows of the same unit in one transaction, with the unit row
  locked (§5.2's ordering rules apply). It is logged as
  `primary_owner_transferred`. This is a mutation of existing rows, in the
  same category as setting `ended_at` — it is not the immutability that §4
  guarantees for `id_cards`.

Data requirements differ by role, and this is deliberate — see `people` above.
Registering the four co-owners and tenants of a unit should not require
collecting a full profile and a photograph for each of them, but the one person
the administration will actually call must be reachable.

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
| template_id | nullable FK — which template version was active at issue time (provenance only, see §10). Nullable because Issuance (Phase 8) ships before template CRUD (Phase 12) exists to populate it |
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

**[changed — front/back added.]** A card is two-sided. The system's output is
a front and a back raster image per issued card; physical printing is a
separate, external workflow (dedicated card-printer software) and out of
scope for this table and for §10 — see there for what that boundary means.

| column | notes |
|---|---|
| id | |
| id_type | `owner` \| `tenant` \| `employee` — each type has its own template(s) |
| name | |
| background_path_front | private disk, not public webroot |
| background_path_back | private disk, not public webroot. Nullable — a template with no back side is technically possible, though the normal case has one |
| width_px, height_px | shared by both sides; configurable, not hard-coded |
| field_positions_front | jsonb: field name → x/y/width/height/font |
| field_positions_back | jsonb, nullable: same shape, back side |
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
`deletion_blocked`, `relationship_opened`, `relationship_closed`,
`primary_owner_transferred`, `id_issued`,
`id_revoked`, `id_expired`, `id_marked_lost`, `id_replaced`,
`control_number_retired`, `account_created`, `account_disabled`,
`account_enabled`, `role_changed`, `password_reset`,
`superadmin_created`, `superadmin_disabled`, `superadmin_password_reset`,
`superadmin_created_via_console`, `superadmin_password_reset_via_console`,
`superadmin_created_via_wizard`

Superadmin-tier actions are kept as distinct action names rather than folded
into the generic `role_changed` / `password_reset` values so the
highest-privilege events in the system stay trivially greppable. The same
reasoning gives the wizard's own account creation
`superadmin_created_via_wizard`, matching the `_via_console` pair exactly,
rather than the generic `superadmin_created` a GUI-created account gets.

**[corrected in Phase 4]** An earlier revision of this list named the
wizard's event `setup_wizard_completed` — a single, one-time "the wizard
finished" marker. That was never implemented and doesn't match §12's own
text, which says plainly that **both** account creations write `audit_logs`:
two rows, one per account, each with that account as `subject`, the same
shape every other creation event has. `setup_wizard_completed` is retired
before it was ever used; `superadmin_created_via_wizard` is what Phase 4
actually built.

`account_created`/`account_disabled`/`account_enabled` are the generic forms
for Admin and Reader accounts — the vocabulary above had Superadmin-specific
creation and disabling covered but no non-Superadmin equivalent to write
when the Users screen (§11) creates or disables an ordinary account.
`enable()` has no `superadmin_*` variant: re-activating an account is the
less sensitive direction, and nothing else in this document singles it out
the way disabling one does.

### `AuditLogger` — the one call-site shape (Phase 4)

**[new]** Every writer of `audit_logs` — every GUI action, every console
command, the setup wizard — goes through one service, `AuditLogger::log()`,
never a raw `AuditLog::create()`. This is the Phase 4 plan's own phrasing
("one call site shape"), and it is what makes retrofitting audit calls onto
finished Phase 3 code a coverage exercise rather than an invention exercise:
one shape to apply everywhere, not a new one per caller.

```php
$auditLogger->log(
    actor: $actor,              // authenticated User, or null
    action: 'role_changed',
    subject: $target,           // any Eloquent model
    previousValue: [...],       // nullable
    newValue: [...],            // nullable
    actingAs: null,             // required when actor is null
);
```

- **`user_role` comes from `$actor->role`, or from `actingAs` when `$actor`
  is null.** There is no default for the null-actor case — `log()` throws
  rather than silently pick `'console'` for something that might be the
  wizard, or vice versa. Today's two null-actor values are `'console'` and
  `'setup_wizard'`.
- **`occurred_at` and `ip_address` are derived, not accepted.** `ip_address`
  is `null` when `app()->runningInConsole()`, the request's real IP
  otherwise — a caller cannot forget this or get it backwards, because there
  is no parameter for it to get wrong.
- **A refused mutation writes nothing.** `disable()`/`changeRole()` throw
  `SuperadminInvariantException` *before* reaching the logger when the
  two-Superadmin invariant would be violated — an attempt that changed
  nothing is not an event. Where a refusal is itself worth recording, that
  is what `security_events`/`deletion_blocked` already exist for.
- **Passwords never appear in `previous_value`/`new_value`, on any path** —
  hashed or not. The console commands' `{"os_user", "hostname"}` provenance
  block (Phase 4 plan) sits alongside the account's username, never the
  password that was just generated and printed once.

### `security_events`
Failed/attempted-action trail — logged always. Same immutability and
read-access rules as `audit_logs`. Kept as a **separate table** so the business
trail stays clean and readable while this one absorbs higher-volume noise.

| column | notes |
|---|---|
| id | |
| occurred_at | |
| user_id | nullable — may be unauthenticated (failed login) |
| event_type | `login_failed`, `authorization_denied`, `qr_verify_miss`, `setup_wizard_blocked` |
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

### 5.2 Seven-slot cap: six occupants plus a reserved primary-owner slot

**[changed — was a flat six-card count.]** A unit has **seven slots**, and they
are not interchangeable: **one is permanently reserved for the primary unit
owner (§3), and six are available to everyone else.**

The reservation holds **whether or not the primary owner ever uses it.** This is
the whole point, and the thing that makes this a slot cap rather than a card
count:

- A **company** primary owner can hold no card (§3), and its slot stays empty
  and unusable. A corporately-owned unit therefore cards **six** occupants, not
  seven. The company is still accountable for the unit and still occupies its
  slot; being uncardable does not release it.
- A natural-person primary owner who simply **hasn't been issued a card yet**
  holds their slot the same way. Without the reservation, six occupants plus one
  more could be carded first and the person accountable for the unit would find
  no slot left for their own card.

So the rule that actually gets implemented is: **active owner/tenant cards
belonging to anyone other than the unit's primary owner must number six or
fewer.** The primary owner's own card, when it exists, sits in the reserved
slot and is never counted against the six.

The totals that follow, stated plainly because they are what an admin actually
asks:

| unit owned by | cards issuable | made up of |
|---|---|---|
| a **natural person whose card names this unit** | **7** | the primary owner, plus 6 persons they authorize |
| a **natural person whose card names another unit** | **6** | 6 authorized persons; the reserved slot is held but unfilled |
| a **company** | **6** | 6 authorized persons; the company's own slot is held but unusable |

**Six authorized persons in every case.** The seventh card exists only when the
primary owner personally carries a card *for this unit*, which is narrower than
it first appears: §5.1 gives a person **one** owner/tenant card in total, naming
one unit. An owner of three units holds a reserved slot in all three and can
fill only one of them, so their other two units card six — behaving exactly like
company-owned units, and for the same reason. The slot is reserved whether or
not its holder can use it; being unable to use it is not the same as not having
it.

Applies only to `type IN ('owner','tenant')`. Employee IDs never count toward
it. An owner of three units consumes the relevant slot in the unit their card
names; their other units are untouched.

**Chosen approach:** pessimistic row lock on the `Unit` (or, for transfers,
**both** units involved) for the duration of the transaction, combined with
optimistic unique-constraint + retry for control-number generation (a separate,
global-uniqueness problem that a per-unit lock doesn't address).

```php
DB::transaction(function () use ($person, $unit, $type) {
    $lockedUnit = Unit::where('id', $unit->id)->lockForUpdate()->first();

    // Guaranteed non-null by the §3 invariant: every unit has exactly one.
    $primaryOwnerPersonId = $lockedUnit->primaryOwnerPersonId();

    // The primary owner is issued into their own reserved slot and is never
    // counted against the six. Everyone else competes for those six.
    if ($person->id !== $primaryOwnerPersonId) {
        $occupantCount = IdCard::where('unit_id', $lockedUnit->id)
            ->where('status', 'active')
            ->whereIn('type', ['owner', 'tenant'])
            ->where('person_id', '!=', $primaryOwnerPersonId)
            ->count();

        if ($occupantCount >= 6) {
            throw new UnitAtCapacityException($lockedUnit);
        }
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
same transaction — otherwise a unit sitting at exactly 6/6 occupants would wrongly reject
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

### 5.4 Unit creation and primary-owner transfer

**[new]** A unit and its primary owner are created together, in one
transaction. Creating a unit opens a form that requires both: the unit code
(§3), and a primary owner who is either selected from existing `people` or
created inline at the **contactable** tier (name, mobile number, email). A unit
never exists, even briefly, without a primary owner — there is no "add the
owner later" path, because the state it would create is exactly the one the
reconciliation dashboard cannot help with: a unit nobody is accountable for.

**Two different operations move the role**, and conflating them is the mistake
this section exists to prevent:

1. **Promotion** — the role moves between two parties who both already hold
   active `owner` relationships on the unit. A co-owner becomes the primary; the
   outgoing one remains a co-owner. No relationship opens or closes, and no card
   is affected.
2. **Ownership transfer** — the outgoing party ceases to own the unit entirely
   and a new party begins. This opens the incoming owner's relationship, moves
   the role, and closes the outgoing owner's relationship — which cascades to
   their cards under §5.3, because they no longer own the unit.

Both run in **one transaction with the unit row locked**, and both write
`primary_owner_transferred` to `audit_logs` naming the outgoing party, the
incoming party, and which of the two operations it was. The incoming party must
already satisfy the contactable tier; the operation refuses otherwise, with an
error naming the missing fields rather than a generic validation failure.

#### Ordering inside the transaction: retire, then set

**[decided — an earlier draft proposed the reverse.]** The role is cleared from
the outgoing relationship **before** it is set on the incoming one. Never the
other way around, and the reason is not stylistic:

- **The partial unique index forbids the overlap.** `is_primary_owner` is
  enforced by `CREATE UNIQUE INDEX ... WHERE is_primary_owner IS TRUE AND
  ended_at IS NULL`. Postgres can defer a unique *constraint*
  (`DEFERRABLE INITIALLY DEFERRED`) but has no partial unique constraint, and a
  unique *index* is never deferrable — it is checked at statement end,
  unconditionally. Setting the incoming flag first would violate the index
  immediately and abort the transaction. **Create-then-retire and the index
  cannot coexist**; one of them has to go, and the index is what makes two
  primary owners structurally impossible rather than merely unlikely.
- **The window it would protect against does not exist.** The concern behind
  create-then-retire is leaving the unit briefly ownerless. Inside a single
  transaction there is no "briefly": no other session observes either
  intermediate state, and a crash at any point rolls the whole thing back. The
  unit is never seen without a primary owner, and never seen with two.
- **The "at least one" check runs at the end of the transaction**, once, against
  the final state — not after each statement. A momentary zero between the two
  writes is invisible to it by construction.

This is the same shape as **retire-then-check** for cards (§5.2): retire the old
fact first, then establish the new one, inside one transaction. The system
already works this way; primary ownership is not an exception to it.

**On recovering a unit with two primary owners:** that state cannot arise from
this flow, so no recovery UI is built for it *as a crash-recovery measure* —
building one would imply the transaction guarantee is untrusted, and a
half-trusted invariant is worse than either alternative. What is built instead
is a cheap integrity canary on the reconciliation dashboard (§14, Query D) that
surfaces any unit whose primary-owner count is not exactly one. It exists to
catch a **bug or a hand-edited database**, not a crash, and it is expected to be
permanently empty.

**The role itself never cascades to cards.** Primary ownership is an
accountability record, not an entitlement: moving the flag neither issues nor
expires anything. In a **promotion**, nothing happens to any card at all — the
outgoing party keeps whatever their still-open owner relationship entitles them
to. In an **ownership transfer**, cards do change, but because the outgoing
party's *relationship closes* (§5.3), not because the role moved. Keeping that
distinction straight is what stops a promotion from silently expiring a
co-owner's card.

**The primary owner may be a company** (§3), and corporate ownership is common
enough that the create-unit form offers both kinds directly rather than hiding
the company case behind a secondary flow. **A company primary owner still
occupies its reserved slot** under §5.2 even though it can hold no card, so a
corporately-owned unit cards six occupants — exactly as many as any other unit.
Occupant capacity does not depend on what kind of party owns the unit.

**Slots re-attribute when the role moves.** The incoming primary owner's
existing card, if they had one, becomes the reserved card by virtue of who they
now are; the outgoing party's card becomes an ordinary card counted against the
six from that moment. The cases that follow, each checked inside the same
transaction:

- **Promotion into a full unit.** Six cards already counted against the six,
  and the promotion would push the outgoing owner's card back into that count
  as a seventh. Refused with `UnitAtCapacityException`, surfaced on the
  transfer screen rather than at issuance, naming the cards involved.
- **Natural person → company.** The incoming company cannot hold a card, so the
  reserved slot empties and the outgoing person's card now counts against the
  six. If that makes seven, the transfer is refused — the admin revokes a card
  first. A unit does not silently exceed its capacity because its owner changed
  kind.
- **Company → natural person.** The reserved slot becomes usable. Capacity
  cannot be exceeded by this direction; if the incoming person already held one
  of the six cards, it moves into the reserved slot and frees one of the six.
- **The incoming party already holds a tenancy on the same unit** — a tenant
  buying the unit. Their new `owner` relationship makes owner outrank tenant
  (§5.1), so their card's type is now wrong and a reissue with
  `replacement_reason = 'type_change'` is required. The transfer names it in
  the confirmation, in the same shape §5.3 uses.
- **The outgoing party is the unit's only owner and is not being replaced by
  an owner** — refused. Ownership transfer requires an incoming owner; there
  is no path that ends with the unit unowned.

### 5.5 Reconciliation

See §14. The dashboard exists because the system deliberately refuses to derive
state from dates, which means divergence between record and reality is expected
rather than exceptional, and needs to be visible.

---

## 6. ID Number Generation

- **User ID Number** (party-level, permanent): random 8-digit, DB-unique,
  retry-on-collision. Randomized deliberately — sequential numbering would
  reveal roughly how many people are in the system and their join order.
  Minted for every `people` row regardless of `entity_type` (§3): a company's
  is never printed, but the column is `NOT NULL` for all parties so this
  generator never has to ask what kind of row it is serving.
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
cron entry driving application logic in either LXC (the nightly backup cron
described in §12 is the sole exception, and only moves bytes to disk — it
never touches `id_cards`, `audit_logs`, or any status transition).

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

**`legal_name` is not on this list and never joins it.** A company holds no
cards (§3), so renaming one triggers nothing: there is no active card for the
reissue flow below to find, and the flow must not be written in a way that
assumes there might be. This is the one name column in the system that is a
plain correction.

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

Retire-then-check ordering (§5.2) applies, or a unit at 6/6 occupants rejects
its own occupant's reissue. Multi-unit reissues use the fixed ascending-unit-ID lock
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

**The system's output is two raster images per issued card — front and
back — nothing more.** **[new]** Printing is a separate, external workflow:
staff feed the rendered images into dedicated card-printer software (the kind
that produces its own proprietary project files, e.g. a card-design tool
bundled with a CR80 card printer). This system has no printer integration, no
print-driver code, and no knowledge of what happens to the image after it is
generated — that boundary is deliberate, not a placeholder for a future
phase. It is also why a proprietary card-design project file (whatever binary
format the printer software's own designer produces) is never accepted as a
`templates` upload: the two `background_path_*` columns hold a plain raster
image (PNG), converted from that design file by whoever operates the
printer software, same as any other image asset in this system.

- Server-side compositing (Intervention Image/Imagick) chosen over
  headless-browser rendering — lighter operational footprint, no browser-engine
  dependency to patch, appropriate for occasional single-card rendering rather
  than bulk batch output.
- Rendering produces one image per side: the front composited from
  `background_path_front` + `field_positions_front`, the back (when present)
  from `background_path_back` + `field_positions_back`.
- Names auto-shrink to fit their field box.
- Photos are always placed as pre-cropped 1:1 images (cropping already happened
  at upload time — see §9), and only ever appear on the side whose
  `field_positions` names a `photo` field — normally the front.
- Dimensions configurable per template, not hard-coded, and shared by both
  sides of a given template.

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
  before any other data exists, through the **first-run setup wizard** (§12).
  From that point the tier is self-managing through the GUI.
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
  automatic lockout, no cooldown timer. Every failed attempt writes to
  `security_events` (§10 — its `event_type` CHECK has no `login_success`
  value; that table is a failed/attempted-action trail, not a full login
  history). A successful login updates `users.last_login_at`; full
  attribution goes through the Phase 4 `AuditLogger`, the same as any other
  actor action.

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
- DHCP with a router-side reservation for a predictable IP. Served by IP only —
  no internal DNS record, no domain name. A LAN this size (2-4 admins, a
  handful of readers) doesn't carry its weight: it's one more thing to
  configure on the router and one more thing that can drift from the actual
  address, for a name nobody but the admins ever needs to type.
- Laravel's `TrustProxies` middleware configured for the reverse proxy so
  `APP_URL` and generated URLs are correct behind upstream TLS termination.
- **No scheduler, no queue worker, no cron entry for application logic.**
  Nothing that touches `id_cards`, `audit_logs`, or any business rule runs
  unattended. If a future feature appears to need one, that is a signal to
  re-read §7.
- **Backups are the one deliberate exception to the rule above**: a nightly
  cron entry in each LXC runs `pg_dump -Fc` + `storage/app/private` (photos,
  templates) + `.env`, stored off the LXC itself. It moves bytes to disk and
  touches no application state, so it doesn't reintroduce the unattended
  business logic §7 rules out. Restoration should be **tested**, not just
  performed — a backup that has never been restored isn't verified.

### Bootstrap: the first-run setup wizard (Phase 3)

**[changed — was console-only bootstrap.]** The system is bootstrapped from the
browser, not the shell. On the **first access of the web GUI**, the app presents
a setup wizard that requires the operator to create **two Superadmin accounts**
before anything else in the system is reachable. This is the normal, documented
path to a working installation: an admin who has just run the Proxmox deploy
opens the app by IP and is walked through it, with no SSH session required.

The wizard is the one place in the system where an unauthenticated HTTP request
creates a privileged account, so it is fenced on every side:

- **Precondition is zero active Superadmins.** The wizard route is reachable
  only while the system holds no active Superadmin. Once the second account is
  created it is permanently unreachable — not hidden, not password-gated:
  the route itself refuses.
- **It is all-or-nothing.** Both accounts are created in one transaction. A
  wizard that could stop after one account would hand the system straight into
  the one-member-tier state §11's invariant exists to prevent.
- **Nothing else is reachable until it completes.** Every other route, login
  included, redirects to the wizard while the precondition holds. There is no
  window where a half-configured system serves an ordinary page.
- **Passwords are set by the operator**, in the browser, over the LAN, and
  confirmed once. Because the operator chooses them directly, these two accounts
  do **not** get `must_change_password` — there is nothing to rotate away from.
- **Both creations write `audit_logs`** with `user_role = 'console'`-equivalent
  provenance: `user_id = null`, `user_role = 'setup_wizard'`, and the request IP
  recorded, so the trail shows which machine on the LAN bootstrapped the system.
  **Landed in Phase 4, not Phase 3**, alongside the console commands' own audit
  rows: Phase 3 has no `AuditLogger`, and writing raw rows here first would
  create exactly the second call-site shape Phase 4 exists to unify.
- **Every attempt to reach the wizard after it has closed writes
  `security_events`** with `event_type = 'setup_wizard_blocked'` and the request
  IP. This is a **fourth value on that table's CHECK constraint**, added by a
  forward-only migration in Phase 3 — it is not folded into
  `authorization_denied`, for the same reason §3 keeps Superadmin actions as
  distinct audit action names: someone probing the bootstrap route on a live
  system is a signal worth finding without filtering through every ordinary
  permission denial in the system.

**Residual risk, stated explicitly:** between deploy and first access, anyone who
can reach the app's IP on the LAN can claim the system by completing the wizard
first. This is the standard trade for browser-based bootstrap and it is
acceptable *only* because the system is LAN/VPN-only (§1) and never public. The
operational rule that follows from it: **complete the wizard immediately after
deploying, not later.** That instruction belongs in the operations manual, not
only here.

**No seeder ships a default account.** A `DatabaseSeeder` with a hardcoded
`admin`/`password` is the single most common way a system like this is
compromised, and on a LAN it will survive for years unnoticed. The wizard is not
a seeder: it creates nothing on its own, and it only ever runs once, in response
to a human at a browser.

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
  from the web tier. The first-run wizard does not violate this: it calls the
  same account-creation *service* the command wraps, never the command itself.
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
(`ended_at IS NULL`) or any active card — **with one carve-out: the unit's own
primary-owner relationship.** The error names them. The admin ends the
relationships and revokes the cards, then deletes. A deleted unit therefore has
no live dependents by construction, and §5's capacity count never sees it.

**Why the primary owner is the exception.** §5.4 refuses to leave a live unit
without a primary owner, so the last relationship standing is one the admin
cannot close beforehand: closing it is forbidden, and leaving it open blocks the
delete. The two rules together make deletion unreachable. The resolution is to
**scope the "at least one primary owner" invariant to live units**
(`deleted_at IS NULL`) and let the deletion transaction close that final
relationship as its last act:

1. The admin closes every other relationship and revokes or expires every card,
   through the ordinary guarded paths. Each cascades under §5.3 as usual.
2. Deletion is then attempted. It refuses if *anything* other than the
   primary-owner relationship is still live — the guard is unchanged for
   every other dependent.
3. In one transaction: the primary-owner relationship is closed (`ended_at`
   set, `is_primary_owner` cleared), then the unit is soft-deleted.

**This is not a cascade, and rule 9 stands.** A cascade would close dependents
the admin never looked at; this closes exactly one relationship, the one the
admin is structurally forbidden from closing themselves, at the moment the unit
stops being live. No ownerless window exists at any point: inside the
transaction nothing observes the intermediate state, and on the other side the
unit is deleted and the invariant no longer applies to it.

Both the closure and the deletion are audit-logged, so the trail shows the
relationship ending as part of the deletion rather than appearing to vanish.

**Deleting a `person`** is refused while they hold any active relationship or
active card. Same resolution path. This matters most for verification: a
soft-deleted person must never have a scannable card, and the guard makes that
structurally true rather than a rule to remember.

**Deleting a primary unit owner is refused separately, and says so
differently.** **[new]** A person who is the primary owner (§3) of any unit is
blocked even once their cards and other relationships are dealt with, and the
generic "end the relationships first" instruction is the wrong advice here: it
describes a path that would leave the unit with nobody accountable for it, which
§5.4 forbids. The block therefore names each affected unit and states the actual
remedy — **transfer the primary-owner role to another person on that unit
first** — with a link to the transfer screen per unit. Only once no unit names
them as primary owner does the ordinary guard above apply.

This is one refusal with a specific message, not a second deletion mechanism.
It writes `deletion_blocked` like any other blocked deletion, with the blocking
units in `detail`.

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
closed. Relationships behave the same way: **nothing reopens on its own.**

**Restoring a unit requires designating a primary owner**, in the same
transaction as the restore. **[new]** A deleted unit has no active
relationships by construction — the deletion transaction closed the last one
itself (above) — so restoring the row alone would produce a live unit with
nobody accountable for it: the state §5.4 forbids, manufactured by the system
rather than by an admin. The restore screen therefore asks the same question
unit creation asks, and refuses the same way: a primary owner selected from
existing `people` or created inline at the **contactable** tier.

The relationship that deletion closed is deliberately **not** resurrected.
Restoring a unit months later would otherwise reinstate a lease that has since
ended in fact, possibly naming a person who has themselves been soft-deleted —
and "closed things stay closed" is the same rule that governs the cards.
Whoever is accountable for the unit now is a question for the admin performing
the restore, not an inference from who was accountable before.

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

**Query B — Persons who could be carded today and are not**
Per person, not per relationship. Catches never-issued cards, and catches
multi-unit owners whose card was expired by a §5.3 cascade and not yet replaced.
A multi-unit owner correctly holding one card does not appear.

**[changed — was "persons with an active relationship and no active card."]**
That definition predates both companies and the completeness tiers (§3), and
under it two permanent, unresolvable states flood the list:

- **Companies**, which hold active owner relationships and can never be issued
  a card by kind. The row could never be actioned — the action text's "issue a
  card" is not available for them at all.
- **Minimal-tier people** — the co-owners and tenants recorded with a name and
  nothing else. Recording someone who was never meant to carry a card is a
  normal, permanent act, not an omission.

At this system's scale that is plausibly hundreds of unresolvable rows hiding a
handful of real ones, which defeats the "empty is the normal state" contract
below and, with it, the dashboard.

**The query therefore lists only people for whom issuance would succeed right
now**: `entity_type = 'natural'`, cardable tier (§3 — photo present), an active
owner/tenant relationship, and no active card. Everyone else is either not
entitled or not ready, and neither is a divergence.

*The trade, stated so it isn't discovered later:* **the photo is what puts
someone on this list.** A resident who should be carded but whose photo has not
been collected is invisible here until it is. The cascade case (§5.3) is
unaffected — a person whose card was expired necessarily has a photo already —
and the profile backlog is answered by the People index instead, see below.

*Action: issue the card. There is no "confirm the omission is intentional" —
under this definition every row is a real gap.*

**Query C — Units with all six occupant slots taken**
Informational. Surfaces the constraint before an admin hits
`UnitAtCapacityException` mid-transaction.

**Query D — Units whose active primary-owner count is not exactly one**
**[new]** An **integrity canary**, unlike A–C. Where those three surface
divergence between the record and reality — an expected, human condition — this
one surfaces divergence between the record and *itself*. It should be
permanently empty: §5.4's transaction and the partial unique index together
make both failure states unreachable through the application.

It is here for what those guarantees do not cover: a hand-edited database, a
restore from a backup taken mid-migration, or a bug in a future code path that
writes relationships outside the sanctioned flow. Two primary owners cannot
survive the index, so in practice this catches **zero**.

*Action: for a unit with none, designate one (§5.4). For a unit with several —
which should be impossible — the screen names each candidate with its
relationship's `start_date` and lets a Superadmin choose which is correct,
retiring the rest. **Non-empty here means something is wrong with the system,
not with the data entry**, and it is worth investigating how the row got there
before clearing it.*

### The profile backlog is not a dashboard query

**[new]** "Who still owes us a photo?" is a real question and it is deliberately
**not** answered here. It is a saved filter on the **People index** — active
relationship, no photo — and it lives there because a browsing surface is where
a permanently long list is normal and harmless.

The reasoning matters, because putting it on this dashboard is the obvious move
and it is wrong: **below-cardable is a legitimate end state, not a backlog.** A
co-owner recorded with only a name is complete as they are. A dashboard query
listing everyone below cardable tier would therefore be permanently populated
with people nobody owes anything — reintroducing on this screen exactly the
noise just removed from Query B, one row down.

Nothing in the data distinguishes "recorded, done" from "started, unfinished"
without an explicit intent flag, which was considered and rejected: it is a
field on every relationship form that drifts out of step with reality the first
time someone forgets it. A filter the admin opens when running a photo drive
needs no such field and cannot go stale.

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
- **High-DPI rendering.** Preview-quality raster output only for the initial
  build; a higher-resolution render is a legitimate future enhancement.
  **Direct printer integration is not deferred — it is out of scope
  permanently, by design (§10).** The system's job ends at producing the
  front/back raster images; printing is always a separate, external workflow.
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
- **"Issue ID" GUI screen.** Deferred from Phase 8 to Phase 12 (explicit
  user decision, 2026-09-07), so it lands together with template CRUD and
  can offer a real `template_id` at issuance instead of shipping once
  against none. `IssuanceManager` and `IdCardPolicy::issueEmployee()` —
  the service layer the screen will call — are fully built and tested as
  of Phase 8; only the screen and its own feature tests are deferred.

---

## 16. Original Spec Edge Cases — Resolution Map

| Edge case | Resolved by |
|---|---|
| Tenant → Owner conversion | §5 — retire-then-check, same-unit |
| Unit-to-unit transfer | §5 — dual-unit lock, fixed order |
| Historical IDs preserved | §4 — status transitions only, no overwrites |
| Lost ID replaced | §4/§5 — `replacement_reason = 'lost'`, `replaces_id_card_id` chain |
| Revoked/expired ID scanned | §8 — verify page always shows true current status |
| Unit with all 6 occupant slots taken | §5.2 — locked count-then-insert against the six, primary owner's slot excluded |
| **Company-owned unit: does it card 6 or 7 occupants?** | §5.2 — six. The reserved slot is held regardless of whether its holder can use it |
| Two admins racing an 8th ID | §5 — `lockForUpdate()` serializes per-unit |
| Employee who is also a resident | Two independent `id_cards` rows; employee row never touches the 7-cap |
| **Unit created with nobody accountable for it** | §5.4 — unit and primary owner are created in one transaction; there is no "add later" path |
| **Primary unit owner deleted, unit orphaned** | §13 — deletion refused until the role is transferred to another active owner |
| **Co-owner or tenant recorded with only a name** | §3 `people` — minimal tier; the photo and contact details are demanded by card issuance, not by existence |
| **Unit owned by a corporation** | §3 `people` — `entity_type = 'company'`, one `legal_name`, contactable tier, never cardable |
| **Attempt to issue a card to a company** | §3 — refused by kind, with an error naming the reason, not a missing-photo validation failure |
| **Company renamed** | §9.3 — a plain correction; `legal_name` is not a printed field and no card exists to reissue |
| **Entity A sells Unit Z to Entity B** | §5.4 — ownership transfer: open B's relationship, retire-then-set the role, close A's relationship (cascading to A's cards via §5.3), one transaction, unit locked |
| **Co-owner promoted to primary without a sale** | §5.4 — promotion: flag moves only, no relationship opens or closes, no card touched |
| **Crash mid-transfer leaves two primary owners** | Cannot occur — one transaction, and the partial unique index rejects the overlap at statement end. §14 Query D is the canary for a hand-edited database, not for this |
| **Unit undeletable because its last relationship is the primary owner's** | §13 — the invariant is scoped to live units; the deletion transaction closes that one relationship as its final act |
| **Company owner permanently listed as missing a card** | §14 Query B — scoped to those who could be carded today; companies and minimal-tier people are excluded, not flagged |
| **"Who still owes us a photo?"** | §14 — a People-index filter, deliberately not a dashboard query: below-cardable is an end state, so the list is permanently long |
| **Restored unit has nobody accountable for it** | §13 — restore requires designating a primary owner in the same transaction; the relationship deletion closed is not resurrected |
| **Transfer to a company that would exceed capacity** | §5.4 — the reserved slot empties and the outgoing owner's card joins the six; refused with `UnitAtCapacityException` if that makes seven |
| **Tenant buys the unit they rent** | §5.4 / §5.1 — owner outranks tenant, so the transfer names a `type_change` reissue in its confirmation |
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
