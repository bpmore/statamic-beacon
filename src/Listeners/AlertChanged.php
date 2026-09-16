<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Listeners;

use Bpmore\Beacon\Beacon;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;

/**
 * Any change to the alerts collection clears the page cache: create,
 * edit, publish, unpublish, delete all arrive as one of these two events.
 * Statamic's own invalidation would clear the entry's URL, and an alert
 * has none; it is on every page.
 */
final class AlertChanged
{
    public function handle(EntrySaved|EntryDeleted $event): void
    {
        $handle = (string) (app(\Bpmore\Beacon\Settings::class)->effective()['collection'] ?? 'alerts');

        if ($event->entry->collectionHandle() !== $handle) {
            return;
        }

        app(Beacon::class)->flush();
    }
}
