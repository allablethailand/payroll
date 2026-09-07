-- 2026-09-05, Phase 12 T073 -- implements the "generate once, persist, reuse" decision (Option C,
-- confirmed via AskUserQuestion after presenting A/background-on-cycle-close vs B/on-demand-every-
-- time vs this C) for the payslip PDF itself. Mirrors the SAME resolve-once-persist-reuse pattern
-- `payroll_run_details.payslip_number` already established (T061) -- one nullable path column,
-- scoped to the same (run_id, employee_id) row a payslip is already keyed by, set on the FIRST
-- real PDF render for that pair and reused by every later caller (PayslipDeliveryService::deliver(),
-- PayslipController::myDownload(), a manual Reports-page download, or a payslip_requests approval)
-- regardless of which one happens to ask first.
--
-- Deliberately NOT a per-payslip_requests-row file_path (unlike Employment Certificate Request's
-- own file_path column) -- a payslip's identity is the (run, employee) pair itself, not the request;
-- a request is just one of several ways to ASK for an already-existing payslip, not what creates it
-- (an employee can download their own payslip via myDownload() with no request involved at all, and
-- Payslip Distribution's auto-send mode has no request either). Caching on payroll_run_details is
-- the one place every access path already converges, so this is where the cache belongs.
--
-- Safe by construction: PaySlipReport::generate() already refuses to run at all unless the run is
-- Approved/Paid/Locked (see that class's own ALLOWED_STATES) -- by the time ANY caller ever reaches
-- the point where a file would be persisted, the underlying payroll numbers are already finalized,
-- so "generate once and never re-render" carries none of Option A's own stale-data risk.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-05_4_payslip_pdf_cache.sql

ALTER TABLE `payroll_run_details`
    ADD COLUMN `payslip_pdf_path` VARCHAR(255) NULL COMMENT 'Relative path (e.g. public/uploads/payslip_files/{comp_id}/{hash}.pdf) to the ONE persisted PDF render for this (run_id, employee_id) pair -- set once on first real generate(), reused by every later caller. NULL = never generated yet.' AFTER `payslip_number`;
