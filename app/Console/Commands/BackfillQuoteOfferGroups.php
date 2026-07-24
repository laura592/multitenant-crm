<?php

namespace App\Console\Commands;

use App\Models\Quote;
use App\Models\QuoteGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillQuoteOfferGroups extends Command
{
    protected $signature = 'quotes:backfill-offer-groups
        {--tenant= : Limita il backfill allo slug tenant}
        {--dry-run : Mostra cosa verrebbe raggruppato senza scrivere nulla}';

    protected $description = 'Raggruppa i preventivi storici dello stesso cliente e della stessa data in un\'offerta globale';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Quote::query()
            ->whereNull('quote_group_id')
            ->where('status', '!=', 'bozza')
            ->when($tenantSlug = $this->option('tenant'), function ($builder) use ($tenantSlug) {
                $builder->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('slug', $tenantSlug));
            })
            ->orderBy('tenant_id')
            ->orderBy('customer_id')
            ->orderBy('date')
            ->orderBy('created_at')
            ->orderBy('number');

        $quotes = $query->get();

        if ($quotes->isEmpty()) {
            $this->info('Nessun preventivo storico da raggruppare.');

            return self::SUCCESS;
        }

        $groupedBuckets = $quotes->groupBy(function (Quote $quote) {
            return implode('|', [
                $quote->tenant_id,
                $quote->customer_id,
                optional($quote->date)->toDateString() ?? 'no-date',
            ]);
        });

        $createdGroups = 0;
        $linkedQuotes = 0;
        $skippedBuckets = 0;

        DB::transaction(function () use ($groupedBuckets, $dryRun, &$createdGroups, &$linkedQuotes, &$skippedBuckets) {
            foreach ($groupedBuckets as $bucketQuotes) {
                if ($bucketQuotes->count() < 2) {
                    $skippedBuckets++;

                    continue;
                }

                $bucketQuotes = $bucketQuotes->sortBy([
                    ['date', 'asc'],
                    ['created_at', 'asc'],
                    ['number', 'asc'],
                ])->values();

                if ($dryRun) {
                    $createdGroups++;
                    $linkedQuotes += $bucketQuotes->count();

                    continue;
                }

                $group = QuoteGroup::create([
                    'tenant_id' => $bucketQuotes->first()->tenant_id,
                    'customer_id' => $bucketQuotes->first()->customer_id,
                    'status' => $bucketQuotes->contains(fn (Quote $quote) => $quote->status === 'accettato')
                        ? 'scelto'
                        : 'inviato',
                    'sent_at' => $bucketQuotes->max('created_at'),
                ]);

                Quote::whereKey($bucketQuotes->pluck('id'))->update(['quote_group_id' => $group->id]);
                $linkedQuotes += $bucketQuotes->count();

                $createdGroups++;
            }
        });

        if ($dryRun) {
            $this->warn('Eseguito in dry-run: nessun dato scritto.');
        }

        $this->info("Offerte create: {$createdGroups}");
        $this->info("Preventivi collegati: {$linkedQuotes}");
        $this->line("Gruppi con un solo preventivo saltati: {$skippedBuckets}");

        return self::SUCCESS;
    }
}
