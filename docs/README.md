# Docs index

Live agent and developer guidance for this plugin lives in the InterSoccer workspace:

- Skill: **reports-rosters** — `.cursor/skills/reports-rosters/SKILL.md`
- Rules (auto-apply under `intersoccer-reports-rosters/`):
  1. **reports-rosters-overview**
  2. **reports-rosters-event-signatures**
  3. **reports-rosters-sync**
  4. **reports-rosters-accuracy**
  5. **reports-rosters-exports**
- Ops refs: `cleanup-playbook.md`, `date-parsing.md` under the skill folder

Historical writeups and one-off fix notes: [archive/](archive/).

## Final Numbers / Live Snapshot UX

Phase A UX quick wins landed in [PR #22](https://github.com/intersoccer/reports-rosters/pull/22), closing issues #14–#17:

### Filter toolbar

- **Scope filters:** Year, Activity, Season, Region — select what data to show
- **Display filters:** Exclude BuyClub, Critical+Low only — control presentation without changing underlying data

### Day headers & heatmap

- Day headers display `Mon Tue Wed Thu Fri` (unambiguous, no duplicate T)
- Heatmap legend is always visible with color chips and text labels:
  - 🔴 Critical ≤7 · 🟠 Low ≤20 · 🟢 Good ≤29 · 🔵 Optimal 30+
- Cells include `aria-label` and `title` for screen readers and hover context

### Empty state & export feedback

- Empty state displays a card with icon, message, and action buttons (**Clear filters**, **Try previous year**)
- Export button is disabled when no data; shows "Exporting…" spinner during download

### Help disclosure

- Long help text ("Full Week / BuyClub / min-max explanation") collapsed behind **"How to read this table"** `<details>` disclosure
- One-line heatmap legend remains visible outside the disclosure

### Excel export

Excel exports are owned here via PhpSpreadsheet. The Live sticky export button and Office 365 checkbox are the UX hotspots alongside the downloaded file itself.

### Roadmap

Phase B issues remain open:
- [#18 — Camp table IA: Full Day | Mini tabs](https://github.com/intersoccer/reports-rosters/issues/18)
- [#19 — Shared design tokens from rebuild-admin](https://github.com/intersoccer/reports-rosters/issues/19)
- [#20 — Sticky Canton/Venue columns on wide tables](https://github.com/intersoccer/reports-rosters/issues/20)

## Admin Rosters UX (unified page)

- Use **Reports & Rosters → Rosters** with **Activity Type** (Camps | Courses | Tournaments).
- **Girls Only** is a separate filter (All | Yes | No) on Camps/Courses, backed by the `pa_girls-only` product attribute → `rosters.girls_only` after Order Meta Repair → Reconcile.
- Legacy `activity_type=girls_only` and `intersoccer-girls-only` redirect to Camps with Girls Only = Yes.
- Legacy **All Rosters** URL (`intersoccer-all-rosters`) redirects to Rosters. Reconcile and per-activity exports remain on Rosters. Advanced still has Export All Rosters (CSV).
