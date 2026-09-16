<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Commands;

use Bpmore\Beacon\Beacon;
use Bpmore\Beacon\Fetch\PollResult;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/** Fetch every remote source now and say what happened. */
class Fetch extends Command
{
    use RunsInPlease;

    protected $signature = 'beacon:fetch {--json : Machine-readable output, nothing else on stdout}';

    protected $description = 'Fetch every remote source now, clear the page cache if the banner changed, and report.';

    public function handle(Beacon $beacon): int
    {
        $results = $beacon->poll(force: true);

        $changed = array_reduce($results, fn (bool $c, PollResult $r) => $c || $r->changed, false);
        if ($changed) {
            $beacon->flush();
        }

        if ($this->option('json')) {
            $this->getOutput()->write(json_encode([
                'flushed' => $changed,
                'sources' => array_map(fn (PollResult $r) => [
                    'source' => $r->source,
                    'succeeded' => $r->succeeded,
                    'skipped' => $r->skipped,
                    'changed' => $r->changed,
                    'alerts' => count($r->snapshot->alerts),
                    'note' => $r->note,
                    'last_error' => $r->snapshot->lastError,
                ], $results),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($results === []) {
            $this->line('No remote sources are configured.');

            return self::SUCCESS;
        }

        foreach ($results as $r) {
            $state = $r->skipped ? 'skipped' : ($r->succeeded ? 'ok' : 'FAILED');
            $this->line(sprintf('%-20s %-8s %d alert(s)%s%s', $r->source, $state, count($r->snapshot->alerts), $r->changed ? ', changed' : '', $r->note ? ': '.$r->note : ''));
        }

        if ($changed) {
            $this->line('Static cache cleared.');
        }

        return self::SUCCESS;
    }
}
