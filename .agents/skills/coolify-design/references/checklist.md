# Coolify UI implementation checklist

Mirror of `DESIGN.md` §11, expanded for agents.

## Before editing

1. Inventory every route and reusable partial in the family (index, create, detail, settings, logs, metrics, danger).
2. Read the current Blade + Livewire class; preserve actions, authorization, loading states, and confirmations.
3. Confirm whether the page is: main-sidebar collection, resource detail (layer-2), or settings workspace (sidebar).

## While building

4. Add the correct dual navigation and scoped workspace/form classes (`.application-settings-workspace` / `.application-settings-form`).
5. Convert meaningful groups to `<x-application.settings-section>` and stack with `gap-6`.
6. Match responsive column counts to controls visible in each state (no empty grid tracks).
7. Replace native selects and config checkboxes with `x-forms.listbox` (keep checkboxes for permission matrices).
8. Put `canGate` + `canResource` on every mutable form control.
9. One save model: instant-save **or** one `<x-unsaved-bar>` — not both patterns fighting.
10. Nested radii: `outer = inner + visible inset`.
11. Modal descriptions purposeful; footer actions compact and right-aligned; listbox panels escape modal overflow.
12. Dense collections → tables + `x-table.*`; small sets → compact cards; empty → `x-empty`.
13. Status → `x-status-badge`; icons → `x-reicon`.
14. Confirm light (purple) and dark (yellow) accent behavior.
15. Fixed-nav anchor offsets (`scroll-margin-top: 7rem`); responsive stacking; no H1 beside settings sidebar on desktop.
16. Sweep sibling routes for legacy `coolbox` / `.navbar-main` / `sub-menu-wrapper` / native selects / old status chips.

## Copy and content

17. Sentence case for labels and headings.
18. No em dash (`—`) in UI copy.
19. No redundant modal description under a self-explanatory title.

## Verify

20. `git diff --check`
21. `docker exec coolify php artisan view:cache` then `docker exec coolify php artisan view:clear`
22. `docker exec coolify-vite npm run build` (or the project's Vite build path)
23. Hard-refresh the whole route family in light and dark themes
24. Add or update Pest/browser tests when behavior changes (authorization, status UI, listbox usage, etc.)

## Quick smoke questions

- Does the first content card align with the settings sidebar label?
- Would removing a card border/shadow break understanding? If not, it may be unnecessary chrome.
- Does active nav still look correct without accent gradients?
- Can every dropdown open fully inside modals and flush table toolbars?
