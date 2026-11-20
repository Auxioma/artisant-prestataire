<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

final readonly class DashboardCoverageView
{
    /** Part de demandes sans devis à partir de laquelle un département est signalé (orange, puis rouge). */
    public const float WARN_RATE = 0.10;
    public const float ALERT_RATE = 0.20;

    /**
     * @param list<array{code: string, name: string, requests: int, uncovered: int, rate: float, severity: string}>                $departments
     * @param list<array{name: string, requests: int}>                                                                             $jobs
     * @param list<array{job_id: string, job: string, department_code: string, department: string, uncovered: int, artisans: int}> $gaps
     */
    public function __construct(public array $departments, public array $jobs, public array $gaps)
    {
    }

    public static function severity(float $rate): string
    {
        return $rate >= self::ALERT_RATE ? 'alert' : ($rate >= self::WARN_RATE ? 'warn' : 'ok');
    }
}
