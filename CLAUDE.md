# Project invariants

Condominium ID Generation, Management & QR Verification System.
Laravel + Livewire + PostgreSQL. LAN-only, never public.

Full reasoning lives in `docs/architecture.md`. Build order lives in
`docs/implementation-plan.md`. **Read this file every session.**

The rules below were each decided deliberately, and several of them look wrong
until you know why. If a task seems to require breaking one, **stop and ask** —
do not work around it, and do not implement a "small exception."

---

## Time

1. **There is no scheduler, queue worker, or cron job.** Nothing in this system
   runs unattended. If a feature seems to need one, that is a signal to re-read
   architecture §7, not to add one.
2. **Nothing derives state from a date.** No `expires_at`, no staleness window,
   no "if past due then inactive." Status changes because an admin acted.
3. **Exactly one place compares a date to today**: Query A of the reconciliation
   dashboard. It produces a list for a human and changes nothing.

## Activity & validity

4. **A relationship is active iff `ended_at IS NULL`.** Never
   `contract_end_date`. That column is paperwork, not state.
5. **A card is valid iff `status === 'active'`.** Never add a boolean for this.
   `isValid()` is computed, never stored.
6. **`expired` ≠ `revoked`.** Expired means the entitlement lapsed. Revoked
   means an admin withdrew the card from someone still entitled.

## Immutability

7. **`id_cards` rows are never deleted and never edited.** No `deleted_at`
   column exists on that table. Change happens via a status transition plus a
   new row linked by `replaces_id_card_id`.
8. **`audit_logs` and `security_events` are append-only**, guarded at the model
   layer. Never write an update or delete path for them.
9. **Soft deletes are guarded, never cascading.** Deleting a person or unit is
   refused while any active relationship or active card exists. Superadmin only.

## Printed data

10. **The printed-field list is closed**: photo, first/middle/last name, suffix,
    position, department — plus the card-level unit, type, and control number.
    `legal_name` is not on it and never joins it — companies hold no cards.
11. **Changing a printed field forces reissue of every active card**, in one
    transaction, confirm-or-cancel. There is no "save without reissuing."
12. **Changing anything else changes nothing else.** Birthdate, gender, address,
    contact numbers, notes: plain corrections, no card consequence.
13. **There is no historical reprint.** `template_id` records provenance only.

## Numbers & QR

14. **`user_id_number` and `control_number` are `char(8)`, zero-padded strings.**
    Never integers. Leading zeros are valid.
15. **The QR encodes the plaintext control number.** No encryption, no token
    column, no encoding scheme. The number is printed on the card anyway; the
    authenticated verify route is the protection.
16. **Collision retry is typed**: `UniqueConstraintViolationException` *and* a
    constraint-name match. Never match on message text alone. Never retry
    capacity or validation errors.

## Concurrency

17. **Capacity checks take a `lockForUpdate()` on the unit** inside the
    transaction.
18. **Multi-unit transactions lock in ascending `unit_id` order**, always,
    regardless of direction. This is what makes deadlock structurally
    impossible.
19. **Retire-then-check**: mark the old card `replaced` before counting
    capacity, or a unit at 6/6 rejects its own occupant's replacement.

## Access

20. **Session auth only.** No Sanctum, no API routes, no tokens.
21. **Login is by username.** There is no email column on `users` and no mail
    server. Password-reset scaffolding is removed, not disabled.
22. **Photos are served through one authenticated route** with a policy check
    per request. No public disk, no symlink, no signed URLs, no base64
    embedding.
23. **A Reader may fetch a photo only within 60 seconds of verifying that
    person**, per a server-side session record.
24. **At least two active Superadmins at all times**, enforced in a transaction
    with the rows locked. A Superadmin cannot demote or disable themselves.
25. **No seeder creates a default account.** A dev seeder must `abort()` unless
    `APP_ENV === 'local'`.

## Working practice

26. **Migrations are forward-only after Phase 2.** Never edit a shipped
    migration.
27. **One phase per branch. `main` itself is never committed to directly.**
    Do not start a phase whose predecessors are incomplete. A phase, a
    hotfix, a UI fix, anything — always starts on its own branch cut from
    `main`, is tested there (Pest, Pint, Larastan all green), and only
    merges into `main` after an explicit go-ahead is asked for and given.
    Each branch lands via a pull request (or a local `--no-ff` merge where
    no `gh` CLI is available), never a direct push to `main`.

    **Carve-out for hotfixes to already-merged phases — narrowed
    2026-09-08 (explicit user instruction), not revoked.** A fix to a phase
    that has already landed may still ride the *current* branch when one
    is already checked out and the fix is blocking work in progress on
    it — that part is unchanged, and still needs no new branch of its own.
    **What changed: "the current branch" can no longer be `main`.** If
    `main` is what's checked out when the bug is found, a branch is cut
    first, before the fix is written — never after. The other two
    conditions from before are unchanged: it must be a genuine fix to
    shipped behavior, not new scope wearing a hotfix label, and it must be
    recorded as a dated note under the phase it belongs to in
    `docs/implementation-plan.md`. An unrecorded deviation is the thing
    rule 29 rules out — a decision that only exists in a commit message
    nobody will read again.
28. **Tests ship inside the phase**, not after it. Policies and transactions get
    feature tests.
29. **If implementation proves the architecture wrong, update
    `docs/architecture.md` in the same PR.** A code comment explaining a
    deviation is a bug report, not a decision.

## Units & primary owners

*(Added 2026-09-06. Architecture §3, §5.2, §5.4, §13.)*

30. **Every *live* unit has exactly one active primary owner, always.** "At most
    one" is a partial unique index (`is_primary_owner IS TRUE AND ended_at IS
    NULL`); "at least one" is an in-transaction application check scoped to
    `deleted_at IS NULL`. A unit is created with its primary owner in the same
    transaction. The single exception is deletion: because the primary-owner
    relationship is the one dependent an admin is forbidden to close while the
    unit lives, the deletion transaction closes it as its final act (§13). That
    is a carve-out, not a cascade — every other dependent must already be closed
    or the delete still refuses.
31. **A unit has seven slots: six occupants plus one reserved for the primary
    owner** — and the cap is counted **at two layers, both required**
    (second one added 2026-09-09): "active owner/tenant *cards* held by anyone
    other than the primary owner ≤ 6", **and** "active owner/tenant
    *relationships* that are not the primary-owner one ≤ 6"
    (`RelationshipManager::openRelationship()`, under the unit's row lock,
    refused with the same `UnitAtCapacityException`). The card count alone let
    an admin record a seventh occupant and only meet the cap later, at
    issuance. Neither count replaces the other: the card count is the
    narrower one and still fires on its own for promotion and transfer, which
    re-attribute cards without opening any relationship. The primary owner's
    own card and own relationship sit in the reserved slot and are never
    counted against the six. **The reservation holds even when its holder
    cannot or does not use it** — a company primary owner, or one not yet
    issued a card, still occupies it. Never rewrite either count as a flat
    count of seven: that hands company-owned units a seventh occupant.
    Employee cards still never count. The tenant→co-owner conversion closes
    the tenancy *before* counting (rule 19's retire-then-check, at the
    relationship layer) — a full unit must not refuse its own occupant's
    change of kind.
32. **Primary ownership is accountability, not entitlement, and moving it is
    retire-then-set.** Moving the flag issues and expires nothing; only
    relationship closure touches cards — which is why an ownership transfer
    affects cards (a relationship closes) and a promotion between existing
    co-owners does not. Clear `is_primary_owner` on the outgoing relationship
    *before* setting it on the incoming one, in one transaction with the unit
    locked. The reverse order is not a style preference: it violates the partial
    unique index immediately, and Postgres cannot defer a partial unique index
    (only constraints defer, and partial unique *constraints* do not exist).
    There is no window to protect against — inside one transaction no session
    sees zero or two primary owners, and a crash rolls back both writes.
33. **`people` has three completeness tiers, enforced per operation.** Minimal
    (name) to exist; contactable (+ mobile, email) to be a primary owner;
    cardable (+ photo) to be issued a card. A person with no photo is a normal
    record. Never back-fill or "upgrade" a stored row — ask what the current
    operation requires.
34. **Deleting a primary unit owner is refused until the role is transferred.**
    The message names the units and points to the transfer screen. The generic
    "end the relationships first" advice is wrong here — that path orphans the
    unit.
35. **`people` holds two kinds of party**: `entity_type` is `natural` or
    `company`. A natural person has first/middle/last/suffix; a company has one
    `legal_name`. A check constraint enforces the pair — never both, never
    neither. `entity_type` is immutable after creation.
36. **A company can only ever be a unit's *primary* owner; it can never hold
    a card, and never holds an ordinary co-owner or tenant relationship
    either** (narrowed 2026-09-09 — a company could previously also be an
    ordinary co-owner). Not cardable by kind, not by missing fields —
    issuance refuses it with a reason that says so. It **still occupies its
    reserved slot** (rule 31), so a company-owned unit cards six occupants
    like any other. `RelationshipManager::openRelationship()` — the ordinary
    (non-primary) relationship path — refuses a company outright regardless
    of the requested type; only `UnitLifecycleManager` (`createUnit()`,
    `transferPrimaryOwnership()`) ever sets a company as primary owner,
    directly.
37. **`display_name()` is the only way a name reaches the UI.** It resolves
    either kind. Code outside the model layer that branches on `entity_type` to
    render a name has reimplemented it badly.

## Bootstrap

*(Added 2026-09-06. Architecture §12. This reverses console-only bootstrap.)*

38. **The system is bootstrapped from the browser.** On first access with zero
    active Superadmins, a first-run wizard creates two of them in one
    transaction and then refuses forever. Every other route redirects to it
    while that precondition holds. The console commands remain as break-glass
    recovery, and `Artisan::call()` is still unreachable from HTTP — the wizard
    calls the service, not the command.

## Deploy surface

*(Added 2026-09-06. `deploy/proxmox/`.)*

39. **When a phase changes what an operator must do to a deployed system, the
    Proxmox helper script and its README change in the same PR.** That means
    `create-qrid-stack.sh`'s post-deploy checklist, `deploy/proxmox/README.md`,
    and any path or command either one prints.

    **Why this is an invariant and not just tidiness:** the script's closing
    checklist is read at the exact moment the operator acts on it, by someone
    who is not reading `docs/`. Stale text there is worse than a stale document
    — it is followed literally. Phase 3 is the proof: the checklist told the
    operator to bootstrap Superadmins "once auth exists," which silently became
    wrong the moment the wizard shipped, and a freshly deployed stack sat
    unclaimed on the LAN with nothing telling anyone to close that window.

    **How to apply:** before finishing a phase, re-read the script's summary
    output and the README as if you had just run the deploy. Every command,
    path, and instruction must still be true of the branch you are on. Verify
    paths against the provisioning scripts rather than assuming — the app lives
    at `/opt/qrid/app`, not wherever seems natural. This is rule 29's sibling:
    architecture in the same PR, operator instructions in the same PR.

## Password lifecycle

*(Added 2026-09-06. Architecture §3 "A password changes in exactly two ways.")*

40. **A password changes in exactly two ways: mandatory rotation, or a
    Superadmin resetting it from the Users screen.** There is no third,
    voluntary, current-password-known path. One was built, then removed —
    don't re-add a self-service password change to the profile page.
41. **The mandatory-rotation form never asks for the current password.**
    Reaching it already proves possession of the account; asking for the
    temporary password back verifies nothing the session doesn't already
    guarantee. On success it logs the session out and redirects to login with
    a flashed confirmation — proving the new password works, rather than
    trusting the still-open session.
42. **A Superadmin may reset their own password, or any other account's,**
    including another Superadmin's. This is not the self-action guard rule 24
    exists for — that guard is specifically about disable/role-change, the
    accidental-lockout path. Password reset is ordinary, audited, expected
    behavior (architecture §11, "Superadmins can impersonate each other").

## Audit trail

*(Added 2026-09-07. Architecture §3, Phase 4 plan.)*

43. **`AuditLogger::log()` is the only writer of `audit_logs`.** Never
    `AuditLog::create()` directly, anywhere, including console commands and
    the setup wizard. One call site is the entire point — it is what makes
    "does this event get logged correctly" a question with one answer
    instead of as many as there are callers.
44. **A null actor requires an explicit `actingAs`.** There is no default
    role for an event with no authenticated user — `log()` throws rather than
    guess. `'console'` and `'setup_wizard'` are the two that exist today;
    a future null-actor path adds its own rather than reusing one of these
    for something it doesn't mean.
45. **A refused mutation writes no audit row.** An invariant-blocked
    `disable()`/`changeRole()` call throws before reaching the logger. Only
    things that actually happened are events; a rejected attempt belongs in
    `security_events` if it's worth recording at all; `deletion_blocked`
    (architecture §13) is the existing pattern for the case that is.
46. **`occurred_at` and `ip_address` are derived, never passed in.**
    `ip_address` comes from `app()->runningInConsole()` — null for console
    and the wizard's own break-glass, the real request IP otherwise — so a
    caller cannot forget it or get it wrong. Passwords, hashed or otherwise,
    never appear in `previous_value`/`new_value`, on any path, including the
    console commands' `{"os_user","hostname"}` provenance block.

## GUI components

*(Added 2026-09-07. Phase 5 plan, `docs/design/`.)*

47. **A sortable screen uses `HasSortableColumns`
    (`app/Livewire/Concerns`) and `<x-data-table>` — never a bare
    `orderBy($request->...)`.** The trait's `sortBy()` silently ignores any
    column outside the consuming screen's own `sortableColumns()` map; a
    screen using it structurally cannot forward an unchecked column name to
    SQL, which is what makes the Phase 13 audit item ("no query takes a
    column name from user input") a formality rather than a per-screen hunt.
48. **Every new screen composes existing components** —
    `<x-data-table>`, `<x-form-field>`, `<x-confirm-dialog>`,
    `<x-status-badge>`, `<x-toast>`, `<x-nav-item>` — **per the pattern it
    maps to in `docs/design/wireframes.md`, not new markup.** Phase 3/4
    screens (Users, Audit Log) predate this library and were deliberately
    not retrofitted; they are not the pattern to copy.
49. **Livewire components in this codebase are Volt single-file components
    by convention — with one deliberate, narrow exception.**
    `App\Livewire\Pages\Dev\ComponentsPreview` is a full class specifically
    so `HasSortableColumns` has a consumer PHPStan's `paths` (`app/` only)
    can see; a Volt SFC's class is embedded in `.blade.php` and invisible to
    static analysis by construction. This is not licence to write more class
    components — it is the one place the trait needed a real example, and
    the reason is the whole reason.
50. **`/dev/components` only exists when `app()->environment('local')`** —
    same gate as the dev seeder (rule 25). A gallery of every component with
    working demo state is a developer tool; a production LAN deployment
    should never be able to reach it, registered or not.

## Phase kickoff workflow

*(Added 2026-09-07. Sibling to rules 26-29 — process, not schema.)*

51. **Starting a new phase follows four steps, each with its own stop point —
    never collapse them into "start phase N" running straight through to code:**
    1. **Read `docs/implementation-plan.md`** for that phase and output a
       summary before writing anything: goal, checklist, traps, and "done
       when."
    2. **Fill in the Phase Ledger artifact** with that phase's detail —
       goal, checklist, traps, done-when — and mark it current/up next,
       *before* asking to proceed. The ledger update is part of presenting
       the phase, not a reward for approval.
    3. **Only then ask for permission to branch.** Once approved, create the
       phase's branch (rule 27's naming), and confirm its predecessors are
       actually complete before treating it as startable — rule 27 already
       forbids starting on top of an incomplete phase; this step is where
       that check is actually performed and stated out loud, not assumed.
    4. **Once implementation is done, confirm what test cases (if any) are
       still missing** against the phase's own "done when," and ask before
       merging — merging still needs the explicit go-ahead this project
       already runs on; finishing a phase's code is not that go-ahead.

    **Why:** skipping straight from "start phase N" to a branch and code
    reuses whatever was discussed earlier in the conversation as if it were
    approval, which it may not be — a session that opens directly on "start
    phase 8" has had no chance to object to that phase's scope yet. Each step
    above is a place the user can redirect before more gets built on top of
    it. The ledger moved ahead of the approval ask (2026-09-07 correction)
    because it's how the user actually reviews a phase's scope before saying
    go — asking first and updating the ledger afterward means the approval
    was given without the one artifact built to show it.

## Relationships

*(Added 2026-09-09. `RelationshipManager::openRelationship()`.)*

52. **A person holds at most one *active* relationship of each kind — owner
    or tenant — on a given unit, and never both kinds at once**, but this is
    scoped to that one unit-person pair, not global: the same person can be
    an active owner on one unit and an active tenant on a different one
    without either touching the other. Three cases, not two:
    - **Same kind already active** (another owner, or another tenant,
      relationship for this exact person on this exact unit): refused
      outright. Two active tenancies, or two active co-ownerships, for one
      person on one unit is never a real state — it's a duplicate, not a
      transition. Added 2026-09-09 as a fix once the cross-kind checks below
      shipped and this gap was noticed sitting right next to them.
    - **Opposite kind already active**, met with a new **owner** request for
      the same pair: resolved automatically — the tenancy closes first
      (`closeRelationship()`, cards and all) and the owner relationship opens
      in its place, atomically. A tenant who buys the unit is the ordinary
      case.
    - **Opposite kind already active**, met with a new **tenant** request:
      refused outright, naming the person and pointing at ending the owner
      relationship first. Owner-to-tenant is a demotion an admin decides
      deliberately — never a side effect of adding a lease.
    Don't "fix" the opposite-kind asymmetry into either a symmetric
    auto-close or a symmetric refusal — those two directions were specified
    independently, and they encode different judgments about which
    transition is routine versus which one needs a deliberate decision. The
    same-kind case has no such asymmetry to preserve: it's a duplicate in
    both directions, refused the same way regardless of which kind repeats.
