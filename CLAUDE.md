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
27. **One phase per branch.** Do not start a phase whose predecessors are
    incomplete.
28. **Tests ship inside the phase**, not after it. Policies and transactions get
    feature tests.
29. **If implementation proves the architecture wrong, update
    `docs/architecture.md` in the same PR.** A code comment explaining a
    deviation is a bug report, not a decision.
