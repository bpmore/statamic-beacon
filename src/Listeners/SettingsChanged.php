<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Listeners;

use Bpmore\Beacon\Beacon;
use Bpmore\Beacon\Settings;
use Statamic\Events\AddonSettingsSaved;

/**
 * Saving the settings screen can change what every page shows (a source
 * added, an audience renamed, a heading level), so the page cache goes.
 * Other addons' settings are not ours to react to.
 */
final class SettingsChanged
{
    public function handle(AddonSettingsSaved $event): void
    {
        if ($event->settings->addon()->package() !== Settings::PACKAGE) {
            return;
        }

        app(Beacon::class)->flush();
    }
}
