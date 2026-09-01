# Origami Payroll Status Push — API Guide for Origami Dev Team

**Status: LIVE.** Confirmed 2026-08-31 — Origami built and deployed the receiving endpoint, and a
real push from Payroll's side now succeeds end-to-end (verified directly, not just taken on
Origami's word: a genuine 2xx response with the exact `{"status": true}` body, not just any
2xx — see `tests/origami_payroll_status_test.php`). Every payroll-run state change and every
Pending-Pull reject-back triggers an outbound call automatically; Payroll needed zero further
changes on its own side once Origami's endpoint went live, exactly as designed.

## 1. What this is for

Two related things, both outbound FROM Payroll TO Origami (the reverse direction of the existing
inbound sync — Origami pushing attendance/leave/OT/employee data INTO Payroll):

1. **Status tracking** — every time a payroll run's state changes on the Payroll side (submitted,
   approved, rejected, paid, cancelled, sent back for more info, reverted, or reopened for revision),
   Payroll notifies Origami so Origami can show its own users where that payroll cycle currently
   stands, without needing to ask Payroll directly.
2. **Reject-back** — an admin on the Payroll side can reject a "Pending Pull" document (a payroll
   cycle Origami already pushed into Payroll, but that hasn't been pulled into an actual payroll run
   yet) with a required comment. Origami needs to receive this so it can flag that cycle back to
   whoever sent it, with the reason why.

Both use the SAME endpoint and payload shape below — only `event_type` (and which fields are
populated) differs.

## 2. The endpoint

```
POST /api/hr/payroll/status
```

### Auth

`Authorization: Bearer {ORIGAMI_API_KEY}` — the SAME credential pair Payroll already uses for its
other outbound call to Origami (`OrigamiEmployeeCandidateClient`, the employee-sync candidate
picker). No new credential to issue.

### Request body

```json
{
  "event_type": "run_approved",
  "company_ref_id": "TESTCODE",
  "origami_process_id": 123456,
  "payroll_run_id": 789,
  "state": "approved",
  "reason": null,
  "occurred_at": "2026-08-31T14:32:10+07:00"
}
```

| Field | Type | Always present? | Description |
|---|---|---|---|
| `event_type` | string | yes | One of the 9 values in §3 below. |
| `company_ref_id` | string \| null | no | `payroll_sync_processes.origami_comp_code` — the Origami-side company code, when this run/document originated from an Origami-pushed sync cycle. `null` for a manual/off-cycle run with no Origami origin. |
| `origami_process_id` | int \| null | no | Origami's own natural key for the payroll cycle this run/document came from (whatever Origami itself sent when originally pushing this cycle into Payroll). `null` when there's no Origami origin. **This is the field Origami should use to match this notification back to its own record of that cycle.** |
| `payroll_run_id` | int \| null | run_* events only | Payroll's own internal run id — useful for logging/debugging on Origami's side, not meant to be a lookup key (Origami has no visibility into Payroll's own id space). `null` for `sync_process_rejected` (no run exists yet — that's the whole point of a reject-back). |
| `state` | string \| null | run_* events only | Payroll's own `payroll_runs.state` value after the change (`draft` / `pending_approval` / `approved` / `rejected` / `need_info` / `paid` / `cancelled` / `locked`). `null` for `sync_process_rejected`. |
| `reason` | string \| null | reject/need-info/reject-back events | The rejection reason / need-info reason / reject-back comment, when applicable. `null` otherwise (e.g. a plain approve or markPaid has no reason). |
| `occurred_at` | string (ISO 8601) | yes | When the action happened on Payroll's side. |

### Expected response

```json
{ "status": true }
```

Any non-2xx HTTP status, or a 2xx body that isn't `{"status": true, ...}`, is treated by Payroll as a
failed push — logged with the real HTTP status + response body, retried on nothing automatically
(this is a direct synchronous call, not a queue — a failure here is simply recorded, Payroll does not
re-attempt it later). A non-JSON or error response is fine to return; Payroll only reads `status`/
`message` if present.

## 3. `event_type` reference

| `event_type` | When it fires | `payroll_run_id` | `state` | `reason` |
|---|---|---|---|---|
| `run_submitted` | Draft run submitted for approval | ✓ | `pending_approval` | — |
| `run_approved` | Run approved | ✓ | `approved` | — |
| `run_rejected` | Run rejected | ✓ | `rejected` | rejection reason |
| `run_paid` | Run marked as paid | ✓ | `paid` | — |
| `run_cancelled` | Run cancelled (before payment) | ✓ | `cancelled` | cancel reason |
| `run_need_info` | Approver sent the run back asking for more information | ✓ | `need_info` | the "need info" reason |
| `run_reverted` | An approval decision (approved/rejected/need_info) was undone, sending the run back to a chosen prior status | ✓ | whichever state it reverted to | revert note, if any |
| `run_revised` | A rejected or need-info run was reopened as a draft for editing | ✓ | `draft` | — |
| `sync_process_rejected` | A Pending Pull document was rejected before ever being pulled into a run | — (`null`) | — (`null`) | the required reject comment |

## 4. Field notes

- **`origami_process_id`** is the one field Origami should treat as the real lookup key back to its
  own records — it's the exact same value Origami itself originally sent when pushing that payroll
  cycle into Payroll (`payroll_sync_processes.origami_process_id` on Payroll's side, already a
  `UNIQUE` key there). A run with no Origami origin at all (a fully manual/off-cycle run created
  directly in Payroll) will send this as `null` — there's nothing for Origami to match it to, and
  that's expected, not an error.
- **`sync_process_rejected`** can ONLY ever happen before a document is pulled into a run — Payroll
  refuses the reject-back action entirely once that document has become a real run (enforced on
  Payroll's own side; Origami will never receive a `sync_process_rejected` push for a cycle that was
  already turned into a payroll run).
- No event ever fires twice for the exact same transition — e.g. approving a run fires `run_approved`
  once; re-opening it later and approving it again would fire it again at that point, correctly
  reflecting a second, real approval event.
- Order is not guaranteed across concurrent runs, but IS guaranteed within a single run's own
  lifecycle (Payroll only ever fires the next event after the previous state change has already
  committed).

## 5. Status: both sides live (confirmed 2026-08-31)

**Payroll's side (unchanged since this doc was first written):**
- Every state-changing action listed in §3 fires this push automatically.
- Every attempt (success or failure) is logged in Payroll's own `origami_status_push_logs` table —
  full request payload, HTTP status, response, timestamp.
- A failed/unreachable push never blocks or undoes the real payroll action it's reporting on.

**Origami's side:**
- The endpoint in §2 is live and accepts the payload shape in that section, authenticated the same
  way Origami already authenticates Payroll's other outbound call.
- Verified end-to-end: a real push now returns a genuine 2xx with `{"status": true}`, and Payroll's
  own `origami_status_push_logs.success` correctly reflects that (was `0` before this endpoint
  existed, now `1`).
- Whatever Origami does with each event internally (e.g. updating a status field on its own copy
  of that payroll cycle, using `origami_process_id` to find it) remains entirely up to Origami's
  own design — Payroll has no expectations beyond the `{"status": true}` acknowledgement.
