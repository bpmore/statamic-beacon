<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Commands;

use Bpmore\Beacon\Beacon;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * What the scheduler runs every minute: poll what is due, notice alerts
 * crossing a start or end, clear the page cache when a visitor would see
 * something different.
 */
class Tick extends Command
{
    use RunsInPlease;

    protected $signature = 'beacon:tick {--force : Poll every remote source now, whether or not it is due}';

    protected $description = 'Poll due remote sources, honour scheduled starts and ends, and clear the page cache when the banner changed.';

    public function handle(Beacon $beacon): int
    {
        $result = $beacon->tick((bool) $this->option('force'));

        foreach ($result['polled'] as $poll) {
            $state = $poll->skipped ? 'skipped' : ($poll->succeeded ? 'ok' : 'failed');
            $this->line(sprintf('%s: %s%s%s', $poll->source, $state, $poll->changed ? ', changed' : '', $poll->note ? ' ('.$poll->note.')' : ''));
        }

        if ($result['boundaries'] !== []) {
            $this->line('Crossed a start or end: '.implode(', ', $result['boundaries']));
        }

        if ($result['flushed']) {
            $this->line('Static cache cleared.');
        }

        return self::SUCCESS;
    }
}
