---
description: Form, UI & mobile interaction standard for Investyx (Broker 2.0)
---

# Form & UI Standard

Consistency rules for all Investyx screens. Stack is **React 19 + Fluent UI v9**.

> Some components referenced here (`SmartDataGrid`, `ActionBar`, `SettingsDialog`) are being
> ported from the SHANON project. Until a component exists in `frontend/src/components/`,
> follow the *pattern* with the closest Fluent UI primitive and leave a `// TODO: port from SHANON`.

## 1. Data Lists & Grids
- **Component:** Use `<SmartDataGrid />` (once ported) inside the page content area; otherwise a Fluent UI `<DataGrid>`.
- **Action bar:** Place an `<ActionBar>` (New / Refresh / bulk actions) above the grid.
- **Selection:** `multiselect` by default to allow bulk actions; `single` only when bulk is impossible.
- **Interaction:** single click selects a row; **double click opens the default action** (usually Edit in a Drawer).

## 2. Create & Edit — the Drawer pattern
- All create/edit forms open in a **right-side Drawer**: `<Drawer position="end" type="overlay" />`.
  - *Exception:* very complex screens (e.g. the import flow) may use a full page.
- **Sizing:** `size="medium"` default; `size="large"` for complex/tabbed forms.
- **Header:** clear title (e.g. „Nová transakce") + Close (X) button.
- **Body:** `<DrawerBody>`, vertical layout, `gap={16}`. Long forms use `Tabs`.
- **Footer:** actions at the bottom, order **[Zrušit (secondary)] … [Uložit (primary)]**.

## 3. Form Inputs
- **Labels:** always `<Label>`; mark mandatory fields with `required`.
- **Layout (mobile-first):** stack inputs vertically on mobile; switch to a 2-column CSS Grid/Flex on desktop (breakpoint ≈ 640px). Never rely on fixed pixel widths.
- **Validation:** show errors via `<MessageBar intent="error">` at the top of the form or inline under the input. **Disable Save while `saving`.**

## 4. Mobile / Responsive
- Design for narrow screens first; the layout must be usable on a phone.
- Drawers go full-width on mobile; tables become horizontally scrollable or switch to a stacked card view.
- Touch targets ≥ 40px. Avoid hover-only interactions.

## 5. Deletion
- Always confirm (Fluent `Dialog`, or a toast with undo).
- Prefer **bulk** delete endpoints (`{ ids: [...] }`).

## 6. Localization
- All user-facing text goes through the translation layer (`t('key')`), never hardcoded.
- New labels are seeded in `api/init_broker.php` and served by `api/api-translations.php`.

## 7. Pre-merge checklist
- [ ] List uses SmartDataGrid (or DataGrid) with an ActionBar.
- [ ] "New"/edit opens a right-side Drawer; double-click row opens edit.
- [ ] Primary action = filled/blue, on the right; secondary on the left.
- [ ] Works on mobile (vertical stack, no horizontal overflow of forms).
- [ ] All text localized via `t()`.
- [ ] `npm run build` passes.
