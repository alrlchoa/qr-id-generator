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

Status: **Built** (shipped — the Phase column says which phase) ·
**Planned** (named here, not yet built) · **N/A** (console-only or
otherwise not a GUI screen).

**Brought current in Phase 14 (2026-09-15).** This table was written in
Phase 5 and then left at "Planned" as Phases 6–13 shipped their screens —
every phase treating it as debt it hadn't created, which Phase 11's own
notes recorded explicitly before deferring the sweep here. Every row now
reflects what actually exists, screens that shipped without ever being
listed (card list/detail, fonts, designate-primary-owner) have been added,
and rows whose real implementation differs from what Phase 5 imagined
carry a footnote rather than being quietly reworded.

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
| Site settings — logo, site name, navbar colour (account menu) | ✓ | ✗ | ✗ | Built | 16 |
| Superadmin bootstrap/recovery (console) | ✓ | — | — | N/A | 3 |

¹ *Built as Breeze's generic landing page — no role-specific content yet.
For a Reader, the QR scan/verify screen (Phase 10) is arguably the real
landing experience; revisit whether Reader's post-login redirect should
target it directly once it exists, rather than a Dashboard with nothing on
it for that role.*

## People & units (Phase 6–7)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Person list / search | ✓ | ✓ | ✗ | Built | 6 |
| Person detail / edit | ✓ | ✓ | ✗ | Built | 6 |
| Person create | ✓ | ✓ | ✗ | Built | 6 |
| Photo upload / replace (within person detail) | ✓ | ✓ | ✗ | Built | 6 |
| Photo-backlog filter (People index, "no photo yet") | ✓ | ✓ | ✗ | Built | 6 |
| Unit list / search | ✓ | ✓ | ✗ | Built | 7 |
| Unit detail | ✓ | ✓ | ✗ | Built | 7 |
| Unit create (with mandatory primary owner) | ✓ | ✓ | ✗ | Built | 7 |
| Relationship: open | ✓ | ✓ | ✗ | Built | 7 |
| Relationship: close (cascade confirmation landed in Phase 9) | ✓ | ✓ | ✗ | Built | 7/9 |
| Relationship history (per person, per unit) | ✓ | ✓ | ✗ | Built² | 7 |
| Primary-owner transfer (promotion / ownership transfer) | ✓ | ✓ | ✗ | Built | 7 |
| Designate primary owner (unit stuck at zero — §15 Query D's resolution) | ✓ | ✗ | ✗ | Built | 13 |
| Person/unit soft-delete confirmation | ✓ | ✗ | ✗ | Built | 6/7 |

² *Built as a "Show ended relationships" toggle on the person and unit
detail screens rather than a screen of its own — activity is `ended_at IS
NULL` (rule 4), so ended rows are hidden by default and revealed on
request, which is what the row above meant by "history."*

## Issuance & lifecycle (Phase 8–9)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| ID card list | ✓ | ✓ | ✗ | Built | 12 |
| ID card detail | ✓ | ✓ | ✗ | Built | 12 |
| Issue owner/tenant ID | ✓ | ✓ | ✗ | Built³ | 8 → 12 |
| Issue employee ID | ✓ | ✗ | ✗ | Built³ | 8 → 12 |
| Mark lost / revoke / expire | ✓ | ✓ | ✗ | Built³ | 9 → 12 |
| Mandatory reissue confirmation (printed-field change, §9.3) | ✓ | ✓ | ✗ | Built | 9 |
| Relationship-closure cascade confirmation (§5.3) | ✓ | ✓ | ✗ | Built | 9 |

³ *Deferred from their own phase to Phase 12 by explicit decision — the
services (`IssuanceManager`, `IdCardLifecycleManager`) shipped in Phases
8–9 as planned, but neither screen had a card index/detail page to live on
until templates landed. The "Phase" column shows planned → actual.*

## Verification (Phase 10)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| QR scan | ✓ | ✓ | ✓ (scan-scoped) | Built | 10 |
| Verify result (status + photo) | ✓ | ✓ | ✓ (60s photo window) | Built | 10 |
| Manual control-number entry | ✓ | ✓ | ✓ | Built | 10 |

## Oversight (Phase 11)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Reconciliation dashboard (Queries A–D) | ✓ | ✓ | ✗ | Built | 11 |

## Templates (Phase 12)

| Screen | Superadmin | Admin | Reader | Status | Phase |
|---|:-:|:-:|:-:|---|---|
| Template list | ✓ | ✗ | ✗ | Built | 12 |
| Template create | ✓ | ✗ | ✗ | Built | 12 |
| Template detail: artwork upload + drag-and-drop field placement | ✓ | ✗ | ✗ | Built | 12 |
| Template render preview (in-place, on the detail screen) | ✓ | ✗ | ✗ | Built⁴ | 12 |
| Card fonts: upload / activate / delete | ✓ | ✗ | ✗ | Built | 12 |

⁴ *Not a separate screen in the end: the placement editor's own stage is
the preview, with a Preview/Edit toggle, sample name/role, and a real
scannable dummy QR. A rendered PNG is also servable per side via
`IdCardRenderController`, gated by `manageLifecycle` — narrower than the
`view` a Reader holds, since the front embeds the photo with no 60-second
window.*

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
