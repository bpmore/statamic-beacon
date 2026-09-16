<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Tests;

use Bpmore\Beacon\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * A booted Statamic with the addon in it. Only the feature tests use this;
 * the core is tested with nothing booted.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use \Statamic\Testing\Concerns\FakesViews;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Testbench ships no app key, and the control panel encrypts sessions.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Permissions and a second user are Pro features, and the preview
        // tests need a user with a permission and one without.
        $app['config']->set('statamic.editions.pro', true);

        // Snapshots and scheduler state in a temporary folder, never the
        // harness's own storage.
        $app['config']->set('statamic-beacon.storage', sys_get_temp_dir().'/beacon-tests-'.getmypid());

        // Blueprints the install command writes go to a temporary folder,
        // not testbench's own resources.
        $app['config']->set('statamic.system.blueprints_path', sys_get_temp_dir().'/beacon-blueprints-'.getmypid());
    }

    protected function setUp(): void
    {
        parent::setUp();

        \Statamic\Facades\Blueprint::setDirectory(sys_get_temp_dir().'/beacon-blueprints-'.getmypid());
    }

    protected function tearDown(): void
    {
        foreach ([sys_get_temp_dir().'/beacon-tests-'.getmypid(), sys_get_temp_dir().'/beacon-blueprints-'.getmypid()] as $dir) {
            if (is_dir($dir)) {
                \Illuminate\Support\Facades\File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }
}
