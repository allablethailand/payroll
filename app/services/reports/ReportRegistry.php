<?php
declare(strict_types=1);
require_once __DIR__ . '/ReportGeneratorInterface.php';
require_once __DIR__ . '/statutory/th/PndOneReport.php';
require_once __DIR__ . '/statutory/th/PndOneKorSummaryReport.php';
require_once __DIR__ . '/statutory/th/Sso110Report.php';
require_once __DIR__ . '/statutory/th/Sso609Report.php';
require_once __DIR__ . '/statutory/th/Kor20KorReport.php';
require_once __DIR__ . '/statutory/th/StudentLoanReport.php';
require_once __DIR__ . '/payment/PaySlipReport.php';
require_once __DIR__ . '/payment/BankTransferFileReport.php';
require_once __DIR__ . '/payment/PaymentVoucherReport.php';
require_once __DIR__ . '/payment/CashPaymentSummaryReport.php';
require_once __DIR__ . '/internal/PayrollRegisterReport.php';
require_once __DIR__ . '/internal/PayrollRunListSummaryReport.php';
require_once __DIR__ . '/internal/ScheduledItemOccurrenceReconciliationReport.php';

/**
 * Central lookup for all registered ReportGeneratorInterface implementations.
 * To add a new report: write the generator class, require it above, and add one line in
 * init() below. Nothing else in the codebase needs to change.
 */
class ReportRegistry {
    /** @var ReportGeneratorInterface[] */
    private static array $reports = [];
    private static bool $initialized = false;

    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::register(new PndOneReport());
        self::register(new PndOneKorSummaryReport());
        self::register(new Sso110Report());
        self::register(new Sso609Report());
        self::register(new Kor20KorReport());
        self::register(new StudentLoanReport());
        self::register(new PaySlipReport());
        self::register(new BankTransferFileReport());
        self::register(new PaymentVoucherReport());
        self::register(new CashPaymentSummaryReport());
        self::register(new PayrollRegisterReport());
        self::register(new PayrollRunListSummaryReport());
        self::register(new ScheduledItemOccurrenceReconciliationReport());
        self::$initialized = true;
    }

    private static function register(ReportGeneratorInterface $report): void {
        self::$reports[$report->code()] = $report;
    }

    public static function get(string $code): ?ReportGeneratorInterface {
        self::init();
        return self::$reports[$code] ?? null;
    }

    /** @return ReportGeneratorInterface[] */
    public static function byType(string $reportType): array {
        self::init();
        return array_values(array_filter(
            self::$reports,
            fn(ReportGeneratorInterface $r) => $r->reportType() === $reportType
        ));
    }

    /** @return ReportGeneratorInterface[] */
    public static function all(): array {
        self::init();
        return array_values(self::$reports);
    }
}
