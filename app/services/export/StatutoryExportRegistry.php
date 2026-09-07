<?php
declare(strict_types=1);
require_once __DIR__ . '/StatutoryExportInterface.php';
require_once __DIR__ . '/th/PndOneExporter.php';
require_once __DIR__ . '/th/PndOneKorExporter.php';
require_once __DIR__ . '/th/Sso110Exporter.php';
require_once __DIR__ . '/th/Sso609Exporter.php';
require_once __DIR__ . '/th/StudentLoanExporter.php';

/**
 * Central lookup for all registered StatutoryExportInterface implementations.
 * To add a new format (new form, new country): write the exporter class, require it above,
 * and add one line in init() below. Nothing else in the codebase needs to change.
 */
class StatutoryExportRegistry {
    /** @var StatutoryExportInterface[] */
    private static array $exporters = [];
    private static bool $initialized = false;

    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::register(new PndOneExporter());
        self::register(new PndOneKorExporter());
        self::register(new Sso110Exporter());
        self::register(new Sso609Exporter());
        self::register(new StudentLoanExporter());
        self::$initialized = true;
    }

    private static function register(StatutoryExportInterface $exporter): void {
        self::$exporters[$exporter->code()] = $exporter;
    }

    public static function get(string $code): ?StatutoryExportInterface {
        self::init();
        return self::$exporters[$code] ?? null;
    }

    /** @return StatutoryExportInterface[] */
    public static function forCountry(string $countryCode): array {
        self::init();
        return array_values(array_filter(
            self::$exporters,
            fn(StatutoryExportInterface $e) => $e->countryCode() === $countryCode
        ));
    }

    /** @return StatutoryExportInterface[] */
    public static function all(): array {
        self::init();
        return array_values(self::$exporters);
    }
}
