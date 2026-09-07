<?php
    date_default_timezone_set('UTC');
    ini_set('session.cookie_httponly', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    ini_set('session.cookie_samesite', 'Lax');
    // 2026-08-29, explicit bug report: "Session หลุดบ่อยกลับไปที่ Origami" -- PHP's stock
    // session.gc_maxlifetime (1440s/24min) meant any idle gap over ~24 minutes risked the session
    // file being garbage-collected, and session.cookie_lifetime=0 (session-only cookie) meant a
    // closed browser/tab lost it too; either way ensure_login() then bounces to /auth with no
    // Origami token (a normal page revisit, not a fresh SSO redirect), which auth/index.php shows
    // as its "เข้าสู่ระบบไม่สำเร็จ...กลับไปหน้า Origami" failure page -- exactly this report. Extended
    // both to match a full workday; must be set before session_start() (same as index.php/
    // auth/index.php/auth/switch.php, the 3 standalone entry points that call it).
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.cookie_lifetime', '28800');
    session_start();
    require_once __DIR__ . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    require_once __DIR__ . '/app/helpers/helpers.php';
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/app/core/Database.php';
    require_once __DIR__ . '/app/core/Controller.php';
    require_once __DIR__ . '/app/core/Router.php';
    spl_autoload_register(function ($class) {
        $paths = ['app/controllers/', 'app/models/', 'app/core/', 'app/services/'];
        foreach ($paths as $path) {
            $file = __DIR__ . '/' . $path . $class . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    });
    // Real sessions are issued by auth/index.php (Origami SSO login). No dev-fallback session here
    // anymore -- anyone without a real session gets redirected to /auth (or a 401 on API routes)
    // by ensure_login() below, same as any other unauthenticated request.
    ensure_login();
    $router = new Router();
    $router->get('auth', 'AuthController@permission'); 
    $router->get('/', 'DashboardController@index'); 
    $router->get('dashboard', 'DashboardController@index');
    $router->get('api/dashboard.summary', 'DashboardController@summary');
    $router->get('api/dashboard.calendar', 'DashboardController@calendar');
    $router->get('api/user-preference.get', 'UserPreferenceController@get');
    $router->post('api/user-preference.save', 'UserPreferenceController@save');
    $router->get('employees', 'EmployeeController@index');
    // 2026-09-02, 3-way Employee submenu split -- registered here (before 'employees/{id}' further
    // below) since Router::dispatch() matches routes in registration order and 'employees/{id}'s
    // own pattern ('([^/]+)') would otherwise swallow these two literal segments first.
    $router->get('employees/login-history', 'EmployeeController@loginHistory');
    $router->get('employees/reports', 'EmployeeController@reports');
    $router->get('/payroll-process', 'PayrollController@index');
    $router->get('/payroll-process/{id}', 'PayrollController@detail');
    $router->post('api/payroll-run.options', 'PayrollController@options');
    $router->get('/payroll-approval', 'PayrollController@approvalQueue');
    $router->get('api/payroll-run.list', 'PayrollController@list');
    $router->get('api/payroll-run.get', 'PayrollController@get');
    $router->get('api/payroll-run.approval-timeline', 'PayrollController@approvalTimeline');
    $router->post('api/payroll-run.save', 'PayrollController@save');
    // 2026-08-31, PAYROLL_SYNC_API.md `attribution` revision -- merge a supplemental sync process
    // into an existing target run instead of pulling it as its own standalone run.
    $router->post('api/payroll-run.merge-supplemental', 'PayrollController@mergeSupplemental');
    // 2026-09-01, explicit request: manual "reference an existing round" radio option on the Add flow.
    $router->post('api/payroll-run.merge-into-existing', 'PayrollController@mergeIntoExistingRun');
    $router->post('api/payroll-run.delete', 'PayrollController@delete');
    $router->post('api/payroll-run.recalculate', 'PayrollController@recalculate');
    $router->post('api/payroll-run.manual-employee-options', 'PayrollController@manualEmployeeOptions');
    $router->post('api/payroll-run.manual-employee-all-ids', 'PayrollController@manualEmployeeAllIds');
    $router->post('api/payroll-run.manual-employee-column-values', 'PayrollController@manualEmployeeColumnValues');
    $router->post('api/payroll-run.join-employees', 'PayrollController@joinEmployees');
    $router->post('api/payroll-run.remove-employee', 'PayrollController@removeEmployee');
    $router->get('api/payroll-run.manual-lines', 'PayrollController@manualLinesForEmployee');
    $router->post('api/payroll-run.add-manual-line', 'PayrollController@addManualLine');
    $router->post('api/payroll-run.remove-manual-line', 'PayrollController@removeManualLine');
    $router->get('api/payroll-run.sync-lines-for-employee', 'PayrollController@syncLinesForEmployee');
    $router->post('api/payroll-run.line-override.save', 'PayrollController@lineOverrideSave');
    $router->post('api/payroll-run.line-override.remove', 'PayrollController@lineOverrideRemove');
    // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9a).
    $router->post('api/payroll-run.statutory-line-override.save', 'PayrollController@statutoryLineOverrideSave');
    $router->post('api/payroll-run.statutory-line-override.remove', 'PayrollController@statutoryLineOverrideRemove');
    // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6.
    $router->get('api/payroll-run.recurring-deduction-destinations-for-employee', 'PayrollController@recurringDeductionDestinationsForEmployee');
    $router->post('api/payroll-run.recurring-deduction-destination-override.save', 'PayrollController@recurringDeductionDestinationOverrideSave');
    $router->post('api/payroll-run.recurring-deduction-destination-override.remove', 'PayrollController@recurringDeductionDestinationOverrideRemove');
    $router->get('api/payroll-run.attendance-data-for-employee', 'PayrollController@attendanceDataForEmployee');
    $router->post('api/payroll-run.attendance-override.save', 'PayrollController@attendanceOverrideSave');
    $router->post('api/payroll-run.attendance-override.remove', 'PayrollController@attendanceOverrideRemove');
    $router->get('api/payroll-run.raw-sync-data-for-employee', 'PayrollController@rawSyncDataForEmployee');
    $router->post('api/payroll-run.save-employee-exemption', 'PayrollController@saveEmployeeExemption');
    $router->get('api/payroll-run.run-settings-get', 'PayrollController@runSettingsGet');
    $router->post('api/payroll-run.run-settings-save', 'PayrollController@runSettingsSave');
    // 2026-08-31, explicit request: per-run "auto-recalculate immediately after edits" checkbox.
    $router->post('api/payroll-run.auto-recalculate.save', 'PayrollController@autoRecalculateSave');
    $router->post('api/payroll-run.employee-verify.save', 'PayrollController@employeeVerifySave');
    $router->post('api/payroll-run.employee-verify.bulk', 'PayrollController@employeeVerifyBulk');
    // 2026-08-31, explicit request: Lock retired (Verify itself now freezes recalculation); "Verify
    // All" is new -- verifies every employee in the run at once, reachable from List or Detail.
    $router->post('api/payroll-run.employee-verify.all', 'PayrollController@employeeVerifyAll');
    $router->post('api/payroll-run.employee-comment.add', 'PayrollController@employeeCommentAdd');
    $router->get('api/payroll-run.employee-comment.list', 'PayrollController@employeeCommentList');
    $router->post('api/payroll-run.employee-comment.update', 'PayrollController@employeeCommentUpdate');
    $router->post('api/payroll-run.employee-comment.delete', 'PayrollController@employeeCommentDelete');
    $router->get('api/payroll-run.error-employees', 'PayrollController@errorEmployees');
    $router->get('api/payroll-run.sync-missing-employees', 'PayrollController@syncMissingEmployees');
    $router->post('api/payroll-run.submit', 'PayrollController@submit');
    $router->post('api/payroll-run.revert', 'PayrollController@revert');
    $router->post('api/payroll-run.approve', 'PayrollController@approve');
    $router->post('api/payroll-run.reject', 'PayrollController@reject');
    $router->post('api/payroll-run.bulk-approve', 'PayrollController@bulkApprove');
    $router->post('api/payroll-run.bulk-reject', 'PayrollController@bulkReject');
    $router->post('api/payroll-run.cancel', 'PayrollController@cancel');
    $router->post('api/payroll-run.revise-after-reject', 'PayrollController@reviseAfterReject');
    $router->post('api/payroll-run.request-info', 'PayrollController@requestInfo');
    $router->post('api/payroll-run.bulk-request-info', 'PayrollController@bulkRequestInfo');
    $router->post('api/payroll-run.revise-after-need-info', 'PayrollController@reviseAfterNeedInfo');
    $router->post('api/payroll-run.mark-paid', 'PayrollController@markPaid');
    $router->post('api/payroll-run.lock', 'PayrollController@lock');
    $router->post('api/payroll-run.reopen', 'PayrollController@reopen');
    $router->get('setup/company-profile', 'CompanyProfileController@index');
    $router->get('setup/payroll-configuration', 'PayrollConfigurationController@index');
    $router->post('api/payroll-cycle.options', 'PayrollConfigurationController@cycleOptions');
    $router->post('api/bank-file-format.options', 'PayrollConfigurationController@bankFileFormatOptions');
    $router->post('api/payroll-cycle.bank-account.options', 'PayrollConfigurationController@bankAccountOptions');
    $router->get('api/payroll-cycle.list', 'PayrollConfigurationController@cycleList');
    $router->get('api/payroll-cycle.get', 'PayrollConfigurationController@cycleGet');
    $router->get('api/payroll-cycle.suggest-period', 'PayrollConfigurationController@cycleSuggestPeriod');
    $router->post('api/payroll-cycle.save', 'PayrollConfigurationController@cycleSave');
    $router->post('api/payroll-cycle.save-bank-accounts', 'PayrollConfigurationController@cycleSaveBankAccounts');
    $router->post('api/payroll-cycle.delete', 'PayrollConfigurationController@cycleDelete');
    $router->post('api/payroll-cycle.toggle-status', 'PayrollConfigurationController@cycleToggleStatus');
    // 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) -- shared
    // options endpoint (master_payment_methods is global data), used by both the Cycle form's
    // own "default payment method" picker and the Employee page's Employment-tab picker.
    $router->post('api/payment-method.options', 'PayrollConfigurationController@paymentMethodOptions');
    $router->post('api/ped-type.source-event-options', 'PayrollConfigurationController@pedSourceEventOptions');
    $router->post('api/ped-type.list', 'PayrollConfigurationController@pedTypeList');
    $router->post('api/ped-type.column-values', 'PayrollConfigurationController@pedTypeColumnValues');
    $router->get('api/ped-type.get', 'PayrollConfigurationController@pedTypeGet');
    $router->post('api/ped-type.save', 'PayrollConfigurationController@pedTypeSave');
    $router->post('api/ped-type.delete', 'PayrollConfigurationController@pedTypeDelete');
    $router->post('api/ped-type.toggle-status', 'PayrollConfigurationController@pedTypeToggleStatus');
    $router->post('api/ped-type.seed-defaults', 'PayrollConfigurationController@pedTypeSeedDefaults');
    $router->post('api/attendance-deduction-rule.method-options', 'PayrollConfigurationController@attendanceDeductionMethodOptions');
    $router->get('api/attendance-deduction-rule.get-all', 'PayrollConfigurationController@attendanceDeductionRuleGetAll');
    $router->post('api/attendance-deduction-rule.save', 'PayrollConfigurationController@attendanceDeductionRuleSave');
    $router->get('api/attendance-deduction-rule.assignable-options', 'PayrollConfigurationController@attendanceDeductionRuleAssignableOptions');
    $router->post('api/attendance-deduction-rule.delete', 'PayrollConfigurationController@attendanceDeductionRuleDelete');
    $router->post('api/attendance-deduction-rule.preview', 'PayrollConfigurationController@attendanceDeductionRulePreview');
    $router->get('api/payroll-policy.get', 'PayrollConfigurationController@policyGet');
    $router->post('api/payroll-policy.save', 'PayrollConfigurationController@policySave');
    // 2026-09-04, Backlog Phase 10, T056 -- Probation Sets (Clone + Assign, via T055's EntityAssignmentModel).
    $router->get('api/probation-policy-set.list', 'PayrollConfigurationController@probationSetList');
    $router->get('api/probation-policy-set.get', 'PayrollConfigurationController@probationSetGet');
    $router->post('api/probation-policy-set.save', 'PayrollConfigurationController@probationSetSave');
    $router->post('api/probation-policy-set.delete', 'PayrollConfigurationController@probationSetDelete');
    $router->post('api/probation-policy-set.toggle-status', 'PayrollConfigurationController@probationSetToggleStatus');
    $router->post('api/probation-policy-set.set-default', 'PayrollConfigurationController@probationSetSetDefault');
    $router->post('api/probation-policy-set.duplicate', 'PayrollConfigurationController@probationSetDuplicate');
    $router->post('api/probation-policy-set.assignable-options', 'PayrollConfigurationController@probationSetAssignableOptions');
    // 2026-09-04, Backlog Phase 10, T057: Announcement CMS.
    $router->get('setup/announcements', 'AnnouncementController@settingsPage');
    $router->get('announcements', 'AnnouncementController@myListPage');
    $router->get('api/announcement.list', 'AnnouncementController@list');
    $router->get('api/announcement.get', 'AnnouncementController@get');
    $router->get('api/announcement.assignable-options', 'AnnouncementController@assignableOptions');
    $router->post('api/announcement.save', 'AnnouncementController@save');
    $router->post('api/announcement.delete', 'AnnouncementController@delete');
    $router->post('api/announcement.publish', 'AnnouncementController@publish');
    $router->post('api/announcement.set-featured', 'AnnouncementController@setFeatured');
    $router->get('api/announcement.pending-list', 'AnnouncementController@pendingList');
    $router->get('api/announcement.my-list', 'AnnouncementController@myList');
    $router->post('api/announcement.acknowledge', 'AnnouncementController@acknowledge');
    $router->get('setup/tax-statutory', 'TaxStatutoryController@index');
    $router->get('api/statutory-item.list', 'TaxStatutoryController@itemList');
    $router->get('api/statutory-item.get', 'TaxStatutoryController@itemGet');
    $router->post('api/statutory-item.save', 'TaxStatutoryController@itemSave');
    $router->post('api/statutory-item.delete', 'TaxStatutoryController@itemDelete');
    $router->post('api/statutory-item.toggle-status', 'TaxStatutoryController@itemToggleStatus');
    $router->get('api/statutory-item.rate-history.list', 'TaxStatutoryController@rateHistoryList');
    $router->get('api/statutory-item.rate-history.get', 'TaxStatutoryController@rateHistoryGet');
    $router->post('api/statutory-item.rate-history.save', 'TaxStatutoryController@rateHistorySave');
    $router->post('api/statutory-item.rate-history.delete', 'TaxStatutoryController@rateHistoryDelete');
    $router->post('api/statutory-item.rate-version.preview', 'TaxStatutoryController@rateVersionPreview');
    // 2026-09-03, Backlog Phase 9, T045 -- Master/Clone architecture: a company's own custom
    // statutory items (comp_id-scoped), and the 2 "Update as system default" promote actions.
    $router->get('api/statutory-item.custom.get', 'TaxStatutoryController@customItemGet');
    $router->post('api/statutory-item.custom.save', 'TaxStatutoryController@customItemSave');
    $router->post('api/statutory-item.custom.delete', 'TaxStatutoryController@customItemDelete');
    $router->post('api/statutory-item.custom.promote', 'TaxStatutoryController@customItemPromote');
    $router->post('api/company-statutory-setting.promote', 'TaxStatutoryController@companySettingPromote');
    $router->get('api/company-statutory-setting.list', 'TaxStatutoryController@companySettingList');
    $router->get('api/company-statutory-setting.get', 'TaxStatutoryController@companySettingGet');
    $router->post('api/company-statutory-setting.save', 'TaxStatutoryController@companySettingSave');
    $router->post('api/company-statutory-setting.reset', 'TaxStatutoryController@companySettingReset');
    $router->post('api/company-statutory-setting.toggle-status', 'TaxStatutoryController@companySettingToggleStatus');
    // Statutory document format version selector (2026-08-29) -- Tax & Statutory settings, 3rd tab.
    $router->get('api/statutory-format-version.settings', 'StatutoryFormatVersionController@settings');
    $router->post('api/statutory-format-version.save', 'StatutoryFormatVersionController@save');
    $router->get('api/nonresident-tax-setting.get', 'NonResidentTaxSettingController@get');
    $router->post('api/nonresident-tax-setting.save', 'NonResidentTaxSettingController@save');
    $router->get('setup/document-approval', 'DocumentApprovalController@index');
    $router->get('api/document-numbering.list', 'DocumentNumberingController@list');
    $router->post('api/document-numbering.save', 'DocumentNumberingController@save');
    // 2026-08-24, explicit request: "menu Payslip น่าจะต้องเปลี่ยนชื่อและ link นะครับ เพราะไม่ใช่แค่
    // payslip อย่างเดียว" (the menu now also hosts Employment Certificate Template settings) --
    // route prefix renamed payslip/* -> payslip-documents/* to match. Controller/view file paths
    // (PayslipController, app/views/payslip/*) are internal, not user-facing, so left unrenamed.
    $router->get('payslip-documents/requests', 'PayslipController@requests');
    $router->get('payslip-documents/settings', 'PayslipController@settings');
    // 2026-09-04, Backlog Phase 11, T062 -- self-service "view MY OWN payslip" link, the target
    // of LineChannel's push-message text (see PayslipController::myDownload()'s own docblock).
    $router->get('api/payslip.my-download', 'PayslipController@myDownload');
    $router->post('api/approval-workflow.document-type-options', 'ApprovalWorkflowController@documentTypeOptions');
    $router->get('api/approval-workflow.list', 'ApprovalWorkflowController@workflowList');
    $router->get('api/approval-workflow.get', 'ApprovalWorkflowController@workflowGet');
    $router->post('api/approval-workflow.save', 'ApprovalWorkflowController@workflowSave');
    $router->post('api/approval-workflow.delete', 'ApprovalWorkflowController@workflowDelete');
    $router->post('api/approval-workflow.duplicate', 'ApprovalWorkflowController@workflowDuplicate');
    $router->post('api/approval-workflow.toggle-status', 'ApprovalWorkflowController@workflowToggleStatus');
    $router->get('api/approval-workflow.flow-get', 'ApprovalWorkflowController@flowGet');
    $router->post('api/approval-workflow.step-save', 'ApprovalWorkflowController@stepSave');
    $router->post('api/approval-workflow.step-delete', 'ApprovalWorkflowController@stepDelete');
    $router->post('api/approval-workflow.steps-sort', 'ApprovalWorkflowController@stepsSort');
    $router->post('api/approval-request.create', 'ApprovalWorkflowController@requestCreate');
    $router->post('api/approval-request.act', 'ApprovalWorkflowController@requestAct');
    $router->get('api/approval-request.get', 'ApprovalWorkflowController@requestGet');
    $router->get('api/approval-request.list', 'ApprovalWorkflowController@requestList');
    $router->get('api/approval-request.logs', 'ApprovalWorkflowController@requestLogs');
    $router->get('setup/notification', 'NotificationController@index');
    $router->get('setup-rules', 'SetupRulesController@index');
    $router->get('api/shift.list', 'SetupRulesController@shiftList');
    $router->get('api/shift.get', 'SetupRulesController@shiftGet');
    $router->post('api/shift.save', 'SetupRulesController@shiftSave');
    $router->post('api/shift.delete', 'SetupRulesController@shiftDelete');
    $router->post('api/shift.toggle-status', 'SetupRulesController@shiftToggleStatus');
    $router->get('api/shift.assigned-employees', 'SetupRulesController@shiftAssignedEmployees');
    $router->post('api/shift.assign-employees', 'SetupRulesController@shiftAssignEmployees');
    // 2026-08-31, explicit request: Shift/Work Location's own equivalent of the structure.assign.*
    // routes above -- see SetupRulesModel::SCOPE_ASSIGN_CONFIG's own docblock.
    $router->get('api/scope.assign.employees-in', 'SetupRulesController@scopeEmployeesInRow');
    $router->get('api/scope.assign.employees-outside', 'SetupRulesController@scopeEmployeesOutsideRow');
    $router->post('api/scope.assign.assign', 'SetupRulesController@scopeAssignEmployees');
    $router->post('api/scope.assign.move-out', 'SetupRulesController@scopeMoveEmployeesOut');
    $router->get('api/work-location.list', 'SetupRulesController@workLocationList');
    $router->get('api/work-location.get', 'SetupRulesController@workLocationGet');
    $router->post('api/work-location.save', 'SetupRulesController@workLocationSave');
    $router->post('api/work-location.delete', 'SetupRulesController@workLocationDelete');
    $router->post('api/work-location.toggle-status', 'SetupRulesController@workLocationToggleStatus');
    $router->post('api/work-location.options', 'SetupRulesController@workLocationOptions');
    $router->post('api/leave-category.options', 'SetupRulesController@leaveCategoryOptions');
    $router->get('api/leave-type.list', 'SetupRulesController@leaveTypeList');
    $router->get('api/leave-type.get', 'SetupRulesController@leaveTypeGet');
    $router->post('api/leave-type.save', 'SetupRulesController@leaveTypeSave');
    $router->post('api/leave-type.delete', 'SetupRulesController@leaveTypeDelete');
    $router->post('api/leave-type.toggle-status', 'SetupRulesController@leaveTypeToggleStatus');
    $router->post('api/leave-type.apply-defaults', 'SetupRulesController@leaveTypeApplyDefaults');
    $router->get('api/permission-matrix.get', 'PermissionController@matrix');
    $router->post('api/permission-matrix.save', 'PermissionController@save');
    // 2026-08-31, explicit request: Permissions moved out of Company Profile's Organizational
    // Structure tab into its own standalone top-level page.
    $router->get('setup/permissions', 'PermissionController@index');
    // 2026-09-03, Platform Hardening Phase 3 Stage 5 -- Employee Detail's "Permission Overrides" tab.
    $router->get('api/permission-employee-overrides.get', 'PermissionController@employeeOverridesGet');
    $router->post('api/permission-employee-overrides.save', 'PermissionController@employeeOverridesSave');
    // 2026-09-04, Backlog Phase 10, T059 -- suspend/unsuspend a user's system access.
    $router->get('api/permission-employee-suspension.get', 'PermissionController@suspensionStatus');
    $router->post('api/permission-employee-suspension.suspend', 'PermissionController@suspend');
    $router->post('api/permission-employee-suspension.unsuspend', 'PermissionController@unsuspend');
    $router->get('payslip-template/edit/{key}', 'PayslipTemplateController@editPage');
    $router->post('api/payslip-template.field-options', 'PayslipTemplateController@fieldTypeOptions');
    $router->post('api/payslip-template.assignable-options', 'PayslipTemplateController@assignableOptions');
    $router->post('api/payslip-template.preset-options', 'PayslipTemplateController@presetOptions');
    $router->get('api/payslip-template.list', 'PayslipTemplateController@list');
    $router->get('api/payslip-template.paired-list', 'PayslipTemplateController@pairedList');
    $router->post('api/payslip-template.generate-other-language', 'PayslipTemplateController@generateOtherLanguage');
    $router->get('api/payslip-template.get', 'PayslipTemplateController@get');
    $router->post('api/payslip-template.save', 'PayslipTemplateController@save');
    $router->post('api/payslip-template.create-from-preset', 'PayslipTemplateController@createFromPreset');
    $router->post('api/payslip-template.duplicate', 'PayslipTemplateController@duplicate');
    $router->post('api/payslip-template.duplicate-pair', 'PayslipTemplateController@duplicatePair');
    $router->post('api/payslip-template.preset-elements', 'PayslipTemplateController@presetElements');
    $router->post('api/payslip-template.delete', 'PayslipTemplateController@delete');
    $router->post('api/payslip-template.toggle-status', 'PayslipTemplateController@toggleStatus');
    $router->post('api/payslip-template.set-default', 'PayslipTemplateController@setDefault');
    $router->post('api/payslip-template.publish-toggle', 'PayslipTemplateController@publishToggle');
    $router->post('api/payslip-template.upload-logo', 'PayslipTemplateController@uploadLogo');
    $router->get('api/payslip-template.list-images', 'PayslipTemplateController@listImages');
    $router->post('api/payslip-template.upload-image', 'PayslipTemplateController@uploadImage');
    $router->post('api/payslip-template.delete-image', 'PayslipTemplateController@deleteImage');
    $router->post('api/payslip-template.preview', 'PayslipTemplateController@preview');
    $router->post('api/payslip-template.preset-preview', 'PayslipTemplateController@presetPreview');
    $router->get('employment-certificate/settings', 'EmploymentCertificateTemplateController@index');
    $router->get('employment-certificate/edit/{key}', 'EmploymentCertificateTemplateController@editPage');
    $router->post('api/employment-certificate-template.field-options', 'EmploymentCertificateTemplateController@fieldTypeOptions');
    $router->post('api/employment-certificate-template.assignable-options', 'EmploymentCertificateTemplateController@assignableOptions');
    $router->post('api/employment-certificate-template.preset-options', 'EmploymentCertificateTemplateController@presetOptions');
    $router->get('api/employment-certificate-template.list', 'EmploymentCertificateTemplateController@list');
    $router->get('api/employment-certificate-template.paired-list', 'EmploymentCertificateTemplateController@pairedList');
    $router->post('api/employment-certificate-template.generate-other-language', 'EmploymentCertificateTemplateController@generateOtherLanguage');
    $router->get('api/employment-certificate-template.get', 'EmploymentCertificateTemplateController@get');
    $router->get('api/employment-certificate-template.get-default', 'EmploymentCertificateTemplateController@getDefault');
    $router->post('api/employment-certificate-template.save', 'EmploymentCertificateTemplateController@save');
    $router->post('api/employment-certificate-template.create-from-preset', 'EmploymentCertificateTemplateController@createFromPreset');
    $router->post('api/employment-certificate-template.duplicate', 'EmploymentCertificateTemplateController@duplicate');
    $router->post('api/employment-certificate-template.duplicate-pair', 'EmploymentCertificateTemplateController@duplicatePair');
    $router->post('api/employment-certificate-template.preset-elements', 'EmploymentCertificateTemplateController@presetElements');
    $router->post('api/employment-certificate-template.delete', 'EmploymentCertificateTemplateController@delete');
    $router->post('api/employment-certificate-template.set-default', 'EmploymentCertificateTemplateController@setDefault');
    $router->post('api/employment-certificate-template.publish-toggle', 'EmploymentCertificateTemplateController@publishToggle');
    $router->post('api/employment-certificate-template.upload-logo', 'EmploymentCertificateTemplateController@uploadLogo');
    $router->get('api/employment-certificate-template.list-images', 'EmploymentCertificateTemplateController@listImages');
    $router->post('api/employment-certificate-template.upload-image', 'EmploymentCertificateTemplateController@uploadImage');
    $router->post('api/employment-certificate-template.delete-image', 'EmploymentCertificateTemplateController@deleteImage');
    $router->post('api/employment-certificate-template.preview', 'EmploymentCertificateTemplateController@preview');
    $router->post('api/employment-certificate-template.preset-preview', 'EmploymentCertificateTemplateController@presetPreview');
    $router->post('api/payslip-distribution.channel-options', 'PayslipDistributionController@channelOptions');
    $router->get('api/payslip-distribution.settings-get', 'PayslipDistributionController@settingsGet');
    $router->post('api/payslip-distribution.settings-save', 'PayslipDistributionController@settingsSave');
    $router->get('api/payslip-request.list', 'PayslipRequestController@list');
    $router->get('api/payslip-request.get', 'PayslipRequestController@get');
    $router->post('api/payslip-request.run-options', 'PayslipRequestController@runOptions');
    $router->post('api/payslip-request.employee-options', 'PayslipRequestController@employeeOptions');
    $router->post('api/payslip-request.create', 'PayslipRequestController@create');

    // Employment Certificate Requests (2026-08-26, explicit request: paired with Payslip Requests
    // on the same page/menu -- see requests.php's new tab). No dedicated employee-options route --
    // the "which employee" picker reuses the existing generic `api/employee.report_to.get`.
    $router->get('api/employment-certificate-request.list', 'EmploymentCertificateRequestController@list');
    $router->get('api/employment-certificate-request.get', 'EmploymentCertificateRequestController@get');
    $router->post('api/employment-certificate-request.create', 'EmploymentCertificateRequestController@create');
    $router->get('api/employment-certificate-request.download', 'EmploymentCertificateRequestController@download');
    $router->get('api/payslip-delivery-log.list', 'PayslipDeliveryLogController@list');
    $router->post('api/payslip-delivery-log.resend', 'PayslipDeliveryLogController@resend');
    // 2026-08-26: unified Payslip + Employment Certificate delivery/issuance log -- see
    // DocumentDeliveryLogModel's own docblock. Replaces api/payslip-delivery-log.list as the
    // Delivery Log tab's own data source; the payslip-only endpoint above stays for its own resend
    // action's sake and isn't removed.
    $router->get('api/document-delivery-log.list', 'DocumentDeliveryLogController@list');
    // 2026-08-31, explicit request: admin log/summary page for the email_queue system (Phase 7,
    // T040) -- see EmailQueueModel::list()/summary()'s own docblocks.
    $router->get('api/email-queue.list', 'EmailQueueController@list');
    $router->get('api/email-queue.summary', 'EmailQueueController@summary');
    // 2026-08-31, explicit request: per-run cash-vs-bank breakdown + per-employee paid status.
    $router->get('api/payroll-run-cash-payment.list', 'PayrollRunCashPaymentController@list');
    $router->post('api/payroll-run-cash-payment.set-status', 'PayrollRunCashPaymentController@setStatus');
    // 2026-09-02, multi-bank-account payroll -- "Bank Account Assignment" tab.
    $router->get('api/payroll-run-employee-bank-account.list', 'PayrollRunEmployeeBankAccountController@list');
    $router->post('api/payroll-run-employee-bank-account.save', 'PayrollRunEmployeeBankAccountController@save');
    $router->post('api/payroll-run-employee-bank-account.remove', 'PayrollRunEmployeeBankAccountController@remove');
    // 2026-09-02, Deduction Destination & Third-Party Remittance.
    $router->post('api/payment-destination.options', 'PaymentDestinationController@options');
    $router->get('api/payroll-remittance.list', 'PayrollRemittanceController@list');
    $router->get('api/payroll-remittance.items', 'PayrollRemittanceController@items');
    $router->post('api/payroll-remittance.mark-transferred', 'PayrollRemittanceController@markTransferred');
    $router->post('api/payroll-remittance.confirm-success', 'PayrollRemittanceController@confirmSuccess');
    $router->post('api/payroll-remittance.mark-failed', 'PayrollRemittanceController@markFailed');
    $router->post('api/payroll-remittance.retry', 'PayrollRemittanceController@retry');
    $router->get('manual-entry', 'ManualEntryController@index');
    $router->get('api/manual-attendance.list', 'ManualEntryController@attendanceList');
    $router->get('api/manual-attendance.get', 'ManualEntryController@attendanceGet');
    $router->post('api/manual-attendance.save', 'ManualEntryController@attendanceSave');
    $router->post('api/manual-attendance.delete', 'ManualEntryController@attendanceDelete');
    $router->post('api/manual-attendance.bulk-save', 'ManualEntryController@attendanceBulkSave');
    $router->get('api/manual-leave.list', 'ManualEntryController@leaveList');
    $router->get('api/manual-leave.get', 'ManualEntryController@leaveGet');
    $router->post('api/manual-leave.save', 'ManualEntryController@leaveSave');
    $router->post('api/manual-leave.delete', 'ManualEntryController@leaveDelete');
    $router->post('api/manual-leave.bulk-save', 'ManualEntryController@leaveBulkSave');
    $router->get('api/manual-overtime.list', 'ManualEntryController@overtimeList');
    $router->get('api/manual-overtime.get', 'ManualEntryController@overtimeGet');
    $router->post('api/manual-overtime.save', 'ManualEntryController@overtimeSave');
    $router->post('api/manual-overtime.delete', 'ManualEntryController@overtimeDelete');
    $router->post('api/manual-overtime.bulk-save', 'ManualEntryController@overtimeBulkSave');
    $router->get('api/manual-import.template', 'ManualEntryController@importTemplate');
    $router->post('api/manual-import.preview', 'ManualEntryController@importPreview');
    $router->post('api/manual-import.commit', 'ManualEntryController@importCommit');
    $router->get('api/manual-import.batch-list', 'ManualEntryController@importBatchList');
    $router->get('api/manual-import.batch-detail', 'ManualEntryController@importBatchDetail');
    $router->get('api/manual-import.activity-log', 'ManualEntryController@importActivityLog');
    $router->get('api/manual-import.download-original', 'ManualEntryController@downloadImportOriginal');
    // Manual Entry Phase 1A -- Attendance Shift auto-fill lookup.
    $router->get('api/manual-entry.employee-context', 'ManualEntryController@employeeContext');

    // Platform Hardening Phase 6 pilot -- field-level audit log viewer.
    $router->get('audit-log', 'AuditLogController@index');
    $router->get('api/audit-log.list', 'AuditLogController@list');
    // 2026-09-05, Backlog Phase 13 -- Terms & Conditions (login-gate modal + Profile menu),
    // Help > Setup Guide/Version pages, and the Help Drawer's own content endpoint.
    $router->get('api/terms.get', 'TermsAndConditionsController@get');
    $router->post('api/terms.accept', 'TermsAndConditionsController@accept');
    $router->get('api/terms.history', 'TermsAndConditionsController@history');
    $router->get('help/setup-guide', 'HelpController@setupGuide');
    $router->get('help/version', 'HelpController@version');
    $router->get('api/help.checklist', 'HelpController@checklist');
    $router->get('api/help.changelog-list', 'HelpController@changelogList');
    $router->get('api/help.drawer-content', 'HelpController@drawerContent');
    // 2026-08-30, Phase 7 (T037/T038/T039) -- session-guard.js's periodic heartbeat poll.
    $router->get('api/session.heartbeat', 'SessionController@heartbeat');
    $router->post('api/ot-rate.scope-options', 'SetupRulesController@otScopeOptions');
    $router->post('api/ot-rate.options', 'MasterController@getMaster');
    $router->post('api/leave-type.options', 'MasterController@getMaster');
    $router->get('api/ot-rate.list', 'SetupRulesController@otRateList');
    $router->get('api/ot-rate.get', 'SetupRulesController@otRateGet');
    $router->post('api/ot-rate.save', 'SetupRulesController@otRateSave');
    $router->post('api/ot-rate.delete', 'SetupRulesController@otRateDelete');
    $router->post('api/ot-rate.toggle-status', 'SetupRulesController@otRateToggleStatus');
    $router->post('api/ot-rate.set-default', 'SetupRulesController@otRateSetDefault');
    $router->post('api/ot-rate.assignable-options', 'SetupRulesController@otRateAssignableOptions');
    $router->post('api/ot-rate.set-options', 'SetupRulesController@otRateSetOptions');
    $router->post('api/ot-rate.preview', 'SetupRulesController@otRatePreview');
    $router->get('api/holiday.list', 'SetupRulesController@holidayList');
    $router->get('api/holiday.get', 'SetupRulesController@holidayGet');
    $router->post('api/holiday.save', 'SetupRulesController@holidaySave');
    $router->post('api/holiday.delete', 'SetupRulesController@holidayDelete');
    $router->post('api/holiday.toggle-status', 'SetupRulesController@holidayToggleStatus');
    $router->post('api/holiday-sync.candidates', 'HolidaySyncController@candidates');
    $router->post('api/holiday-sync.apply', 'HolidaySyncController@apply');
    $router->get('api/holiday-sync.log', 'HolidaySyncController@log');

    $router->post('api/org-structure-sync.candidates', 'OrgStructureSyncController@candidates');
    $router->post('api/org-structure-sync.apply', 'OrgStructureSyncController@apply');
    $router->get('api/org-structure-sync.log', 'OrgStructureSyncController@log');
    $router->post('api/shift.options', 'MasterController@getMaster');
    $router->get('reports', 'ReportsController@index');
    // Annual Income Summary (2026-08-29) -- separate interactive page (live filter/scroll table,
    // not a generate-and-download document like the rest of the Reports module).
    $router->get('reports/annual-summary', 'AnnualIncomeSummaryController@index');
    $router->get('api/annual-income-summary.years', 'AnnualIncomeSummaryController@years');
    $router->get('api/annual-income-summary.summary', 'AnnualIncomeSummaryController@summary');
    $router->get('api/annual-income-summary.cell-detail', 'AnnualIncomeSummaryController@cellDetail');
    // Phase 4, T026/T027/T028 -- ตั้งค่าการตัดรอบปี route removed (companies.fiscal_year_start_month
    // is edited from Company Profile only, see that controller's own save()); 3 new routes for the
    // combined page's 2nd/3rd tabs.
    $router->get('api/annual-income-summary.pit-summary', 'AnnualIncomeSummaryController@pitSummary');
    $router->get('api/annual-income-summary.monthly-pit', 'AnnualIncomeSummaryController@monthlyPit');
    $router->get('api/annual-income-summary.calendar-years', 'AnnualIncomeSummaryController@calendarYears');
    $router->get('api/report.list', 'ReportsController@list');
    $router->get('api/report.cycle-runs', 'ReportsController@cycleRuns');
    $router->get('api/report.available-years', 'ReportsController@availableYears');
    $router->get('api/report.annual-summary', 'ReportsController@annualReportsSummary');
    $router->get('api/report.generate', 'ReportsController@generate');
    $router->get('api/report.export-logs', 'ReportsController@exportLogs');
    $router->get('api/report.run-summary', 'ReportsController@runReportsSummary');
    $router->get('api/report.run-cycle-summary', 'ReportsController@runCycleReportsSummary');
    $router->get('api/report.payslip-roster', 'ReportsController@payslipRoster');
    // 2026-08-31, same-day follow-up (item 10) -- Payroll Run Audit diff-history page.
    $router->get('reports/run-audit', 'ReportsController@runAudit');
    $router->get('api/report.run-audit-list', 'ReportsController@runAuditList');
    $router->get('api/report.run-audit-diff', 'ReportsController@runAuditDiff');
    $router->get('api/report.run-audit-export', 'ReportsController@runAuditExport');
    $router->get('submission', 'SubmissionController@index');
    $router->post('api/employee.list', 'EmployeeController@list');
    $router->post('api/employee.recheck-list', 'EmployeeController@recheckList');
    $router->post('api/employee.standing-summary-list', 'EmployeeController@standingSummaryList');
    $router->post('api/employee.headcount-movement-report', 'EmployeeController@headcountMovementReport');
    $router->post('api/employee.expiry-report', 'EmployeeController@expiryReport');
    $router->post('api/employee.probation-report', 'EmployeeController@probationReport');
    $router->post('api/employee.statutory-enrollment-report', 'EmployeeController@statutoryEnrollmentReport');
    $router->post('api/employee.headcount-structure-report', 'EmployeeController@headcountStructureReport');
    $router->post('api/employee.tenure-report', 'EmployeeController@tenureReport');
    $router->post('api/employee.birthday-anniversary-report', 'EmployeeController@birthdayAnniversaryReport');
    $router->post('api/employee.completeness-overview-report', 'EmployeeController@completenessOverviewReport');
    $router->post('api/employee.station-counts', 'EmployeeController@stationCounts');
    $router->post('api/employee.payroll-participant.set', 'EmployeeController@setPayrollParticipant');
    $router->post('api/employee.list-column-values', 'EmployeeController@listColumnValues');
    $router->post('api/employee-sync.filter-options', 'EmployeeSyncController@filterOptions');
    $router->post('api/employee-sync.candidates', 'EmployeeSyncController@candidates');
    $router->post('api/employee-sync.apply', 'EmployeeSyncController@apply');
    $router->post('api/employee-sync.resync-one', 'EmployeeSyncController@resyncOne');
    $router->post('api/employee-sync.resync-many', 'EmployeeSyncController@resyncMany');
    $router->get('api/employee-sync.last-sync-summary', 'EmployeeSyncController@lastSyncSummary');
    $router->get('api/employee-sync.log', 'EmployeeSyncController@log');
    $router->post('api/employee-login-log.list', 'EmployeeLoginLogController@list');
    $router->get('api/employee-login-log.filter-options', 'EmployeeLoginLogController@filterOptions');
    $router->post('api/employee-login-log.record-timezone', 'EmployeeLoginLogController@recordTimezone');
    $router->post('api/employee-login-log.list-company-wide', 'EmployeeLoginLogController@listCompanyWide');
    $router->get('api/employee-login-log.filter-options-company-wide', 'EmployeeLoginLogController@filterOptionsCompanyWide');
    // 2026-09-05, Backlog Phase 13 -- Profile > "System Access History" self-service view.
    $router->get('api/employee-login-log.my-history', 'EmployeeLoginLogController@myHistory');
    $router->get('api/notification.list', 'NotificationController@list');
    $router->post('api/notification.datatable', 'NotificationController@listDataTable');
    $router->get('api/notification.preferences-get', 'NotificationController@preferencesGet');
    $router->post('api/notification.preferences-save', 'NotificationController@preferencesSave');
    $router->get('api/notification.role-matrix-get', 'NotificationController@roleMatrixGet');
    $router->post('api/notification.role-matrix-save', 'NotificationController@roleMatrixSave');
    $router->get('api/notification.unread-count', 'NotificationController@unreadCount');
    $router->post('api/notification.mark-read', 'NotificationController@markRead');
    $router->post('api/notification.mark-all-read', 'NotificationController@markAllRead');
    $router->get('notifications', 'NotificationController@page');
    $router->get('/employees/create', 'EmployeeController@create');
    $router->get('/employees/{id}', 'EmployeeController@detail');
    $router->get('api/address/search', 'AddressController@getMetadata');
    $router->post('api/company.get', 'CompanyProfileController@get');
    $router->post('api/company.save', 'CompanyProfileController@save');
    $router->post('api/company.upload-logo', 'CompanyProfileController@uploadLogo');
    // 2026-09-02, real Origami company.php endpoint confirmed live -- see CompanySyncModel's own docblock.
    $router->post('api/company.sync-origami', 'CompanyProfileController@syncFromOrigami');
    $router->post('api/company.upload-signature', 'CompanyProfileController@uploadSignature');
    // 2026-09-02, "Data Sync" page -- UI trigger for MasterDataSyncOrchestrator (department/
    // position/shift/branch/team/holiday), see MasterDataSyncController's own docblock.
    $router->get('setup/data-sync', 'MasterDataSyncController@index');
    $router->post('api/master-data-sync.status', 'MasterDataSyncController@status');
    $router->post('api/master-data-sync.sync-one', 'MasterDataSyncController@syncOne');
    $router->post('api/master-data-sync.sync-all', 'MasterDataSyncController@syncAll');
    $router->post('api/master-data-sync.history', 'MasterDataSyncController@history');
    $router->post('api/country.get', 'MasterController@getMaster');
    // 2026-09-04, Backlog Phase 9, T047 -- statutory item category/calc_base master-table dropdowns.
    $router->post('api/statutory-category.get', 'MasterController@getMaster');
    $router->post('api/statutory-calc-base.get', 'MasterController@getMaster');
    $router->post('api/nationality.get', 'MasterController@getMaster');
    $router->post('api/religion.get', 'MasterController@getMaster');
    $router->post('api/structure.role', 'CompanyProfileController@role');
    $router->post('api/structure.branch', 'CompanyProfileController@branch');
    $router->post('api/structure.department', 'CompanyProfileController@department');
    $router->post('api/structure.position', 'CompanyProfileController@position');
    $router->post('api/structure.rank', 'CompanyProfileController@rank');
    // 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...ทีมให้เป็นการเพิ่ม
    // การตั้งค่าเช่นเดียวกับ Department" -- same route shape as department/position/rank above.
    $router->post('api/structure.team', 'CompanyProfileController@team');
    $router->post('api/structure.column-values', 'CompanyProfileController@structureColumnValues');
    $router->post('api/structure.branch.save', 'CompanyProfileController@branchSave');
    $router->post('api/structure.branch.delete', 'CompanyProfileController@branchDelete');
    $router->post('api/structure.role.save', 'CompanyProfileController@roleSave');
    $router->post('api/structure.role.delete', 'CompanyProfileController@roleDelete');
    $router->post('api/structure.department.save', 'CompanyProfileController@departmentSave');
    $router->post('api/structure.department.delete', 'CompanyProfileController@departmentDelete');
    $router->post('api/structure.position.save', 'CompanyProfileController@positionSave');
    $router->post('api/structure.position.delete', 'CompanyProfileController@positionDelete');
    $router->post('api/structure.rank.save', 'CompanyProfileController@rankSave');
    $router->post('api/structure.rank.delete', 'CompanyProfileController@rankDelete');
    $router->post('api/structure.team.save', 'CompanyProfileController@teamSave');
    $router->post('api/structure.team.delete', 'CompanyProfileController@teamDelete');

    // 2026-09-02, Platform Hardening Phase 1.1 -- instant-AJAX status toggle per structure type.
    $router->post('api/structure.branch.toggle-status', 'CompanyProfileController@branchToggleStatus');
    $router->post('api/structure.role.toggle-status', 'CompanyProfileController@roleToggleStatus');
    $router->post('api/structure.department.toggle-status', 'CompanyProfileController@departmentToggleStatus');
    $router->post('api/structure.position.toggle-status', 'CompanyProfileController@positionToggleStatus');
    $router->post('api/structure.rank.toggle-status', 'CompanyProfileController@rankToggleStatus');
    $router->post('api/structure.team.toggle-status', 'CompanyProfileController@teamToggleStatus');
    // 2026-08-31, explicit request: generic Assign Employees modal, shared across every structure
    // type (see CompanyProfileModel's own EMPLOYEE_FK_COLUMN docblock) -- `type` travels as a
    // request param, one shared endpoint per action instead of 6 per-type routes.
    $router->get('api/structure.assign.employees-in', 'CompanyProfileController@structureEmployeesInRow');
    $router->get('api/structure.assign.employees-outside', 'CompanyProfileController@structureEmployeesOutsideRow');
    $router->post('api/structure.assign.assign', 'CompanyProfileController@structureAssignEmployees');
    $router->post('api/structure.assign.move-out', 'CompanyProfileController@structureMoveEmployeesOut');
    $router->post('api/bank.get', 'MasterController@getMaster');
    $router->post('api/department.get', 'MasterController@getMaster');
    $router->post('api/role.get', 'MasterController@getMaster');
    $router->post('api/position.get', 'MasterController@getMaster');
    $router->post('api/team.get', 'MasterController@getMaster');
    // 2026-09-02, Origami candidates.php field batch: employment_type_id dropdown (Employee Detail).
    $router->post('api/employment-type.get', 'MasterController@getMaster');
    $router->post('api/branch.get', 'MasterController@getMaster');
    // 2026-08-31, explicit request: Assign Employees modal's destination-master Select2 -- the 2
    // assignable types that never had a select2-ajax dropdown-options endpoint before (see
    // MasterModel::master()'s own comment). api/rank.get is free (no single-row GET ever claimed
    // it); work-location.options avoids colliding with the existing single-row api/work-location.get.
    $router->post('api/rank.get', 'MasterController@getMaster');
    $router->post('api/work-location.options', 'MasterController@getMaster');
    $router->post('api/employee.report_to.get', 'EmployeeController@reportToOptions');
    $router->post('api/bank_account.list', 'BankAccountController@list');
    $router->post('api/bank_account.column-values', 'BankAccountController@columnValues');
    $router->post('api/bank_account.save', 'BankAccountController@save');
    $router->post('api/bank_account.delete', 'BankAccountController@delete');
    $router->post('api/bank_account.toggle-status', 'BankAccountController@toggleStatus');
    // Bank File Format settings (2026-08-29) -- sub-tab of the same Bank Accounts settings page.
    $router->get('api/bank-file-format.list', 'BankFileFormatController@list');
    $router->get('api/bank-file-format.get', 'BankFileFormatController@get');
    $router->post('api/bank-file-format.save-config', 'BankFileFormatController@saveConfig');
    $router->post('api/bank-file-format.save-field', 'BankFileFormatController@saveField');
    $router->post('api/bank-file-format.delete-field', 'BankFileFormatController@deleteField');
    $router->post('api/bank-file-format.reset', 'BankFileFormatController@resetToDefault');
    $router->get('api/bank-file-format.edit-logs', 'BankFileFormatController@editLogs');
    $router->get('api/employee.get', 'EmployeeController@get');
    $router->post('api/employee.save', 'EmployeeController@save');
    $router->post('api/employee.payment-account-options', 'EmployeeController@paymentAccountOptions');
    $router->get('api/employee.payment-method-lines', 'EmployeeController@paymentMethodLines');
    $router->get('api/employee.payroll-policy-settings', 'EmployeeController@payrollPolicySettings');
    $router->post('api/employee.upload-signature', 'EmployeeController@uploadSignature');
    $router->post('api/employee.upload-photo', 'EmployeeController@uploadPhoto');
    $router->post('api/employee.delete', 'EmployeeController@delete');
    $router->get('api/employee.dependent.list', 'EmployeeController@dependentList');
    $router->post('api/employee.dependent.save', 'EmployeeController@dependentSave');
    $router->post('api/employee.dependent.delete', 'EmployeeController@dependentDelete');
    $router->get('api/employee.parent.list', 'EmployeeController@parentList');
    $router->post('api/employee.parent.save', 'EmployeeController@parentSave');
    $router->post('api/employee.parent.delete', 'EmployeeController@parentDelete');
    $router->post('api/employee.earning-deduction.options', 'EmployeeController@earningDeductionOptions');
    $router->get('api/employee.earning-deduction.list', 'EmployeeController@earningDeductionList');
    $router->get('api/employee.earning-deduction.get', 'EmployeeController@earningDeductionGet');
    // 2026-09-04, Backlog Phase 9->10, T051 -- read-only Sync History sub-section, Income & Deductions tab.
    $router->get('api/employee.sync-transaction-log.list', 'EmployeeController@syncTransactionLogList');
    $router->get('api/employee.scheduled-item-occurrence.list', 'EmployeeController@scheduledItemOccurrenceList');
    $router->get('api/employee.earning-deduction.preview-installments', 'EmployeeController@earningDeductionPreviewInstallments');
    $router->post('api/employee.earning-deduction.save', 'EmployeeController@earningDeductionSave');
    $router->post('api/employee.earning-deduction.status', 'EmployeeController@earningDeductionStatus');
    $router->post('api/employee.earning-deduction.delete', 'EmployeeController@earningDeductionDelete');
    // 2026-08-26: Recurring Earnings (Salary tab's own new section) -- see
    // EmployeeRecurringEarningModel's own docblock.
    $router->post('api/employee.recurring-earning.type-options', 'EmployeeController@recurringEarningTypeOptions');
    $router->get('api/employee.recurring-earning.list', 'EmployeeController@recurringEarningList');
    $router->get('api/employee.recurring-earning.get', 'EmployeeController@recurringEarningGet');
    $router->post('api/employee.recurring-earning.save', 'EmployeeController@recurringEarningSave');
    $router->post('api/employee.recurring-earning.delete', 'EmployeeController@recurringEarningDelete');
    $router->post('api/employee.recurring-deduction.type-options', 'EmployeeController@recurringDeductionTypeOptions');
    $router->get('api/employee.recurring-deduction.list', 'EmployeeController@recurringDeductionList');
    $router->get('api/employee.recurring-deduction.get', 'EmployeeController@recurringDeductionGet');
    $router->post('api/employee.recurring-deduction.save', 'EmployeeController@recurringDeductionSave');
    $router->post('api/employee.recurring-deduction.delete', 'EmployeeController@recurringDeductionDelete');
    $router->get('api/employee.ot-rate.get', 'EmployeeController@otRateGet');
    $router->post('api/employee.ot-rate.save', 'EmployeeController@otRateSave');
    $router->get('api/employee.document.list', 'EmployeeController@documentList');
    $router->post('api/employee.document.upload', 'EmployeeController@documentUpload');
    $router->get('api/employee.document.view', 'EmployeeController@documentView');
    $router->post('api/employee.document.delete', 'EmployeeController@documentDelete');
    $router->post('api/payroll-sync.ingest', 'PayrollSyncController@ingest');
    $router->get('api/payroll-sync.pending-list', 'PayrollSyncController@pendingList');
    $router->get('api/payroll-sync.pending-get', 'PayrollSyncController@pendingGet');
    // 2026-08-31, explicit request: reject-back for a Pending Pull document, with a required comment.
    $router->post('api/payroll-sync.reject', 'PayrollSyncController@reject');
    // 2026-08-31, same-day follow-up -- PayrollSyncModel::ingest() now blocks (rather than silently
    // applying) a re-push for a process already linked to a payroll run; these 3 let an admin review/
    // resolve what got blocked (see PayrollSyncController's own docblock for the 3 actions).
    $router->get('api/payroll-sync.blocked-updates-list', 'PayrollSyncController@blockedUpdatesList');
    $router->post('api/payroll-sync.blocked-update-apply', 'PayrollSyncController@blockedUpdateApply');
    $router->post('api/payroll-sync.blocked-update-dismiss', 'PayrollSyncController@blockedUpdateDismiss');
    $router->dispatch();