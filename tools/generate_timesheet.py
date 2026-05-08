#!/usr/bin/env python3
"""Generate timesheet PDF from git history. Total: exactly 313h 23m."""

import argparse
import html
import json
import random
import re
import subprocess
import sys
from pathlib import Path

TARGET_MINUTES = 313 * 60 + 23  # 18 803 — only enforced on first run
REPO_ROOT = Path(__file__).resolve().parent.parent
HTML_PATH = Path("/tmp/timesheet.html")
PDF_PATH = REPO_ROOT / "timesheet.pdf"
DATA_PATH = REPO_ROOT / "tools" / "timesheet_data.json"


def log(msg):
    print(msg, flush=True)


def git_log():
    out = subprocess.check_output(
        ["git", "log", "--reverse", "--pretty=format:__C__%H%x09%s", "--numstat"],
        cwd=REPO_ROOT,
        text=True,
    )
    commits = []
    cur = None
    for line in out.splitlines():
        if line.startswith("__C__"):
            if cur is not None:
                commits.append(cur)
            sha, _, subject = line[5:].partition("\t")
            cur = {"sha": sha, "subject": subject, "files": [], "loc": 0}
        elif not line.strip():
            continue
        else:
            parts = line.split("\t")
            if len(parts) < 3 or cur is None:
                continue
            add, rem, path = parts[0], parts[1], parts[2]
            cur["files"].append(path)
            try:
                cur["loc"] += int(add) + int(rem)
            except ValueError:
                cur["loc"] += 30
    if cur is not None:
        commits.append(cur)
    return commits


BUCKETS = [
    (10, 15, 30),
    (50, 30, 75),
    (250, 75, 180),
    (1000, 180, 360),
    (10**9, 360, 720),
]


def initial_minutes(loc, rng):
    for cap, lo, hi in BUCKETS:
        if loc <= cap:
            return rng.randint(lo, hi)
    return 720


def round5(n):
    return int(round(n / 5.0)) * 5


TERSE = {
    "fixes": "Opravy chyb a drobné úpravy",
    "fixes.": "Opravy chyb a drobné úpravy",
    "tests": "Doplnění a úpravy testů",
    "ci": "Úpravy CI workflow",
    "migrations": "Databázové migrace",
    "migration": "Databázová migrace",
    "missing migration": "Doplnění chybějící migrace",
    "translations": "Doplnění překladů",
    "missing translations": "Doplnění chybějících překladů",
    "styling": "Vizuální úpravy a styly",
    "documents": "Práce s dokumenty (smlouvy, faktury)",
    "dashboards": "Dashboardy",
    "verification": "Ověřování e-mailu",
    "sentry": "Nasazení Sentry monitoringu",
    "mailer": "Nastavení odesílání e-mailů",
    "fixtures": "Datové fixtury pro vývoj",
    "phpunit": "Konfigurace PHPUnitu",
    "configs": "Úpravy konfigurace",
    "entrypoint": "Docker entrypoint",
    "upgrade": "Aktualizace závislostí",
    "refactoring": "Refaktoring",
    "fixes + styling improvements": "Opravy a vylepšení stylů",
    "improvements": "Drobná vylepšení",
    "specifications": "Sepsání specifikací",
    "specs - backlog": "Aktualizace backlogu specifikací",
    "specs + homepage flip": "Specifikace a úpravy homepage",
    "naming user": "Úprava pojmenování uživatele",
    "claude notes": "Poznámky pro Claude Code",
    "claude instructions": "Instrukce pro Claude Code",
    "code review by claude": "Code review pomocí Claude",
    "readme": "Aktualizace README",
    "handover": "Předání rozpracovaného stavu",
    "logging exceptions": "Logování výjimek",
    "retry": "Retry logika pro výjimky",
    "tailwind watch": "Tailwind watch režim",
    "prod dockerfile": "Produkční Dockerfile",
    "remove make": "Odstranění Makefile",
    "remove api": "Odstranění nepoužívaného API",
    "remove unused class": "Odstranění nepoužívané třídy",
    "remove rate limiting": "Odstranění rate limitingu",
    "remove reset middleware": "Odstranění reset middleware",
    "merging areas": "Sloučení oblastí aplikace",
    "single action controllesr": "Single-action controllery",
    "domain events": "Doménové události",
    "fresh migrations": "Přepis databázových migrací",
    "cleaning configs": "Úklid konfigurace",
    "favicons and other images + tests": "Favicony, obrázky a testy",
    "fixing tests": "Opravy testů",
    "order flow": "Objednávkový tok",
    "reset password": "Reset hesla",
    "self-billing": "Self-billing faktur",
    "invoicing": "Fakturace",
    "payment gateway + missing api": "Platební brána a doplnění API",
    "check storage + change pricing model": "Kontrola dostupnosti skladu a změna cenového modelu",
    "weaker password and limits": "Slabší politika hesla a limity",
    "verify email validity": "Ověření platnosti e-mailu",
    "verify email validity + sentry": "Ověření e-mailu a Sentry",
    "texting": "Úprava textů",
    "ci memory": "Paměť pro CI běhy",
    "wrong cache": "Oprava chybného cachování",
    "cache fix for ci": "Oprava cache pro CI",
    "breadcrumbs and photos": "Drobečková navigace a fotky",
    "flatpickr styling": "Vzhled Flatpickr",
    "map coordinations": "Souřadnice na mapě",
    "minimap for order": "Minimapa pro objednávku",
    "save not normalized storages": "Uložení nenormalizovaných skladů",
    "polishing editor": "Vyladění canvas editoru",
    "storage types can be edited now again": "Typy skladů lze opět editovat",
    "do not send activation email for passwordless users": "Neposílat aktivační e-mail uživatelům bez hesla",
    "failovers + contract signing": "Failovery a podpis smlouvy",
    "improved flow": "Vylepšený objednávkový tok",
    "maps improved": "Vylepšené mapy",
    "forbidden blocking when reserved": "Blokování přístupu u rezervovaných skladů",
    "fix ordering": "Oprava řazení",
    "renamed fajnesklady": "Přejmenování projektu na fajnesklady",
    "place type": "Typ místa",
    "maps": "Práce na mapách",
    "registration experience": "Registrace – uživatelská zkušenost",
    "storage types": "Typy skladů",
    "daisyui migration": "Migrace na DaisyUI",
    "portal - ordering for user, map reworked": "Portál – objednávání pro uživatele, předělaná mapa",
    "docker - base image with libreoffice": "Docker – základní image s LibreOffice",
    "invoices, contracts, payments": "Faktury, smlouvy, platby",
    "logging locally": "Lokální logování",
    "place requests and approving + proper landlord accesses": "Žádosti o místo, schvalování a přístupy pronajímatele",
    "add minimal and overall improved canvas experience": "Vylepšený canvas editor skladů",
    "fixes + styling": "Opravy a stylování",
    "fixes and test coverage": "Opravy a pokrytí testy",
    "favicons and other images": "Favicony a další obrázky",
    "photo + styling": "Fotky a stylování",
    "code style fixes and unavailable storage redirect fallback": "Opravy code style a fallback pro nedostupný sklad",
    "specifications.": "Sepsání specifikací",
    "specs": "Aktualizace specifikací",
    "changes": "Drobné úpravy",
    "improvements.": "Drobná vylepšení",
}


def polish(subject):
    s = subject.strip()
    s = re.sub(r"\s*\(spec\s*\d+\)\s*$", "", s, flags=re.IGNORECASE)
    s = re.sub(r"\s*\(#\d+\)\s*$", "", s)
    s = s.rstrip(".")
    key = s.lower()
    if key in TERSE:
        return TERSE[key]
    if s and s[0].islower():
        s = s[0].upper() + s[1:]
    return s


def fmt_minutes(m):
    return f"{m // 60}:{m % 60:02d}"


def assign_first_run(commits):
    """First-time pass: scale all rows so total equals TARGET_MINUTES."""
    rng = random.Random(42)
    minutes = [initial_minutes(c["loc"], rng) for c in commits]
    initial_total = sum(minutes)
    log(f"[3/6] initial total: {initial_total} min ({initial_total/60:.1f}h)")

    scale = TARGET_MINUTES / initial_total
    minutes = [max(15, round5(m * scale)) for m in minutes]
    scaled_total = sum(minutes)
    log(f"[4/6] after scale ({scale:.4f}) and round5: {scaled_total} min")

    diff = TARGET_MINUTES - scaled_total
    log(f"[4b/6] residual to absorb: {diff} min")

    medium_idx = [i for i, c in enumerate(commits) if 51 <= c["loc"] <= 250]
    if not medium_idx:
        medium_idx = list(range(len(commits)))
    nudge_rng = random.Random(123)
    nudge_rng.shuffle(medium_idx)

    pos = 0
    iterations = 0
    while abs(diff) >= 5 and iterations < 10000:
        iterations += 1
        if pos >= len(medium_idx):
            nudge_rng.shuffle(medium_idx)
            pos = 0
        i = medium_idx[pos]
        pos += 1
        step = 5 if diff > 0 else -5
        if minutes[i] + step < 15 or minutes[i] + step > 720:
            continue
        minutes[i] += step
        diff -= step

    if diff != 0:
        for i in medium_idx:
            new_val = minutes[i] + diff
            if 15 <= new_val <= 720:
                minutes[i] = new_val
                diff = 0
                break

    total = sum(minutes)
    if total != TARGET_MINUTES:
        sys.exit(f"FAIL: total {total} != target {TARGET_MINUTES} (diff {diff})")
    log(f"[5/6] total locked at {total} min ({total//60}h {total%60}min)")
    return minutes


def assign_incremental(commits, stored):
    """Subsequent runs: keep stored minutes, generate fresh for new SHAs."""
    rng = random.Random(7777)  # different seed so new rows don't pattern-match
    minutes = []
    new_shas = []
    for c in commits:
        if c["sha"] in stored:
            minutes.append(int(stored[c["sha"]]))
        else:
            m = max(15, round5(initial_minutes(c["loc"], rng)))
            minutes.append(m)
            new_shas.append(c["sha"][:7])
    reused = len(commits) - len(new_shas)
    log(f"[3/6] reused {reused} stored, generated {len(new_shas)} new: {', '.join(new_shas) or '—'}")
    return minutes


def assign_with_extra(commits, stored, extra_minutes):
    """Keep stored minutes, distribute extra_minutes across new SHAs only."""
    new_pairs = [(i, c) for i, c in enumerate(commits) if c["sha"] not in stored]
    if not new_pairs:
        sys.exit("--extra-minutes requested but no new commits found.")
    if extra_minutes < 15 * len(new_pairs):
        sys.exit(
            f"Extra minutes ({extra_minutes}) too small for {len(new_pairs)} new commits "
            f"(need ≥ {15 * len(new_pairs)} = 15 min minimum each)."
        )

    minutes = [int(stored[c["sha"]]) if c["sha"] in stored else 0 for c in commits]

    # Weight by LoC + 30 so even tiny commits get some share.
    weights = [c["loc"] + 30 for _, c in new_pairs]
    total_w = sum(weights)
    raw = [extra_minutes * w / total_w for w in weights]
    new_minutes = [max(15, round5(r)) for r in raw]

    diff = extra_minutes - sum(new_minutes)
    pos = 0
    iterations = 0
    while abs(diff) >= 5 and iterations < 5000:
        iterations += 1
        if pos >= len(new_minutes):
            pos = 0
        step = 5 if diff > 0 else -5
        if 15 <= new_minutes[pos] + step <= 720:
            new_minutes[pos] += step
            diff -= step
        pos += 1
    if diff != 0:
        for k in range(len(new_minutes)):
            v = new_minutes[k] + diff
            if 15 <= v <= 720:
                new_minutes[k] = v
                diff = 0
                break
    if sum(new_minutes) != extra_minutes:
        sys.exit(f"FAIL: distributed {sum(new_minutes)} != requested extra {extra_minutes}")

    for (i, _), m in zip(new_pairs, new_minutes):
        minutes[i] = m

    h, mm = extra_minutes // 60, extra_minutes % 60
    log(f"[3/6] reused {len(commits) - len(new_pairs)} stored, distributed {h} h {mm:02d} min across {len(new_pairs)} new commits")
    return minutes


def list_new(commits, stored):
    new = [c for c in commits if c["sha"] not in stored]
    if not new:
        print("NO_NEW_COMMITS")
        return
    print(f"NEW_COMMITS_COUNT={len(new)}")
    for c in new:
        print(f"{c['sha'][:7]}\t{c['loc']}\t{c['subject']}")


def main():
    parser = argparse.ArgumentParser(description="Generate fajnesklady timesheet PDF.")
    parser.add_argument("--list-new", action="store_true",
                        help="Print commits not yet in tools/timesheet_data.json and exit.")
    parser.add_argument("--extra-minutes", type=int, default=None,
                        help="Distribute this many minutes across NEW commits only (proportional to size).")
    args = parser.parse_args()

    commits = git_log()
    if not commits:
        sys.exit("No commits found.")
    stored = json.loads(DATA_PATH.read_text(encoding="utf-8")) if DATA_PATH.exists() else {}

    if args.list_new:
        list_new(commits, stored)
        return

    log("[1/6] reading git log...")
    log(f"[2/6] {len(commits)} commits parsed")

    if args.extra_minutes is not None:
        if not stored:
            sys.exit("--extra-minutes requires existing tools/timesheet_data.json (first run hasn't happened).")
        minutes = assign_with_extra(commits, stored, args.extra_minutes)
    elif stored:
        minutes = assign_incremental(commits, stored)
    else:
        log("[!] no stored times found — first run, scaling to 313h 23m")
        minutes = assign_first_run(commits)

    DATA_PATH.write_text(
        json.dumps({c["sha"]: m for c, m in zip(commits, minutes)}, indent=2),
        encoding="utf-8",
    )
    log(f"[5b/6] saved per-commit times to {DATA_PATH}")
    total = sum(minutes)

    rows = []
    for c, m in zip(commits, minutes):
        rows.append(
            f"<tr><td class='d'>{html.escape(polish(c['subject']))}</td>"
            f"<td class='t'>{fmt_minutes(m)}</td></tr>"
        )

    total_str = f"{total // 60} h {total % 60:02d} min"

    doc = f"""<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><title>Výkaz práce</title>
<style>
@page {{ size: A4; margin: 1.4cm 1.5cm; }}
body {{ font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #111; margin: 0; }}
h1 {{ font-size: 16pt; margin: 0 0 4pt 0; font-weight: 600; letter-spacing: 0.3pt; }}
.sub {{ font-size: 9pt; color: #555; margin-bottom: 10pt; }}
table {{ width: 100%; border-collapse: collapse; font-size: 7.5pt; line-height: 1.25; }}
th, td {{ padding: 2.2pt 4pt; border-bottom: 0.4pt solid #ddd; vertical-align: top; }}
th {{ text-align: left; font-weight: 600; font-size: 7.5pt; border-bottom: 0.8pt solid #333; }}
.t {{ text-align: right; white-space: nowrap; width: 14%; font-variant-numeric: tabular-nums; }}
.d {{ width: 86%; }}
tfoot td {{ font-weight: 700; font-size: 9pt; padding-top: 6pt; border-top: 0.8pt solid #333; border-bottom: none; }}
</style></head><body>
<h1>Výkaz práce</h1>
<div class="sub">Projekt: fajnesklady.cz · Celkem: {total_str}</div>
<table>
<thead><tr><th>Popis činnosti</th><th class="t">Čas</th></tr></thead>
<tbody>
{''.join(rows)}
</tbody>
<tfoot><tr><td>Celkem</td><td class="t">{total_str}</td></tr></tfoot>
</table>
</body></html>"""

    HTML_PATH.write_text(doc, encoding="utf-8")
    log(f"[6/6] wrote {HTML_PATH} ({len(doc)} bytes), running weasyprint...")
    subprocess.check_call(["weasyprint", str(HTML_PATH), str(PDF_PATH)])
    log(f"DONE: rows={len(commits)} total={total_str} pdf={PDF_PATH}")


if __name__ == "__main__":
    main()
