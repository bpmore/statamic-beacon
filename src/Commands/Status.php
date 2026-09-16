<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Commands;

use Bpmore\Beacon\Beacon;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Throwable;

/**
 * The health of the alert pipeline, for a person or for monitoring.
 *
 * Exit 0 when every remote source has a payload within its ceiling and
 * the scheduler has run recently. Exit 1 when one has not, or with
 * --strict when any source's last attempt failed even though its retained
 * payload is still good. Exit 2 when the command itself could not run.
 */
class Status extends Command
{
    use RunsInPlease;

    protected $signature = 'beacon:status
        {--json : Machine-readable output, nothing else on stdout}
        {--strict : Also fail when a source\'s most recent attempt failed}';

    protected $description = 'Report the state of every alert source and the scheduler.';

    public function handle(Beacon $beacon): int
    {
        try {
            $status = $beacon->status();
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->getOutput()->write(json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $this->error('Could not read the status: '.$e->getMessage());
            }

            return 2;
        }

        $failing = (bool) $this->option('strict') && array_reduce(
            $status['sources'],
            fn (bool $c, array $s) => $c || (($s['remote'] ?? false) && ($s['last_error'] ?? null) !== null && ($s['last_error_at'] ?? '') >= ($s['fetched_at'] ?? '')),
            false,
        );

        $exit = ($status['healthy'] && ! $failing) ? 0 : 1;

        if ($this->option('json')) {
            $this->getOutput()->write(json_encode(array_merge($status, ['exit' => $exit]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line('Audience: '.$status['audience']);
        $this->line('Active alerts for this audience: '.$status['active']);

        if ($status['freshness_budget_seconds'] !== null) {
            $this->line('Freshness budget: up to '.$status['freshness_budget_seconds'].' seconds from a remote change to a visitor seeing it.');
        }

        $s = $status['scheduler'];
        $this->line('Scheduler last ran: '.($s['last_tick_at'] ?? 'never').($s['late'] ? '  <-- LATE' : ''));
        $this->newLine();

        foreach ($status['sources'] as $source) {
            if (! $source['remote']) {
                $this->line(sprintf('%-20s %s', $source['key'], $source['driver']));

                continue;
            }

            $this->line(sprintf('%-20s %-7s %s', $source['key'], $source['driver'], $source['ok'] ? 'ok' : ($source['never_fetched'] ? 'NEVER FETCHED' : 'STALE')));
            $this->line('  url:          '.$source['url']);
            $this->line('  alerts:       '.$source['alerts']);
            $this->line('  fetched:      '.($source['fetched_at'] ?? 'never'));
            if ($source['last_error']) {
                $this->line('  last error:   '.$source['last_error'].' at '.$source['last_error_at']);
            }
            if ($source['backoff_until']) {
                $this->line('  backing off:  until '.$source['backoff_until']);
            }
            if ($source['rate_limit_remaining'] !== null) {
                $this->line('  rate limit:   '.$source['rate_limit_remaining'].' left'.($source['rate_limit_reset'] ? ', resets '.$source['rate_limit_reset'] : ''));
            }
        }

        return $exit;
    }
}
