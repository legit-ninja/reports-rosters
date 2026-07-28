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

## Admin Rosters UX (unified page)

- Use **Reports & Rosters → Rosters** with **Activity Type** (Camps | Courses | Tournaments).
- **Girls Only** is a separate filter (All | Yes | No) on Camps/Courses, backed by the `pa_girls-only` product attribute → `rosters.girls_only` after Order Meta Repair → Reconcile.
- Legacy `activity_type=girls_only` and `intersoccer-girls-only` redirect to Camps with Girls Only = Yes.
- Legacy **All Rosters** URL (`intersoccer-all-rosters`) redirects to Rosters. Reconcile and per-activity exports remain on Rosters. Advanced still has Export All Rosters (CSV).
