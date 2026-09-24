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

**Hotfix, 2026-09-09** (CLAUDE.md rule 27 — on its own branch,
`hotfix-test-suite-failures`): **`php artisan test` was silently running the
whole suite in the `local` environment instead of `testing`**, on this
machine. Root cause: Collision's `TestCommand` (`vendor/nunomaduro/collision`)
only clears `.env`-loaded environment variables before spawning PHPUnit when
no `--env` option is passed — the exact opposite of what `--env=testing`
suggests it does. Invoking with `--env=testing` (to point at a local,
gitignored `phpunit.local.xml` with this machine's own DB credentials) skipped
that clear, so `.env`'s `APP_ENV=local` leaked into the PHPUnit subprocess and
PHPUnit's own `<env name="APP_ENV" value="testing">` tag — lacking
`force="true"` — silently deferred to it. Effect: every macro or check gated
on `environment('testing')` behaved as if in `local` instead — 12 tests
errored on a missing `assertSeeLivewire`/`assertDontSeeLivewire` (a Livewire
testing macro registered only in the `testing` environment), 2 tests
asserting local-only routes/commands refuse outside `local` saw the opposite
of what they expected, and 2 file-upload tests failed on stale local-disk
temp-upload state left over from the wrong environment's config. None of it
was an application bug — `./vendor/bin/pest -c phpunit.local.xml` run
directly (bypassing the Artisan wrapper) passed 334/334 throughout. Fixed by
adding `force="true"` to every `<env>` tag in `phpunit.xml` (defense in depth
— makes the suite correct even when a caller's shell has already exported one
of these variables) and documenting the actual correct invocation: plain
`php artisan test`, no `--env` flag. `phpunit.local.xml` is gitignored and
received the same `force="true"` treatment locally, though it turns out to be
redundant with `phpunit.xml`'s own values on this machine.

**Hotfix, 2026-09-10** (CLAUDE.md rule 27 — own branch, `fix-package-lock-drift`,
cut from `main`): **CI's "Install npm dependencies" step (`npm ci`) had been
failing on every push to `main` since Phase 10, silently.** Phase 10 added
`html5-qrcode` to `package.json`'s dependencies (commit `42dccd4`) but
`package-lock.json` was never regenerated to include it — `npm ci` requires
the two files to be in sync and refuses to proceed otherwise
(`Missing: html5-qrcode@2.3.8 from lock file`). Confirmed via
`gh run list --branch main`: CI had reported failure on the last several
merges, even though the application code and Pest suite were fine throughout
— a pure lockfile-drift issue, not a real regression. Fixed by running
`npm install` (Node 20.20.2, matching CI's runner exactly) to regenerate
`package-lock.json`; the diff is a 9-line addition, `html5-qrcode` and its one
transitive dependency. `npm ci`, `npm run build`, Pint, and Larastan all
verified green locally before the branch was pushed. **Trap for later:**
this machine ships with no Node.js/npm at all by default — a portable,
no-install zip build of Node was fetched and unpacked to `%LOCALAPPDATA%`
rather than the MSI installer, since the MSI requires admin privileges this
account doesn't have. Worth remembering if this needs doing again.

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
LXCs are wired is untouched. Phase 15's trap still applies (renumbered
2026-09-15, was Phase 16): operator-facing polish belongs there, provisioning
behavior belongs here.

**Hotfix landed 2026-09-15, on the Phase 15 branch** (CLAUDE.md 27's carve-out
again — found while making re-runs safe, which Phase 15's idempotency item
could not close without it):

A re-run of `create-qrid-stack.sh` locked the app out of its own database.
Every run generates a fresh `DB_PASSWORD` and `provision-app.sh` writes it
into the app's `.env`, but `provision-db.sh` set the role's password only
inside `IF NOT EXISTS … CREATE ROLE` — so on any re-run the role kept its old
password while the app switched to the new one. The role's password is now
set on every run (`ALTER ROLE … WITH LOGIN PASSWORD` when it already exists).
Same privileges, same `pg_hba.conf` scoping; only the password is kept in
step. Phase 15 fixed the operator-side re-run bugs itself (a backup cron line
appended again on every run, a bind mount re-applied to a running
container), and delivered the helper-script shape the **Future distribution
goal** below describes — community-scripts conventions, not their code.

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

- [x] First-run wizard route, reachable **only** while zero active Superadmins
      exist, creating **two** Superadmin accounts in one transaction
- [x] Middleware redirecting every other route — login included — to the wizard
      while that precondition holds, and permanently refusing the wizard route
      once it no longer does
- [x] Operator sets both passwords in the browser; neither account gets
      `must_change_password` (there is nothing to rotate away from)
- [x] ~~Both creations write `audit_logs`~~ — **deferred to Phase 4** with the
      console commands' audit rows. Phase 3 has no `AuditLogger`, and a raw
      write here would be the second call-site shape Phase 4 exists to unify
- [x] **Forward-only migration adding `setup_wizard_blocked`** to the
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
- [x] **Hotfix, 2026-09-15 — a user's own display-name change is now
      audited.** Found in Phase 14's final consistency review: the Profile
      page's name save (`profile/update-profile-information-form`, kept from
      Breeze) wrote `$user->save()` directly, so it never reached
      `UserAccountManager` and this phase's retrofit never saw it — the one
      account mutation with no audit row. It now calls
      `UserAccountManager::changeOwnDisplayName()`: action
      `display_name_changed`, the user as their own actor, `{name}` as
      previous/new value, rename and row in one transaction, and nothing
      written for an unchanged save (the same "only what happened" reasoning
      as the trap below). Architecture §3's action vocabulary updated to
      match. Its own branch from `main` (rule 27), not folded into Phase 14.

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

**Hotfix, 2026-09-10, own branch (`fix-users-stale-invariant-error`) cut from
`main` per rule 27** — the Users screen's `toggleActive()` and `changeRole()`
(this phase's own GUI retrofit, above) never call `validate()`, so Livewire's
`resetErrorBag()` — which only wipes the bag on a successful `validate()` call
for that key — never cleared a previous `addError('invariant', ...)` on its
own. A refused toggle/role-change for one user left its message sitting in
the error bag through every later render, including a different, successful
action on an unrelated user. Fixed the same way as the identical pattern
found and fixed the same day on `Phase-12-templates-rendering`
(`templates/show`'s `savePositions()`/`activate()`/`delete()`, `fonts/index`'s
`delete()`, `id-cards/show`'s `print()`): an explicit
`$this->resetErrorBag('invariant')` at the top of both methods, right after
`$this->authorize(...)`. Regression test added to `UsersPageTest` asserting a
stale error from one user's failed toggle doesn't survive a different user's
successful one. 15/15 `UsersPageTest` tests passing, full suite green, 0 Pint,
0 Larastan.

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

**Hotfix, 2026-09-08** (CLAUDE.md rule 27 — on its own branch,
`Fix-nested-delete-buttons`, per the same-day tightening of that rule —
see Phase 7's own note on this fix for why): **the "Delete" button on the
person show page has never actually submitted anything.** The exact
sibling of the unit show page's "Delete unit" bug (same day, Phase 7):
`<button wire:click="delete" wire:confirm="..." type="button">` wrapped
an `<x-danger-button>` — a `<button>` nested inside a `<button>`, invalid
per the HTML5 content model. A browser implicitly closes the outer one
the instant it meets the inner one, so the visible button (the inner
one) carried no `wire:click` at all. Found by grepping every raw
`<button>` tag across the codebase once the Units-page version of this
bug was confirmed, rather than assuming it was isolated. Fixed by
collapsing to a single `<x-danger-button wire:click="delete"
wire:confirm="...">`, the same shape used everywhere else a destructive
Livewire action needs a confirm dialog. New regression test asserts the
rendered markup (not `->call('delete')`, which bypasses the button and
is why every existing test missed this), confirmed to fail against the
pre-fix markup first.

**Addition, 2026-09-08 — a "Units" relationships table on the person show
page** (own branch, `Person-relationships-table`, per rule 27): the
person-side mirror of the unit show page's own relationships table
(Phase 7's addition above), same day. Active-only by default with a
"Show ended relationships" toggle; each row is a unit this person holds
a relationship on, with an "End" action reusing
`RelationshipManager::closeRelationship()` and its existing
stage/preview/confirm-modal flow exactly (same shape as the unit show
page's "Close," including the reissue-offer banner when ending a
relationship leaves the person still entitled elsewhere). No "End" is
rendered for a primary-owner row — ending a primary ownership is a
transfer, done from the unit page, not a plain close; the underlying
guard (`PrimaryOwnerInvariantException`) still refuses it directly even
if reached. Sort: primary-owner row(s) first, then by unit code — there's
no name to sort by the way the unit-side table's mirror-image version
has, since every row here is the same person.

**Addition, 2026-09-08/09 — editing a relationship's `contract_end_date`,
from both tables, with two guards** (own branch,
`Edit-relationship-contract-end-date`, per rule 27): `contract_end_date`
is paperwork, not activity (rule 4) — this is architecture §14 Query A's
own resolution action ("extend `contract_end_date`, or close the
relationship") made reachable from the relationship's own screens, not
just prose describing what an admin should already know to go do
somewhere unspecified. New `RelationshipManager::updateContractEndDate()`:
sets the one column, audit-logs `relationship_contract_end_date_updated`,
touches nothing else — no `ended_at`, no card, no `is_primary_owner`. An
"Edit" action sits next to "Close"/"End" on every *active* relationship
row on both the unit show page and the person show page's own table
(2026-09-08 addition above), opening a small modal (`<x-confirm-dialog>`
holding a single date field, reusing the existing component rather than
building a bespoke one) staged with the relationship's current value.

**Two guards, both in `RelationshipManager` and both refused with a new
`InvalidContractEndDateException` (`App\Exceptions`, extending
`InvalidArgumentException` so it stays catchable as one), added
2026-09-09 after real usage found the first cut too permissive:**

- **An owner relationship — primary or co-owner — never has a contract
  end date.** Only a tenant's lease has a term; setting one on an owner
  row is refused outright (clearing one, i.e. passing `null`, is always
  allowed — that's not "setting a term," it's removing one that should
  never have been there). Enforced in both `openRelationship()` and
  `updateContractEndDate()`, so the same rule holds whether the date
  arrives at creation or via this new Edit action.
- **A contract end date must fall strictly after `start_date`** — on the
  same day or earlier describes a term that never actually ran, refused
  the same way.

**Both errors route through a session flash, not `addError()`** — the
edit modal's Save button (`<x-confirm-dialog>`) dispatches
`close-modal` client-side the instant it's clicked, the same shape
`stageCloseRelationship`'s own confirm dialog already has, so a form
error attached to the (about-to-vanish) modal would never be seen.
`closeRelationshipNow()` already solved this with
`session()->flash('error', ...)`; `saveContractEndDate()` on both pages
now does the same. The **Open Relationship** form is a real
`wire:submit`, not a modal, so its own catch block still uses
`addError()` as before — just routed to `open_contract_end_date`
specifically for the new exception, rather than the pre-existing
`open_type` the company/tenant refusal already used.

**One-time data fix**: `php artisan relationships:clear-owner-contract-dates`
(`App\Console\Commands\ClearOwnerContractEndDates`) nulls out
`contract_end_date` on any owner relationship that already has one —
data written before this guard existed, since nothing before this
commit ever stopped it. Not wired into `deploy.sh` (rule 39 doesn't
apply — this fixes existing rows, it isn't a step deploying this branch
requires); run once by hand against each environment that might have
pre-existing bad rows. Idempotent — a second run is a silent no-op.

**Hotfix, 2026-09-19** (CLAUDE.md rule 27 — its own branch,
`fix-photo-pixel-cap`, cut from `main`): **a phone photo could exhaust
PHP's memory while it was processed.** The 1 MB limit was the only size
guard, but a compressed JPEG that small can be a 12- or 24-megapixel photo,
and GD decodes every pixel. The service then made a second full-size square
copy and stored the photo at full resolution — 3024 × 3024 for an ordinary
phone photo, decoded again at every card render. Measured with the
framework loaded, against the default 128 MB limit: a 12 MP photo peaked at
106 MB, a 24 MP one at 180 MB (a fatal error). Found while measuring the
same thing for Phase 16's logo upload. Fixed in three parts:
- **At most 16 megapixels**, read from the file header before anything is
  decoded, refused with a message giving the photo's size and how to fix it
- **One resample crops and scales at once**, straight to the stored size —
  no second full-size image
- **Stored at most 1024 × 1024** — the card is 1011 × 638 at 300 DPI, so no
  photo box can use more. Photos already stored stay as they are

After the fix: 12 MP peaks near 74 MB and 16 MP near 90 MB; 24 MP (127 MB)
is refused. The limit is a validation rule, `PersonPhotoService::rules()`,
shared by the create and show pages, so a refused photo is a field error
before anything is saved — on the create page, a service exception would
otherwise have come after the person row was written. The service still
checks the same limit itself, as a backstop.

---

## Phase 7 — Units & relationships

**Goal:** units, and the relationship model that everything downstream reads.

**Hotfix, 2026-09-09** (CLAUDE.md rule 27 — on its own branch,
`fix-unit-occupant-relationship-cap`): **§5.2's six-slot cap was enforced only
on cards, so a unit could be given any number of active co-owners and
tenants.** Opening a relationship ran no capacity check at all — the cap was
first met at issuance, when the seventh card was refused. That made a unit's
recorded occupancy and its cardable occupancy diverge silently, and the
divergence was invisible on the unit page, which lists relationships rather
than cards. Fixed by counting the same cap a second time, one step earlier:
`RelationshipManager::openRelationship()` now takes the unit's row lock
(rule 17), counts active non-primary-owner owner/tenant relationships via the
new `Unit::nonPrimaryOwnerActiveRelationshipCount()`, and throws the existing
`UnitAtCapacityException` on the seventh; the unit page catches it and reports
it on the person field rather than letting a `RuntimeException` become a 500.
Three things the fix had to get right, each covered by a test: the primary
owner's reserved relationship is excluded (a unit still takes six occupants
*plus* its owner), co-owners and tenants are counted together rather than six
of each, and the tenant→co-owner conversion closes the tenancy before counting
so a full unit does not refuse its own occupant's change of kind. The card
count is unchanged and still fires independently — promotion and transfer
re-attribute cards without opening any relationship, so it is not made
redundant. Architecture §5.2 and CLAUDE.md rule 31 updated in the same branch
(rule 29).

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

**Correction, 2026-09-07 (before this phase's own PR merged — real usage
against a deployed test copy, not a hotfix to shipped behavior):**

- **`Person::fullName()` is "Last, First Middle Suffix"**, not
  "First Middle Last Suffix" as first written in Phase 6. Changed once, in
  the model — `display_name()` and every screen that calls it inherited the
  new format automatically, per rule 37. `docs/implementation-plan.md`'s
  Phase 6 section still describes the accessor's existence and role
  correctly; only the token order was ever wrong, and it's a display
  decision, not a schema or invariant one.
- **The People index's `name` sort was broken and untested.** It passed a
  comma-joined, multi-expression raw SQL string as a plain string value in
  `sortableColumns()`; `HasSortableColumns::applySort()` appends one
  trailing direction keyword to whatever comes back, which is correct for
  a single expression and invalid SQL for several joined by commas — no
  existing test ever clicked the Name header, so this shipped unnoticed.
  Fixed by building one combined sortable text key instead of several
  clauses (natural persons get `'0|' || last_name || '|' || first_name`,
  companies get `'1|' || legal_name`, so a single ascending/descending sort
  naturally groups naturals before companies and orders each group
  correctly) — not by teaching the trait to compose multiple `orderBy()`
  calls, which the Units index's `primary_owner` column (added the same
  day, one clean joined expression) shows was never actually necessary.
  **Trap for later:** a `sortableColumns()` value must resolve to exactly
  one `ORDER BY` item. If a future column seems to need several, look for
  the one-expression version first — case/concat tricks usually get there
  — before reaching for anything that bypasses `applySort()`.
- **People index gained a `kind` sort** (`entity_type`) — trivial, listed
  here only because it shipped alongside the harder fix above.
- **Units index gained a `primary_owner` sort**, joining
  `person_unit_relationships` (`is_primary_owner = true AND ended_at IS
  NULL`) to `people` and sorting by `COALESCE(legal_name, last_name,
  first_name)`. `Unit::query()->select('units.*')` before the joins keeps
  the paginated result set built entirely from `Unit` columns.
- **The Create Unit "existing owner" field is now
  `<x-person-picker>`**, not a bare ID-number text input — a searchable,
  Alpine-driven dropdown over every contactable-tier person (natural or
  company), each row formatted `{user_id_number} - {display_name}`.
  Selecting an option writes the ID number to the same Livewire property
  the old text input bound to, so `create()`'s validation and lookup logic
  didn't need to change. New reusable component
  (`resources/views/components/person-picker.blade.php`) rather than a
  one-off in the create page, since the same shape (search a person by ID
  number or name, get back an ID number) is a reasonable bet to be needed
  again — the transfer/restore forms on the Unit show page still use the
  plain text input and are candidates for the same treatment later, not
  changed here since only unit creation was asked for.

**Addition, 2026-09-07 — optional photo on Create Person, plus camera
capture:**

- Create Person gained a `photo` field (`WithFileUploads`), validated and
  stored through the exact same `PersonPhotoService` pipeline the show
  page's upload already used — the service needs a real `Person` row to
  attach to and to unlink a previous file against, so the person is always
  created first (minimal tier is enough), then the photo attached in the
  same request if one was staged. Still fully optional: architecture §3's
  "a person can exist with no photo" is unchanged, this just collapses
  what used to be a mandatory second visit to the show page into one step
  when a photo happens to be on hand at creation time.
- **New reusable component: `<x-camera-capture>`.** Captures a square
  frame from `getUserMedia()` and pushes it through `$wire.upload()` into
  whichever Livewire property the page already validates and stores
  against (`photo` here) — a captured frame and a file-picker upload are
  indistinguishable to the server, both land as a `TemporaryUploadedFile`.
  No new server-side path needed. Requires a secure context (HTTPS, or
  `localhost`) per browser policy — this deployment already terminates TLS
  in front (Phase 2), so this only bites local `http://` testing over a
  LAN IP rather than `localhost`, and the component surfaces that as a
  readable error rather than doing nothing.
- Not done: gating the photo section by `entity_type` — the show page's
  own upload form was already un-gated for companies before this change
  (Phase 6), so the create form matches that existing behavior rather than
  introducing a new inconsistency between the two screens. Rule 36 (a
  company can never be issued a card) isn't affected either way — a stored
  `photo_path` on a company row is inert, never read by anything that
  checks `entity_type`.

**Addition, 2026-09-07 — client-side square check + crop tool:**

- **New `<x-cropping-file-input>`** replaces the plain `<input
  type="file" wire:model="photo">` on both Create Person and the show
  page's photo upload. On selection it loads the image client-side and
  checks `naturalWidth === naturalHeight`; a square image uploads straight
  through `$wire.upload()` exactly like before. A non-square image opens
  an in-browser crop modal instead — drag to reposition a square box,
  resize it with a slider, confirm — and **only the cropped result is
  ever uploaded**; the original non-square file never reaches
  `$wire.upload()` at all, so there's nothing server-side to discard.
  `<x-camera-capture>` needed no change: it already captures a square
  region by construction, so the check always passes for it.
- Crop rectangle math: the modal displays the image scaled to a fixed
  max dimension for layout convenience, but the crop box's position/size
  are converted back to *natural* pixel coordinates (`scale =
  naturalWidth / displayWidth`) before drawing to the output canvas, so
  the crop is accurate regardless of how large the source photo actually
  is.
- **Untestable by Pest, and not pretended otherwise.** The crop
  interaction is pointer-drag + canvas pixel manipulation with no
  server round-trip — nothing a headless feature test can drive. What
  the test suite actually covers: the component renders on both pages,
  and the server-side handling of an already-square upload (which is
  what the crop tool's output *is*, from the server's point of view) is
  unchanged and still fully covered by the existing photo tests.

**Addition, 2026-09-07 — photo file size shown on create and show:**

- **`PersonPhotoService::sizeInBytes()`** returns the *stored* file's
  actual size on disk (post crop/compress), not whatever was originally
  uploaded — that's the number that matters once a photo is saved, since
  the GD pipeline re-encodes it. Formatted via
  `Illuminate\Support\Number::fileSize()` (already in this Laravel
  version, no new dependency).
- **Person show page** displays this under the stored photo.
- **Create Person** shows the *staged* file's size (`$photo->getSize()`,
  a real `TemporaryUploadedFile` method) next to its preview, before the
  person is even saved — this one is necessarily the pre-processing size,
  since nothing has been stored yet to measure. The two numbers can differ
  slightly (compression), and that's expected, not a bug to reconcile.

**Addition, 2026-09-07 — camera capture on the show page too:**

- `<x-camera-capture>` was only ever wired into Create Person, not the
  Person show page's photo upload — an oversight, not a deliberate
  restriction; there was never a reason a replacement photo should be
  file-only when a new one isn't. Show page now mirrors Create's pattern
  exactly: a staged photo (from either the file input or the camera) gets
  a preview + size + Clear button before the actual "Upload photo" submit,
  instead of submitting blind.
- New `clearStagedPhoto()` method, parallel to Create's `removePhoto()` —
  kept as a named method rather than an inline `wire:click="$set(...)"`
  action, matching this codebase's existing convention of explicit
  component methods for anything beyond the simplest cases.

**Addition, 2026-09-07 — one Save action, a Reset button, and a real fix
for a stale-photo report:**

- **`uploadPhoto()` is gone.** The Person show/edit page had two
  independent submit buttons that could each mutate the same record —
  "Upload photo" and "Save" — which is two ways to edit one thing, not a
  feature. A staged photo (file input or camera) now sits in `$this->photo`
  doing nothing server-side until the single `save()` action runs, exactly
  like every other field on the form. `save()` still fires
  `person_data_updated` and `photo_updated` as the two distinct audit
  actions they always were, independently, based on what actually changed
  in that one click.
- **`resetForm()` + a Reset button**, next to Save. Re-fetches the person
  (`->fresh()`, not the in-memory copy — "what's saved" means the real
  row, not just whatever loaded when the page opened), re-hydrates every
  field from it, and clears any staged photo. `hydrateFieldsFromPerson()`
  is the same routine `mount()` already used, extracted rather than
  duplicated.
- **The "doesn't update without a refresh" report was real, and it was the
  photo `<img>` tag, not Livewire.** Livewire re-renders the whole
  component after every action already — the show page's own bound fields
  reflect a save immediately, with no special handling needed. What
  doesn't refresh on its own is a browser's cache of an image at a fixed
  URL: `route('people.photo', $person)` names the same URL before and
  after a photo is replaced, so a browser that already fetched it once has
  no reason to ask again. Fixed with a cache-busting query string —
  `?v={{ $person->updated_at->timestamp }}` — so the URL itself changes
  whenever the person row does, forcing a refetch. This is the general
  shape of that class of bug: if something "needs a refresh," look for a
  static resource URL before assuming Livewire's reactivity is broken.
- **Users and Units were asked about too, and don't get a Reset button
  here.** Users' role/active/password changes are each already immediate,
  single-click actions (`wire:change`, `wire:click` with `wire:confirm`)
  with no staged draft to discard — there's nothing a Reset would revert.
  Units has no form that edits the unit's *own* fields at all yet
  (`building_code`/`floor_code`/`unit_number`) — every form on that page
  opens a relationship, promotes, transfers, or deletes, none of which
  are "editing an existing record's fields" in the sense this request
  means; there's no persisted draft state to reset to. If a genuine
  edit-in-place form is added to either screen later, it should get this
  same Reset treatment then.

**Addition, 2026-09-07 — camera mirroring, and a real mobile layout bug:**

- **The live camera preview is now mirrored (CSS `-scale-x-100`), the
  captured photo is not.** `<video>` shows a flipped self-view — the
  natural framing convention every phone/webcam camera app uses — but
  `capture()`'s `drawImage(video, ...)` always reads the video's raw
  underlying frame regardless of any CSS transform applied to the
  element for display, so the *saved* photo is never mirror-reversed.
  This matters specifically because it's an ID photo: a flipped save
  would part hair on the wrong side and reverse any text on clothing.
- **Every fixed `w-24 h-24`/`w-48 h-48` photo, video, and placeholder
  element needed `shrink-0`, and was missing it.** Each one sits inside
  a `flex` row alongside other content (buttons, size labels). Without
  `shrink-0`, a flex item's *width* can compress below its declared size
  once the row runs out of horizontal room — exactly what a narrow phone
  screen does — while its `h-24`/`h-48` *height* class stays fixed
  regardless, squashing what should be a square photo into a rectangle.
  This is the actual bug behind "photos don't stay 1:1 on mobile"; the
  crop tool's own math was never the cause. `flex-wrap` added to the
  surrounding rows too, so once `shrink-0` refuses to compress the photo,
  sibling content (Clear/Remove buttons, captions) wraps to its own line
  instead of overflowing.

**Addition, 2026-09-08 — relationships table filter/sort, and person-pickers
on Open Relationship and Transfer:**

- **The relationships table now defaults to active-only**
  (`ended_at IS NULL`), with a "Show ended relationships" toggle to reveal
  the rest — the unit show page previously listed every relationship,
  active and ended, with no way to narrow it. Sorted primary owner first,
  then everyone else alphabetically — naturals by last name, companies by
  legal name, the same `0|last_name|first_name` / `1|legal_name` key the
  People index already sorts by (§7's earlier correction), built here as a
  stable two-pass collection sort rather than a second `ORDER BY`
  expression: a unit never holds more than a handful of relationships, so
  the SQL version wasn't worth it.
- **No new "delete" concept was added.** What was asked for by that name is
  the existing "Close" action (`stageCloseRelationship`, unchanged) — it
  already sets `ended_at` in a transaction and cascades to cards per §5.3.
  Kept the existing label rather than renaming it: this codebase reserves
  "delete" for hard entity deletion (`Unit`/`Person` soft-delete via
  `deleted_at`, Superadmin-only, rule 9) and "close" for relationship
  activity (rule 4) — renaming the button would have blurred two concepts
  that are deliberately kept apart everywhere else.
- **`<x-person-picker>` extended to two more fields**: Open Relationship's
  "Person" and Transfer Primary Ownership's "New owner," the exact
  candidates Phase 7's own notes named ("candidates for the same treatment
  later, not changed here since only unit creation was asked for"). The two
  option lists are deliberately different: Open Relationship's carries
  every person, tier notwithstanding — opening a relationship has no tier
  requirement of its own, only issuance does later — while Transfer's is
  scoped to the contactable tier, mirroring Create Unit's own
  `loadAvailableOwners()` exactly, since an incoming primary owner must
  already satisfy that tier (§3).

**Hotfix, 2026-09-08** (CLAUDE.md rule 27's carve-out — landed directly on
`main`, blocking manual testing of the addition above): **the "Open
relationship" and "Promote" buttons on the unit show page have never
actually submitted their forms.** `<x-secondary-button>` defaults to
`type="button"` (`resources/views/components/secondary-button.blade.php`)
unless the caller overrides it, and neither button did — both sat inside
a real `wire:submit` form since this phase's original commit, silently
inert. Every existing test called the Livewire action directly
(`->call('openRelationship')`, `->call('promote')`), which bypasses a
button entirely, so nothing caught it until a user reported the button
doing nothing. Fixed by adding `type="submit"` to both call sites; a new
regression test asserts the rendered attribute rather than calling the
action, and was confirmed to fail against the pre-fix markup before being
trusted.

**Hotfix, 2026-09-08 (second)** (CLAUDE.md rule 27 — on its own branch,
`Fix-nested-delete-buttons`, not landed on `main` directly — see below for
why): **the "Delete unit" button on this same page has also never
submitted anything, a different bug with the same symptom.** User-reported
right after confirming the fix above. `<button wire:click="delete"
wire:confirm="..." type="button"><x-danger-button>Delete
unit</x-danger-button></button>` — a `<button>` nested inside a
`<button>`, invalid per the HTML5 content model. A browser implicitly
closes the outer one (which carried `wire:click`/`wire:confirm`) the
instant it meets the inner one, so the visible button never had a click
handler at all. Fixed by collapsing to a single `<x-danger-button
wire:click="delete" wire:confirm="...">`. Checking for the same shape
elsewhere in the codebase found an identical bug on the person show
page's own "Delete" button — see Phase 6's hotfix note for that fix.

**This second fix is also the point rule 27 itself was narrowed.**
Finding a second bug mid-session, on the same page, while fixing the
first — both fixes were applied directly on `main`, matching the *old*
carve-out ("may ride the current branch") — is exactly the moment a
"just this once, it's trivial" direct commit to `main` happens. The
user's explicit instruction (given while this second fix was in
progress) heads that off going forward: `main` itself can no longer be
"the current branch" a hotfix rides — a branch is cut first. The
carve-out otherwise still stands for any branch that isn't `main`; see
rule 27 itself in `CLAUDE.md` for the amended text. This fix was moved
onto `Fix-nested-delete-buttons` before being committed, per the
corrected rule.

**Addition, 2026-09-09 — a person holds at most one *kind* of active
relationship (owner or tenant) per unit** (own branch,
`Enforce-single-relationship-type-per-unit`, per rule 27): the two
directions aren't symmetric. An active **tenant** relationship, met with
a new **owner** request for the same person-unit pair, closes the
tenancy automatically (the same `closeRelationship()` path a manual
Close uses — cards and all) and opens the owner relationship in its
place, atomically; a tenant who buys the unit is the ordinary case this
serves. An active **owner** relationship (primary or co-owner), met with
a new **tenant** request, is refused outright — owner-to-tenant is a
demotion an admin should decide deliberately, never a side effect of
adding a lease. Both directions live in `RelationshipManager::openRelationship()`,
scoped to the specific unit-person pair — a person can be an active
owner on one unit and an active tenant on another without either
touching the other. Surfaced as a form error attached to
`open_type` on the Open Relationship form (the same field the existing
company/tenant refusal already used), since this form is a real
`wire:submit`, not a confirm-dialog modal.

**Found and fixed in the same commit: the demo seeder generated exactly
this now-refused combination.** `SeedDemoData` draws unit occupants from
a pool that overlaps with the pool primary owners are drawn from, so a
unit's own primary owner could be independently redrawn as a random
tenant/co-owner assignment on the very unit they already own — always
nonsensical demo data, previously silent, now a real refusal. Fixed by
skipping an occupant draw that matches the unit's own primary owner.

**Addition, 2026-09-09 (same branch) — the same-kind duplicate the above
left open.** User-reported oversight: nothing stopped a person from
holding *two* active tenant relationships, or two active co-ownerships,
on the same unit — the checks above only ever compared against the
*opposite* kind. `openRelationship()` now checks same-kind first: an
existing active relationship of the same type being requested, for the
same person on the same unit, is refused outright ("already holds an
active {type} relationship on this unit"), before the opposite-kind
logic even runs. This also closes the gap for a unit's existing primary
owner being handed a second, ordinary co-owner relationship — `type`
alone (not `is_primary_owner`) is what's compared, so the primary
owner's own row counts as "already owner" the same as any co-owner's
would. Scoped to *active* duplicates only — a person can freely reopen a
tenant relationship on a unit once their earlier one there has ended,
and two different people can each hold their own active tenancy on the
same unit without conflict.

**Addition, 2026-09-09 (same branch) — narrowed further: a company can
only ever be a unit's primary owner, never an ordinary co-owner
either.** User-reported tightening of architecture §3, which previously
allowed a company as an ordinary co-owner alongside primary ownership.
`openRelationship()` — which only ever creates a *non-primary*
relationship, since `createRelationship()` always sets
`is_primary_owner => false` — now refuses a company outright regardless
of the requested type, checked before the same-kind/opposite-kind logic
above even runs. Primary ownership is unaffected: `UnitLifecycleManager`
(`createUnit()`, `transferPrimaryOwnership()`) sets it directly and never
goes through `openRelationship()`, so a company becoming or receiving
primary ownership works exactly as before. The unit show page's Open
Relationship picker (`loadAllPersons()`) now excludes companies entirely
— offering an option that's always refused server-side would just be a
worse error message than not offering it.

**Tested and confirmed, 2026-09-09: this refusal is app-layer only, with
no database-level backstop.** A raw SQL `INSERT` into
`person_unit_relationships` naming a company bypasses
`openRelationship()` entirely and succeeds cleanly — verified directly
against the local database, both `type = 'tenant'` and an ordinary
`type = 'owner'` row. The app doesn't protect itself on the read side
either: the resulting row reads back and renders on the unit show page
without erroring or flagging anything. Recorded in architecture §15 and
Phase 13's own checklist as a DB-level trigger to add during the
security review — same category and same deferral reasoning as the
audit-log immutability item already there, not a new pattern.

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
not a repeatable fixture. `db:wipe-test-data` (below) is what clears the
way for a re-run.

## Dev tool — full database wipe

*(Added 2026-09-07, same branch as the seeder above.)*

`php artisan db:wipe-test-data` `TRUNCATE`s every domain table —
`sessions`, `users`, `id_cards`, `person_unit_relationships`, `units`,
`people`, `templates`, `audit_logs`, `security_events` — with
`RESTART IDENTITY CASCADE`, so IDs start fresh too. **`users` is wiped
here**, unlike the seeder: this is a full reset for testing bootstrap
itself, not a companion to re-seeding. The next visit to the app re-
triggers the first-run setup wizard (architecture §12).

Confirmation is Laravel's own `ConfirmableTrait` — the exact mechanism
`migrate:fresh` already uses in this codebase: prompts (or requires
`--force`) only when `APP_ENV=production`, proceeds immediately in `local`.
No bespoke safety flag was invented; the project's real deployed
environment is already `production`, so the framework's existing gate is
the right one.

**Deliberately bypasses the app layer via raw `TRUNCATE`, `audit_logs` and
`security_events` included.** CLAUDE.md rule 8 guards those two tables
against an *application* edit/delete path (`AppendOnly`'s model-layer
hooks); it doesn't reach a human-invoked, environment-gated, whole-database
reset command, which is the same category as `migrate:fresh` — already
capable of dropping and recreating both tables — rather than a feature this
app exposes through the UI or a service. Worth restating if this pattern is
ever questioned later: the guard's job is stopping the app from silently
mutating history, not stopping an operator from wiping a test database on
purpose.

---

## Phase 8 — Issuance

The hardest phase. Do not start it with Phases 1–7 partially done — issuance
reads relationships, and capacity now counts against a unit whose primary owner
Phase 7 guarantees exists.

**Goal:** cards can be issued, correctly, under concurrency.

- [x] `randomEightDigits()` using `random_int`, zero-padded
- [x] Typed retry: `UniqueConstraintViolationException` **and** constraint-name
      match, 5 attempts, zero delay
- [x] Type/unit resolution (§5.1): owner outranks tenant, earliest
      `start_date`, ties by lowest `unit_id`, admin override within type
- [x] **Slot-cap enforcement** with `lockForUpdate()` on the unit (§5.2): six
      occupants plus one slot reserved for the primary owner. The check counts
      active owner/tenant cards **excluding the primary owner's**, and caps that
      at six; the primary owner is issued into their reserved slot without
      counting. **Not a flat count of seven** — that would hand company-owned
      units a seventh occupant
- [x] Issuance refuses a person below the cardable tier (§3), naming the
      missing fields — a person with no photo is a normal record, not an error
- [x] Issuance refuses `entity_type = 'company'` **by kind, before any field
      check** — the error reads "a company cannot be issued an ID card," never
      "photo is required." The company **still holds its reserved slot**: a
      corporately-owned unit cards six occupants, the same as any other
- [x] `UnitAtCapacityException` surfaced as a usable error, not a 500
- [x] Employee issuance, Superadmin-only, bypassing the cap
- [x] Audit: `id_issued`

**Done when:** a concurrency test proves two simultaneous issuances against a
unit at 5/6 occupants produce exactly one card and one clean rejection; a
company-owned unit refuses a seventh occupant card; and a natural-person primary
owner with no card yet can still be issued one when all six occupant slots are
full. ✅ All three proven — 236/236 tests, 0 Pint issues, 0 Larastan errors.

**Traps:**
- Employee cards never count toward the cap.
- **The reserved slot is held whether or not it is used.** The tempting
  simplification — count all active cards, cap at seven — silently gives
  company-owned units a seventh occupant and lets six occupants lock a
  yet-uncarded primary owner out of their own slot. Both are the bug this
  phase's tests exist to catch.
- One card per person, not one per relationship.
- The chosen unit is stored, never recomputed on read.

**Implementation notes, 2026-09-07:**

- **`ControlNumberGenerator` mirrors `PersonIdNumberGenerator` exactly** —
  same typed-retry shape (rule 16), same `MAX_ATTEMPTS`-then-`RuntimeException`
  fallback, one new service rather than generalizing the two into a shared
  base. Two nearly-identical 40-line classes read clearer than one generic
  one bent to fit both `people` and `id_cards`.
- **`IssuanceManager` is two independent methods, not one with a `$type`
  branch.** `issueOwnerOrTenantCard()` resolves §5.1, locks the unit, and
  checks the six-slot cap; `issueEmployeeCard()` does none of that — no
  lock, no unit, no cap. Sharing a method would have meant an `if
  ($type !== 'employee')` wrapped around everything capacity-related,
  which is exactly the shape that invites a future edit to that method
  accidentally applying the cap to an employee card, or skipping it for
  an owner card. Both refuse a company and a below-cardable-tier person
  through the same two private helpers, since that check is identical
  either way.
- **A new `CardIssuanceRefusedException`**, same shape as
  `DeletionBlockedException` (a message plus a typed detail array —
  `missingFields` here). Reused for both the company-by-kind refusal
  (`missingFields` empty) and the cardable-tier refusal, rather than two
  exception classes, since a caller handling one handles the other the
  same way: read the message, optionally read the array.
- **A person already holding an active owner/tenant card is refused a
  second one** — not asked for explicitly, but "one card per person, not
  one per relationship" is a named trap, and nothing else in this phase
  enforces it. Reissue/replacement is Phase 9 scope; this is a narrow
  guard against double-issuing, not a reissue path.
- **`IdCardPolicy::issueEmployee()`** is a new ability distinct from
  `create()` — Admin can issue owner/tenant cards but not employee cards.
  Checked at the call site (Livewire/controller), never inside
  `IssuanceManager` itself, matching every other service in this
  codebase: services don't self-authorize.
- **The concurrency test reuses `UserAccountManagerConcurrencyTest`'s
  exact shape**: a real second PDO connection with `lock_timeout` proves
  the unit's `lockForUpdate()` genuinely blocks a second reader, and a
  sequential handoff test (issue the sixth card, then attempt the
  seventh) proves the lock-then-recount pair carries committed state
  across the boundary rather than a value cached before the first call.
  This codebase has no thread/process-parallel test runner, so this
  lock-blocks-a-second-connection-plus-sequential-handoff pair is what
  "a concurrency test proves" means here, consistently with Phase 3's
  two-Superadmin invariant test.
- **Not built in this phase: an "Issue ID" GUI screen.** The wireframes
  name one (`docs/design/wireframes.md`'s Create pattern), but this
  phase's own checklist and "done when" are backend-only — every other
  phase that ships a screen lists it explicitly (Phase 6/7 both did).
  `IssuanceManager` and `IdCardPolicy::issueEmployee()` are usable from a
  console command or a future screen either way; wiring the screen itself
  is left as a named gap rather than silently expanding this phase's
  scope.

**Deferred to Phase 12, 2026-09-07 (explicit user decision, not a code
gap):** the "Issue ID" GUI screen and its feature/integration tests ride
with Phase 12 instead of landing here or as their own phase. Phase 12 is
the first phase where a `template_id` exists to select at issuance time,
so the screen and template rendering land together rather than the
screen shipping once now and needing rework once templates exist. See
architecture §15 for the tracked entry. `IssuanceManager` itself —
type/unit resolution, the six-slot cap, employee issuance, the
concurrency proof — is fully tested and merges with this phase; only the
screen and its tests move.

---

## Phase 9 — Lifecycle, cascade & mandatory reissue

**Goal:** every status transition in §4, plus the two flows that chain them.

- [x] `markLost`, `revoke`, `expire` — all requiring actor and reason
- [x] Replacement issuance setting `replaces_id_card_id` and
      `replacement_reason`
- [x] Retire-then-check ordering inside every replacement transaction
- [x] **Relationship closure cascade** (§5.3): closing a relationship expires
      matching active owner/tenant cards in the same transaction; confirmation
      screen names them first; offers reissue where another relationship remains
- [x] **Mandatory reissue** (§9.3): changing a printed field names every
      affected card, then confirm-or-cancel — no decline path — with data change
      and reissues in one transaction
- [x] Fixed ascending-`unit_id` lock ordering for any multi-unit transaction
- [x] Audit: `id_revoked`, `id_expired`, `id_marked_lost`, `id_replaced`

**Done when:** closing a relationship for a two-unit owner expires one card and
surfaces the reissue, and editing a name with three active cards replaces all
three atomically or none. ✅ Both proven — 257/257 tests, 0 Pint issues,
0 Larastan errors.

**Traps:**
- Cancelling abandons the edit. There is no "save without reissuing."
- The printed-field list is closed: photo, name fields, position, department.
  Editing a birthdate must not trigger anything.

**Implementation notes, 2026-09-07:**

- **`IdCardLifecycleManager::replace()` is the one building block** behind
  both `markLost()` (old status `lost`, `replacement_reason = 'lost'`) and
  every printed-field reissue (old status `replaced`, reason matching what
  changed). Type and unit always default to the old card's own — a straight
  reissue never changes what a card is *for*, only what's printed on it;
  a type or unit change is a transfer/promotion decision made elsewhere
  (`UnitLifecycleManager`, already built in Phase 7) and was never this
  phase's job to reinvent.
- **`revoke()` and `expire()` (direct) never issue a replacement.** Revoke
  is deliberately withdrawing a card from someone still entitled — a future
  card for them is a fresh admin decision. Direct expire records that the
  entitlement itself lapsed. Only the *cascade* variant
  (`expireForClosure()`) and `markLost()` ever chain into a new card, and
  for different reasons: the cascade might offer one afterward (see below),
  `markLost()` always issues one immediately.
- **Clarified architecture §5.3**: the cascade's own prose named only
  `unit_id` as the match key for "the matching card," which — read
  literally — would expire every *other* occupant's card on the unit the
  moment any one relationship closes. `person_id` was always implied by
  the surrounding context (a person's *own* relationship closing affects
  that *same* person's card) but never stated; tightened in the same PR
  per rule 29, since this is exactly the kind of doc gap that turns into a
  real bug the moment someone implements the literal sentence.
- **`RelationshipManager::closeRelationship()` wasn't transactional
  before this phase** — nothing needed it to be, since it only ever made
  one write. Wrapping it was necessary the moment a second write (the card
  cascade) had to commit or roll back with it.
- **The reissue offer is a flash-banner + button, not a second modal.**
  After closing a relationship whose person is still entitled elsewhere,
  `IssuanceManager::issueOwnerOrTenantCard()` (Phase 8, unchanged) is
  called directly — §5.1's own tie-breaker decides which of the person's
  remaining relationships gets the card, so this phase doesn't re-decide
  anything it already solved.
- **Not built in this phase: a Card lifecycle GUI screen** (the
  wireframes' "Lifecycle Action" pattern — mark lost / revoke / expire
  buttons on a card's own page). Same reasoning as Phase 8's deferred
  "Issue ID" screen: this phase's own checklist names the three
  *transitions* as backend capabilities, not a screen, and no card
  index/show page exists yet for such a screen to live on. Deferred to
  Phase 12 alongside "Issue ID" — both are card-facing screens with no
  natural home until then. `IdCardLifecycleManager` is fully built and
  tested either way; only the screen moved. The two screens this phase
  *does* explicitly name — the relationship-close confirmation and the
  mandatory-reissue confirmation — are built, because the checklist itself
  describes screen behavior for those two ("confirmation screen names
  them," "confirm-or-cancel"), not just a service method.

**Deferred to Phase 12, 2026-09-07 (explicit user decision, not a code
gap — same shape as Phase 8's deferral above):** final testing of the
Card lifecycle GUI screen itself rides with Phase 12, once that screen
exists. `IdCardLifecycleManager` — `markLost`, `revoke`, `expire`,
`replace()`, the cascade, the mandatory-reissue orchestration — is fully
built and tested at the service/Livewire level in this phase and merges
as-is; only screen-level tests for the not-yet-built lifecycle-action
buttons move. See architecture §15.

---

## Phase 10 — QR & verification

**Goal:** the guardhouse flow.

- [x] QR generation encoding the plaintext control number, 8 chars
- [x] Print/preview surface for the QR
- [x] Scan page using `html5-qrcode`, Reader-accessible
- [x] Verify result: current status, photo, identifying info. Non-active status
      displayed unmistakably
- [x] Manual control-number entry with zero-padding applied before lookup
- [x] Reader 60-second session window for photo access, recorded server-side at
      scan time
- [x] `qr_verify_miss` and `authorization_denied` written to `security_events`

**Done when:** a Reader can scan and see a photo, and the same Reader hitting
that photo URL 61 seconds later gets a 403 and a logged event. ✅ Proven,
end-to-end, in one test — 284/284 tests, 0 Pint issues, 0 Larastan errors.

**Traps:** no encryption, no token column. Test the QR at actual print size on
the actual scanning hardware before this phase closes — it is the whole reason
§8 changed. **Not done in this session** — no scanner or printer was available
in this dev environment; see the implementation notes below.

**Implementation notes, 2026-09-07:**

- **`bacon/bacon-qr-code` added as a production dependency** (`^3.1`, pinned
  after `composer require` resolved `v3.1.1` — not left at the `*` composer
  first wrote). Error correction level **M**, not the library's default L:
  a printed card spends a year in a wallet and gets scanned at an angle on
  cheap guardhouse hardware — the exact failure mode §8's own reasoning
  describes for the old encrypted design — so M buys back some margin for
  the 8-character plaintext payload without pushing the QR version up
  meaningfully.
- **`html5-qrcode` added to `package.json`, bundled via Vite** (imported
  once in `resources/js/app.js`, exposed as `window.Html5Qrcode` for
  `<x-qr-scanner>`'s plain inline Alpine script) — never loaded from a CDN,
  since this system is LAN-only and never public.
- **`PersonPolicy` gained a `viewPhoto` ability, separate from `view`.**
  `view` (Superadmin/Admin only) is unchanged — a Reader still can't reach
  the People index/show screens. `viewPhoto` is narrower and is what
  `PersonPhotoController` now checks: Superadmin/Admin always, a Reader
  only when `ReaderVerificationSession::isRecentlyVerified()` says so.
  Splitting the ability, rather than teaching `view` a special case, keeps
  "can see this person's record" and "can see this person's photo right
  now" as the two different questions they actually are.
- **`ReaderVerificationSession` (`App\Support`) is the session record
  architecture §9.2 names** — a person-ID-to-timestamp map, nothing more.
  No migration, matching the architecture note that no schema is needed at
  this scale. `CardVerificationService::verify()` is the only writer;
  `PersonPolicy::viewPhoto()` is the only reader.
- **The 60-second window opens on verify, regardless of the card's
  status.** A revoked or expired card is still a verification of who that
  person is — the guard comparing the photo against the person standing
  there is exactly the check that matters most on a non-active hit, so
  gating the photo window on `status === 'active'` would have broken the
  one case §8 cares most about.
- **No new photo route.** The existing single authenticated photo route
  from Phase 6 (`PersonPhotoController`) is reused as-is for the verify
  result's photo — only its policy check changed. Rule 22's "one
  authenticated route" would have been violated by adding a second one.
- **The QR print/preview surface is a bare route
  (`id-cards/{idCard}/qr`), not part of a card's own screen** — same
  reasoning as the two screens already deferred to Phase 12: no Card
  index/show page exists yet. This route is real and tested, just not
  linked from anywhere in the nav yet; Phase 12's Card lifecycle screen is
  the natural place to link to it once it exists.
- **Real-hardware trap: closed, 2026-09-07.** No scanner or printer was
  available in this dev environment, so this had to happen against the
  real deployment (192.168.100.45) rather than in-session — printed the
  QR from the desktop preview, scanned it from a phone as a Reader, and
  confirmed the guardhouse flow end to end. Caught one real bug in the
  process (the camera-visibility fix logged below), fixed, redeployed,
  and re-verified working. `npm run build` itself still hasn't run in
  *this* dev environment (no local node/npm here), but it has now run
  for real at the deploy that was tested against.

**Bugfix, 2026-09-07 (found during the real-hardware test above, same
branch — not yet merged, so this is an ordinary fix, not rule 27's
hotfix carve-out):**

- **`<x-qr-scanner>` requested camera permission successfully but never
  showed a video feed on mobile.** Root cause: `#qr-reader-region` was
  revealed (`active = true`) only *after* `Html5Qrcode.start()` resolved,
  but the library measures its target element's rendered size to lay out
  the video the moment `start()` runs — and a `display:none` element
  measures 0×0. The camera stream genuinely started (hence the permission
  prompt succeeding), it was just sized against a hidden container and
  never became visible. Fixed by setting `active = true` and awaiting
  Alpine's `$nextTick()` *before* constructing `Html5Qrcode` and calling
  `start()`, so the container has real dimensions by the time the library
  looks at it. No Pest coverage for this — it's a pure client-side layout
  bug with no server round-trip to assert against; the manual hardware
  test is what caught it and is what re-verifies it.

**Hotfix, 2026-09-15 — a Reader lands on Verify, not the empty dashboard.**
Found in Phase 14's final consistency review. This phase made Verify a
Reader's one working screen, but login, `/` and the nav logo still sent
every role to the dashboard — Breeze's "You're logged in!" placeholder,
which for a Reader is a dead end one click from where they need to be.
`User::homeRoute()` now names each role's landing screen (`verify.index`
for a Reader, `dashboard` otherwise), and all three entry points use it; an
intended URL still wins at login. The dashboard stays reachable and in
every role's nav — this changes where a Reader lands, not what they can
reach. Own branch from `main` (rule 27); tests cover login and `/` for a
Reader and the logo's target for a Reader and an Admin.

---

## Phase 11 — Reconciliation dashboard

**Goal:** divergence becomes visible.

- [x] Query A — leases past `contract_end_date`, still open. **The only
      date-to-today comparison in the system**
- [x] Query B — persons who **could be carded today and are not**: natural
      persons at cardable tier (photo present) with an active owner/tenant
      relationship and no active card, per person. **Companies and minimal-tier
      people are excluded** — both are permanent, unresolvable states that would
      swamp the list and break §14's "empty is normal" contract
- [x] Query C — units with all six occupant slots taken (the primary owner's
      reserved slot is not part of this count)
- [x] Query D — units whose active primary-owner count is not exactly one. An
      **integrity canary**, expected permanently empty: §5.4's transaction and
      the partial unique index make both states unreachable through the app.
      It catches a hand-edited database, a mid-migration restore, or a future
      code path writing relationships outside the sanctioned flow. For a unit
      with none, designate one; for one with several, name each candidate with
      its `start_date` and let a Superadmin choose. **Non-empty means the system
      is wrong, not the data entry**
- [x] Each row links to the screen that resolves it. No bulk actions, no
      counters, no badges
- [x] Viewing writes nothing to `audit_logs`

**Done when:** all four queries are correct against a seeded fixture containing
each divergence, a multi-unit owner holding one valid card does **not** appear
in Query B, **a company owner and a minimal-tier co-owner do not appear in
Query B either**, and Query D returns nothing for a fixture built entirely
through the application's own flows. ✅ All proven — 304/304 tests, 0 Pint
issues, 0 Larastan errors.

**Implementation notes, 2026-09-08:**

- **`ReconciliationQueries` (`App\Services`) holds all four queries**, kept
  separate from the Volt component so each query is independently testable
  without rendering a page — `tests/Feature/ReconciliationQueriesTest.php`
  exercises the query logic directly, `tests/Feature/ReconciliationDashboardTest.php`
  covers the screen (access control, the read-only guarantee, the nav link).
- **Query C reuses `Unit::nonPrimaryOwnerActiveCardCount()`** (Phase 7/8)
  rather than re-deriving the six-slot definition in a second place — the
  same reasoning Phase 8's own traps already state: two independent
  implementations of §5.2 is exactly the shape that drifts apart silently.
  Iterates every unit in PHP rather than a single aggregate SQL query; at
  this system's scale (a handful of buildings, not a portfolio) that trade
  reads clearer than an equivalent `HAVING count(*) >= 6` across two joined
  tables, matching how the existing Audit viewer and Units index also favor
  plain, direct queries over hand-tuned SQL.
- **Query D's `LEFT JOIN` (not a plain `GROUP BY` on the relationship
  table) is what catches a unit with *zero* primary owners**, not only one
  with several — a unit with none has no relationship row to group into a
  count at all. Same join shape (alias `pur`, same `ON` clause) the Units
  index already uses for its `primary_owner` sort column, rather than a
  second way of expressing the same join.
- **No new migration.** All four queries read the existing schema; nothing
  in this phase required a schema change, so the deploy script and its
  checklist (rule 39) are unaffected.
- **New Gate, not a new Policy.** `view-reconciliation-dashboard`, defined
  in `AppServiceProvider::boot()` — the dashboard reads across `Person`,
  `Unit`, and `PersonUnitRelationship` at once, so no single model's Policy
  is the natural home for its `viewAny`-shaped check. Same Superadmin-or-Admin
  test every other admin screen's Policy already runs; not a new permission,
  per the phase's own scope.
- **Query B's "no active card" check mirrors `IssuanceManager`'s own
  duplicate-card guard exactly** (`status = 'active'`, `type IN ('owner',
  'tenant')`) rather than a looser "no card at all" — an employee card,
  which is unrelated to relationship-based entitlement, must never hide
  someone from this list.
- **Query D's "several candidates" branch has no test**, and cannot: the
  partial unique index refuses two active `is_primary_owner` rows on the
  same unit at the database level, even via a raw insert that bypasses
  every application-layer guard. The architecture doc's own words are
  proven, not just asserted — "two primary owners cannot survive the
  index, so in practice this catches zero." The screen still renders that
  branch (`ReconciliationQueries::primaryOwnerCandidates()`), left in
  place for the same reason `IdCard::isValid()` stays a real accessor
  rather than being deleted for having no failing case to exercise: an
  unreachable path in application code is not the same claim as an
  unrenderable one in the view. **Scheduled to Phase 13 on 2026-09-09** as a
  confirmation item, not a test: the whole claim rests on one partial index
  keeping its exact predicate, which is worth re-checking during the
  security review rather than inheriting on trust.
- **Query D's "Resolve" link has nowhere to actually resolve a zero-owner
  unit, and this phase did not build one.** Architecture §14 describes the
  action as "designate one," but no service method does that for a unit
  that currently has none — `promotePrimaryOwner()` and
  `transferPrimaryOwnership()` both require an existing outgoing owner.
  Confirmed as a real, user-facing gap (not a hypothetical) while checking
  this phase against the Units page for inconsistencies, then explicitly
  deferred rather than built or silently left undocumented — recorded in
  architecture §15 as its own entry. **Scheduled to Phase 13 on 2026-09-09**
  by explicit user decision, once Phase 11 merged; see that phase's
  checklist for the shape the action has to take.
- **`docs/design/screen-inventory.md`'s Reconciliation dashboard row still
  reads "Planned."** Every other phase's rows in that table were left at
  "Planned" too when their screens shipped (Phases 6–10 included) — that
  table has never been kept current after Phase 5, and fixing it phase by
  phase would mean this phase alone paying down debt it didn't create.
  Left for Phase 14's consistency pass (renumbered 2026-09-15, was Phase 15),
  matching precedent rather than setting a new one here.

---

## Phase 12 — Templates & rendering

**Goal:** CR80 card rendering, a drag-and-drop placement editor, and — by
two deliberate deferrals from Phases 8 and 9 — the card-facing screens
that had nowhere to live until now.

**Unblocked 2026-09-10** by explicit designer input, which reversed one
earlier decision (§15's "no canvas" deferral) and added several new ones
not in the original checklist below — see CLAUDE.md rules 53–57 and
architecture §10 for the reasoning, not repeated here.

- [x] **"Issue ID" GUI screen (owner/tenant/employee), deferred here from
      Phase 8** — `pages.id-cards.issue`, built against the already-merged,
      already-tested `IssuanceManager` and `IdCardPolicy::issueEmployee()`.
      A person picker, an optional unit override for owner/tenant, and a
      Superadmin-gated employee tab with position/department.
- [x] **Card lifecycle GUI screen (mark lost / revoke / expire), deferred
      here from Phase 9** — `pages.id-cards.index`/`show`, built against
      the already-merged, already-tested `IdCardLifecycleManager`. Gated by
      a new `IdCardPolicy::manageLifecycle()` ability, narrower than the
      existing `view()`/`viewAny()` — those deliberately include Reader for
      the verify flow's own internal single-card check, a different screen
      entirely that this one must not be reachable through.
- [x] Template CRUD, Superadmin-only, front/back artwork upload to the
      private disk — renamed to `overlay_path_front`/`overlay_path_back`
      in the same migration that adds the CR80 dimension check and the
      at-most-one-active-per-`id_type` partial unique index (rule 26 —
      the original migration is untouched)
- [x] **Orientation** (portrait/landscape) chosen at template creation,
      before any artwork — sets `width_px`/`height_px` to one of exactly
      two CR80-at-300-DPI pairs, never edited after (rule 55)
- [x] **Compositing order inverted**: white canvas → fields → uploaded PNG
      last, on top — the overlay frames a field as a border rather than
      sitting beneath it (rule 53)
- [x] **Drag-and-drop placement editor** (Alpine.js over the uploaded
      front image), with six alignment buttons (the four edges, center
      horizontal, center vertical) and numeric x/y/width/height inputs as
      a fallback — reverses §15's "no canvas" deferral by explicit
      decision, 2026-09-10
- [x] **Alpha-channel check on save**: opaque artwork over the QR box
      refuses outright; over any other field it warns, with a
      confirm-and-retry dialog (rule 54)
- [x] `is_active` per `id_type`, enforced as at-most-one via a partial
      unique index, not merely a convention (rule 56)
- [x] Server-side compositing via **plain GD** — not Intervention
      Image/Imagick, which this codebase never installed; `php8.3-gd` was
      already provisioned for Phase 6's photo pipeline, so no deploy
      change was needed (rule 39 — checked, not just skipped: confirmed
      against `provision-app.sh`)
- [x] QR rasterized directly from `bacon/bacon-qr-code`'s own module
      matrix (`QrCodeGenerator::rasterFor()`) — the library ships no GD
      backend, only SVG/EPS/Imagick, none of which this box can use to
      produce a raster cheaply; a QR is a plain module grid, so drawing it
      directly is simpler than implementing the library's full path-based
      rendering interface
- [x] Name auto-shrink — TrueType via a Superadmin-uploaded font
      (`/fonts`, single `.ttf` or a `.zip` of several) when one is
      active, GD's five built-in sizes otherwise — never a hard
      dependency on a font existing (see traps)
- [x] Preview-quality output only
- [x] Rendered output is servable and saveable — `IdCardRenderController`
      returns a plain `image/png` response per side, viewable inline and
      downloadable via the browser's own save-image, not a dedicated
      download button. No printer integration of any kind; printing
      happens in separate, external card-printer software

**Traps:**
- No historical-reprint feature. `template_id` is provenance — resolved
  fresh at issuance *and* at every replacement, never inherited from the
  card being replaced (rule 57).
- Never accept a card-design tool's own project file (whatever proprietary
  binary format that software saves) as a template upload — only a plain
  raster image (PNG). Converting from the design tool's format to PNG is the
  admin's job, outside this system (§10).
- **The control number is no longer printed as text anywhere on the
  card** — only inside the QR. This makes a clause in architecture §8 and
  CLAUDE.md rule 15 false as written ("the number is printed on the card
  anyway"); both are reworded in this phase's branch. The actual security
  conclusion is unchanged — a plaintext QR was never a secret container
  either way, decodable by any phone's camera regardless of whether the
  number also appeared as text.
- **An opaque overlay upload is a silent failure with no natural
  symptom.** With the artwork drawn last, a template with no transparency
  renders as just the artwork — every field invisible beneath it — and
  nothing about the result looks broken. `TemplateManager`'s alpha check
  exists because there is no other way this would ever be caught.
- **Closed same day: no font is bundled in the codebase — a Superadmin
  uploads one through the GUI instead** (`fonts`, `FontManager`,
  `pages.fonts.index`). `resources/fonts/CardFont.ttf` (this trap's
  original text) never shipped; a static bundled-asset path was rejected
  once it was clear an upload screen was the right shape, since
  `resources/` is source code an operator's next deploy would silently
  wipe. `CardRenderer` still falls back to GD's five built-in bitmap
  sizes whenever no font is active — a real, tested rendering path, not a
  stopgap — so a fresh install with nothing uploaded yet still renders
  legible cards.
- **The drag editor works in display pixels, the renderer in
  render-resolution pixels** (1011×638 or 638×1011). Coordinates are
  scaled back to render resolution before `$wire.savePositions()` is
  called — verified with a real pointer-driven save round-trip in a
  browser, not only through Pest, since Pest cannot exercise Alpine's
  drag/scale logic at all.
- **Orientation cannot be changed on an existing template** — a different
  orientation means creating a new one. This is a deliberate refusal, not
  a missing feature: a saved field position is meaningless against a
  canvas that changed shape out from under it.

**Done when:** a Superadmin can choose an orientation, create a template,
upload both sides at the dimensions it implies, place every field by
dragging (or numeric fallback), save (accepting the confirm-and-retry for
a non-QR warning, refusing outright for the QR), and activate it; an
Admin cannot reach template management at all; an Admin (or Superadmin)
can issue an owner/tenant card through the Issue ID screen and a
Superadmin can issue an employee card; the issued card's `template_id`
resolves to whatever was active at issuance time; a rendered front/back
PNG is servable through `IdCardRenderController`, gated so a Reader can
never reach it (the front embeds the photo with no 60-second window);
mark lost/revoke/expire all work from the Card lifecycle screen, with
mark-lost redirecting to its replacement; a Superadmin can upload a font
(or a zip of several) and activate one, and card text renders with it. ✅
All proven — 423/423 tests, 0 Pint issues, 0 Larastan errors, plus the
drag editor's save round-trip and a real font's actual rendering both
confirmed by hand (the former because Pest cannot reach Alpine's pointer
handling at all; the latter because no font file is safe to commit into
this repo's test suite without confirming its exact license).

**Implementation notes, 2026-09-10:**

- **A pre-existing Larastan-visibility gap surfaced and was fixed in the
  same branch**, the same class Phase 6 already documented for `Person`:
  `IdCard`'s `person()`/`unit()`/`template()` relations lacked the
  `BelongsTo<Related, $this>` generics Larastan needs to type a magic
  relation property, and `Person::photo_path`'s Phase-6 nullability
  relaxation (a raw `ALTER TABLE`, invisible to Larastan's static
  migration parser) was never added to `Person`'s own `@property` block.
  `CardRenderer` was the first caller to actually touch `$card->person`
  and `$person->photo_path` together, which is what surfaced it. Fixed at
  the model, not worked around at the call site — see `IdCard.php` and
  `Person.php`.
- **`overlay_path_front` and `field_positions_front` both had to become
  nullable**, beyond the rename the migration was originally written for
  — the original schema made a template `NOT NULL` on both from the
  moment it existed, back when a template couldn't exist without
  background artwork already in hand. The new flow creates a template
  (name, `id_type`, orientation) *before* any artwork or positions exist,
  which the original constraint made impossible. Caught by actually
  trying `Template::create()` with neither, not by inspection.
- **The "no historical reprint" principle (rule 13) turned out to apply
  one layer deeper than Phase 9 left it.** `IdCardLifecycleManager::
  replace()` previously carried the retiring card's own `template_id`
  forward onto its replacement — harmless when `template_id` was always
  null, but wrong now: a replacement is a fresh issuance in every sense
  that matters, so it re-resolves the currently active template rather
  than inheriting a possibly-retired one from the card it succeeds (rule
  57).

**Follow-up, same day (2026-09-10), same branch:** the font gap the phase
originally shipped with — no TrueType file bundled, `CardRenderer` degrading
to GD's built-in fallback — is closed. Rather than bundling a static file at
a fixed path in `resources/`, a Superadmin uploads one (or a `.zip` of
several — a whole family package in one action) through a new `/fonts`
screen; `FontManager` validates it two layers deep (sfnt magic bytes, then an
actual `imagettfbbox()` call — a file can pass the first and still be a font
GD can't read) and stores it on the private `local` disk, the same one
photos and template overlays already use. `resources/` was rejected as the
storage location once it was clear this needed to be an upload, not a
bundled asset: it's source code, and a real deploy's `git reset --hard`
would silently discard anything dropped there between deploys. At most one
font is active at a time (`uq_fonts_active`), same partial-unique-index
shape as `uq_templates_active_per_id_type`. `CardRenderer`'s fallback path
is unchanged and still real — a fresh install with nothing uploaded yet
still renders legible cards. See CLAUDE.md rule 58 and architecture §10.
Verified end to end with a real, redistributably-licensed font (GNU
FreeFont, found locally, bundled with pygame) — uploaded through
`FontManager::upload()`, activated, and used to render actual anti-aliased
text on a card, by hand outside the committed test suite; the committed
`FontManagerTest`/`FontPageTest` deliberately don't depend on any real font
file, since one isn't safe to commit into this repo without confirming its
exact license, and none is needed to exercise the validation, activation, or
deletion logic.

**Second follow-up, same day, same branch — six fixes from a first real
usability pass:**

- **Real bug, found and fixed: Mark lost/Revoke/Expire's confirmation
  dialog never actually opened in a browser**, despite every Pest test for
  it passing. The dialog used `x-show="{{ $pendingAction ? 'true' : 'false'
  }}"` — Alpine compiles an `x-show` expression into a fixed closure at
  directive-init time and never re-parses it just because Livewire's morph
  later patches the raw attribute string; the dialog was permanently stuck
  showing whatever it evaluated to on first page load. Pest's component
  tests called `stage()`/`confirmStaged()` directly, which exercises the
  underlying Livewire property but never renders or diffs real DOM, so
  they had no way to catch this. Fixed by replacing the Alpine `x-show`
  with a plain server-driven `@if($pendingAction)` — this modal's
  visibility is entirely PHP state, and Livewire's own re-render already
  includes/excludes the markup on every request, so Alpine was never
  needed here at all. The identical pattern existed in the template
  editor's own obscured-field confirm dialog and was fixed the same way.
  Both regression tests were strengthened to `assertSee`/`assertDontSee`
  the dialog's real markup rather than only the underlying property, which
  is what actually would have caught this the first time. Confirmed fixed
  by hand in a real browser — clicked through mark-lost and revoke, both
  completed correctly end to end.
- **The template editor's default field positions are centered**, not
  scattered diagonally from the corner — every field starts horizontally
  centered on the canvas and stacked top-to-bottom in reading order
  (photo, name, unit number, role, QR), still fully draggable from there.
- **The name field renders "First Middle Last Suffix"** (`Person::
  printedName()`), not `displayName()`'s "Last, First Middle Suffix" — a
  second, explicit printed-card convention alongside the existing UI one,
  not a replacement for it. `CardRenderer` is the only caller; the
  `entity_type` branch stays in the model per rule 37, even though a
  company never reaches it (companies never hold cards, rule 36).
- **The field placement editor shows realistic filler content** instead
  of the bare field key — "John Doe", "A0101", and the template's own
  role label (`IdCard::roleLabelFor()`, the same mapping `CardRenderer`
  renders a real card with) for the three text fields; a person-silhouette
  icon and a QR-pattern icon, not text, for photo and QR. A new
  `Illuminate\Support\Js::from()`-embedded `fillerText` map in the
  editor's Alpine state, keyed the same way `positions` already is.
- **Query B's rows link straight to Issue an ID**, prefilled with the
  person, not just to their People record — `id-cards.issue` gained a
  `#[Url(as: 'person')]`-bound `person_id_number`, and `<x-person-picker>`
  gained an `:initial-query` prop so the search box actually displays the
  prefilled value rather than showing empty over an already-set property.
- **Printing**: `id_cards.printed_at` (nullable timestamp, forward-only
  migration) — orthogonal to `status`, the same family of distinctions
  rule 6 already draws (a lost/revoked/expired card can still have been
  printed once; printing never changes `status`). `CardPrintService::
  print()` refuses a card already printed (no un-print — a card needing a
  new physical copy after this is a replacement, which starts
  `printed_at` at `null` again, not a reset of this one's), renders both
  sides, zips them (`front.png`/`back.png`, named by control number), sets
  `printed_at`, and audits `id_printed`, all before returning the zip —
  never left printed with no zip actually generated, or vice versa. Both
  the Card lifecycle show page and the ID Cards index return the zip via
  Livewire's `streamDownload()`-based file-download support, which is
  what turns an action's return value into an actual browser save-file
  dialog. Once printed, the button is replaced by a "Printed :date"
  indicator — genuinely gone, not just disabled, confirmed both in Pest
  (`assertDontSee`) and by hand (a real click that set `printed_at`,
  verified in the database, then confirmed the button doesn't come back
  on reload).

435/435 tests, 0 Pint issues, 0 Larastan errors after all six. See
CLAUDE.md rule 59 for the `printed_at` invariant.

**Third follow-up, same day, same branch — three invariant tightenings:**

- **Printing is now refused for any non-`active` card** —
  `CardPrintService::print()` checks `status === 'active'` before it ever
  checks whether the card was already printed. Producing a physical copy
  of a card whose entitlement no longer exists (lost, revoked, or
  expired) is the one thing this feature must never do. `printed_at`
  stays visible as a historical fact on a card that later became
  inactive — the Card lifecycle show page distinguishes three states now,
  not two: printed (badge, with date), active-and-unprinted (the Print
  button), and inactive-and-unprinted ("Cannot print — card is
  :status"). See CLAUDE.md rule 59's expanded text.
- **The manual "Expire" action is tenant-only.** `IdCardLifecycleManager::
  expire()` gained `refuseUnlessTenant()`, checked before
  `refuseUnlessActive()` — a kind-based refusal ahead of a state-based
  one, `IssuanceManager`'s own ordering for refusing a company by kind
  before checking tier. `expireForClosure()` (§5.3's automatic
  relationship-closure cascade) is deliberately untouched — restricting
  it too would have silently broken tested Phase 9 behavior for the sake
  of a rule aimed at the manual button, not the cascade; a relationship
  closing is a genuine, correct expiry of the card tied to it regardless
  of type. The Card lifecycle screen now hides the Expire button for
  owner/employee cards, and `confirmStaged()` gained a `try`/`catch` it
  was missing before — the refusal would otherwise have been a 500, not
  a clean error, for a stale page or any other path that reaches
  `expire()` without going through the button. See CLAUDE.md rule 60.
- **No two fields in a template's front may occupy the same area.**
  `TemplateManager::saveFieldPositions()` checks every pair of boxes and
  refuses the save if any two share real area; two boxes that only touch
  at a shared edge are fine. Enforced at save time only — the drag editor
  itself doesn't prevent dragging one field over another mid-edit, only
  saving that state. See CLAUDE.md rule 61.

59/59 new and updated tests passing, full suite green, 0 Pint, 0
Larastan.

**Fourth follow-up, same day, same branch — a stale-error UI bug, found
the same way the Alpine `x-show` bug was: by actually retrying an action
twice, not just once.**

Submitting the template placement editor a second time, after a first
submission had failed, left the *first* failure's error message on
screen even when the second submission succeeded — no success message,
no updated error, nothing changed. Root cause, precisely: Livewire only
clears a field's entry in the error bag when a `validate()` call for that
same key *succeeds* (`validate()` calls `resetErrorBag()` with no
arguments on success internally, wiping the whole bag). `savePositions()`
never calls `validate()` — the positions payload is raw JS, validated
entirely by `TemplateManager`, not Livewire's rule engine — so an error
added via `addError('positions', ...)` on one failed attempt sat in the
bag forever, surviving every later call to that method, success or
failure, until a full page reload reset the component from scratch.

Fixed with `$this->resetErrorBag('positions')` at the top of the method,
before the `try` block — the same one-line fix applied to every other
Livewire action in this phase with the identical shape (an `addError()`
whose failure path has no accompanying `validate()` call):
`TemplateManager`-backed `activate()` and `delete()` on the same page,
`FontManager`-backed `delete()` on `/fonts`, and `CardPrintService`-backed
`print()` on the Card lifecycle show page. See CLAUDE.md rule 62 for the
general shape of this bug, so it doesn't get reintroduced by a future
action written the same way.

The same investigation surfaced the identical bug, unfixed, in
`users/index.blade.php` (`toggleActive()`/`changeRole()`) — Phase 3/4,
already merged, out of this branch's scope. Spawned as its own follow-up
task rather than fixed here.

Every fix has a regression test that reproduces the actual reported
shape — fail once, then succeed, and confirm the error is gone (not just
that the underlying property looks right, the same distinction the
`x-show` regression tests already had to learn). 448/448 → tests grew
again with this pass; full suite, Pint, and Larastan all green.

---

## Phase 13 — Security review

**Goal:** confirm — not build — that the invariants every earlier phase
established actually hold, and close the one genuine injection route and one
genuine DB-level gap this codebase has.

- [x] **Policy coverage audit: every route, every role, tested.** Every
      `Volt::route()`/`Route::get()` in `routes/web.php` carries `auth`
      middleware, and every mount()/action calls `$this->authorize(...)` or
      `Gate::authorize()`/`Gate::denies()` before doing anything — verified
      by reading every route and every call site, not sampled. Coverage is
      indirect (through the Volt/HTTP page tests' `assertForbidden()` cases,
      one per screen) rather than direct Policy-class unit tests, which is
      the more meaningful shape: it exercises the real `authorize()` call
      site, not the policy method in isolation. One real finding:
      **`SecurityEventPolicy` is fully written but nothing calls it** — no
      route or screen ever reads `security_events` back; the table is
      written to (failed logins, `authorization_denied`, `qr_verify_miss`,
      `deletion_blocked`) but never displayed. Not a hole — nothing is
      unprotected, there's simply no consumer — but worth knowing rather
      than assuming its `viewAny`/`view` gates are exercised by anything.
      Building a viewer is new scope beyond this phase's own checklist;
      flagged (CLAUDE.md rule 66) rather than built.
- [x] Confirm no `Artisan::call()` is reachable from HTTP — grepped
      `app/`/`routes/`, zero hits. The wizard calls the account-creation
      service directly, exactly as architecture §12 requires.
- [x] Confirm no public disk, symlink, or unauthenticated file route exists —
      `FILESYSTEM_DISK=local`, no `Storage::disk('public')` call anywhere,
      no `public/storage` symlink on disk.
- [x] Confirm no scheduler, queue worker, or cron beyond the backup job —
      `routes/console.php` carries only the Laravel-default `inspire`
      command, no `$schedule->` calls anywhere in `app/Console/`,
      `QUEUE_CONNECTION=sync`.
- [x] **Confirm no query takes a column name, table name, or sort direction
      from user input.** Every `orderBy()`/raw-SQL-family call site in
      `app/` and the Livewire pages checked individually: every one either
      takes a literal string constant, or — `HasSortableColumns::
      applySort()` — resolves through `$columns[$this->sortColumn] ?? null`,
      the allowlist *value*, never the raw user-supplied key. Confirmed
      structurally correct, exactly as the trait's own docblock claims.
- [x] **Approach B audit immutability.** Built differently than planned —
      see CLAUDE.md rule 63 and architecture §15 for the full story:
      `REVOKE` turned out to be a no-op against this deployment (the app's
      DB user owns these tables, and PostgreSQL ownership overrides
      `REVOKE` unconditionally), so a `BEFORE UPDATE OR DELETE` trigger
      (`reject_append_only_mutation()`) is the real backstop instead —
      proven directly with a raw `DB::table()` write bypassing Eloquent's
      `AppendOnly` guard entirely.
- [x] **DB-level trigger enforcing a company can only ever be a unit's
      primary owner.** Built as `enforce_company_primary_owner_only()`
      (CLAUDE.md rule 64), firing on both `INSERT` and `UPDATE` since the
      rule is a standing state, not just an entry condition. Proven against
      the exact raw-`INSERT` bypass confirmed 2026-09-09. Surfaced a real
      gap in two existing tests along the way — `IssuanceManagerTest` and
      `ReconciliationQueriesTest` had each been constructing "a company
      with an ordinary co-owner relationship" directly via the factory to
      exercise downstream refusal logic, a state the trigger now makes
      uncreatable at any layer, factories included. Fixed by switching both
      to the `primaryOwner()` factory state, a state a company can actually
      be in.
- [x] **Build the "designate a primary owner" action for a unit that has
      none.** `UnitLifecycleManager::designatePrimaryOwner()` (CLAUDE.md
      rule 65), reachable from a live unit's own show page — the route
      Query D's "Resolve" link has pointed at since Phase 11. Refuses
      outright if the unit already has an active primary owner (that's
      promotion's or transfer's job). **Superadmin-only** — a deliberate
      departure from promotion/transfer's Admin-reachable gate, since this
      repairs data corruption rather than performing routine reassignment;
      needed its own `UnitPolicy::designatePrimaryOwner()` ability rather
      than reusing `update`, caught only because the checklist's own text
      said "Superadmin-only" and the first draft didn't match it.
- [x] **Confirm Query D's "several active primary owners" branch is still
      unreachable.** Directly proven, not just reasoned about: a raw
      `INSERT` giving a unit a second active `is_primary_owner` row, in a
      transaction rolled back afterward, still throws
      `UniqueConstraintViolationException` against
      `uq_pur_one_primary_owner_per_unit` with its exact original
      predicate.
- [x] **Review `security_events` volume and retention.** Reviewed, no
      retention mechanism built: `audit_logs`/`security_events` are
      append-only by design (rule 8, and now rule 63's DB trigger) — an
      in-app pruning job would need to delete or update rows, which is
      exactly what this phase just made structurally impossible, on
      purpose. Retention, if ever needed, belongs at the operations layer
      (a DBA-run archival step outside the app), not inside it, and rule 1
      rules out a scheduler doing it automatically either way. Volume
      remains genuinely unverified at real scale — the same gap Phase 4
      flagged and this phase's own re-verification below couldn't close,
      since this database still has no production-scale data. Carried to
      Phase 17 (Production cutover — renumbered 2026-09-15, was Phase 14).
- [x] **Re-verify Phase 4's three flagged-unverified items**, now that real
      multi-type data exists (not itself an original checklist line, but
      the checklist's own confirmation-first spirit called for it since two
      of the three items are finally checkable):
      - **The subject-type filter's multi-type exclusion** — closed with a
        real test (`AuditViewerTest`): logs a `Person` and a `Unit` event,
        filters to one `getMorphClass()`, and asserts the other type's
        `new_value` payload is absent. (Checking for the bare action
        string, e.g. `'person_created'`, gives a false failure — the
        filter form's own `<datalist>` autocomplete always lists every
        known action globally, filtered or not.)
      - **`subjectWithTrashed()`'s non-SoftDeletes branch** — closed the
        same way: every prior test used `User`, which has SoftDeletes, so
        `in_array(SoftDeletes::class, ...)`'s false branch had never
        actually run. `IdCard` never has SoftDeletes (rule 7) — a real
        subject that genuinely takes that path, and now does in a test.
      - **Behavior at real volume** — still open; this database has no
        production-scale data to test against. Carried to Phase 17, same
        as the retention item above.
- [x] Confirm the audit viewer escapes stored request data — grepped every
      Livewire page for `{!! !!}`, zero hits anywhere in the tree.
- [x] Dependency audit — `composer audit` against the locked tree: no
      security vulnerability advisories found.

**Done when:** every audit item above has an explicit pass/fail recorded
(not silently assumed), the two DB triggers exist and are proven to fire
against a raw-SQL bypass attempt, the primary-owner-designation action works
end-to-end from Query D's Resolve link, and `composer audit` is clean or
every finding is triaged. ✅ All proven — 481/481 tests, 0 Pint issues,
0 Larastan errors.

**Traps hit, not just anticipated:**
- **`migrate:fresh` on Postgres drops tables but not standalone functions.**
  A bare `CREATE FUNCTION` in the first draft of both trigger migrations
  collided with a leftover function from an earlier manual `migrate` run
  the moment `migrate:fresh` re-ran them from a clean slate — the function
  from the *previous* fresh survived, the table it was attached to didn't.
  Fixed with `CREATE OR REPLACE FUNCTION`/`CREATE OR REPLACE TRIGGER`
  (PG 14+, this deployment runs 16) in both migrations.
- **A DB trigger that fires "regardless of caller" also fires against test
  infrastructure that assumed it couldn't be reached that way.**
  `UserAccountManagerConcurrencyTest` — the one test directory that opts
  out of `RefreshDatabase` and commits real rows across two live
  connections — cleaned up its own fixtures with a raw
  `DB::table('audit_logs')->delete()`, justified at the time as "test
  cleanup, not a business path." The new append-only trigger doesn't
  distinguish; neither does the app's own DB role hold Postgres superuser,
  so there is no legitimate bypass available even for test cleanup. Fixed
  by not needing the delete at all: fixture usernames now carry a random
  per-run suffix so they never collide, and a `beforeEach` resets the
  *global* active-Superadmin count to a deterministic baseline via a plain
  `UPDATE` (untouched by either trigger) before each test builds its own
  fixture count on top of it.
- **Two existing tests had been using a database state the new company
  trigger makes permanently impossible to construct.** See the trigger
  item above — not a flaw in either the trigger or the original tests, just
  the trigger doing exactly the job it was built for a beat before the
  fixtures caught up.

---

## Phase 14 — Codebase refactoring & cleanup

**Reordered 2026-09-15 (explicit user decision):** this phase and Phase 15
below now run before Production cutover, not after — renumbered from 15 to
14. Production cutover moved to Phase 17, leaving Phase 16 open for
something not yet planned. See that phase's own note for why: most of its
checklist is physical/production work an AI assistant can't execute, so
refactoring and the deploy-script polish are useful, mergeable work to do
first rather than wait on it.

**Goal:** pay down whatever accumulated across Phases 0–13 without changing
behavior. Fourteen phases of incremental delivery leave duplication and
inconsistency that a mid-phase refactor would have been premature to fix —
this is where it gets fixed deliberately, all at once, with the full test
suite as the safety net.

- [x] **Duplication audit.** Five real findings, extracted — and two that
      only looked like duplication, deliberately left:
      - **Unit row lock** — `Unit::where('id', $x)->lockForUpdate()->first()`
        spelled out at seven call sites across five services →
        `Unit::lockById()` (rule 17).
      - **Occupant cap** — a bare `6` in six capacity comparisons across five
        services → `Unit::OCCUPANT_SLOTS` (rule 31). The comparisons stay at
        their call sites: five check a current count (`>=`), transfer checks
        a projected post-operation count (`>`). One helper would have erased
        that distinction.
      - **Person-picker options** — four builders across three pages, two
        distinct filters, one "ID number - Name" format written out four
        times → `Person::naturalPickerOptions()` /
        `contactablePickerOptions()` over one private formatter; a name still
        reaches it only through `displayName()` (rule 37).
      - **Printed-name field list** — already defined once, but as a literal
        inside `people/show.blade.php`, a Volt class Larastan cannot see
        (rule 49) → `Person::PRINTED_NAME_FIELDS`, in `app/` where a
        reviewer tracing rules 10–11 would look.
      - **Security-event writes** — `SecurityEvent::create()` written out by
        hand at seven call sites, with the IP derived two ways (four bare
        `request()->ip()`, three console-aware) → `SecurityEventLogger`,
        `AuditLogger`'s sibling (rule 43). Missed by the first pass, which
        checked audit rows (already one writer) but not security events
        (none); caught when the user asked for a second look. The four bare
        sites only ever run inside web requests, where both forms agree.
        Under Pest, which runs in the console, their test rows now record a
        null IP instead of `127.0.0.1` — no test asserts one, and it is the
        same split `AuditLogger` already has.
      - **Primary-owner relationship open + audit** looked identical in four
        places; two genuinely were (`createUnit()` and
        `UnitDeletionManager::restore()`, whose own comment already admitted
        the copy) → `UnitLifecycleManager::openPrimaryOwnerRelationship()`.
        The other two stay bespoke on purpose, and the helper's docblock says
        why: `transferPrimaryOwnership()` writes two audit rows *between* its
        insert and its `relationship_opened` (folding it in would reorder an
        append-only trail), and `designatePrimaryOwner()` re-checks rule 30
        between insert and audit so a failed designation logs nothing
        (rule 45).
      - **Left alone after review:** audit-row call shapes (already one
        writer, rule 43 — the payloads differ because the events do), and the
        name validation rules `people/create` and `people/show` both carry
        (create branches on an unsaved `entity_type`, edit reads the stored
        one, immutable per rule 35 — the two builders differ in exactly the
        input they depend on).
- [x] **Consistency pass — the mechanical part.**
      `docs/design/screen-inventory.md` brought current (the sweep Phase 11
      deferred here): every Phase 6–13 row still read "Planned" though
      shipped, and screens that never made the list — card list/detail,
      fonts, designate-primary-owner — are now in it.
      `UnitLifecycleManager::createUnit()` carried two stacked docblocks, so
      PHP read only the second and the `@param`/`@return` block was dead —
      merged. Three exceptions documented their constructor parameters on
      the class docblock, where PHPStan never reads them — moved. Phase 3/4
      screens (Users, Audit Log) stay on their original markup, per rule 48.
- [x] **Consistency pass — the 2026-09-07 "cosmetic" People/Units note.**
      Closed 2026-09-15 with nothing built: it turned out to be edit-in-place
      scope, not a sweep — see the note below.
- [x] **Dead code, unused routes, leftover scaffolding.** Found with a script
      counting every `app/` method's references across `app/`,
      `resources/`, `tests/`, `database/`, `routes/` and `config/`
      (framework-invoked methods — trait `boot*()`, attribute accessors —
      cleared by hand). Removed: `Unit::nonPrimaryOwnerActiveRelationships()`
      (superseded by the Count variant, which is scoped differently on
      purpose), `AuditLog::subject()` (superseded by `subjectWithTrashed()`),
      `User::auditLogs()` and `User::securityEvents()` (never traversed), the
      three imports they orphaned, `SecurityEventPolicy` (rule 66, explicit
      user decision), and Breeze's `confirm-password` screen, route and its
      three tests — unreachable, since no route carries the
      `password.confirm` middleware, and never mentioned in any doc. It
      survived Phase 3's strip because, unlike the password-reset pieces, it
      boots fine. Routes: all 30 names checked — the four that looked unused
      are passed to `<x-nav-item route="...">` as strings, `home` is the site
      root, and `dev.components` is the local-only gallery reached by URL.
- [x] **Larastan 5 → 6.** See `phpstan.neon`'s own note. All 33 level-6
      findings were missing annotations (relation/Attribute/Collection
      generics, array value types), and adding them surfaced two real
      defects level 5 couldn't see: the stacked docblock above, and
      `DeletionBlockedException::$detail` documented as
      `array<int, string>` while both callers pass an associative jsonb
      payload. Level 7 not taken: its 22 findings are GD calls ignoring
      documented `false` returns plus `findOrFail()`'s union return —
      deciding what a render does when GD fails is behavior, and this phase
      changes none.
- [x] **Test-suite audit.** No flake observed across repeated full runs. The
      one known flake pattern — `IdCardFactory`'s random owner/tenant
      `type`, which bit in Phase 12 — has 41 remaining un-pinned uses; one
      exercised a type-sensitive action (`IdCardPageTest`'s "confirming a
      staged action without a reason is refused" stages `expire`,
      tenant-only per rule 60) and passed only because reason validation
      runs before the lifecycle call. Pinned to `tenant`. Per-test timing
      was not profiled: total runtime is ~2.5 min on this machine, with one
      ~7 min outlier during this phase — worth profiling if that recurs.
      Policy/transaction coverage was audited in Phase 13; this phase
      removed only tests belonging to deleted code.
      **Hotfix, 2026-09-15 (found in Phase 15's CI, CLAUDE.md 27's
      carve-out — it blocked PR #20):** a second flake pattern this audit
      missed. `UnitPageTest`'s contract-end-date test (added 2026-09-09)
      checked the raw HTML for a factory-generated name, but Blade escapes
      an apostrophe (`O'Hara` renders as `O&#039;Hara`), so the test failed
      whenever Faker produced one — the same commit passed on its push run
      and failed on its pull-request run. It now names the tenant `O'Hara`
      on purpose and compares against `e()`, so every run exercises the
      escaping. A sweep for the same pattern found one more:
      `AuditViewerTest`'s subject-filter test asserted a raw name was *not*
      on the page — vacuously true for any name with an apostrophe, so it
      could pass with the row shown. Same fix. `assertSee()` escapes on its
      own and was never affected.
- [x] **Consistency pass — the visible part (2026-09-15, explicit user
      decision).** Asked whether anything else was inconsistent before the
      phase closed, the user chose to fix these three here. They are the one
      deliberate exception to "no behavior changes": each changes what a
      screen looks like, none changes what it does.
      - **Flash messages** — six hand-written banners in four color/size
        combinations across five screens (People and Units detail, Card
        list, Fonts, Template editor) → `<x-toast :message>`, a server-driven
        mode added to the shared component. Messages still stay on screen
        until the next action (the user's choice, over the component's
        three-second auto-hide, which remains its event-driven mode). Login
        keeps Breeze's `<x-auth-session-status>`: password rotation and the
        setup wizard redirect there.
      - **Relationship tables** — the person and unit pages' relationship
        lists were the only raw `<table>`s outside the Phase 3/4 screens
        → `<x-data-table>` + `<x-data-table.empty>`. The same
        `closeRelationship()` read "End" on the person page and "Close" on
        the unit page → "Close" on both (the user's choice), including the
        browser prompt and the card-expiry dialog ("Close and expire").
      - **Confirm dialogs** — the card lifecycle dialog (lost / revoke /
        expire) and the template artwork warning were hand-built overlays
        with their own look, because `<x-confirm-dialog>` closes itself in
        the browser the moment Confirm is clicked and would hide the reason
        field's validation error → a server-driven mode on the component
        (`:open`, `cancelAction`, `cancelLabel`), rendered as `<x-modal>`'s
        panel. Button labels unchanged.
      Also raised in the same review and split out, each onto its own branch
      from `main` (rule 27): a user's own display-name change writes no audit
      row, and a Reader lands on an empty dashboard after login instead of
      Verify. The shared `SecurityEventLogger` above came out of the same
      review.
- [x] **No behavior changes.** Baseline before any change: 483 tests /
      1,230 assertions. After: 480 / 1,221 — the difference is exactly the
      3 tests / 9 assertions of `PasswordConfirmationTest`, counted before
      its deletion. Pint clean; Larastan clean at level 6. That holds for the
      refactor; the visible pass above is the one deliberate exception, and
      it changed no test's expectations — it added its own
      (`SharedComponentModesTest`), as did `SecurityEventLogger`.

**Done when:** no known duplication remains, Pint/Larastan are clean, the
full test suite is green before and after with identical results, and a
reviewer can trace every printed-field/capacity/lock rule to exactly one
implementation. ✅ Met for the code — capacity is `Unit::OCCUPANT_SLOTS`,
the lock is `Unit::lockById()`, the person-side printed fields are
`Person::PRINTED_NAME_FIELDS`, and the pre-existing suite matches its
baseline exactly. Final run with this phase's own additions: 487 / 1,244,
all green — exactly 480 / 1,221 plus `SecurityEventLoggerTest` (3) and
`SharedComponentModesTest` (4) with their 23 assertions. Pint clean;
Larastan clean at level 6. Every checklist line is closed. Browser-verified
2026-09-15 by real clicks, since Phase 12 proved Pest cannot see a dialog
that never opens: the card dialog stays open after a blank Confirm with
"The reason field is required." visible inside it, and Cancel closes it;
the template warning names the covered field, Go back saves nothing, and
Save anyway saves; both relationship tables read "Close"; flashed messages
render through `<x-toast>`. The check was briefly blocked because the local
`.env` pointed the dev server at the test database, so every suite run
wiped the dev data — a local-config fix, not code (the documented name was
always `qr_id_generator`).

**Trap:** forward-only migrations still apply — this phase cleans up
application code, not shipped migrations.

**Deferred here, 2026-09-07 — closed 2026-09-15, nothing built.** The
original note read "cosmetic naming and layout inconsistencies noticed across
the People/Units screens," a paraphrase of the user's "certain cosmetic
changes with the names and layout." It recorded no specifics, so this pass
pulled the session transcript: the instruction was the user's reply to a
2026-09-07 16:01 message about adding a Reset button, which ended by offering
edit-in-place forms for a unit's floor/number and for user details. The user
clarified on 2026-09-15 that the note concerned exactly those, and that
neither is wanted — **a unit's code stays fixed at creation, and account
details stay at exactly today's surface** (CLAUDE.md rule 67). Person-detail
editing, the one thing the user did want, was already in place: built in
Phase 6, with Phase 9 adding the printed-field reissue and 2026-09-08/09
adding contract-end-date editing. No code change.

---

## Phase 15 — Proxmox helper script polish

**Goal:** take `deploy/proxmox/create-qrid-stack.sh` from "works, with some
hand-holding" (its state after Phase 2) to genuinely user-friendly, folding
in everything learned from real hands-on testing along the way.

- [x] Fix every rough edge accumulated during Phase 2's real-world testing
      that wasn't worth blocking Phase 2 for. Only the three named in the
      items below were ever recorded; all three are fixed. Two more
      surfaced while making re-runs safe: the backup cron line was
      appended again on every run (now replaced, never duplicated), and a
      re-run left the database role on its old password while the app
      switched to a fresh one — a Phase 2 hotfix, recorded under Phase 2
- [x] Input validation on every interactive prompt (reject invalid CTIDs,
      non-numeric memory/disk, malformed domains) instead of failing deep
      into provisioning with an opaque error. Every setting — typed into a
      dialog, preset as an environment variable, or defaulted — passes one
      `preflight()` before anything is created: container IDs (free
      cluster-wide, or ours to resume), hostnames, cores and RAM against
      this host, disk against the chosen storage's free space, storage
      content types, the bridge, SQL identifiers, the backup path, the sudo
      username. Every problem is listed at once. There is no domain to
      validate — the app is served by IP. The pure validators live in
      `qrid.func` and are tested in CI (`tests/test-validators.sh`)
- [x] Idempotency review: safe to re-run against a partially-created stack
      without manual cleanup (Phase 2 testing hit a stuck half-created
      container that needed a manual `pct destroy` before retrying).
      Containers are tagged `qrid-db` / `qrid-app` and resumed by tag —
      pre-Phase-15 stacks by hostname — and anything else at a chosen ID
      is refused. The bind mount is skipped when already attached, the cron
      line replaced, the database password reset. A failed run offers to
      remove only the containers it created, recorded *before* `pct create`
      so a create that dies halfway is found too — that half-built
      container was exactly the Phase 2 case
- [x] Clearer progress output and error messages throughout, continuing the
      pattern started by Phase 2's sibling-fetch error message. One ✔ / ✖
      line per step with a spinner; command output goes to a root-only log
      under `/var/log/qrid/`; a failure names the step and shows the log's
      last 15 lines; `QRID_VERBOSE=yes` streams everything instead
- [x] Consider adopting more of the community-scripts `build.func`
      conventions (whiptail dialogs, a Default/Advanced menu) if it
      genuinely improves the experience without adding fragile dependencies.
      **Adopted as conventions, not code (decided 2026-09-15):** our own
      `deploy/proxmox/qrid.func` — banner, `msg_info`/`msg_ok`/`msg_error`,
      a `$STD`-style `run()`, one error trap, whiptail dialogs with a
      plain-prompt fallback — plus `var_*` settings (Phase 2's names kept
      as aliases), a Default / Advanced menu, and a Notes panel on each
      container in the Proxmox UI. Their framework is never sourced: it is
      ~8,000 lines of third-party code run as root on every deploy, it has
      already moved repositories once, and it sends telemetry
- [x] Update `deploy/proxmox/README.md` to match the final flow exactly
- [x] Suppress the harmless `perl: warning: Setting locale failed` noise that
      `pct exec` prints on every invocation (LANG/LC_ALL aren't propagated
      into the container's exec environment) — cosmetic, but it clutters
      every command's output during Phase 2 testing. `ct_exec()` pins
      `LANG`/`LC_ALL` to `C.UTF-8`, which every image has, on every call
- [x] **Update mode (added 2026-09-15, explicit user decision; the `update`
      command added 2026-09-16, user request).** Every container the script
      builds gets an `update` command — community-scripts' own convention —
      plus `/etc/qrid-role` saying which container it is. Typing `update`
      downloads the current `create-qrid-stack.sh` from the stack's branch
      (`main` normally; the repo publishes no GitHub Releases, so "the latest
      release" is `main`) and runs it in the container, which works out
      where it is: the **app container** refreshes its Condo ID scripts,
      pulls, builds, migrates and restarts PHP (`deploy.sh`), then installs
      its OS package updates; the **database container** refreshes its
      backup script, installs its OS package updates — PostgreSQL's minor
      releases included — and checks PostgreSQL is still running. Package
      upgrades keep this system's edited config files (`--force-confold`)
      rather than stopping to ask. A Yes quiet / Yes verbose / No menu, or
      quiet with no terminal. On the Proxmox host the same script builds;
      anywhere else it refuses. Refreshing the scripts on every update is
      what lets a fix reach existing stacks, not only new ones. Hardened
      along the way: the downloaded updater runs from a private temp
      directory, and the script only uses sibling files next to a real file
      on disk — the one-liner used to look in the current directory, so a
      file planted in `/tmp` could have been sourced as root. Updates stay
      operator-invoked (architecture §7): nothing schedules them
- [x] **Multiple stacks per host (added 2026-09-15, user request).** One
      Proxmox host can hold several Condo ID stacks — one per condo — each
      fully separate. Every stack has a name, stored as a Proxmox tag
      (`qrid-stack-<name>`) on both containers next to `qrid-db` /
      `qrid-app`, and gets its own hostnames and backup directory. The first
      stack is `main` with the Phase 2 layout (`qrid-db` / `qrid-app`,
      `/var/lib/vz/qrid-backups`), so a stack built before stacks had names
      is still recognised as `main` and is tagged on its first re-run;
      later ones default to `stack2`, `stack3`… (`qrid-<name>-db`,
      `/var/lib/vz/qrid-backups-<name>`), or a chosen name in Advanced. The
      menu gained **Re-run an existing stack**, which reads the stack's IDs,
      hostnames, backup directory and database names back from its own
      containers; unattended, `var_instance=<name>` does the same.
      **Why the tags carry the stack, not just the role:** with role tags
      alone, a re-run of one stack would count another stack's database
      container as "ours" and re-provision it — and two stacks sharing one
      backup directory would prune each other's dumps. A container is now
      resumed only as part of its own stack; an ID belonging to another is
      refused with that stack named, and preflight refuses a backup
      directory another stack already uses

**Done when:** someone with no prior context can run the one-liner, answer
the prompts, and land on a working deployment without reading the script
source or asking for help. **Not yet proven** — this dev machine has no
Proxmox host. Proven locally: every script passes `bash -n` and ShellCheck
(CI's own command, now covering `qrid.func` and the tests), the 64 validator
tests and 17 update-command tests pass (the latter with a stand-in `curl`,
no network), and on a machine that is neither a Proxmox host nor a Condo ID
container the script refuses and cleans up after itself. Still owed, on a
real Proxmox host: a Default install from the one-liner (stack `main`), a
second Default install beside it (stack `stack2`, with its own hostnames
and backup directory), **Re-run an existing stack** against one of them,
and `update` typed inside each kind of container — the app container
updating its code and packages, the database container its packages.

**Trap:** this phase is about the operator-facing experience of the script
itself — don't let it drift back into changing Phase 2's actual
provisioning logic (what gets installed, how the LXCs/DB/app are wired).
That's a Phase 2 fix, landed on Phase 2's own branch, not this one.

---

## Phase 16 — Site branding

**Added 2026-09-19 (user request)**, filling the slot the 2026-09-15
renumbering left open. Decisions made at kickoff (user, 2026-09-19): any
navbar colour, with the text colour picked automatically; a logo that isn't
square is refused, not cropped; the settings live in the account menu, not
the navbar.

**Goal:** a Superadmin gives the system its own identity — logo, name,
navbar colour — without touching code or the server.

- [x] **Site settings page, Superadmin only** (`/settings/site`, the
      `manage-site-settings` Gate — one row of settings, not a model an
      admin works with, so a Gate like the reconciliation dashboard's).
      Reached from the account menu, so the navbar Phase 15 fitted under
      1,280px doesn't get an eleventh link. Settings live in a new
      single-row `site_settings` table: the id is pinned to 1 by a check
      constraint and the row is inserted by the migration, so nothing races
      to create it; further checks refuse a malformed colour or a blank or
      over-long name at the database layer too. `SiteSettingsManager` is
      its only writer, and every change writes an audit row (architecture
      §3); an unchanged save writes none
- [x] **Logo:** PNG or JPEG, at most 1 MB, square, at most 3000 × 3000
      pixels (a bigger image can exhaust PHP's 128 MB memory limit while
      it's decoded — 3000 peaks near 94 MB, measured with the framework
      loaded). It was 2048 until the browser check, when a real 2400 × 2400
      logo was refused. Re-encoded to a PNG of at most 512 × 512 with
      transparency kept, stored on the private `local` disk beside photos
      so the nightly backup takes it, the previous file deleted on
      replacement. It replaces the Laravel logo everywhere
      `<x-application-logo>` renders — the login, setup and
      change-password pages and the navbar. With no logo, a neutral
      ID-card mark shows instead; Laravel's is gone either way
- [x] **The logo route is public** (`branding.logo`): those pages show it
      before anyone signs in. Both gatekeeping middlewares let it through.
      Safe because it only ever serves the server's own re-encoded PNG —
      never an SVG, which can carry script. Recorded as CLAUDE.md rule 68 so
      "public" doesn't spread from here
- [x] **Site name:** at most 60 characters, "Condo ID System" (the
      configured app name) until set; whitespace collapsed, control
      characters refused, escaped wherever it renders. It shows on the
      login screen under the logo, beside the logo in the navbar (capped at
      8rem, the full name in a tooltip, so the navbar still fits), and in
      the browser tab title
- [x] **Navbar colour:** any `#rrggbb` colour, white by default. The text
      colours switch between dark and light by WCAG contrast
      (`App\Support\ColorContrast`) — every link, the account menu trigger,
      the hamburger, and the mobile menu. The colour is applied inline,
      since Tailwind can't build a class for a colour chosen at runtime;
      the two text sets are ordinary classes in the build. The settings
      page previews the colour live before it's saved
- [x] **Tests:** `SiteSettingsTest` (access by role and for guests; name,
      colour and logo — saved, audited, refused, shown on the login page
      and the navbar; the logo route public, before setup and during a
      forced password change) and `ColorContrastTest` (the contrast rule)

**Done when:** a Superadmin can upload a square logo, rename the site and
pick a navbar colour; all three show on the login screen and in the navbar;
Admins and Readers can't reach the page; every change is in the audit log;
Pest, Pint and Larastan green, checked in the browser.

**Result, 2026-09-19:** done. 42 new tests (`SiteSettingsTest` 26,
`ColorContrastTest` 16), full suite 534/534, Pint and Larastan
clean. Checked in the browser as a Superadmin: the site renamed ("Torre de
Florencia") shows in the navbar, the tab title and on the signed-out login page, where the logo loads without a session; a dark
navbar (`#1e3a8a`) switches every link, the name and the account menu to
light text; a real 2400 × 2400 logo uploads and replaces the mark. That
logo was first refused by a 2048px source limit — raised to 3000 after
measuring, see the Logo item above. The same measurement found person
photos had no pixel limit at all; fixed separately, as a Phase 6 hotfix
(`fix-photo-pixel-cap`).

**Traps:** Tailwind can't style a colour chosen at runtime — the colour is
inline, the contrast classes are static. Never accept an SVG logo. Don't
widen the navbar past what Phase 15 fitted under 1,280px.

---

## Phase 17 — Production cutover

**Reordered 2026-09-15 (explicit user decision):** was Phase 14; moved here
so Phases 14–15 above (refactoring, deploy-script polish) run first. Phase
16, left open then, became Site branding on 2026-09-19.

**Marked complete 2026-09-19 (explicit user instruction).** This phase is
production work on the live install, done by the user outside this
repository — it had no branch and no code. The items below are ticked on
that instruction, not verified from here.

- [x] Real data load or entry
- [x] Bootstrap the two production Superadmins **through the first-run wizard**,
      at the console of the deployed system, immediately after the deploy — not
      hours later. Until it completes, anyone who can reach the app's IP on the
      LAN can claim the system (architecture §12)
- [x] Verify the wizard route refuses once bootstrap is complete
- [x] Verify no dev seeder can run in this environment
- [x] Restore drill against production backups
- [x] Operations manual written — see below

---

## Operations manual (Phase 17 deliverable, not code)

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

---

## Phase 18 — Cloudflare Tunnel access

**Added 2026-09-19 at the user's request.** It reverses "LAN-only, never
public" (architecture §1, CLAUDE.md) — an explicit user decision: the app
is reachable from outside **through Cloudflare Zero Trust only**, never an
open router port. Found on a live install first: a tunnel service of
`https://<lan-ip>` hit Caddy's self-signed certificate (502 until No TLS
Verify was on), and visitors were redirected to the LAN IP.

**Goal:** after the helper script runs, adding one Public Hostname route in
Cloudflare Zero Trust is the only step — no edits to `.env`, the Caddyfile
or code.

- [x] **Caddyfile in the repo** (`deploy/proxmox/Caddyfile`), no address or
      hostname in it: `:80` plain HTTP for the tunnel (service
      `http://<app-ip>:80`), Caddy's own HTTPS redirect off; `:443`
      `tls internal` with on-demand certificates for the LAN; one shared
      root / `php_fastcgi` / `encode`. Caddy trusts private addresses as
      proxies, so the tunnel's `X-Forwarded-Proto: https` reaches PHP.
      `install-system-files.sh` validates it before replacing the running
      one, and runs on every provision and every `update`
- [x] **Links follow the request, not `APP_URL`** — already true of
      Laravel's URL generator. No `forceRootUrl` was added: forcing the
      root URL to the request's own host changes nothing. What was needed
      was the right scheme: `X-Forwarded-Proto` trusted, and cookies Secure
      over HTTPS (`SecureCookiesOverHttps`); `SESSION_DOMAIN` stays null
- [x] **Safeguards (user-approved):** only `X-Forwarded-Proto` trusted —
      no more `X-Forwarded-For`/`-Host`/`-Port`; the client IP from
      `Cf-Connecting-IP` (`UseCloudflareClientIp`); an unclaimed system
      refuses every request through the tunnel (403,
      `setup_via_tunnel_refused`, a forward-only migration on the
      `security_events` check); `:80` redirects anything without `Cf-Ray`
      to HTTPS. CLAUDE.md rule 69
- [x] **`qrid-set-domain <hostname>`** — validated, idempotent: sets
      `APP_URL`, rebuilds the config cache, reloads Caddy and PHP-FPM, shows
      `curl -sI`'s status line and `Location`. A re-run of the helper script
      keeps the hostname (it only replaces an IP or the default)
- [x] **`qrid-selftest <hostname>`** — services running, a tunnel-style
      request answered for the hostname, redirects on the hostname and not a
      private IP, setup finished, `APP_URL`, LAN HTTPS, LAN HTTP → HTTPS,
      and the hostname answering through Cloudflare. PASS/FAIL per check;
      `APP_URL` is WARN only, since pages don't use it
- [x] **Installer output and notes:** the exact Cloudflare steps with the
      app's real IP; README (root and `deploy/proxmox/`) with the two-step
      procedure and troubleshooting for a 502 and for a redirect to a LAN IP
      or a blank page
- [x] **Tests:** `CloudflareTunnelTest` (redirects on the public hostname
      over https, a forged `X-Forwarded-Host`/`-For` ignored, the client IP
      from Cloudflare, Secure cookies over HTTPS only, the unclaimed system
      refusing the tunnel but not the LAN or `/up`);
      `deploy/proxmox/tests/test-domain-tools.sh` (hostname validation,
      private addresses, URL hosts) in CI

**Done when:** on a fresh install, adding the Public Hostname route is the
only step; `qrid-selftest` passes for that hostname; the LAN still works
over HTTPS; Pest, Pint, Larastan, ShellCheck and the deploy-script tests
green.

**Traps:**
- **Caddy overwrites `X-Forwarded-Proto` from an untrusted peer.** Without
  `trusted_proxies`, the tunnel's `https` becomes `http`, the app builds
  `http://` links on an `https://` page, the browser blocks them as mixed
  content — a blank page.
- **`Cf-Ray` and `Cf-Connecting-IP` can be forged from the LAN.** Use them
  to choose a scheme, a redirect or an IP to record, never to grant access.
- **Can't be proven here:** Caddy isn't installed on this machine, so the
  Caddyfile is checked by `caddy validate` when it's installed, and the
  whole path by `qrid-selftest` on a real container.

---

## Phase 19 — Bulk export of printable ID cards

**Added 2026-09-24 (user request).** Cards are printed in **Smart
IDesigner**, which lays out and prints the physical card itself from a
database import — a spreadsheet plus photo files. Until now the system
could only hand over one card at a time, as rendered PNGs
(`CardPrintService`, Phase 12). Decisions made at kickoff (user,
2026-09-24): every active card not yet printed, owner, tenant and employee
alike; exporting marks the cards printed; the Image cell is the photo's
filename only; the Name cell is First Last Suffix; OpenSpout writes the
spreadsheet; the button lives on the Cards list page. **Extended the same
day (user request):** the single-card Print button produces this same zip
for its one card, instead of the front/back PNG pair.

**Why not beside Query B, as first asked.** Architecture §14 makes the
reconciliation dashboard read-only with "no bulk operations", and this
export writes `printed_at` on many cards at once. Query B also lists people
with *no* card, while this export covers cards that exist but are
unprinted. The user chose the Cards list page (2026-09-24), leaving §14
untouched; Query B gains only a text link.

**Goal:** one download carries every card waiting to be printed, in the
shape Smart IDesigner imports, and no card is ever exported twice. Printing
a single card produces the same shape for that one card.

- [x] **The download** — `id-cards-YYYY-MM-DD-HHMM.zip` with three folders,
      by `id_cards.type`: `owner` → `Unit Owner/`, `tenant` → `Tenant/`,
      `employee` → `Employee/`. Each holds a `cards.xlsx` and the photos of
      its cards. **All three folders are always present** — a type with no
      cards gets a header-only `cards.xlsx`, so the import never changes
      shape. Photos are the stored JPEG copied byte-for-byte (already 1:1,
      ≤1024 px, architecture §9.1), named `<control_number>.jpg`
- [x] **`cards.xlsx`** — one sheet; row 1 is `Image | Name | Unit | Code`,
      then one row per card:
      - Image: `<control_number>.jpg`, resolved by Smart IDesigner against
        the folder
      - Name: `first_name last_name suffix`, blanks skipped ("Juan Dela
        Cruz Jr.") — a new `Person::exportName()` beside `printedName()`;
        a printed-card name for one consumer, not a UI name, so rule 37 is
        unaffected
      - Unit: `Unit::unitCode()`; empty for employee cards (`unit_id` null)
      - Code: `control_number` **as a text cell**, so leading zeros survive
        (rule 14)
      Owner/tenant rows sorted by unit code then name; employees by name
- [x] **`BulkCardExportService::export(User $actor)`**, one
      `DB::transaction`:
      1. Lock every `status = 'active'`, `printed_at IS NULL` card
         (`lockForUpdate()`), person and unit eager-loaded — two admins
         exporting at once serialize, the second getting only what the
         first didn't take
      2. Refuse if there are none
      3. Refuse if any photo file is missing on the `local` disk, naming
         the control numbers — nothing is marked; a missing file is a
         disk/backup fault to fix, not a card to skip
      4. Build the zip in a temp directory, never in memory (a few thousand
         photos would pass PHP's 128 MB limit); `ZipArchive::close()` runs
         inside the transaction, so a zip failure rolls everything back
      5. One `printed_at` for the batch; one `id_printed` row per card
         through `AuditLogger` (rule 43), `new_value`
         `{"printed_at": …, "via": "bulk_export"}` so the trail tells the
         two print paths apart
      Returns the temp path, download filename and count; the Livewire
      action sends it with `deleteFileAfterSend()`. Templates play no part
      — Smart IDesigner does the layout, so a card with no `template_id`
      exports like any other
- [x] **One zip format, one implementation.** The folder layout,
      `cards.xlsx` and photo naming live in one builder
      (`SmartIdesignerZip`), used by both the bulk export and the single
      print — so the two can never produce different shapes
- [x] **Single-card print switches to that format.**
      `CardPrintService::print()` keeps every guard it has (active only,
      refuses a card already printed — rule 59), its `printed_at` and its
      `id_printed` audit row, but its zip (`id-card-<control_number>.zip`)
      now holds the Smart IDesigner layout for that one card: the same
      three folders, the card's own folder holding its `cards.xlsx` row and
      photo, the other two header-only. The two PNGs are no longer part of
      printing. `CardRenderer`, the template editor's preview and the
      `id-cards.render.*` routes stay — the card page still shows the
      rendered card, and Phase 20 emails those PNGs. A missing photo file
      refuses the print, as it does the bulk export. Existing
      `CardPrintService` tests that expect `-front.png`/`-back.png` change
      with it — a deliberate behavior change, not a regression
- [x] **OpenSpout `^4.32`** (`openspout/openspout`), not 5.x: 5.x requires
      PHP 8.4, and production and CI run 8.3. 4.32 supports 8.3–8.5 and
      needs only extensions already provisioned (`php8.3-xml`, `-zip`,
      `-common`). It arrives through the `composer install` that
      `deploy.sh` and `update` already run — no new operator step
- [x] **Cards list** (`pages.id-cards.index`): an "Export unprinted cards
      (N)" button, N = active cards with null `printed_at`, disabled at 0,
      Superadmin and Admin only (`IdCardPolicy::manageLifecycle`, the
      single print's gate). It opens `<x-confirm-dialog :open>` (rule 48)
      stating the N cards will be marked printed and can't be exported
      again; Confirm downloads. A refusal shows as
      `<x-toast variant="error">`, and the action resets its own error key
      first (rule 62)
- [x] **Reconciliation, Query B**: one line of text — "Cards issued but not
      yet printed are exported from the Cards list." — linking to
      `id-cards.index`. No button, no count; §14 unchanged
- [x] **Docs, same PR (rule 29)**: architecture §10: printing now hands
      over data for external layout software rather than rendered images,
      for one card or many, under the same one-way `printed_at` rule;
      rendering stays for on-screen preview and Phase 20's email;
      CLAUDE.md gains a rule recording that the export marks printed and
      that the reconciliation dashboard stays read-only
- [x] **Tests:** `BulkCardExportTest` — Superadmin and Admin may export, a
      Reader is forbidden; the three folders always present, empty ones
      header-only; spreadsheet contents read back with OpenSpout's reader
      (header, filename, suffix kept and middle name dropped, unit code,
      empty employee unit, a leading-zero control number kept as text);
      photos byte-identical to the stored files; lost, revoked, expired,
      replaced and already-printed cards excluded; `printed_at` set on
      every exported card and a second export refused as empty; one
      `id_printed` row per card with `via: bulk_export`; a missing photo
      refuses the export and marks nothing. Single print: its zip holds
      the same layout for its one card, the other folders header-only;
      still refused for an inactive or already-printed card, and for a
      missing photo. Page tests for the confirm flow on the Cards list and
      the Query B link

**Done when:** an Admin downloads one zip from the Cards list that Smart
IDesigner imports — three folders, each `cards.xlsx` linking its photos by
filename; every exported card is marked printed and audited, and a second
export is refused as empty; a single card's Print button downloads the
same layout for that card; Pest, Pint and Larastan green on PHP 8.3 in CI;
checked in the browser, and the zip opened in Excel. ✅ `SmartIdesignerZip`,
`BulkCardExportService`, `Person::exportName()` built; `CardPrintService`
switched over; Cards-list export button and Query B's link in place;
architecture §10 and CLAUDE.md (rule 70) updated in the same branch. 566/566
tests green, 0 Pint issues, 0 Larastan errors, resolved on this machine's
PHP 8.5 — **CI's PHP 8.3 run is still the real proof the lock resolves
there too, per this phase's own trap below.** Not yet checked in a browser
or against a real Excel/Smart IDesigner import — do that before merging.

**Traps:**
- **Excel turns `00451234` into `451234`** if Code is written as a number.
  Write it as a string cell, and test with a leading zero.
- **Resolve the lock for PHP 8.3, not this machine's 8.5.** A
  `composer require` run on 8.5 can pick versions 8.3 can't install;
  CI on 8.3 is the proof.
- **The trade:** once an export commits, its cards are printed as far as
  the system knows. A download lost after that is recovered card by card
  through replacement (`IdCardLifecycleManager::replace()`), which mints
  new control numbers — the same as a lost single-card zip today.
- **Out of scope:** re-exporting printed cards, choosing a subset, rendered
  card images in the bulk zip, and any Smart IDesigner project file.

---

## Phase 20 — Emailing digital ID copies

**Added 2026-09-24 (user request).** It reverses "no mail server; the
system never sends mail" (CLAUDE.md rule 21, architecture §3 and §14) — an
explicit user decision, for one purpose only: sending unit owners a digital
copy of their printed card. Login stays by username, `users` still has no
email column, and there is still no password-reset mail. Decisions made at
kickoff (user, 2026-09-24): a separate Email action, never automatic on
print; owner cards whose holder has an email on file; SMTP only, the
password field taking an API key where the provider issues one; the card
images carry a "DIGITAL ONLY" watermark that stays clear of the QR code
(first decided unmarked, reversed the same day).

**Goal:** a Superadmin connects a mail account and writes the email once;
an Admin then sends each printed owner card's front and back PNG to its
holder, one card or a batch at a time, with nothing running unattended.

- [ ] **Mail settings, Superadmin only** — `/settings/mail`, reached from
      the account menu beside Site settings, gated by the existing
      `manage-site-settings` Gate. One row in a new `mail_settings` table
      (id pinned to 1 by a check constraint, inserted by the migration —
      Phase 16's `site_settings` pattern), written only by
      `MailSettingsManager`: host, port, encryption (`tls` \| `ssl` \|
      `none`), username, password, From address, From name, subject
      template, body template. Every change audited
      (`mail_settings_changed`); an unchanged save writes none
- [ ] **The password or API key is write-only.** Stored with Laravel's
      `encrypted` cast (so an `APP_KEY` loss means re-entering it — the
      backup already takes `.env`, architecture §12); never rendered back —
      the field reads "leave blank to keep the current one"; never in
      `audit_logs` — the audit row records only `{"password": "changed"}`
      (rule 46's rule for passwords, applied to this secret)
- [ ] **The mailer is built from that row at send time**
      (`Mail::build([...])`), not from `.env` — nothing per install is
      edited to turn mail on, the same stance as rule 69. `MAIL_MAILER`
      stays `log` and is never the path this feature takes. A **Send test
      email** button sends to an address the Superadmin types and shows the
      transport's own error on failure
- [ ] **Email template with fields.** Subject and body are plain text with
      placeholders: `{first_name}`, `{name}` (`Person::exportName()`,
      Phase 19), `{unit}`, `{control_number}`, `{site_name}`. Filled by
      plain string replacement — **never Blade, never `eval`**: a template
      stored in the database and compiled as Blade would let whoever edits
      it run PHP on the server. A placeholder outside the list refuses the
      save and names it. The body is sent as plain text, plus an HTML part
      that is the escaped text with line breaks. The migration stores a
      sensible default template, so mail works once the account is set
- [ ] **Who and what is eligible:** an `active` **owner** card (primary
      owner or co-owner) that **has been printed** (`printed_at` set) and
      whose person has an `email`. The attachments are `CardRenderer`'s
      front and back PNGs, `<control_number>-front.png` / `-back.png`,
      watermarked (below). A card with no template to render from is not
      eligible, and says so
- [ ] **"DIGITAL ONLY" watermark, clear of the QR.** `CardRenderer` gains a
      watermark option used only by the email; the on-screen preview stays
      unmarked. On both sides, a semi-transparent full-width band with
      "DIGITAL ONLY", drawn **after** the overlay so artwork can't hide it.
      On the front it goes at the top, middle or bottom — the first
      position whose band doesn't touch the template's QR box **plus its
      4-module quiet zone**; the back carries no QR, so it goes in the
      middle. If no position is clear (a template whose QR spans the
      card's height), the band is drawn around the QR box and never over
      it. The QR's own pixels are never touched, so it scans as before
      (rule 54's reasoning: artwork over a QR can break scanning while
      looking fine on screen)
- [ ] **`id_cards.emailed_at`** (nullable, a forward-only migration) — set
      when the send succeeds. Orthogonal to `status` and to `printed_at`,
      rule 59's family; it records a fact and gates nothing except which
      cards the batch picks up
- [ ] **`DigitalCopyMailer`** sends one card: render, send, then set
      `emailed_at` and write `id_emailed` (rule 43) with the recipient
      address. The send is **not** inside a database transaction — mail
      can't be rolled back — so the order is send first, record after; a
      failed send records nothing (rule 45) and returns the transport's
      error. Sent synchronously: there is no queue worker (rule 1)
- [ ] **Per card — the card page:** an "Email digital copy" button on an
      eligible owner card, for Superadmin and Admin
      (`IdCardPolicy::manageLifecycle`). It works again on an already-
      emailed card ("Resend", showing when it was last sent) — an owner
      who lost the email asks, and every send is audited
- [ ] **Batch — the Cards list**, beside Phase 19's export: "Email
      digital copies (N)", N = eligible cards with `emailed_at` null. Each
      click sends **at most 25**, then reports sent, failed (with the
      reason) and how many remain — so a slow mail server never holds one
      request for minutes, and nothing unattended is needed. Eligible owner
      cards whose holder has no email are listed as skipped, not counted
      in N. Disabled, with a link to the settings, while mail isn't set up
- [ ] **Docs and deploy, same PR (rules 29, 39):** CLAUDE.md rule 21
      amended (mail exists for digital copies only; login, `users` and
      password recovery unchanged), plus new rules for the write-only
      secret and the never-Blade template; architecture §3 (`email`'s
      "the system never sends mail", the audit vocabulary gaining
      `mail_settings_changed` and `id_emailed`), §8 (the watermarked
      digital copy and its trade), §10 (the watermark option), §12 (outbound SMTP from the app container) and §14 (the
      "no mail server" line); `deploy/proxmox/README.md` noting mail is set
      up in the app, not `.env`, and needs outbound access to the SMTP port
- [ ] **Tests:** `MailSettingsTest` — Superadmin only; the password
      encrypted at rest, never rendered back, never in an audit row; blank
      keeps it; an unknown placeholder refused; the test email sent through
      the configured transport. `DigitalCopyMailerTest` (with `Mail::fake()`
      and a failing transport) — placeholders filled, including a name with
      a suffix and a leading-zero control number; both PNGs attached;
      eligibility (owner only, printed only, active only, email present,
      a template to render); the watermark present on both PNGs and the
      QR box plus quiet zone pixel-identical to an unmarked render, for
      landscape and portrait and a QR at the top, middle and bottom;
      `emailed_at` and `id_emailed` written only on success; a failure records nothing. Page tests: the per-card button
      and resend; the batch capped at 25 with the remaining count; skipped
      owners listed; both disabled while mail isn't configured

**Done when:** a Superadmin sets up a real mail account and a template,
and the test email arrives; an Admin emails one printed owner card and the
owner receives both PNGs, watermarked "DIGITAL ONLY", with the fields
filled, and the emailed front's QR scans at the gate; a batch sends 25 and
reports what remains; a failed send leaves the card un-emailed with the
reason shown; the password never appears on screen or in the audit log;
Pest, Pint and Larastan green on PHP 8.3 in CI; checked in the browser.

**Depends on Phase 19** — "has been printed" is only meaningful once
printing is the Smart IDesigner hand-off, and the Name placeholder reuses
`Person::exportName()`.

**Traps:**
- **Never compile the template as Blade.** It is database content a
  Superadmin writes; compiling it would turn a settings field into code
  execution on the server.
- **The watermark must never touch the QR or its quiet zone.** Test it
  by comparing the QR box's pixels, quiet zone included, between a
  watermarked and an unmarked render — they must be identical — across
  landscape and portrait templates and a QR placed at the top, middle and
  bottom.
- **The watermark marks the copy; it doesn't make it safe.** A forwarded
  email still gives someone a scannable card image. That grants nothing a
  copied physical card doesn't: verification shows the live status and the photo
  on file, and a different face at the gate is a failed verification
  (architecture §8, operations manual rule 3). Worth telling guards that a
  phone screen is not the card.
- **Send, then record — never the reverse.** Recording first would mark a
  card emailed that never was. The cost of this order: a crash between
  send and record means the batch sends that card again. Acceptable.
- **Don't reach for a queue** when batches feel slow — rule 1. The cap of
  25 per click is the answer; raise it only after timing a real server.
- **Out of scope:** email to tenants or employees, attachments other than
  the card PNGs, provider API mailers (Resend, Postmark, SES), and any
  mail for accounts or passwords.
