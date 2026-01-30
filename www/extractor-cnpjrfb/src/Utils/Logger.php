<?php

namespace CnpjRfb\Utils;

use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;

class Logger
{
    private static ?MonologLogger $logger = null;

    public static function log(string $message, string $level = 'info', array $context = []): void
    {
        if (self::$logger === null) {
            self::init();
        }

        try {
            match (strtolower($level)) {
                'debug'     => self::$logger->debug($message, $context),
                'info'      => self::$logger->info($message, $context),
                'notice'    => self::$logger->notice($message, $context),
                'warning'   => self::$logger->warning($message, $context),
                'error'     => self::$logger->error($message, $context),
                'critical'  => self::$logger->critical($message, $context),
                'alert'     => self::$logger->alert($message, $context),
                'emergency' => self::$logger->emergency($message, $context),
                default     => self::$logger->info($message, $context),
            };
        } catch (\Throwable $e) {
            // Last resort: If logging totally fails (e.g. file permission during write),
            // fallback to error_log so we don't crash the app.
            error_log("Logger Error (Fallback): " . $message . " | Exception: " . $e->getMessage());
        }
    }

    private static function init(): void
    {
        self::$logger = new MonologLogger('cnpj_extractor');
        
        // 1. StdOut (Docker/CLI always needs this)
        $handler = new StreamHandler('php://stdout', Level::Debug);
        self::$logger->pushHandler($handler);

        // 2. Database Handler (Dashboard Viewer)
        // Replaces the file handler that had permission issues.
        try {
            $dbHandler = new DatabaseLogHandler(Level::Debug);
            self::$logger->pushHandler($dbHandler);
        } catch (\Throwable $e) {
            error_log("Logger Error: Could not init Database Handler. " . $e->getMessage());
        }
    }
}
