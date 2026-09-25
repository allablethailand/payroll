# tiny-F — PayeeDescriptorTrait (2026-09-19)

`payeeDestinationDescriptor()` was PayrollRunModel's own private method, while the 2 models owning the
SAME 4 routing columns (`employee_earning_deductions`, `employee_recurring_deductions`) returned those
columns raw — so each surface re-branched on them itself. Two spellings of one account.

**Moved, not rewritten.** `app/models/PayeeDescriptorTrait.php` holds it character-for-character
(`private` → `protected`); PayrollRunModel `use`s it, its 4 call sites unchanged. Added beside it:
`payeeDescriptorLookup()` (the 3 option-row maps for a row set) and `attachPayeeDescriptor()` (adds
`payee` per row, lookups ONCE per list — the test measures statement count at 2 rows and at 12).

Both `list()`s stay **additive**: existing columns untouched, `payee` nested rather than merged flat
(`destination_account_name`/`bank_account_name` already existed there with a different provenance). A
`payee_type` NULL row gets the full key set, all null — unlike `enrichLinePayee()`, which answers null
because a persisted line either is routed or isn't; a list row is a form's state, and "is this key even
here?" is what the descriptor exists to remove.

F2: `employee_detail_url` built `/employees/{employees.id}` while that route's parameter is
`employee_no` — the link 404'd, and `tests/eed_dest_payload_test.php:230` asserted the wrong one of the
two, which is why nothing caught it (no JS consumer wired up yet, so nothing shipped broken).
Not done: `PayrollRunModel::payeeLookupForLines()` still batches the same 3 lookups itself (it also
resolves instalments); folding it in means editing a method this round may not touch → BACKLOG.
