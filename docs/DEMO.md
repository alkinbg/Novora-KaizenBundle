# Novora KaizenBundle — 5-minute product demonstration

## Core message (30 seconds)

"Errors are easy to count. Improving a process is harder. KaizenBundle helps a Symfony team focus on repeat issues, investigate why they occurred, record a countermeasure, and check whether it helped—without a new APM server."

This is **not** a demo of automatic root-cause analysis, uptime or AI. Show the evidence and the constraints, not promises the bundle cannot keep.

## Setup

- Use a dedicated Symfony application with the bundle installed and its admin route restricted to authorized users.
- Prepare a **synthetic** or appropriately redacted Monolog file with a recurring failure; never use real client data or credentials in a public demonstration.
- Confirm the configured log file is readable and the 'var/kaizen' directory is writable by the HTTP PHP worker.
- Have at least one known failure **before** a documented correction time and ordinary log activity **after**. The sample must reach both sides of the comparison for an informative PDCA result.
- Optional: enable correlation and generate fresh requests, or omit the correlation segment altogether. Do not pretend older entries are correlated.

## Five-minute flow

| Time | Screen | What to show | What not to claim |
|---|---|---|---|
| 0:00–0:45 | Waste Radar /_kaizen | Recurring failures prioritized in the bounded sample | Highest count equals greatest business impact |
| 0:45–1:30 | Errors / Pareto | Select the issue, inspect frequency and symptom family | One family proves one underlying cause |
| 1:30–2:00 | Execution timeline (optional) | **Problems** shows actual error records first; expand diagnostics as needed | Every record sharing an ID is a distinct incident |
| 2:00–3:15 | **Investigate** | Record Gemba observation, competing Ishikawa causes and a grounded Why | Automatically identified cause |
| 3:15–4:30 | PDCA | Record when the correction was implemented; review before/after counts | Zero recent logs means fully resolved |
| 4:30–5:00 | Improvements | Reopen the saved case; demonstrate no duplicate active case | Automated preventive maintenance |

## Example, based on a common Symfony incident

1. Observed symptom: SessionHandler::gc(): Permission denied (13).
2. Gemba evidence: timestamps and the process user / directory permissions. Two error-level logs may represent one exception.
3. Ishikawa category: Infrastructure. Hypothesis: the PHP worker lacks the required directory access.
4. Check ownership/mode **before** confirming the cause. Do not infer it from the PHP file where the error appears.
5. PDCA Do: adjust access on the session directory only, with a recorded change time.
6. PDCA Check: compare the exact fingerprint before and after. If logs no longer cover the baseline or there has been no traffic, say **Insufficient evidence**.
7. Document the result. Close manually only when sufficient supporting evidence is available.

## Demo success criterion

A person unfamiliar with the implementation can get from one recurring exception to a preserved investigation and a defensible PDCA Check **without reading bundle source code or receiving developer assistance**.

## Presentation-safe limitations

- This product handles diagnostic logs; show it only behind authentication and use sanitized demonstration data.
- Current version has English user interface.
- Does not collect user data remotely or make external telemetry calls.
- Publication on Packagist and a stable release tag are separate steps. Do not present the release as published until those steps are complete.
