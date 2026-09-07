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
that half-lands leaves the next one building on sand. Branch name is
`Phase-XX-Short-description` — `XX` the two-digit zero-padded phase number,
the description up to six words, hyphen-separated. Each phase lands via a pull
request, not a direct push to `main`.

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

**Hotfix landed 2026-09-06, on the Phase 3 branch** (CLAUDE.md 27's carve-out
— recorded here because that is where a Phase 2 change belongs, regardless of
which branch carried it):

Provisioning had no retry around any network step. A transient GitHub 504 on
a single Composer zipball killed a real deploy: under `set -e` one blip
aborts a multi-minute provision and leaves a half-built container, which is
worse to recover from than the blip. `provision-app.sh` and `provision-db.sh`
now retry every network-dependent step with backoff, and Composer falls back
to `--prefer-source` — it does not fall back from dist to source on its own,
so one bad API response was fatal. Two retry-only bugs were fixed alongside:
`gpg --dearmor` needed `--yes` (on a second attempt the keyring exists and
gpg would prompt, hanging inside `pct exec`), and the piped `curl | gpg`
commands needed explicit `pipefail`, which `bash -c` does not inherit.

Nothing about provisioning *logic* changed — what gets installed and how the
LXCs are wired is untouched. Phase 16's trap still applies: operator-facing
polish belongs there, provisioning behavior belongs here.

**Traps:** no Docker (architecture §12). No scheduler entry in crontab — the
only cron on this box is the backup job.

**Future distribution goal — Proxmox VE Helper Script:** once this phase's
manual deploy path is proven, it should be scripted into a Proxmox VE Helper
Script (the `community-scripts.github.io`/tteck style: an `install/` script
that builds the LXC, installs PHP/Postgres/Caddy, clones the repo, runs
`composer install`, migrates, and bootstraps the two Superadmins) so the
system can be handed to other condo admins as a one-command LXC deploy rather
than a manual runbook. Not built now — Phase 2's manual steps are exactly
the steps that script will later automate, so keep them scripted-shell-friendly
(no interactive prompts beyond what the helper-script convention expects) as
they're written.

---

## Phase 3 — Authentication & roles

**Goal:** login works, roles exist, the Superadmin tier is bootstrappable.

- [x] Breeze (Livewire stack) installed
- [x] **Strip** password-reset routes, `password_reset_tokens` migration,
      `CanResetPassword`, and email verification. They key on a column that
      doesn't exist and will fail to boot
- [x] Login switched from email to `username`
- [x] `must_change_password` middleware forcing rotation before any other route
- [x] `is_active` checked at login
- [x] Role constants and a `Role` enum-like helper; policy skeleton registered
      for every model
- [x] `id:superadmin-create`, `id:superadmin-reset`, `id:superadmin-list`
- [x] Two-active-Superadmin invariant, enforced inside a transaction with the
      Superadmin rows locked
- [x] Superadmin cannot act on their own account for role change or disable
- [x] Failed logins write `security_events`; 3 consecutive failures show the
      "contact a Superadmin" prompt with no lockout

**Added 2026-09-06 — first-run setup wizard.** Bootstrap moved from console-only
to the browser (architecture §12). These land on this same branch before the
phase merges; the console commands above stay, as break-glass recovery:

- [ ] First-run wizard route, reachable **only** while zero active Superadmins
      exist, creating **two** Superadmin accounts in one transaction
- [ ] Middleware redirecting every other route — login included — to the wizard
      while that precondition holds, and permanently refusing the wizard route
      once it no longer does
- [ ] Operator sets both passwords in the browser; neither account gets
      `must_change_password` (there is nothing to rotate away from)
- [ ] ~~Both creations write `audit_logs`~~ — **deferred to Phase 4** with the
      console commands' audit rows. Phase 3 has no `AuditLogger`, and a raw
      write here would be the second call-site shape Phase 4 exists to unify
- [ ] **Forward-only migration adding `setup_wizard_blocked`** to the
      `security_events.event_type` CHECK, written on every post-bootstrap
      attempt to reach the wizard. Kept distinct from `authorization_denied` so
      probing the bootstrap route stays greppable on its own

**Done when:** a fresh install with an empty database serves the wizard and
nothing else, two Superadmins created through it can log in, the wizard route
refuses afterward, a third can be created in the GUI, and tests prove the
two-Superadmin invariant holds against a concurrent attempt to disable both.

**Traps:**
- The wizard is the only unauthenticated route in the system that creates a
  privileged account. It calls the account-creation *service*, never
  `Artisan::call()` — that prohibition (§12) is unchanged.
- Console passwords are never command arguments — generated and printed once.
  The wizard is the exception by design: the operator types both passwords into
  the browser, so neither account needs `must_change_password`.
- No seeder creates a default account. A dev-only seeder is permitted but must
  `abort()` unless `APP_ENV === 'local'`. The wizard is not a seeder — it runs
  once, in response to a human at a browser.
- No Sanctum, no API routes.

**Added 2026-09-06 — password lifecycle narrowed to two paths** (architecture
§3, CLAUDE.md 40–42). A password now changes only through mandatory rotation
or a Superadmin's reset; the third, voluntary path Breeze scaffolds by default
was removed rather than kept alongside the two:

- [x] Mandatory-rotation form (`change-password`) no longer asks for the
      current password — reaching it already proves possession of the account
- [x] On success, the session is logged out and redirected to `login` with a
      flashed success message, rather than continuing to the dashboard — proof
      the new password works, not trust in the still-open session
- [x] Self-service `profile.update-password-form` removed: no route, no
      component, nothing on the profile page in its place
- [x] `UserAccountManager::resetPassword()` — a Superadmin resets any
      account's password, including their own or another Superadmin's, from
      the Users screen. Same shape as account creation: a generated password,
      shown once, `must_change_password` set
- [x] `EnsurePasswordIsCurrent` gained the same Livewire path-matching fix as
      `EnsureSystemIsBootstrapped` (§12) — it had the identical `routeIs('livewire.*')`
      bug, which would have made the rotation form unsubmittable the same way
      the wizard was

**Trap:** resetting a password is not guarded by the self-action rule that
guards disable/role-change. That guard exists specifically for the
accidental-lockout path (rule 24); a Superadmin resetting their own forgotten
password is ordinary, not that.

---

## Phase 4 — Audit & security event infrastructure

**Goal:** logging exists before anything worth logging does.

- [x] `AuditLogger` service: one call site shape, takes actor, action, subject,
      previous/new values. `occurred_at`/`ip_address` derived, never passed;
      a null actor requires an explicit `actingAs` — no silent default
- [x] Model-level immutability guard on `AuditLog` and `SecurityEvent` — boot
      hooks throwing on `updating` and `deleting`. Shared via an `AppendOnly`
      trait rather than duplicated across both models
- [x] `user_id` nullable; console writes use `user_role = 'console'` with
      `{"os_user","hostname"}` in `new_value`
- [x] Read-only audit viewer, Superadmin/Admin, with filters by actor, action,
      subject type/id, date range. Resolves a deleted subject's label via
      `withoutGlobalScope(SoftDeletingScope::class)` — the identical runtime
      effect as `withTrashed()`, reached that way because `withTrashed()`
      isn't statically callable on a `Builder` typed against a variable
      class string, and Larastan correctly said so
- [x] Console commands from Phase 3 retrofitted to write audit rows
      (`superadmin_created_via_console`, `superadmin_password_reset_via_console`)
- [x] **First-run wizard retrofitted too** (§12): both Superadmin creations
      write `user_id = null`, `user_role = 'setup_wizard'`, and the request IP.
      Deferred from Phase 3 so both bootstrap paths adopt the `AuditLogger`
      call shape at the same time rather than one inventing its own. Action is
      `superadmin_created_via_wizard` — see architecture §3's correction of an
      earlier, never-implemented `setup_wizard_completed` name
- [x] **GUI paths retrofitted too, beyond what this checklist originally
      named.** `UserAccountManager`'s five mutations were "finished Phase 3
      controllers" in exactly the sense this phase's own rationale describes,
      and shipping Phase 4 without them would have left the single largest
      source of Superadmin-tier events unaudited: `createAccount`
      (`superadmin_created` / `account_created`), `resetPassword`
      (`superadmin_password_reset` / `password_reset`), `disable`
      (`superadmin_disabled` / `account_disabled`), `enable`
      (`account_enabled`), `changeRole` (`role_changed`)

**Done when:** a test proves `AuditLog::first()->update()` throws, and every
console command produces a correctly-shaped row. ✅ Both proven, plus the same
for `SecurityEvent`, the wizard, and every GUI mutation — 125/125 tests,
0 Pint issues, 0 Larastan errors.

**Why this phase sits here:** retrofitting audit calls across finished
controllers is precisely where coverage gaps appear. Every later phase writes
its own audit entries as part of its definition of done.

**Trap avoided:** an invariant-blocked mutation (disabling the second-to-last
active Superadmin, changing your own role) must write *no* audit row — the
attempt changed nothing, so nothing happened to log. Tested explicitly
(`UsersPageTest`), because the natural implementation mistake is logging
before checking rather than after.

**⚠ Needs further testing — flagged, not deferred.** Every test above is
real and passing, but they can only exercise what exists: `users` is the
only subject type any writer has ever produced. Three things stay genuinely
unverified until later phases give them something to verify against:

- **The audit viewer's subject-type filter is a no-op today.** Its dropdown
  is populated from `AuditLog::distinct('subject_type')`, so with one
  subject type in the table it offers exactly one option. Filtering
  across *multiple* types — confirming a `Person` row doesn't leak into a
  `Unit` filter — has no data to prove it against yet.
- **`subjectWithTrashed()`'s non-SoftDeletes branch is unexercised.**
  `id_cards` has no `deleted_at` (§3 — never soft-deleted, by design), so
  the plain-`find()` path for a subject whose class doesn't use `SoftDeletes`
  has no real caller yet. The `users` case exercises the *other* branch, not
  this one.
- **Volume and mix are unknown.** Every test here writes a handful of rows
  of one or two actions. What the viewer looks and performs like with the
  thousands of rows a live system produces across a dozen action types is
  not something Phase 4's own fixtures can show.

**Resolution:** re-verify these specifically once Phase 6 (People) and
Phase 7 (Units) land their own audit calls — `person_created`, `unit_created`,
and a real soft-deleted `Person` are what actually exercises the gaps above.
Phase 13's policy-coverage audit is the latest point this should still be
open at; if it's still unverified there, that phase is where it gets closed.

---

## Phase 5 — GUI & UI/UX design

**Goal:** a design system and screen inventory exist before any CRUD screen is
built, so Phases 6+ implement against a settled layout rather than inventing
one per feature.

- [x] Screen inventory: every screen implied by architecture §11's role table
      (person/unit CRUD, relationship management, issuance, lifecycle actions,
      reissue confirmation, QR scan/verify, reconciliation dashboard, audit
      viewer, template CRUD, account management) named and listed, none
      designed silently later — `docs/design/screen-inventory.md`
- [x] Wireframes (low-fidelity is enough) for each screen in the inventory,
      reviewed against the role table so a Reader's wireframes show only what
      §11 grants them — `docs/design/wireframes.md`. Text-form rather than a
      drawing tool: every *built* screen wireframed as it stands, every
      *planned* screen mapped to one of a small set of reusable patterns
      (List/Index, Detail/Edit, Create, Confirm-or-Cancel, Delete
      Confirmation, Lifecycle Action, Scan/Verify, Reconciliation Query) —
      the mapping table is what makes "every screen has a wireframe" true
      without one bespoke drawing per screen
- [x] Shared Blade/Livewire component library: nav shell (`<x-nav-item>`,
      replacing four near-duplicate `@if` blocks in `navigation.blade.php`
      with one role-gated component), data table (`<x-data-table>` +
      `<x-data-table.sort-header>` + `<x-data-table.empty>`, with the
      sort/filter/pagination pattern used everywhere — **its sort column and
      direction resolve through a per-table allowlist, never straight from the
      request**, enforced structurally by `HasSortableColumns`
      (`app/Livewire/Concerns`): `sortBy()` silently ignores any column not in
      the consuming screen's own `sortableColumns()` map, so a screen can't
      forget the check — it never gets the chance to skip it. Getting this
      right once here is what keeps Phase 13's audit item a formality), form
      field wrapper (`<x-form-field>`), modal/confirm dialog
      (`<x-confirm-dialog>`, built on Breeze's existing `<x-modal>` — the
      confirm-or-cancel pattern §9.3 and §5.3 both need, plus a `danger`
      variant for soft-delete), status badge (`<x-status-badge>`,
      active/lost/revoked/expired/replaced), toast/flash messages
      (`<x-toast>`, generalizing Breeze's existing `<x-action-message>`
      rather than replacing it)
- [x] Navigation structure and role-based menu visibility (hiding a nav item
      is UX, not the authorization boundary — Policies still gate the route,
      per §11) — `<x-nav-item>`, role checks unchanged, just no longer
      duplicated per breakpoint
- [x] Responsive baseline: admin screens for desktop/tablet at the guardhouse
      workstation; the QR scan/verify screen additionally usable one-handed on
      a phone browser — `docs/design/responsive-and-accessibility.md`
- [x] Empty, loading, and error states designed once per component, not
      improvised per screen — `<x-data-table.empty>`, `<x-input-error>` (via
      `<x-form-field>`), Livewire's own `wire:loading` idiom documented as the
      loading-state convention rather than a new component forcing one style
- [x] Basic accessibility pass: focus order, contrast, label associations —
      proportionate to an internal LAN tool, not a public-facing audit —
      `docs/design/responsive-and-accessibility.md`

**Done when:** every screen in the inventory has a wireframe, the component
library renders in a Livewire component-preview route, and Phase 6 onward can
build a CRUD screen by composing existing components rather than writing new
markup patterns. ✅ `/dev/components` (local-only, gated the same way as the
dev seeder — CLAUDE.md 25) demonstrates every component with real, working
state: the data table's sort genuinely re-orders sample rows, the confirm
dialog genuinely opens, the toast genuinely fires. 134/134 tests, 0 Pint
issues, 0 Larastan errors.

**Traps:**
- This phase is the **GUI/UX design system** — layout, components, navigation.
  It is not §10/Phase 12's **template rendering**, which composites the
  printed physical/digital ID card image, a completely different surface.
- Don't let this phase invent new permissions or screens beyond what §11
  already grants each role — it designs the presentation of the roles table,
  not a new one.
- No design tool lock-in required — wireframes can be low-fidelity (paper,
  Excalidraw, Figma, whatever), but they must exist and be committed
  (`docs/design/` or similar), not live only in someone's head.
- **`HasSortableColumns` needs a real, non-Blade consumer to stay visible to
  Larastan.** Every ordinary consumer of this trait will be a Volt
  single-file component — Blade-embedded, and therefore invisible to
  PHPStan's `paths` (`app/` only), which flags the trait as unused with zero
  real ones in scope. `App\Livewire\Pages\Dev\ComponentsPreview` is a class
  component instead of Volt specifically to be that visible example — not a
  style change for the rest of the codebase, and not a reason to convert
  anything else away from Volt.
- **Phase 3/4 screens were not retrofitted onto the new components.** Users
  and Audit Log still use their original ad-hoc markup; churn on shipped,
  tested code wasn't worth it for this phase. New screens should look like
  the patterns in `wireframes.md`, not like those two.

---

## Phase 6 — People & photos

**Goal:** person records and the photo pipeline.

- [x] People CRUD, Superadmin/Admin. **Every field in §3 is present on the
      form**; what varies is which ones the current operation *requires*, per
      the tier rule below. "Present but not required" is the normal state of
      most fields on most rows — an incomplete profile is a valid record, not
      a draft, and nothing in the UI may present it as one
      — List/Index (`/people`), Create (`/people/create`), Detail/Edit
      (`/people/{person}`) Volt SFCs, built on the Phase 5 component library
      per `docs/design/wireframes.md`
- [x] **Forward-only migration relaxing the `people` NOT NULL set** to the
      minimal tier (§3 "Profile completeness"): `photo_path`, `gender`,
      `home_address`, `mobile_number`, and `email` become nullable
      — `2026_09_07_120549_relax_people_minimal_tier_columns.php`
- [x] **Forward-only migration adding `entity_type` (`natural` | `company`,
      default `natural`) and `legal_name`**, with a check constraint enforcing
      the pair: `natural` requires first + last name and null `legal_name`;
      `company` requires `legal_name` and null person-name columns. Existing
      rows backfill to `natural`, which is what they all are
      — `2026_09_07_120555_add_entity_type_and_legal_name_to_people.php`.
      `first_name`/`last_name` drop NOT NULL in this same migration (only
      makes sense alongside the kind that doesn't use them)
- [x] `display_name()` accessor resolving both kinds — **the only name-rendering
      path in the system.** Index, search, and sort go through it
      — `Person::displayName()`; `fullName()` stays the natural-only internal
      helper it dispatches to
- [x] Person form switches on kind: one name field for a company, the four
      person-name fields otherwise. `entity_type` is chosen at creation and is
      not editable afterward
- [x] `user_id_number` is minted for **every** party, companies included (§6) —
      the column stays `NOT NULL` so generation and collision-retry need no
      branch — `PersonIdNumberGenerator` (typed retry: `UniqueConstraintViolationException`
      matched by index name `uq_people_user_id_number`, never message text)
- [x] **Application-layer guard: `users.person_id` may not reference a
      company** (§3). A CHECK cannot express this — it would have to read
      `entity_type` on another table — and a composite FK or trigger is
      disproportionate for a column never consulted for authorization. Model
      guard plus form validation, with a feature test for each
      — guard added to `UserAccountManager::createAccount()`; no form yet
      selects a person for a user account, so no UI validation to add
- [x] **Tiered validation, enforced per operation, not per row**: minimal
      (name only) to create; contactable (+ mobile, email) to be a primary unit
      owner; cardable (+ photo) to be issued a card. The check asks what is
      being attempted — it never upgrades or back-fills a stored row
      — `Person::isContactable()`/`isCardable()`, cumulative per §3's table;
      only the minimal tier is enforced by this phase's own create/edit forms
- [x] Photo upload: validate type and MIME sniff, ≤1MB, crop 1:1 at upload,
      compress, UUID filename, private disk
      — `PersonPhotoService` (GD-based crop/compress, no new Composer
      dependency; MIME sniffed via `getimagesize()` on the real bytes, never
      the client-supplied extension or header)
- [x] Single authenticated serving route with a policy check on every request
      — `GET /people/{person}/photo` → `PersonPhotoController`, `Gate::authorize('view', $person)`
      on every request, streamed from the `local` disk
- [x] Photo replacement unlinks the old file
- [x] Search and index views — `/people`, `HasSortableColumns` + `<x-data-table>`,
      text search across name/legal name/ID number
- [x] **Saved filter: active relationship, no photo** — the profile backlog,
      for running a photo drive. It lives here and **not** on the reconciliation
      dashboard (§14): below-cardable is a legitimate end state, so the list is
      permanently long, which is normal on a browsing surface and fatal on a
      divergence dashboard
      — toggle on `/people`, `photo_path IS NULL` + an active `person_unit_relationships` row
- [x] Audit: `person_created`, `person_data_updated`, `photo_updated`
      — all three via `AuditLogger::log()`; `person_data_updated` only fires
      when a save actually changes a tracked field

**Done when:** a photo is unreachable without a session, the private disk has no
symlink, a policy test covers each role, a test creates a person with a first
and last name alone — no photo, no contact details — then reads it back
unchanged through the index and detail views, and a second test creates a
company with only a `legal_name` and finds it listed and searchable alongside
natural persons under `display_name()`.

**Traps:**
- No public disk, no `storage:link` for these, no signed URLs. The Reader
  60-second rule arrives in Phase 10 — until then Readers simply cannot fetch
  photos at all.
- **A person with no photo is finished, not half-entered.** This reverses §3's
  earlier "a person record cannot exist without a photo already uploaded." No
  "complete your profile" nag, no completeness meter, no filtering incomplete
  people out of lists. The photo is demanded at issuance (Phase 8), by
  issuance, and nowhere else.
- Requiredness lives in the operation, never in the form definition. A single
  "person form" that hard-codes required fields will be wrong for two of the
  three tiers.
- **Never stuff a company name into `last_name`.** It would put a company into
  the printed-field list and into mandatory reissue (§9.3), and both are wrong
  for something that holds no card. That is the entire reason `legal_name` is
  its own column.
- **Larastan's model-property check parses migration files statically — it
  never queries a live database.** It only understands columns added through
  `Schema::create()`/`Schema::table()`+`Blueprint`; a column added purely via
  raw `DB::statement("ALTER TABLE ...")` is invisible to it even though the
  column is real and the app runs correctly. `entity_type`/`legal_name` go
  through `Schema::table()` for exactly this reason (the NOT NULL drops on
  `first_name`/`last_name` in the same migration stay raw — Blueprint's
  `->change()` needs doctrine/dbal, not installed here — and Larastan doesn't
  need to see a nullability change, only a column's existence). Even then, a
  file that mixes raw statements with a `Schema::table()` block for the same
  table can still slip past this tool's static parse in ways that are cheaper
  to document than to keep chasing: `Person` carries an explicit `@property`
  docblock for the two new columns instead. If a future migration adds a
  column and Larastan reports it as an undefined property despite going
  through Blueprint, this is why — check the docblock route before spending
  time re-diagnosing the same static-parse gap.

---

## Phase 7 — Units & relationships

**Goal:** units, and the relationship model that everything downstream reads.

- [x] Unit CRUD — **fixed `ABBCC` shape, not a configurable numbering
      scheme.** This checklist item was written before architecture §3 was
      updated (marked `[changed]` there) to fix the shape; the schema and
      `Unit` model already reflected the fixed shape from Phase 1, so the
      only work here was building CRUD screens against it. Wording fixed to
      match — see the Traps entry below
- [x] Open a relationship: person, unit, type, `start_date`, optional
      `contract_end_date` — `RelationshipManager::openRelationship()`, refuses
      a company `type = 'tenant'` per §3
- [x] Close a relationship: sets `ended_at`. **The card cascade arrives in
      Phase 9** — leave a clearly-named seam, not a silent gap —
      `RelationshipManager::closeRelationship()`; refuses to close the
      primary-owner relationship directly (points at transfer instead)
- [x] Relationship history view per person and per unit — the unit show page
      lists every relationship (active and ended); the person show page is
      Phase 6's, unchanged here — a person-side history view was judged
      redundant with the unit-side one for this phase's scope and can be
      added later without a schema change
- [x] Audit: `unit_created`, `relationship_opened`, `relationship_closed`

**Primary unit owner (§3, §5.4).** Every unit has exactly one, always:

- [x] Forward-only migration: `is_primary_owner` boolean on
      `person_unit_relationships`, plus a **partial unique index** on `unit_id`
      scoped to `is_primary_owner IS TRUE AND ended_at IS NULL`, and a check
      constraint pairing `is_primary_owner` with `type = 'owner'`
      — `2026_09_07_122838_add_is_primary_owner_to_person_unit_relationships.php`
- [x] Unit creation requires a primary owner **in the same transaction** —
      selected from existing people or created inline at the contactable tier.
      No "add the owner later" path exists — `UnitLifecycleManager::createUnit()`
- [x] **The primary owner may be a company** (§3): the create-unit form offers
      both kinds directly, not the company case behind a secondary flow.
      Corporate ownership is common, not exceptional
- [x] Tenancy is refused for `entity_type = 'company'` — a corporate lease is
      recorded against the company as owner, or against the occupying
      individuals as tenants
- [x] **Two distinct operations, not one** (§5.4): **promotion** moves the role
      between two existing active owners and touches no card; **ownership
      transfer** opens the incoming owner's relationship, moves the role, and
      closes the outgoing one — which cascades to their cards via §5.3. A
      promotion that expires a co-owner's card is the bug this split prevents
      — `UnitLifecycleManager::promotePrimaryOwner()` / `transferPrimaryOwnership()`
- [x] Both are **retire-then-set** in one transaction with the unit locked:
      clear `is_primary_owner` on the outgoing relationship *before* setting it
      on the incoming one. **The reverse order aborts the transaction** — the
      partial unique index is checked at statement end and Postgres cannot defer
      a partial unique index. There is no ownerless window to avoid: inside one
      transaction nothing observes the intermediate state
- [x] Refuses an incoming party below the contactable tier, naming the missing
      fields
- [x] Capacity re-attribution checked in the same transaction: a promotion or
      transfer that pushes non-primary cards past six is refused with
      `UnitAtCapacityException` on the transfer screen. Covers natural → company
      (reserved slot empties, outgoing card joins the six) and the tenant-buys-
      the-unit case, which needs a `type_change` reissue per §5.1
      — the reissue itself is Phase 9's; this phase writes
      `requires_type_change_reissue` into the `primary_owner_transferred`
      audit row as a named seam, per the same pattern as the relationship-
      closure cascade above. `nonPrimaryOwnerActiveCardCount()` counts
      *cards*, not relationships — a relationship can be open with no card
      issued yet, so counting relationships would over-count against the cap
- [x] Closing or deleting anything that would leave a **live** unit without a
      primary owner is refused, inside the same transaction as the attempted
      change. The "at least one" check is scoped to `deleted_at IS NULL`
- [x] **Unit deletion carve-out** (§13): deletion still refuses while any other
      relationship or card is live, but its own transaction closes the
      primary-owner relationship as its final act — otherwise the unit is
      undeletable, since that relationship cannot be closed while the unit
      lives. Both the closure and the deletion are audit-logged
      — `UnitDeletionManager::delete()`. The guard check runs inside the same
      locked transaction as the close+delete, but a *refusal* returns instead
      of throwing from inside it — throwing there would roll back the
      `deletion_blocked` security-event write along with everything else,
      which is the opposite of rule 45's intent. The event and exception are
      raised after the transaction commits
- [x] **Unit restore requires designating a primary owner** in the same
      transaction (§13) — a deleted unit has no active relationships, so
      restoring the row alone manufactures the ownerless state §5.4 forbids.
      The relationship deletion closed is **not** resurrected; closed things
      stay closed, as with cards — `UnitDeletionManager::restore()`. The unit
      show route uses `->withTrashed()` so the same page serves both the live
      unit and its restore screen
- [x] **Person deletion blocked while they are any unit's primary owner**, with
      a message that names each unit and points to the transfer screen — not the
      generic "end the relationships first," which would describe a path that
      orphans the unit — `PersonDeletionManager`, checked before the generic
      active-relationship/active-card guard so the specific message always
      wins when both would otherwise apply
- [x] Audit: `primary_owner_transferred`, naming both people and the unit

**Done when:** `whereNull('ended_at')` is the only activity test in the codebase,
verified by grep, a person can hold several concurrent relationships, no unit
can be created or left without exactly one primary owner (proven by a test that
attempts each path), and deleting a primary owner is refused with the transfer
instruction rather than the generic one.

**Traps:**
- Nothing anywhere compares `contract_end_date` to today. That comparison
  exists in exactly one place, and it arrives in Phase 11.
- Primary ownership is accountability, not entitlement: transferring it issues
  and expires nothing. Only relationship closure (§5.3, Phase 9) touches cards.
- Flipping `is_primary_owner` mutates existing relationship rows, which is
  allowed — it is the same category as setting `ended_at`. It is **not** a
  breach of the `id_cards` immutability rule, which applies to a different
  table.
- **This checklist's own wording drifted from architecture.md once.** "Unit
  CRUD with a configurable numbering scheme" was written against an early
  draft; architecture §3 was later changed to a fixed `ABBCC` shape and
  marked `[changed]` there, but this line was never updated to match. The
  schema and model were already built correctly (Phase 1) — only this
  document was stale. Caught while implementing Phase 7, fixed in the same
  PR per the rule this trap is itself an example of: when a doc and the code
  disagree, find out which one is actually wrong before writing more code
  against either.
- **A refusal path that writes a `security_events` row must not throw from
  inside the `DB::transaction()` closure that wrote it.** The throw rolls
  back everything in that transaction, the security event included — the
  opposite of what rule 45 wants recorded. `UnitDeletionManager::delete()`
  is the concrete shape: the guard check runs inside the locked transaction
  (so the lock actually protects the check), but returns a "blocked" value
  instead of throwing; the transaction always commits (as a no-op when
  blocked), and the event + exception are raised afterward, outside it.
  `PersonDeletionManager::delete()` doesn't hit this because its guard never
  runs inside a transaction in the first place — worth checking for this
  shape on any future guard that both writes a security event and wraps its
  real work in a transaction.

---

## Dev tool — demo data seeder

*(Added 2026-09-07, off `Phase-07-units-relationships`. Not a numbered phase
— a standalone utility for optical/visual testing of the People and Units
screens, built once Phase 6 and 7 made companies and multi-unit ownership
real.)*

`php artisan demo:seed-test-data` (`App\Console\Commands\SeedDemoData`)
seeds ≥50 natural persons across all three completeness tiers, ≥5
companies, ≥10 units, and ≥3 entities holding more than one unit as primary
owner — enough real data to exercise sorting, search, and the "active
relationship, no photo" filter with something other than a handful of rows.
**Never writes to `users`.** Every row goes through the same services the
UI uses (`PersonIdNumberGenerator`, `UnitLifecycleManager`,
`RelationshipManager`), so seeded data satisfies every invariant those
services enforce — the partial unique index, the contactable-tier gate on
primary owners, a company never holding a tenancy — rather than being
raw inserts that happen to look right. Console-originated
(`actingAs: 'console'`, rule 44), so every action is a normal `audit_logs`
row, not a bypass.

Not gated to `local` the way `DatabaseSeeder` is: rule 25's gate is
specifically "no seeder creates a default account," and this one creates
zero accounts. It requires `--force` outside `local` instead — the same
kind of guardrail `migrate --force` uses on a real deployment, not a hard
block, since the whole point is running it once against a freshly deployed
system to look at.

A handful of the cardable-tier people get a real generated avatar (GD,
solid color + initials) written to the private `local` disk through the
exact path shape `PersonPhotoController` serves from, so photos render for
real during optical testing rather than sitting as an unusable
`photo_path` string.

**Not idempotent.** Unit codes are fixed (`A`/`B` × 3 floors × 2 numbers),
so a second run against the same database collides with the unique index
and fails — intentional, since this is a one-shot tool for a fresh system,
not a repeatable fixture.

---

## Phase 8 — Issuance

The hardest phase. Do not start it with Phases 1–7 partially done — issuance
reads relationships, and capacity now counts against a unit whose primary owner
Phase 7 guarantees exists.

**Goal:** cards can be issued, correctly, under concurrency.

- [ ] `randomEightDigits()` using `random_int`, zero-padded
- [ ] Typed retry: `UniqueConstraintViolationException` **and** constraint-name
      match, 5 attempts, zero delay
- [ ] Type/unit resolution (§5.1): owner outranks tenant, earliest
      `start_date`, ties by lowest `unit_id`, admin override within type
- [ ] **Slot-cap enforcement** with `lockForUpdate()` on the unit (§5.2): six
      occupants plus one slot reserved for the primary owner. The check counts
      active owner/tenant cards **excluding the primary owner's**, and caps that
      at six; the primary owner is issued into their reserved slot without
      counting. **Not a flat count of seven** — that would hand company-owned
      units a seventh occupant
- [ ] Issuance refuses a person below the cardable tier (§3), naming the
      missing fields — a person with no photo is a normal record, not an error
- [ ] Issuance refuses `entity_type = 'company'` **by kind, before any field
      check** — the error reads "a company cannot be issued an ID card," never
      "photo is required." The company **still holds its reserved slot**: a
      corporately-owned unit cards six occupants, the same as any other
- [ ] `UnitAtCapacityException` surfaced as a usable error, not a 500
- [ ] Employee issuance, Superadmin-only, bypassing the cap
- [ ] Audit: `id_issued`

**Done when:** a concurrency test proves two simultaneous issuances against a
unit at 5/6 occupants produce exactly one card and one clean rejection; a
company-owned unit refuses a seventh occupant card; and a natural-person primary
owner with no card yet can still be issued one when all six occupant slots are
full.

**Traps:**
- Employee cards never count toward the cap.
- **The reserved slot is held whether or not it is used.** The tempting
  simplification — count all active cards, cap at seven — silently gives
  company-owned units a seventh occupant and lets six occupants lock a
  yet-uncarded primary owner out of their own slot. Both are the bug this
  phase's tests exist to catch.
- One card per person, not one per relationship.
- The chosen unit is stored, never recomputed on read.

---

## Phase 9 — Lifecycle, cascade & mandatory reissue

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

## Phase 10 — QR & verification

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

## Phase 11 — Reconciliation dashboard

**Goal:** divergence becomes visible.

- [ ] Query A — leases past `contract_end_date`, still open. **The only
      date-to-today comparison in the system**
- [ ] Query B — persons who **could be carded today and are not**: natural
      persons at cardable tier (photo present) with an active owner/tenant
      relationship and no active card, per person. **Companies and minimal-tier
      people are excluded** — both are permanent, unresolvable states that would
      swamp the list and break §14's "empty is normal" contract
- [ ] Query C — units with all six occupant slots taken (the primary owner's
      reserved slot is not part of this count)
- [ ] Query D — units whose active primary-owner count is not exactly one. An
      **integrity canary**, expected permanently empty: §5.4's transaction and
      the partial unique index make both states unreachable through the app.
      It catches a hand-edited database, a mid-migration restore, or a future
      code path writing relationships outside the sanctioned flow. For a unit
      with none, designate one; for one with several, name each candidate with
      its `start_date` and let a Superadmin choose. **Non-empty means the system
      is wrong, not the data entry**
- [ ] Each row links to the screen that resolves it. No bulk actions, no
      counters, no badges
- [ ] Viewing writes nothing to `audit_logs`

**Done when:** all four queries are correct against a seeded fixture containing
each divergence, a multi-unit owner holding one valid card does **not** appear
in Query B, **a company owner and a minimal-tier co-owner do not appear in
Query B either**, and Query D returns nothing for a fixture built entirely
through the application's own flows.

---

## Phase 12 — Templates & rendering

Blocked on designer input. Build the CRUD; leave rendering behind a seam.

- [ ] Template CRUD, Superadmin-only, front/back background upload to the
      private disk (`background_path_front`, `background_path_back`)
- [ ] `field_positions_front` / `field_positions_back` editing as numeric
      values — no canvas
- [ ] `is_active` per `id_type`
- [ ] Server-side compositing via Intervention Image, producing one raster
      image per side
- [ ] Name auto-shrink to fit the field box
- [ ] Preview-quality output only
- [ ] Rendered output is downloadable (front/back images) — no printer
      integration of any kind. Printing happens in separate, external
      card-printer software; this phase's job ends at the raster image

**Traps:**
- No historical-reprint feature. `template_id` is provenance.
- Never accept a card-design tool's own project file (whatever proprietary
  binary format that software saves) as a template upload — only a plain
  raster image (PNG). Converting from the design tool's format to PNG is the
  admin's job, outside this system (§10).

---

## Phase 13 — Security review

- [ ] Policy coverage audit: every route, every role, tested
- [ ] Confirm no `Artisan::call()` is reachable from HTTP
- [ ] Confirm no public disk, symlink, or unauthenticated file route exists
- [ ] Confirm no scheduler, queue worker, or cron beyond the backup job
- [ ] **Confirm no query takes a column name, table name, or sort direction
      from user input.** Laravel binds *values*, never identifiers:
      `orderBy($request->sort)`, `whereRaw`, `selectRaw`, and `havingRaw`
      interpolate directly and are the only realistic SQL-injection route into
      this codebase. Grep for the raw-SQL family and confirm every hit is
      either literal DDL in a migration or a bound placeholder; confirm every
      sortable column resolves through an allowlist, not through the request.
      **This becomes reachable in Phase 5**, when data tables gain
      sort/filter/pagination — it is not a risk in Phases 0–4, where no query
      is built from a string
- [ ] **Approach B audit immutability**: revoke `UPDATE`/`DELETE` grants on
      `audit_logs` and `security_events` from the app's DB user, plus triggers
- [ ] Review `security_events` volume and retention
- [ ] Confirm the audit viewer escapes stored request data. `security_events`
      and `audit_logs` hold attacker-influenced strings (attempted routes,
      submitted usernames); Blade escapes by default, so this is a check that
      no `{!! !!}` crept into those views
- [ ] Dependency audit — `composer audit` against the locked tree, not just a
      version-constraint eyeball

---

## Phase 14 — Production cutover

- [ ] Real data load or entry
- [ ] Bootstrap the two production Superadmins **through the first-run wizard**,
      at the console of the deployed system, immediately after the deploy — not
      hours later. Until it completes, anyone who can reach the app's IP on the
      LAN can claim the system (architecture §12)
- [ ] Verify the wizard route refuses once bootstrap is complete
- [ ] Verify no dev seeder can run in this environment
- [ ] Restore drill against production backups
- [ ] Operations manual written — see below

---

## Phase 15 — Codebase refactoring & cleanup

**Goal:** pay down whatever accumulated across Phases 1–14 without changing
behavior. Fourteen phases of incremental delivery leave duplication and
inconsistency that a mid-phase refactor would have been premature to fix —
this is where it gets fixed deliberately, all at once, with the full test
suite as the safety net.

- [ ] Audit every phase's code for duplicated logic (repeated capacity/lock
      patterns, repeated validation, repeated audit-log call shapes) and
      extract shared services/traits where it genuinely simplifies things
- [ ] Consistency pass: naming, file organization, and confirm the Phase 5
      shared Blade component library is actually used everywhere — no
      ad-hoc markup left behind from a phase that predates a component
- [ ] Remove dead code, unused routes, and leftover scaffolding
- [ ] Re-run Larastan and consider raising the level beyond 5 if the
      codebase is clean enough to support it
- [ ] Test-suite audit: eliminate flaky or slow tests, confirm every policy
      and transaction still has feature-test coverage per the ground rules
- [ ] No behavior changes. Anything that looks like a bug during this pass
      becomes its own fix, tracked separately — not folded in silently

**Done when:** no known duplication remains, Pint/Larastan are clean, the
full test suite is green before and after with identical results, and a
reviewer can trace every printed-field/capacity/lock rule to exactly one
implementation.

**Trap:** forward-only migrations still apply — this phase cleans up
application code, not shipped migrations.

---

## Phase 16 — Proxmox helper script polish

**Goal:** take `deploy/proxmox/create-qrid-stack.sh` from "works, with some
hand-holding" (its state after Phase 2) to genuinely user-friendly, folding
in everything learned from real hands-on testing along the way.

- [ ] Fix every rough edge accumulated during Phase 2's real-world testing
      that wasn't worth blocking Phase 2 for
- [ ] Input validation on every interactive prompt (reject invalid CTIDs,
      non-numeric memory/disk, malformed domains) instead of failing deep
      into provisioning with an opaque error
- [ ] Idempotency review: safe to re-run against a partially-created stack
      without manual cleanup (Phase 2 testing hit a stuck half-created
      container that needed a manual `pct destroy` before retrying)
- [ ] Clearer progress output and error messages throughout, continuing the
      pattern started by Phase 2's sibling-fetch error message
- [ ] Consider adopting more of the community-scripts `build.func`
      conventions (whiptail dialogs, a Default/Advanced menu) if it
      genuinely improves the experience without adding fragile dependencies
- [ ] Update `deploy/proxmox/README.md` to match the final flow exactly
- [ ] Suppress the harmless `perl: warning: Setting locale failed` noise that
      `pct exec` prints on every invocation (LANG/LC_ALL aren't propagated
      into the container's exec environment) — cosmetic, but it clutters
      every command's output during Phase 2 testing

**Done when:** someone with no prior context can run the one-liner, answer
the prompts, and land on a working deployment without reading the script
source or asking for help.

**Trap:** this phase is about the operator-facing experience of the script
itself — don't let it drift back into changing Phase 2's actual
provisioning logic (what gets installed, how the LXCs/DB/app are wired).
That's a Phase 2 fix, landed on Phase 2's own branch, not this one.

---

## Operations manual (not code, but a deliverable)

Four rules live outside the software and must be written down for staff:

1. **Move-out closes the relationship.** The system cascades to the card, but
   nothing prompts for a perpetual lease that ends in practice. This is the one
   gap the reconciliation dashboard cannot cover.
2. **Old cards are surrendered when replacements are collected.** The system
   marks them replaced regardless, but physical collection is what stops two
   plausible cards circulating.
3. **A scan that returns `active` but shows a different face is a failed
   verification.** Guards compare the photo, not the status light.
4. **Complete the first-run wizard immediately after deploying.** Between the
   deploy finishing and the wizard completing, the first person to reach the
   app's IP on the LAN becomes the system's two Superadmins. This is inherent
   to browser-based bootstrap (architecture §12) and the only defence is not
   leaving the gap open. Never deploy and walk away.
