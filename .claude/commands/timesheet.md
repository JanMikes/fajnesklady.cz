---
description: Append new commits to the fajnesklady.cz timesheet PDF (existing rows stay locked, only new commits get added)
argument-hint: [optional: total extra time, e.g. "12h 30min" or "750m"]
---

You are running the **timesheet update workflow** for `fajnesklady.cz`. The
generator is `tools/generate_timesheet.py`; the persistent per-commit times
live in `tools/timesheet_data.json`; the rendered PDF is `timesheet.pdf` in
the repo root.

The workflow is **append-only**: stored times must never be modified — only
new commits (SHAs not yet in the JSON) get times added, and the total grows.

## Steps

### 1. Detect new commits

Run:

```
python3 tools/generate_timesheet.py --list-new
```

Output is one of:

- `NO_NEW_COMMITS` — nothing new since last update.
- `NEW_COMMITS_COUNT=<n>` followed by tab-separated lines:
  `<short-sha>\t<loc>\t<subject>`

### 2a. If NO_NEW_COMMITS

Tell the user there's nothing new and stop. Do **not** regenerate the PDF
(it's already current and re-running would not change anything).

If the user explicitly asks to refresh anyway (e.g. they edited the script),
run `python3 tools/generate_timesheet.py` and report the unchanged total.

### 2b. If new commits exist

1. Show the user the list of new commits in a short, readable form
   (one line each: `<sha> — <subject> (<loc> LoC)`).

2. Determine the total extra time to log across these commits:
   - If the user supplied an argument to the slash command (`$ARGUMENTS`),
     parse it directly. Accepted formats:
     - `12h 30min`, `12h 30m`, `12:30` → 750 min
     - `12.5h`, `12.5 h` → 750 min
     - `750m`, `750 min`, `750` → 750 min
   - Otherwise, ask the user via `AskUserQuestion` how much total time to
     log for the new commits. Provide 2–3 sensible options based on the
     commit sizes (e.g. for 3 small/medium commits suggest "4h", "8h",
     "12h"; for one large spec commit suggest "6h", "10h", "16h"). Always
     include an "Other (custom)" possibility implicitly via the tool's
     standard fallback.

3. Convert the chosen value to **integer minutes** and ensure it's at
   least `15 × <new-commit-count>` (the generator's minimum). If the
   user picks too small a value, ask again.

4. Run:

   ```
   python3 tools/generate_timesheet.py --extra-minutes <N>
   ```

5. Read the script's output (last line shows new total + PDF path) and
   report to the user:
   - How many minutes were distributed across how many commits.
   - The new grand total.
   - The PDF path (`/Users/janmikes/www/fajnesklady.cz/timesheet.pdf`).

## Notes

- Never edit `tools/timesheet_data.json` by hand — let the script manage it.
- Never pass `--extra-minutes` when there are no new commits (the script
  will exit non-zero and that's correct behavior).
- The generator script and JSON file are intentionally **not committed** to
  git — they're personal/local tooling. Don't commit them unless the user
  explicitly asks.
