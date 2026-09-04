# Implementation Plan
## Condominium ID Generation, Management & QR Verification System

Companion to `docs/architecture.md` (Revision 2). That document says **what** the
system is and why; this one says **in what order to build it** and what "done"
means at each step.

**This is the production codebase from commit one.** There is no rewrite
planned. Prototype quality means "features arrive incrementally," not "code
gets thrown away."

---

## Ground rules

**Migrations are forward-only from Phase 2.** Until the first deploy to the LXC,
migrations may be edited and re-run freely. After Phase 2, every schema change
is a new migration — never an edit to a shipped one. Note the date you cross
this line in the repo.

**One branch per phase**, merged when its definition of done is met. A phase
that half-lands leaves the next one building on sand.

**Tests are written inside the phase, not after it.** Each phase's definition of
done names the tests that must exist. Pest, feature tests over unit tests
wherever a policy or transaction is involved.

**No phase is complete while a `TODO` remains in its own scope.** Deferred work
belongs in §15 of the architecture document, not in a comment.

**The architecture document wins.** If implementation reveals it to be wrong,
change the document in the same PR — do not let code and document diverge
silently. A code comment explaining a deviation is a bug report, not a decision.

---

## Phase 0 — Repository & local environment

**Goal:** a running Laravel app on Postgres, locally, with tooling in place.

- [ ] Laravel (current stable), PHP (current stable)
- [ ] PostgreSQL locally — Herd, Sail, or Docker Compose. Match the LXC's major
      version exactly
- [ ] Pest, Laravel Pint, Larastan (level 5+)
- [ ] `CLAUDE.md` at repo root — the invariants file, committed before any
      feature code
- [ ] `docs/architecture.md` and `docs/implementation-plan.md` committed
- [ ] `.env.example` complete, with `DB_CONNECTION=pgsql`
- [ ] GitHub Actions: Pint, Larastan, Pest on push

**Done when:** `php artisan migrate` runs clean against local Postgres and CI is
green on an empty test suite.

**Trap:** don't install Breeze yet. Phase 3 strips half of it, and stripping is
easier before Livewire components accumulate around it.

---

## Phase 1 — Full schema

**Goal:** every table, constraint, model, and factory from architecture §3.

- [ ] Migrations for `users`, `people`, `units`, `person_unit_relationships`,
      `id_cards`, `templates`, `audit_logs`, `security_events`
- [ ] Named unique constraints: `uq_people_user_id_number`,
      `uq_id_cards_control_number`
- [ ] Check constraints via `DB::statement` — Laravel has no native builder for
      these. Cover: `gender`, `id_cards.type`, `id_cards.status`,
      `id_cards.replacement_reason`, `users.role`,
      `person_unit_relationships.type`, and `^[0-9]{8}$` on both number columns
- [ ] `jsonb` for `field_positions`, `previous_value`, `new_value`, `detail`
- [ ] Eloquent models with relationships, casts, and `SoftDeletes` on `people`,
      `units`, `users` — **not** on `id_cards`
- [ ] Factories for every model
- [ ] `IdCard::isValid()` as a computed accessor

**Done when:** migrations run clean, factories produce valid rows, and a test
asserts each check constraint rejects an invalid value.

**Traps:**
- `id_cards` has **no** `deleted_at` and **no** validity boolean. If either
  appears, something was misread.
- `person_unit_relationships` has **two** date columns with different jobs.
  `ended_at` is activity; `contract_end_date` is paperwork.
- `char(8)`, not integer, not `varchar`.

---

## Phase 2 — LXC mirror & deploy path

**Goal:** the schema-only app deploys to Proxmox and serves a page. Proving the
pipeline now means every later phase is a routine deploy.

- [ ] Two LXCs provisioned: app, and Postgres
- [ ] Postgres tuned minimally; app DB user created with least privilege
- [ ] Caddy or Nginx in front, TLS terminated there
- [ ] `TrustProxies` configured
- [ ] Internal DNS name pointed at the proxy; DHCP reservation for the app LXC
- [ ] Deploy script or GitHub Action: pull, `composer install --no-dev`,
      `migrate --force`, cache config/routes/views
- [ ] `pg_dump -Fc` backup job + `storage/app/private` + `.env`, stored off the
      LXC
- [ ] **One restore performed and verified**, not just configured

**Done when:** a push to `main` reaches the LXC and `/up` responds over TLS via
the internal DNS name, and a restored backup has been opened and checked.

**From here, migrations are forward-only.**

**Traps:** no Docker (architecture §12). No scheduler entry in crontab — the
only cron on this box is the backup job.

---

## Phase 3 — Authentication & roles

**Goal:** login works, roles exist, the Superadmin tier is bootstrappable.

- [ ] Breeze (Livewire stack) installed
- [ ] **Strip** password-reset routes, `password_reset_tokens` migration,
      `CanResetPassword`, and email verification. They key on a column that
      doesn't exist and will fail to boot
- [ ] Login switched from email to `username`
- [ ] `must_change_password` middleware forcing rotation before any other route
- [ ] `is_active` checked at login
- [ ] Role constants and a `Role` enum-like helper; policy skeleton registered
      for every model
- [ ] `id:superadmin-create`, `id:superadmin-reset`, `id:superadmin-list`
- [ ] Two-active-Superadmin invariant, enforced inside a transaction with the
      Superadmin rows locked
- [ ] Superadmin cannot act on their own account for role change or disable
- [ ] Failed logins write `security_events`; 3 consecutive failures show the
      "contact a Superadmin" prompt with no lockout

**Done when:** two Superadmins exist via console only, a third can be created in
the GUI, and tests prove the invariant holds against a concurrent attempt to
disable both.

**Traps:**
- Passwords are never command arguments — generated and printed once.
- No seeder creates a default account. A dev-only seeder is permitted but must
  `abort()` unless `APP_ENV === 'local'`.
- No Sanctum, no API routes.

---

## Phase 4 — Audit & security event infrastructure

**Goal:** logging exists before anything worth logging does.

- [ ] `AuditLogger` service: one call site shape, takes actor, action, subject,
      previous/new values
- [ ] Model-level immutability guard on `AuditLog` and `SecurityEvent` — boot
      hooks throwing on `updating` and `deleting`
- [ ] `user_id` nullable; console writes use `user_role = 'console'` with
      `{"os_user","hostname"}` in `new_value`
- [ ] Read-only audit viewer, Superadmin/Admin, with filters by actor, action,
      date, subject. Uses `withTrashed()` to resolve deleted subjects
- [ ] Console commands from Phase 3 retrofitted to write audit rows

**Done when:** a test proves `AuditLog::first()->update()` throws, and every
console command produces a correctly-shaped row.

**Why this phase sits here:** retrofitting audit calls across finished
controllers is precisely where coverage gaps appear. Every later phase writes
its own audit entries as part of its definition of done.

---

## Phase 5 — People & photos

**Goal:** person records and the photo pipeline.

- [ ] People CRUD, Superadmin/Admin, all fields from §3
- [ ] Photo upload: validate type and MIME sniff, ≤1MB, crop 1:1 at upload,
      compress, UUID filename, private disk
- [ ] Single authenticated serving route with a policy check on every request
- [ ] Photo replacement unlinks the old file
- [ ] Search and index views
- [ ] Audit: `person_created`, `person_data_updated`, `photo_updated`

**Done when:** a photo is unreachable without a session, the private disk has no
symlink, and a policy test covers each role.

**Traps:** no public disk, no `storage:link` for these, no signed URLs. The
Reader 60-second rule arrives in Phase 9 — until then Readers simply cannot
fetch photos at all.

---

## Phase 6 — Units & relationships

**Goal:** units, and the relationship model that everything downstream reads.

- [ ] Unit CRUD with a configurable numbering scheme
- [ ] Open a relationship: person, unit, type, `start_date`, optional
      `contract_end_date`
- [ ] Close a relationship: sets `ended_at`. **The card cascade arrives in
      Phase 8** — leave a clearly-named seam, not a silent gap
- [ ] Relationship history view per person and per unit
- [ ] Audit: `unit_created`, `relationship_opened`, `relationship_closed`

**Done when:** `whereNull('ended_at')` is the only activity test in the codebase,
verified by grep, and a person can hold several concurrent relationships.

**Trap:** nothing anywhere compares `contract_end_date` to today. That comparison
exists in exactly one place, and it arrives in Phase 10.

---

## Phase 7 — Issuance

The hardest phase. Do not start it with Phases 1–6 partially done.

**Goal:** cards can be issued, correctly, under concurrency.

- [ ] `randomEightDigits()` using `random_int`, zero-padded
- [ ] Typed retry: `UniqueConstraintViolationException` **and** constraint-name
      match, 5 attempts, zero delay
- [ ] Type/unit resolution (§5.1): owner outranks tenant, earliest
      `start_date`, ties by lowest `unit_id`, admin override within type
- [ ] Six-cap enforcement with `lockForUpdate()` on the unit
- [ ] `UnitAtCapacityException` surfaced as a usable error, not a 500
- [ ] Employee issuance, Superadmin-only, bypassing the cap
- [ ] Audit: `id_issued`

**Done when:** a concurrency test proves two simultaneous issuances against a
unit at 5/6 produce exactly one card and one clean rejection.

**Traps:**
- Employee cards never count toward the cap.
- One card per person, not one per relationship.
- The chosen unit is stored, never recomputed on read.

---

## Phase 8 — Lifecycle, cascade & mandatory reissue

**Goal:** every status transition in §4, plus the two flows that chain them.

- [ ] `markLost`, `revoke`, `expire` — all requiring actor and reason
- [ ] Replacement issuance setting `replaces_id_card_id` and
      `replacement_reason`
- [ ] Retire-then-check ordering inside every replacement transaction
- [ ] **Relationship closure cascade** (§5.3): closing a relationship expires
      matching active owner/tenant cards in the same transaction; confirmation
      screen names them first; offers reissue where another relationship remains
- [ ] **Mandatory reissue** (§9.3): changing a printed field names every
      affected card, then confirm-or-cancel — no decline path — with data change
      and reissues in one transaction
- [ ] Fixed ascending-`unit_id` lock ordering for any multi-unit transaction
- [ ] Audit: `id_revoked`, `id_expired`, `id_marked_lost`, `id_replaced`

**Done when:** closing a relationship for a two-unit owner expires one card and
surfaces the reissue, and editing a name with three active cards replaces all
three atomically or none.

**Traps:**
- Cancelling abandons the edit. There is no "save without reissuing."
- The printed-field list is closed: photo, name fields, position, department.
  Editing a birthdate must not trigger anything.

---

## Phase 9 — QR & verification

**Goal:** the guardhouse flow.

- [ ] QR generation encoding the plaintext control number, 8 chars
- [ ] Print/preview surface for the QR
- [ ] Scan page using `html5-qrcode`, Reader-accessible
- [ ] Verify result: current status, photo, identifying info. Non-active status
      displayed unmistakably
- [ ] Manual control-number entry with zero-padding applied before lookup
- [ ] Reader 60-second session window for photo access, recorded server-side at
      scan time
- [ ] `qr_verify_miss` and `authorization_denied` written to `security_events`

**Done when:** a Reader can scan and see a photo, and the same Reader hitting
that photo URL 61 seconds later gets a 403 and a logged event.

**Traps:** no encryption, no token column. Test the QR at actual print size on
the actual scanning hardware before this phase closes — it is the whole reason
§8 changed.

---

## Phase 10 — Reconciliation dashboard

**Goal:** divergence becomes visible.

- [ ] Query A — leases past `contract_end_date`, still open. **The only
      date-to-today comparison in the system**
- [ ] Query B — persons with an active relationship and no active card,
      per person
- [ ] Query C — units at the six-card cap
- [ ] Each row links to the screen that resolves it. No bulk actions, no
      counters, no badges
- [ ] Viewing writes nothing to `audit_logs`

**Done when:** all three queries are correct against a seeded fixture containing
each divergence, and a multi-unit owner holding one valid card does **not**
appear in Query B.

---

## Phase 11 — Templates & rendering

Blocked on designer input. Build the CRUD; leave rendering behind a seam.

- [ ] Template CRUD, Superadmin-only, background upload to the private disk
- [ ] `field_positions` editing as numeric values — no canvas
- [ ] `is_active` per `id_type`
- [ ] Server-side compositing via Intervention Image
- [ ] Name auto-shrink to fit the field box
- [ ] Preview-quality output only

**Trap:** no historical-reprint feature. `template_id` is provenance.

---

## Phase 12 — Security review

- [ ] Policy coverage audit: every route, every role, tested
- [ ] Confirm no `Artisan::call()` is reachable from HTTP
- [ ] Confirm no public disk, symlink, or unauthenticated file route exists
- [ ] Confirm no scheduler, queue worker, or cron beyond the backup job
- [ ] **Approach B audit immutability**: revoke `UPDATE`/`DELETE` grants on
      `audit_logs` and `security_events` from the app's DB user, plus triggers
- [ ] Review `security_events` volume and retention
- [ ] Dependency audit

---

## Phase 13 — Production cutover

- [ ] Real data load or entry
- [ ] Bootstrap the two production Superadmins via console
- [ ] Verify no dev seeder can run in this environment
- [ ] Restore drill against production backups
- [ ] Operations manual written — see below

---

## Operations manual (not code, but a deliverable)

Three rules live outside the software and must be written down for staff:

1. **Move-out closes the relationship.** The system cascades to the card, but
   nothing prompts for a perpetual lease that ends in practice. This is the one
   gap the reconciliation dashboard cannot cover.
2. **Old cards are surrendered when replacements are collected.** The system
   marks them replaced regardless, but physical collection is what stops two
   plausible cards circulating.
3. **A scan that returns `active` but shows a different face is a failed
   verification.** Guards compare the photo, not the status light.
