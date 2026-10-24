<?php

namespace App\Console\Commands;

use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'policyflow:reconcile {--limit=50 : Maximum number of requests to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and reconcile stale PENDING_CONFIRMATION and NEEDS_RECONCILIATION requests with provider';

    /**
     * Execute the console command.
     */
    public function handle(IssuanceService $issuanceService): int
    {
        $limit = (int) $this->option('limit');
        $this->info("Starting reconciliation run for up to {$limit} pending/unreconciled policies...");

        $candidates = PolicyRequest::whereIn('status', [
            PolicyRequest::STATUS_PENDING_CONFIRMATION,
            PolicyRequest::STATUS_NEEDS_RECONCILIATION,
        ])
        ->whereNotNull('provider_reference')
        ->limit($limit)
        ->get();

        if ($candidates->isEmpty()) {
            $this->info('No pending policies require reconciliation.');
            return Command::SUCCESS;
        }

        $reconciledCount = 0;

        foreach ($candidates as $policy) {
            $this->line("Checking policy request #{$policy->id} (Ref: {$policy->provider_reference})...");

            $providerStatus = $issuanceService->queryProviderStatus($policy->provider_reference);

            if (!$providerStatus || !($providerStatus['found'] ?? false)) {
                $this->warn("Provider has no record for reference: {$policy->provider_reference}");
                continue;
            }

            if (($providerStatus['status'] ?? '') === 'ISSUED' && !empty($providerStatus['policy_number'])) {
                $issuanceService->reconcileToIssued(
                    $policy,
                    $providerStatus['policy_number'],
                    $providerStatus['provider_reference'],
                    'Reconciled via scheduled console command'
                );

                $this->info("-> Successfully reconciled #{$policy->id} to ISSUED with policy #{$providerStatus['policy_number']}");
                $reconciledCount++;
            }
        }

        $this->info("Reconciliation run completed. Reconciled: {$reconciledCount} / {$candidates->count()}");
        return Command::SUCCESS;
    }
}
