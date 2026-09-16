<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

/**
 * Creates the alerts collection and its blueprint. The one thing the
 * addon writes to the site, and only when asked.
 */
class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'beacon:install {--force : Overwrite the blueprint if it already exists}';

    protected $description = 'Create the alerts collection and blueprint for Beacon.';

    public function handle(): int
    {
        $handle = (string) (config('statamic-beacon.collection') ?? 'alerts');

        if (Collection::findByHandle($handle) === null) {
            Collection::make($handle)
                ->title('Alerts')
                ->sites(\Statamic\Facades\Site::all()->map->handle()->all())
                ->save();

            $this->info("Created the `{$handle}` collection.");
        } else {
            $this->line("The `{$handle}` collection already exists.");
        }

        $existing = Blueprint::find("collections.{$handle}.alert");

        if ($existing !== null && ! $this->option('force')) {
            $this->line("The `alert` blueprint already exists. Pass --force to replace it.");
        } else {
            $contents = YAML::file(__DIR__.'/../../resources/blueprints/alert.yaml')->parse();

            Blueprint::make('alert')
                ->setNamespace("collections.{$handle}")
                ->setContents($contents)
                ->save();

            $this->info('Wrote the `alert` blueprint.');
        }

        $this->newLine();
        $this->line('Next: put `{{ beacon }}` first inside <body> in your layout,');
        $this->line('and make sure `php please schedule:run` runs every minute if you use a remote source.');

        return self::SUCCESS;
    }
}
