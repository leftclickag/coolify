# Coolify Blade component catalog

Path root: `resources/views/components/`. Prefer these before new styling.

## Core building blocks

| Component | Use |
|---|---|
| `x-application.settings-section` | Layer card (elevated header + nested body) |
| `x-reicon` | Outline icons (`name="dashboard"`, `class="size-4"` / `menu-item-icon`) |
| `x-status-badge` | Neutral status pill + semantic dot |
| `x-empty` | Empty states (`size="sm"`, icon slot) |
| `x-unsaved-bar` | Floating dirty-state save pill |
| `x-toast` | Global toast host (`window.toast`) |
| `x-helper` | Field helper / info tooltip content |
| `x-callout` | Inline notice (`warning`/`danger`/`info`/`success`) |
| `x-checkpoint-item` | Onboarding / validation step row |
| `x-error-page` | Public HTTP error pages |

## Forms (`x-forms.*`)

| Component | Notes |
|---|---|
| `input` | Text + helper; password eye/eye-off Reicons |
| `textarea` | Same authorization + dirty patterns |
| `button` | Neutral `.button`; loading spinner support |
| `checkbox` | Custom 18px box; theme-aware checked fill |
| `listbox` | **Required** instead of native `<select>` on app routes |
| `searchable-listbox` | Searchable single-select variant |
| `monaco-editor` | Code / compose editors |
| `env-var-input` | Environment variable rows |
| `domain-input` / `domain-chips` | Domain entry UI |
| `copy-button` | Clipboard affordance |

Authorization on mutable controls (PHP in `app/View/Components/Forms/*`):

```blade
<x-forms.input canGate="update" :canResource="$resource" id="name" label="Name" />
<x-forms.button canGate="update" :canResource="$resource" type="submit">Save</x-forms.button>
<x-forms.listbox
    id="is_enabled"
    label="Enabled"
    :options="[
        ['value' => true, 'label' => 'Enabled'],
        ['value' => false, 'label' => 'Disabled'],
    ]"
    onChange="instantSave"
    canGate="update"
    :canResource="$resource" />
```

When denied: control disables; buttons show auth tooltip.

## Tables (`x-table.*`)

| Component | Owns |
|---|---|
| `toolbar` | Search-left / actions-right layout |
| `search` | Icon, loading, clear, input anatomy |
| `filter` | Multi-select Filter trigger + count pill + Reset footer |
| `sort` | Static Sort trigger + single-select panel |
| `loading` | Overlay for backend search/filter/sort/pagination |

Also: `x-table-pagination` for footer controls.

## Navigation and layout

| Component | Role |
|---|---|
| `navbar` | Main sidebar groups |
| `top-breadcrumb` | Resource identity + status in global topbar |
| `top-user-menu` | Account menu |
| `resource-heading-tabs` | Compact layer-2 tab row helper |
| `dashboard/navbar` | Top-level page header |
| `team/navbar`, `notification/navbar`, `settings/navbar`, `security/navbar`, `profile/navbar` | Family tab strips |
| `application/configuration-sidebar`, `server/sidebar*`, `settings/sidebar`, `service/configuration-sidebar` | Grouped settings sidebars |
| `*/settings-layout` | Family layout wrappers |

## Overlays

| Component | Role |
|---|---|
| `modal-input` | Layer-card create/edit modal |
| `modal-confirmation` / `confirm-modal` | Destructive / confirm flows |
| `modal` | Generic modal shell |
| `slide-over` | Drawer |
| `popup` / `popup-small` | Compact popovers |
| `process-dialog` | Long-running process UI |

## Status

Prefer `x-status-badge`. Legacy wrappers under `components/status/*` (`running`, `stopped`, `degraded`, `restarting`, `services`) should resolve to the shared badge language.

## Loading

| Component | Role |
|---|---|
| `loading` | Generic spinner |
| `loading-on-button` | Button-inline spinner (pair with `is-loading`) |
| `page-loading` | Full-page loading |

## Reference surfaces (copy patterns from these)

| Surface | File |
|---|---|
| Dashboard | `resources/views/livewire/dashboard.blade.php` |
| Collection cards | `resources/views/livewire/project/index.blade.php` |
| Settings form anatomy | `resources/views/livewire/project/application/general.blade.php` |
| Layer-2 nav | `resources/views/livewire/project/application/heading.blade.php` |
| Settings sidebar | `resources/views/livewire/project/application/configuration.blade.php` |
| Dense table | `resources/views/livewire/project/shared/environment-variable/all.blade.php` |
| Metrics | `resources/views/livewire/project/shared/metrics.blade.php` |
| Terminal | `resources/views/livewire/terminal/index.blade.php` |
| Command palette | `resources/views/livewire/global-search.blade.php` |
| Layer card | `resources/views/components/application/settings-section.blade.php` |
| Listbox | `resources/views/components/forms/listbox.blade.php` |

## Common utilities (from `utilities.css`)

`button`, `button-highlighted`, `icon-button`, `input`, `input-select`, `input-focus`, `app-tab`, `app-tab-active`, `menu-item`, `menu-item-active`, `menu-item-icon`, `menu-subitem`, `nav-section`, `auth-tooltip`, `loading`, `alert-success`, `alert-error`.
