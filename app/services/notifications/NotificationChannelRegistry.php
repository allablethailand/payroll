<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';
require_once __DIR__ . '/EmailChannel.php';
require_once __DIR__ . '/LineChannel.php';
require_once __DIR__ . '/TelegramChannel.php';

/**
 * Central lookup for all registered NotificationChannelInterface implementations, keyed by
 * master_notification_channels.code. To add a channel: write the class, require it above, add
 * one line in init() below.
 */
class NotificationChannelRegistry {
    /** @var NotificationChannelInterface[] */
    private static array $channels = [];
    private static bool $initialized = false;

    private static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::register(new EmailChannel());
        self::register(new LineChannel());
        self::register(new TelegramChannel());
        self::$initialized = true;
    }

    private static function register(NotificationChannelInterface $channel): void {
        self::$channels[$channel->code()] = $channel;
    }

    public static function get(string $code): ?NotificationChannelInterface {
        self::init();
        return self::$channels[$code] ?? null;
    }

    /** @return NotificationChannelInterface[] */
    public static function all(): array {
        self::init();
        return array_values(self::$channels);
    }
}
