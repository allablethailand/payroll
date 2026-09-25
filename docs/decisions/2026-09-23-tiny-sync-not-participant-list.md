# tiny: sync-not-participant list (2026-09-23)

`PayrollRunModel::syncMappedNotParticipants($processId, $compId)` — row-level counterpart to the
existing `syncMappedNotParticipantCount($runId, $compId)`. Same FROM/JOIN/WHERE, unchanged (diff 0).

Shape (mirrors `syncMissingEmployees()`'s own `data`): `id` (raw `employees.id`, unencoded — this
list never used IdCodec, same as `data` never did), `employee_no`, `name_th`, `surname_th`,
`name_en`, `surname_en`. No photo fields — not needed by this list's consumer.

Takes `$processId` directly, not `$runId`: "who did this sync payload mention" is a property of the
process, not of any one run built from it. The controller resolves `sync_process_id` from the run
first (`PayrollController::syncMissingEmployees()`), same as the count method already does
internally.

No `run_purpose`/state gate — matches the count method exactly (that method is deliberately
ungated; see its own docblock).

Separate method instead of widening the count query: the count is a scalar already consumed as an
int by a banner; this list has its own consumer coming later. Keeping them apart means neither
one's contract can break the other's caller.

`api/payroll-run.sync-missing-employees` now returns `in_sync_not_participant` (array) alongside
the existing `in_sync_not_participant_count`. UI is the next chunk — see BACKLOG.md.
