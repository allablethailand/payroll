-- Final cleanup step of the OT Rate Set replacement (2026-08-30_24 through 2026-08-30_26,
-- see OtRateSetModel's own docblock). Every consumer of the old flat `ot_rates` table has been
-- repointed: SyncPayResolver/PayrollRunModel (calc engine), OvertimeRecordModel/
-- TransactionDataPayAdapter (manual/imported overtime entry, via ot_rate_set_items), OtRateSyncer/
-- OvertimeRecordSyncer (Origami sync), EmployeeOtRateModel (per-employee override defaults),
-- MasterModel (select2 dropdown source), and SetupRulesController/OtRateSetModel (the setup-rules
-- UI). Confirmed via a full source grep that nothing outside historical docblock comments still
-- names this table. Zero real rows existed in it before or during this whole replacement.
DROP TABLE IF EXISTS `ot_rates`;
