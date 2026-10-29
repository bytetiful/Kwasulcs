<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function telegram_monotonic_seconds(): float
{
    return function_exists('hrtime') ? hrtime(true) / 1_000_000_000 : microtime(true);
}

function telegram_rate_limit_interval_seconds(?array $config = null): float
{
    return 1.0 / (float)telegram_config($config)['max_messages_per_second'];
}

final class TelegramRateLimiter
{
    private float $interval;
    private ?float $lastPermitAt = null;
    private $clock;
    private $sleeper;

    public function __construct(?array $config = null, ?callable $clock = null, ?callable $sleeper = null)
    {
        $this->interval = telegram_rate_limit_interval_seconds($config);
        $this->clock = $clock ?? static fn(): float => telegram_monotonic_seconds();
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) usleep((int)ceil($seconds * 1_000_000));
        };
    }

    /** Return the amount of pacing delay applied before this permit. */
    public function acquire(): float
    {
        $now = (float)($this->clock)();
        $wait = $this->lastPermitAt === null ? 0.0 : max(0.0, $this->lastPermitAt + $this->interval - $now);
        if ($wait > 0) ($this->sleeper)($wait);
        $after = (float)($this->clock)();
        $this->lastPermitAt = max($after, $this->lastPermitAt === null ? $after : $this->lastPermitAt + $this->interval);
        return $wait;
    }
}
