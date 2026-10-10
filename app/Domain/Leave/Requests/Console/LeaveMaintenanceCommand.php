<?php

namespace App\Domain\Leave\Requests\Console;

use App\Domain\Leave\CompOff\CompOffService;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Requests\DecisionService;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** Escalates overdue pending leave approvals to HR and marks lapsed comp-off credits. Idempotent; hourly. */
class LeaveMaintenanceCommand extends Command
{
    protected $signature = 'leave:maintain-requests';

    protected $description = 'Escalate overdue leave approvals and expire lapsed comp-off credits';

    public function handle(TenantRunner $runner, DecisionService $decisions, CompOffService $compOff): int
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $n = $runner->run($tenantId, function () use ($decisions, $compOff) {
                $count = 0;
                foreach (LeaveRequest::query()->where('status', 'pending')->get() as $r) {
                    $count += $decisions->escalateIfOverdue($r) ? 1 : 0;
                }

                return [$count, $compOff->expireDue()];
            });
            $this->line("Tenant $tenantId: $n[0] escalated, $n[1] comp-off credit(s) expired.");
        }

        return self::SUCCESS;
    }
}
