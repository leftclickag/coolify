# Coolify design tokens

Source: `resources/css/app.css` (`@theme`, `:root`, `.dark`) and `resources/css/utilities.css`.

## Brand and semantic colors

| Token | Value | Role |
|---|---|---|
| `--color-coollabs` | `#6b16ed` | Brand purple; **light-mode accent** |
| `--color-coollabs-50..300` | purple scale | fills / gradients (legacy highlighted buttons) |
| `--color-warning` | `#fcd452` | Brand yellow; **dark-mode accent** |
| `--color-success` | `#22C55E` | success |
| `--color-error` | `#dc2626` | error / danger |
| `--color-accent` | purple light / yellow dark | theme-aware focus and active |

Accent rule: light → purple; dark → yellow. Do not leave yellow accent utilities active in light mode. Do not hard-code blue focus rings.

Primary action / active tab classes:

```html
bg-coollabs/10 text-coollabs ring-coollabs/25
dark:bg-warning/15 dark:text-warning dark:ring-warning/25
```

Utility: `app-tab-active`. Focus utilities use `ring-coollabs` / `dark:ring-warning` or `var(--color-accent)`.

## Surface ladder (`--coollabs-*`)

| Token | Light | Dark | Use |
|---|---|---|---|
| `--coollabs-canvas` | near white | 10% neutral | page canvas |
| `--coollabs-elevated` | 98% neutral | 15% neutral | shells, card headers |
| `--coollabs-base` | white | 17% neutral | nested card bodies |
| `--coollabs-recessed` | 96% neutral | 20% neutral | inputs, listboxes |
| `--coollabs-fill` | 92.2% neutral | 26.9% neutral | dividers, passive fills |
| `--coollabs-line` | translucent dark | 32% neutral | control borders |
| `--coollabs-hairline` | 93.5% neutral | 26.9% neutral | shell rings |
| `--coollabs-subtle` | 55.6% neutral | 70.8% neutral | labels, muted titles |

Graphite aliases in `@theme` (dark-leaning names still used in utilities): `--color-app`, `--color-panel`, `--color-surface`, `--color-raised`, `--color-selected`, `--color-fg`, `--color-fg-dim`, `--color-fg-faint`, `--color-hairline`.

Custom themes: `html[data-theme="custom"]` with `--theme-base-color` / `--theme-bright-color` remaps accent and surfaces.

## Typography

| Token | Stack |
|---|---|
| `--font-sans` | Geist Sans, Inter, sans-serif |
| `--font-mono` / `--font-logs` | Geist Mono, SFMono, Consolas, … |

Practical scale:

- Page title ~24px
- Control / nav text ~13px (`text-[13px]`)
- Card titles ~14px
- Collection card meta ~11px
- Muted summary ~13px with subtle/fg-dim color

## Control sizing

- Controls: `h-9` / min-height ~32–36px, `rounded-md`
- Sidebar rows: `h-8` (`menu-item`)
- Tabs: `h-7` (`app-tab`)
- Icon buttons: `size-7` (`icon-button`)
- Nav icons: `size-[18px]` (`menu-item-icon`)
- Layer card shell radius: 8px
- Listbox panel: ~10px outer around 6px options with 4px inset

## Shadows

- Prefer hairline rings over heavy shadows
- Modal shadow token: `--shadow-modal`
- Avoid strong multi-layer glows on product surfaces

## Status mapping

`x-status-badge` types:

| Type | Dot | Typical states |
|---|---|---|
| `success` | emerald | running |
| `warning` | warning yellow | degraded, restarting, starting |
| `error` | red | failed / error |
| `neutral` | gray | stopped / unknown |

Badge chrome is a **neutral pill**, never a full colored rectangle.

## Theme application

- Variant: `@custom-variant dark (&:where(.dark, .dark *));`
- Early theme script in `layouts/base.blade.php` sets `html.dark` / `data-theme` before paint
- Storage key: `localStorage.theme` (`dark` | `light` | `system` | `custom`)
- Re-apply after `livewire:navigated`
