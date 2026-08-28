# Database Migrations

`database/payroll.sql` is frozen as the schema baseline matching what's live on Production as of
2026-08-28. It is used for fresh installs only — do not append further changes to it.

Every database change from this point forward (new table, `ALTER TABLE`, new master-data seed
rows, etc.) gets its own file here, named:

```
YYYY-MM-DD_short_description.sql
```

If more than one migration lands the same day, suffix `_2`, `_3`, etc. in the order they were
applied. One file = one change/feature — don't append unrelated changes into an existing file.

Each file should be plain, idempotent-where-practical SQL (matching statements already run
directly against the live database), with a short comment at the top explaining what it does and
why, same documentation style already used throughout `payroll.sql`'s own history.
