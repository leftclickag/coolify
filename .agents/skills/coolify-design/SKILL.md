---
name: coolify-design
description: "Use for any Coolify frontend UI/UX work in Livewire, Blade, Alpine, or Tailwind. Activate when building or restyling pages, layouts, navigation, forms, tables, modals, toasts, terminals, metrics, empty states, status badges, settings workspaces, or dark/light theme accents. Also activate for design-system questions, visual consistency reviews, or when the user mentions DESIGN.md, layer cards, Reicons, listboxes, or Coolify purple/yellow accents. Skip for pure backend PHP, API-only, migrations, queues, and non-UI refactors."
license: MIT
metadata:
  author: coolify
---

# Coolify Design

Canonical source of truth: **`DESIGN.md`** at the repo root. Read it before inventing new UI patterns. Update `DESIGN.md` in the same change when you introduce a shared visual pattern.

This skill is the agent-facing extraction of that system. Prefer existing Blade components over new CSS abstractions.

## Stack

Livewire 3 + Blade + Alpine.js + Tailwind CSS v4.

Key files:

| Role | Path |
|---|---|
| Design rules | `DESIGN.md` |
| Tokens + layer-card CSS | `resources/css/app.css` |
| Control/nav utilities | `resources/css/utilities.css` |
| Fonts | `resources/css/fonts.css` (Geist Sans / Geist Mono) |
| Components | `resources/views/components/**` |
| App shell | `resources/views/layouts/{base,app}.blade.php` |

Deep references in this skill:

- [tokens.md](references/tokens.md) — colors, surfaces, accent rules
- [components.md](references/components.md) — Blade component catalog + usage
- [checklist.md](references/checklist.md) — implementation + verification checklist

## Visual direction (non-negotiable)

- Compact product UI: 13–14px type, ~32px controls, 8px control radius
- Near-neutral layered surfaces; hairline rings instead of heavy borders
- Sentence-case labels; **never** use an em dash (`—`) in UI copy
- Outline icons only via `<x-reicon>` (not Heroicons as the primary system)
- Active nav: solid neutral fill (`bg-black/5` light, `bg-white/6` dark), not accent gradients
- Light accent: Coolify purple (`coollabs` / `#6b16ed`)
- Dark accent: Coolify yellow (`warning` / `#fcd452`)

Primary / active tint pattern:

```html
bg-coollabs/10 text-coollabs ring-coollabs/25
dark:bg-warning/15 dark:text-warning dark:ring-warning/25
```

Utility equivalent: `app-tab-active`.

## Do / don't

**Do**

- Reuse `x-application.settings-section`, `x-forms.*`, `x-table.*`, `x-status-badge`, `x-empty`, `x-reicon`, `x-unsaved-bar`, `x-toast`
- Scope settings with `.application-settings-workspace` / `.application-settings-form`
- Stack layer cards with `gap-6` (not `space-y-*`)
- Nested radii: `outer = inner + visible inset`
- Dual navigation: topbar = identity/status; layer-2 = tabs/actions; settings sidebar = third level
- Dense collections → tables; small browsable sets → compact cards (`min-h-28`/`min-h-32`)
- One save model per component: instant-save **or** one floating unsaved bar
- Authorize mutable controls with `canGate` + `canResource`

**Don't**

- Native `<select>` on application routes (use `x-forms.listbox`)
- Legacy shells as primary UI: `coolbox`, `.navbar-main`, `sub-menu-wrapper`
- Full colored status chips (`badge-success` rectangles) — use `x-status-badge`
- Oversized titles, generic dashboard metric cards, strong shadows, thick dividers
- Yellow accent utilities in light mode, or hard-coded blue focus rings
- Nest `<button>` inside tab links
- Duplicate main-sidebar destinations in layer-2 tabs
- Put a Save button on every card when a shared unsaved bar exists

## Page shells

### Global shell

`layouts/app.blade.php`: fixed topbar (`h-12`), collapsible sidebar (`w-56` / `w-16`), main content `max-w-[1400px]`. Sidebar rows use `menu-item` / `menu-item-active` + Reicons.

### Layer-2 resource nav

Fixed under the topbar for application/server families. Active tabs use the purple/yellow tint. Keep route-derived active state in Blade/Livewire (not Alpine-only).

### Settings workspace

```blade
<div
    class="application-settings-workspace mt-8 grid min-w-0 gap-8
        xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
    <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
        {{-- grouped icon-led nav --}}
    </aside>
    <div class="min-w-0 xl:mt-3 flex flex-col gap-6">
        <x-application.settings-section title="Section" helper="Why it matters.">
            ...
        </x-application.settings-section>
    </div>
</div>
```

Cap instance/settings families at `max-w-[1180px]`.

### Top-level collection pages

24px title + 13px muted summary; primary action top-right with restrained brand fill; `x-empty` for empty states; four-column compact cards or a dense table.

## Layer cards

Use `<x-application.settings-section>`:

```blade
<x-application.settings-section
    id="public-access-section"
    title="Public access"
    helper="How this section affects the resource.">
    <x-slot:actions>
        <x-forms.button canGate="update" :canResource="$resource">Action</x-forms.button>
    </x-slot:actions>

    {{-- body --}}
</x-application.settings-section>
```

Anatomy: 8px shell radius, elevated header, nested base body with fill ring, 16px body padding, optional `flush` for full-bleed tables. Card actions live in the header slot.

## Forms and controls

- Height ~32–36px (`h-9` utilities), `rounded-md`, 13px text
- Inputs: `x-forms.input` / `x-forms.textarea`
- Dropdowns: `x-forms.listbox` (never native select on app routes)
- Booleans that configure behavior → two-option listbox; permission matrices may keep `x-forms.checkbox`
- Checkbox anatomy: 18px custom box; purple checked in light, yellow in dark
- Mutable fields **must** include authorization:

```blade
<x-forms.input
    canGate="update"
    :canResource="$resource"
    id="name"
    label="Name" />
```

Instant-save listboxes: await the Livewire handler, use `preserveValue` when morphs would clobber newer Alpine state, and keep `wire:key` stable (do not put the selected value in the key).

## Tables

Use Cloudflare-inspired dense tables with shared chrome:

- `<x-table.toolbar>` / `search` / `filter` / `sort` / `loading`
- ~40px header, ~48px rows; subtle hover
- Status via `x-status-badge` (neutral pill + semantic dot)
- Footer inside the shell: `Showing X–Y of Z` + pagination; hide footer when only one page
- Backend-filtered tables must use `x-table.loading`

Classes: `.data-table`, `.data-table-header`, `.data-table-row`, `.table-badge`.

## Overlays and feedback

- Modals (`x-modal-input`, confirmations): layer-card shell, content-width on desktop, right-aligned footer actions, listboxes that escape scroll clipping
- Toasts: `window.toast(message, options)` from `x-toast` — compact card, max 26rem, ≤4 stacked, 4s dismiss
- Unsaved: `x-unsaved-bar` floating bottom pill (not a full-width footer)
- Empty: `x-empty` + Reicon
- Status: `x-status-badge` types `success|warning|error|neutral`
- Command palette: `livewire:global-search` — inset pill results, accent left rail on keyboard focus, no global `ring-2` treatment

## Livewire UI conventions

- Exactly one root element per Livewire component
- Prefer `{{ wireNavigate() }}` for in-app links
- Loading: `wire:loading.attr="disabled"`; buttons add `is-loading` + `<x-loading-on-button>`
- Preserve auth, confirmations, and existing actions while restyling
- Theme: `html.dark` / `data-theme="custom"` applied early in `layouts/base.blade.php`

## Anti-patterns (tested / forbidden)

| Avoid | Prefer |
|---|---|
| Em dash `—` | period, colon, comma, or ASCII `-` |
| Native `<select>` | `x-forms.listbox` |
| `badge-success` status chips | `x-status-badge` |
| Accent gradient active nav | `menu-item-active` solid fill |
| `space-y-*` between layer cards | `gap-6` |
| Save on every card | one unsaved bar or instant-save |
| Forms without `canGate` | always authorize mutations |

## Verify before finishing

1. `git diff --check`
2. `docker exec coolify php artisan view:cache` then `view:clear`
3. `docker exec coolify-vite npm run build`
4. Hard-refresh and inspect the whole route family in light and dark
5. Add/update tests when behavior changes

Full checklist: [references/checklist.md](references/checklist.md).
