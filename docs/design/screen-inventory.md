# Screen Inventory

Companion to `docs/architecture.md` §11 (Roles & Permissions) and
`docs/implementation-plan.md` Phase 5. Every screen implied by the role
table is named here, once, before any of the unbuilt ones get designed
silently inside whichever phase happens to need them first.

**Role columns show what §11 grants — never the UI's decision.** Hiding a
nav item is convenience; the Policy on the route is the actual boundary
(Phase 5 plan, "Traps"). A screen marked ✗ for a role means: if that role
reaches the route directly, the Policy refuses it. The UI simply never
offers the link.

Status: **Built** (shipped, Phase 3/4) · **Planned** (named here, built in
the phase noted) · **N/A** (console-only or otherwise not a GUI screen).

## Access & account

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Login | ✓ | ✓ | ✓ | Built | 3 |
| First-run setup wizard | ✓ (pre-account) | — | — | Built | 3 |
| Mandatory password change | ✓ | ✓ | ✓ | Built | 3 |
| Profile (own info, own password stays reset-only — CLAUDE.md 40) | ✓ | ✓ | ✓ | Built | 3 |
| Dashboard (landing page) | ✓ | ✓ | ✓ | Built¹ | 3 |
| Users — Superadmin/Admin/Reader accounts | ✓ | ✗ | ✗ | Built | 3/4 |
| Audit log viewer | ✓ | ✓ | ✗ | Built | 4 |
| Superadmin bootstrap/recovery (console) | ✓ | — | — | N/A | 3 |

¹ *Built as Breeze's generic landing page — no role-specific content yet.
For a Reader, the QR scan/verify screen (Phase 10) is arguably the real
landing experience; revisit whether Reader's post-login redirect should
target it directly once it exists, rather than a Dashboard with nothing on
it for that role.*

## People & units (Phase 6–7)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Person list / search | ✓ | ✓ | ✗ | Planned | 6 |
| Person detail / edit | ✓ | ✓ | ✗ | Planned | 6 |
| Person create | ✓ | ✓ | ✗ | Planned | 6 |
| Photo upload / replace (within person detail) | ✓ | ✓ | ✗ | Planned | 6 |
| Photo-backlog filter (People index, "no photo yet") | ✓ | ✓ | ✗ | Planned | 6 |
| Unit list / search | ✓ | ✓ | ✗ | Planned | 7 |
| Unit detail | ✓ | ✓ | ✗ | Planned | 7 |
| Unit create (with mandatory primary owner) | ✓ | ✓ | ✗ | Planned | 7 |
| Relationship: open | ✓ | ✓ | ✗ | Planned | 7 |
| Relationship: close (cascade confirmation lands in Phase 9) | ✓ | ✓ | ✗ | Planned | 7 |
| Relationship history (per person, per unit) | ✓ | ✓ | ✗ | Planned | 7 |
| Primary-owner transfer (promotion / ownership transfer) | ✓ | ✓ | ✗ | Planned | 7 |
| Person/unit soft-delete confirmation | ✓ | ✗ | ✗ | Planned | 6/7 |

## Issuance & lifecycle (Phase 8–9)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Issue owner/tenant ID | ✓ | ✓ | ✗ | Planned | 8 |
| Issue employee ID | ✓ | ✗ | ✗ | Planned | 8 |
| Mark lost / revoke / expire | ✓ | ✓ | ✗ | Planned | 9 |
| Mandatory reissue confirmation (printed-field change, §9.3) | ✓ | ✓ | ✗ | Planned | 9 |
| Relationship-closure cascade confirmation (§5.3) | ✓ | ✓ | ✗ | Planned | 9 |

## Verification (Phase 10)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| QR scan | ✓ | ✓ | ✓ (scan-scoped) | Planned | 10 |
| Verify result (status + photo) | ✓ | ✓ | ✓ (60s photo window) | Planned | 10 |
| Manual control-number entry | ✓ | ✓ | ✓ | Planned | 10 |

## Oversight (Phase 11)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Reconciliation dashboard (Queries A–D) | ✓ | ✓ | ✗ | Planned | 11 |

## Templates (Phase 12)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Template list | ✓ | ✗ | ✗ | Planned | 12 |
| Template create/edit (field positions, front/back) | ✓ | ✗ | ✗ | Planned | 12 |
| Template render preview | ✓ | ✗ | ✗ | Planned | 12 |

## Internal (not part of the role table)

| Screen | Who | Status | Phase |
|---|---|---|---|
| Component preview (`/dev/components`) | Developer, `local` env only | Built | 5 |

---

## Notes for the phases that build these

- **Employee ID issuance is the one Superadmin-only CRUD action** among
  otherwise-symmetric Superadmin/Admin screens (§11 "Other notes") — the
  issue-ID screen needs to branch on role for the employee path, not hide
  behind a separate screen.
- **Soft-delete confirmations are Superadmin-only with no Admin equivalent**
  (§11) — these are not the same confirm-dialog component used for
  mandatory reissue/cascade (§9.3, §5.3 both use confirm-or-cancel, no
  decline path); a soft-delete confirmation can simply be cancelled.
- **The QR scan/verify screen is Phase 5's responsive baseline's one
  phone-first case** — everything else here targets desktop/tablet at the
  guardhouse workstation.
