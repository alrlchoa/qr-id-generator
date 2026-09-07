# Responsive Baseline & Accessibility Pass

Companion to `docs/design/wireframes.md`. Phase 5 plan: both proportionate
to an internal LAN tool for 3–10 admins, not a public-facing audit — real
choices, not exhaustive WCAG conformance.

## Responsive baseline

**Breakpoint:** Tailwind's default `sm` (640px), inherited from Breeze —
no custom breakpoints added. One deliberate split:

- **Every admin screen** (everything in `docs/design/screen-inventory.md`
  except QR scan/verify) targets **desktop/tablet at the guardhouse
  workstation.** Below `sm`, the nav collapses to the existing hamburger
  menu (built in Phase 3, unchanged); tables get `overflow-x-auto`
  (`<x-data-table>`'s own wrapper) rather than a squeezed, unreadable
  layout. Usable on a narrow screen in a pinch; not designed for it.
- **QR scan/verify (Phase 10) is the one screen designed phone-first**,
  per the wireframes' Scan/Verify pattern: single column, thumb-reachable
  controls, the photo and status the first thing in view. It doesn't exist
  yet — this is the constraint Phase 10 builds against, not a retrofit.

**What this means for Phase 6+:** build admin screens against the List/Index
and Detail/Edit patterns as they stand — no additional responsive work
required beyond what `<x-data-table>`'s overflow handling already gives
every table. Don't reach for a mobile-specific admin layout; nothing in
§11's role table asks for one.

## Accessibility pass

Proportionate effort, four concrete things rather than a checklist run
against every screen that doesn't exist yet:

- **Focus order follows DOM order**, which follows visual order in every
  component built this phase — no `tabindex` overrides anywhere except
  `<x-modal>`'s own focus trap (Breeze-provided, unchanged), which is
  exactly where one belongs: trapping focus inside an open dialog is the
  correct exception, not a workaround.
- **Every form field's label is associated by `for`/`id`**, enforced
  structurally rather than by convention: `<x-form-field name="x">`
  generates the label's `for` from `name`, and the input inside its slot
  must share that `id` — the existing `<x-input-label>` / `<x-text-input>`
  pair already works this way; the wrapper just stops each screen from
  re-deriving the association by hand.
- **Non-text state has a text equivalent.** The sort indicator in
  `<x-data-table.sort-header>` shows ▲/▼ with `aria-hidden="true"` plus an
  `sr-only` "sorted ascending/descending" string alongside it — the arrow
  alone means nothing to a screen reader. The toast component sets
  `role="status"` so its message is announced without needing focus.
- **Status badge colour is never the only signal.** `<x-status-badge>`
  pairs colour with the status word itself (`Active`, `Lost`, `Revoked`,
  `Expired`, `Replaced`) rather than a bare coloured dot — colour
  reinforces the word, it doesn't replace it. Contrast: every pairing here
  is a dark-on-light text/background combination (e.g. `text-green-800` on
  `bg-green-100`), chosen for readability at a glance from the standard
  Tailwind palette rather than measured against a formal AA/AAA target —
  proportionate to this tool's audience, not a public-facing claim.

**What Phase 6+ inherits automatically** by composing the shared
components rather than writing new markup: label association, the sort
indicator's screen-reader text, and the empty-state's plain-language
copy. What each new screen still owns: its own focus order (falls out of
writing normal DOM order) and, for any icon-only control this phase didn't
already cover, giving it an accessible name — the password-reveal toggles
(Phase 3, login/wizard/change-password) sidestep the question entirely by
using a visible "Show"/"Hide" text label instead of a bare icon, which is
also why they need no `aria-label`: the visible text already is the
accessible name.
