# Wireframes

Low-fidelity, text-form (Phase 5 plan explicitly allows this — "paper,
Excalidraw, Figma, whatever," as long as it's committed, not held in
someone's head). Two kinds of entry here, and every screen in
`screen-inventory.md` maps to one:

1. **Built screens**, wireframed as they actually are — Login, wizard,
   Dashboard, Profile, Users, Audit log.
2. **Reusable patterns**, wireframed once — every *planned* screen composes
   from one of these rather than getting its own bespoke layout. That
   composition is the entire point of this phase: Phase 6 building a Person
   list should mean filling in the List/Index pattern with Person columns,
   not inventing table markup again.

All screens share the nav shell (`<x-app-layout>` / `layouts/app.blade.php`,
already built in Phase 3): header bar with the app name, role-gated nav
links, a "Log out" action, and a slot for page content below.

---

## Built screens

### Login (`/login`)

```
┌─────────────────────────────────┐
│         [ Application Logo ]     │
│                                   │
│  ┌─────────────────────────────┐ │
│  │ (flash status, if any)      │ │
│  │                              │ │
│  │ Username [______________]   │ │
│  │ Password [______________][👁]│ │
│  │           [ ] Remember me   │ │
│  │                 [ Log in ]  │ │
│  └─────────────────────────────┘ │
└─────────────────────────────────┘
```
Guest layout, centered card, no nav shell (nothing to navigate to yet).

### First-run wizard (`/setup`)

```
┌───────────────────────────────────────────┐
│  Set up this system                        │
│  (explanatory copy — one-time, all-or-     │
│   nothing, no mail server)                 │
│                                             │
│  [ error summary, if any ]                 │
│                                             │
│  ── First Superadmin ──────────────────    │
│  Username [____________]                   │
│  Display name [____________]                │
│  Password [__________][👁]                  │
│  Confirm  [__________][👁]                  │
│                                             │
│  ── Second Superadmin ─────────────────    │
│  (same four fields)                        │
│                                             │
│                    [ Create both accounts ] │
└───────────────────────────────────────────┘
```
Guest layout. Same card pattern as login, wider. Client-side checks
(username clash, password length, confirmation match) show inline; the
submit button is never disabled by them (CLAUDE.md — client-side state
advises, the server decides).

### Mandatory password change (`/change-password`)

```
┌─────────────────────────────────┐
│  Your password must be changed   │
│  before you can continue.        │
│                                   │
│  New Password    [______][👁]    │
│  Confirm New     [______][👁]    │
│                                   │
│  [Log out instead]   [ Change ]  │
└─────────────────────────────────┘
```
Guest layout (reachable pre-"real" session in the sense that nothing else
is). No current-password field, by design.

### Dashboard (`/dashboard`)

```
┌─ nav shell ─────────────────────────────┐
│  Dashboard                               │
├───────────────────────────────────────────┤
│  (empty — Breeze default; no role-       │
│   specific content built yet)            │
└───────────────────────────────────────────┘
```
Placeholder today. See screen-inventory.md's footnote on whether Reader's
landing target should become the QR scan screen once Phase 10 exists.

### Profile (`/profile`)

```
┌─ nav shell ─────────────────────────────┐
│  Profile                                 │
├───────────────────────────────────────────┤
│  ┌ Update Profile Information ─────────┐ │
│  │ Name  [____________]                │ │
│  │ (no email field — username login)   │ │
│  │                            [ Save ] │ │
│  └──────────────────────────────────────┘ │
│  (no password section — reset-only,      │
│   CLAUDE.md 40)                          │
└───────────────────────────────────────────┘
```

### Users (`/users`) — Superadmin only

```
┌─ nav shell ─────────────────────────────┐
│  Manage Users                            │
├───────────────────────────────────────────┤
│  [ banner: one-time password, if shown ] │
│  ┌ Create Account ─────────────────────┐ │
│  │ Username [___] Name [___] Role [▾] │ │
│  │                     [ Create ]      │ │
│  └──────────────────────────────────────┘ │
│  ┌ Accounts ────────────────────────────┐ │
│  │ User │ Name │ Role▾ │ Active │ MCP │  │ ← "MCP" = must_change_password
│  │ ana  │ ...  │ [▾]   │ Yes    │ No  │ [Reset pw] [Disable]
│  │ ...                                  │ │
│  └──────────────────────────────────────┘ │
└───────────────────────────────────────────┘
```
This is the **List/Index pattern**'s ancestor — built before the pattern was
formalized, which is exactly why Phase 5 exists. Not retrofitted this phase
(churn on shipped, tested code isn't worth it), but new screens should look
like the pattern below, not like this table's ad-hoc column set.

### Audit log (`/audit`) — Superadmin, Admin

```
┌─ nav shell ─────────────────────────────┐
│  Audit Log                               │
├───────────────────────────────────────────┤
│  ┌ Filters ─────────────────────────────┐ │
│  │ Actor[___] Action[___▾] Type[▾] #[__]│ │
│  │ From[date] To[date]      [Clear]     │ │
│  └──────────────────────────────────────┘ │
│  ┌ Results ─────────────────────────────┐ │
│  │ Occurred │ Actor │ Action │ Subject │ │
│  │ Changes │ IP                        │ │
│  │ ...rows...                           │ │
│  │              « 1 2 3 »               │ │
│  └──────────────────────────────────────┘ │
└───────────────────────────────────────────┘
```
This one *is* the pattern — filter row above a data table, pagination
below — built this phase specifically to be the template the List/Index
pattern below generalizes from.

---

## Reusable patterns (every planned screen maps to one)

### Pattern: List / Index

```
┌─ nav shell ─────────────────────────────┐
│  {Screen title}              [+ Create]  │
├───────────────────────────────────────────┤
│  ┌ Filters (optional, per screen) ──────┐ │
│  └──────────────────────────────────────┘ │
│  ┌ <x-data-table> ──────────────────────┐ │
│  │ Col ▾│ Col ▾│ Col ▾│         actions │ │
│  │ ...sortable header row...            │ │
│  │ ...rows, or <x-data-table.empty>...  │ │
│  │              « pagination »          │ │
│  └──────────────────────────────────────┘ │
└───────────────────────────────────────────┘
```
**Used by:** Person list, Unit list, Template list.
Sort column/direction resolve through a per-screen allowlist passed to
`<x-data-table>` — never straight from the request (CLAUDE.md, Phase 13
trap). Empty state is the table's own slot, not improvised per screen.

### Pattern: Detail / Edit

```
┌─ nav shell ─────────────────────────────┐
│  {Record} #{id}                          │
├───────────────────────────────────────────┤
│  ┌ <x-form-field> × N ──────────────────┐ │
│  │ Label                                │ │
│  │ [ input ]                            │ │
│  │ (error, if any)                      │ │
│  └──────────────────────────────────────┘ │
│                              [ Save ]     │
│  ┌ Related data (read-only lists) ──────┐ │
│  │ e.g. relationship history, cards      │ │
│  └──────────────────────────────────────┘ │
└───────────────────────────────────────────┘
```
**Used by:** Person detail, Unit detail.
Changing a printed field from here is what triggers the Confirm-or-Cancel
pattern below, in the same transaction (§9.3) — the Save button on this
screen and the confirmation are one flow, not two screens pretending not to
know about each other.

### Pattern: Create

Same shape as Detail/Edit with empty fields and a "Create" submit label.
**Used by:** Person create, Unit create (+ mandatory primary owner —
the primary-owner fields are part of this same form, not a second step),
Issue ID (owner/tenant/employee).

### Pattern: Confirm-or-Cancel (no decline path)

```
┌─────────────────────────────────────────┐
│  {Action} will affect:                    │
│   • Card #12345678 (owner, Unit A01)      │
│   • Card #87654321 (tenant, Unit B02)     │
│                                            │
│  This cannot be undone.                   │
│                                            │
│              [ Cancel ]   [ Confirm ]      │
└─────────────────────────────────────────┘
```
**Used by:** Mandatory reissue (§9.3), relationship-closure cascade (§5.3),
primary-owner transfer capacity conflicts. Modal, built on
`<x-confirm-dialog>`. Explicitly **not** used for soft-delete — see below.

### Pattern: Delete Confirmation (Superadmin-only, cancellable)

```
┌─────────────────────────────────────────┐
│  Delete {record}?                         │
│  (blocked-reason list, if refused)        │
│              [ Cancel ]   [ Delete ]       │
└─────────────────────────────────────────┘
```
**Used by:** Person/unit soft-delete. Same `<x-confirm-dialog>` component as
above, different copy — this one really can be cancelled with no
consequence, unlike mandatory reissue.

### Pattern: Lifecycle Action (mark lost / revoke / expire)

```
┌─ nav shell ─────────────────────────────┐
│  Card #{control_number}   <x-status-badge>│
├───────────────────────────────────────────┤
│  Reason (required)   [____________]       │
│         [ Mark Lost ] [ Revoke ] [ Expire ]│
└───────────────────────────────────────────┘
```
**Used by:** ID lifecycle actions (Phase 9). Every action requires actor +
reason (architecture §7) — the reason field is not optional UI, it's a
required form field with server-side validation to match.

### Pattern: Scan / Verify (the one phone-first screen)

```
┌───────────────────┐   ← single column, thumb-reachable
│   [ Camera / QR ]   │
│   viewfinder        │
│                     │
│  or enter manually: │
│  [________] [Go]    │
├─────────────────────┤
│  <x-status-badge>    │
│  [ photo, if within  │
│    60s window ]      │
│  Name, Unit, Type    │
└───────────────────┘
```
**Used by:** QR scan, verify result, manual entry (Phase 10). The
responsive baseline's one deliberately mobile-first screen — everything
else in this document targets desktop/tablet.

### Pattern: Reconciliation Query

```
┌─ nav shell ─────────────────────────────┐
│  Reconciliation Dashboard                 │
├───────────────────────────────────────────┤
│  ┌ Query A — Leases past term ──────────┐ │
│  │ (row) → [ Resolve ]                   │ │
│  │ (empty state: "Nothing here.")        │ │
│  └──────────────────────────────────────┘ │
│  ┌ Query B / C / D — same shape ────────┐ │
│  └──────────────────────────────────────┘ │
└───────────────────────────────────────────┘
```
**Used by:** All four reconciliation queries (Phase 11). No bulk actions,
no counters/badges (architecture §14, "Design constraints") — each row
links to the screen that resolves it, full stop. Viewing writes nothing to
`audit_logs`, matching the audit viewer's own read-only guarantee.

---

## Mapping: screen → pattern

| Screen (from screen-inventory.md) | Pattern |
|---|---|
| Person / Unit / Template list | List / Index |
| Person / Unit detail | Detail / Edit |
| Person / Unit / Issue-ID create | Create |
| Mandatory reissue, cascade confirmation | Confirm-or-Cancel |
| Person/unit soft-delete | Delete Confirmation |
| Mark lost / revoke / expire | Lifecycle Action |
| QR scan, verify, manual entry | Scan / Verify |
| Reconciliation Queries A–D | Reconciliation Query |
| Template render preview | *(Phase 12, one-off — no pattern forced; a
  raster-image preview doesn't fit the others and shouldn't be bent to)* |

Primary-owner transfer, relationship open/close, and template create/edit
compose **Detail/Edit** plus a **Confirm-or-Cancel** step where capacity or
type-change conflicts apply (§5.4) — two patterns in sequence, not a third
pattern invented for what's really a Detail/Edit form with a confirmation
gate some submissions need and others don't.
