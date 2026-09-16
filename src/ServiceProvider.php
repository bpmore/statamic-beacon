<?php

declare(strict_types=1);

namespace Bpmore\Beacon;

use Bpmore\Beacon\Alert\Clock;
use Bpmore\Beacon\Alert\SystemClock;
use Bpmore\Beacon\Fetch\Poller;
use Bpmore\Beacon\Fetch\Store;
use Bpmore\Beacon\Fetch\Transport;
use Bpmore\Beacon\Sanitize\HtmlSanitizer;
use Bpmore\Beacon\Sanitize\SymfonySanitizer;
use Bpmore\Beacon\Source\ParserFactory;
use Bpmore\Beacon\Statamic\FileStore;
use Bpmore\Beacon\Statamic\LaravelTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Permission;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

/**
 * The addon: a tag, four commands, a listener, a middleware, a permission,
 * a utility page, and a scheduled tick.
 *
 * The core (Alert, Sanitize, Mapping, Source, Fetch, Render) knows nothing
 * of Statamic. This provider builds it from config and hands it a
 * transport, a store and a clock.
 */
class ServiceProvider extends AddonServiceProvider
{
    public const PACKAGE = 'bpmore/statamic-beacon';

    protected $config = true;

    protected $viewNamespace = 'beacon';

    protected $tags = [
        Tags\Beacon::class,
    ];

    protected $commands = [
        Commands\Install::class,
        Commands\Tick::class,
        Commands\Fetch::class,
        Commands\Status::class,
    ];

    protected $listen = [
        EntrySaved::class => [Listeners\AlertChanged::class],
        EntryDeleted::class => [Listeners\AlertChanged::class],
        AddonSettingsSaved::class => [Listeners\SettingsChanged::class],
    ];

    protected $middlewareGroups = [
        'web' => [\Bpmore\Beacon\Http\Middleware\PreviewIsUncacheable::class],
    ];

    protected $publishables = [
        __DIR__.'/../resources/dist' => 'dist',
    ];

    public function register()
    {
        parent::register();

        $this->app->singleton(Clock::class, fn () => new SystemClock);

        $this->app->singleton(HtmlSanitizer::class, fn () => new SymfonySanitizer(
            fn (int $count) => Log::warning("Beacon: {$count} link(s) with an unsafe scheme were replaced with # in a fetched alert."),
        ));

        $this->app->bind(Transport::class, fn ($app) => new LaravelTransport($app->make(Http::class)));

        $this->app->singleton(Store::class, fn () => new FileStore(
            (string) (config('statamic-beacon.storage') ?: storage_path('beacon')),
        ));

        $this->app->bind(Poller::class, fn ($app) => new Poller(
            $app->make(Transport::class),
            $app->make(Store::class),
            $app->make(Clock::class),
            fn (string $level, string $message) => Log::log($level, $message),
        ));

        $this->app->bind(ParserFactory::class, fn ($app) => new ParserFactory($app->make(HtmlSanitizer::class)));

        $this->app->bind(Settings::class, fn () => new Settings);

        $this->app->bind(Beacon::class, fn ($app) => new Beacon(
            $app->make(Settings::class)->effective(),
            $app->make(Store::class),
            $app->make(Poller::class),
            $app->make(ParserFactory::class),
            $app->make(Clock::class),
        ));

        $this->app->bind(Assets::class, fn ($app) => new Assets(
            (string) ($app->make(Settings::class)->effective()['assets'] ?? 'inline'),
            __DIR__.'/../resources/dist',
            '/vendor/statamic-beacon/dist',
        ));
    }

    public function bootAddon()
    {
        // `@plain($x)` for any value that reaches the utility view, which
        // Vue compiles. See `VueSafe` for why Blade's escaping is not enough.
        Blade::directive('plain', fn ($expression) => "<?php echo \\Bpmore\\Beacon\\Support\\VueSafe::text({$expression}); ?>");

        Permission::extend(function () {
            Permission::group('beacon', 'Beacon', function () {
                Permission::register(Beacon::PERMISSION_PREVIEW)
                    ->label('View Beacon previews')
                    ->description('See a preview banner on the live site with ?beacon-preview=severity. Nobody else can.');
            });
        });

        Utility::extend(fn () => Utility::register(
            Utility::make('beacon')
                ->title('Beacon')
                ->navTitle('Beacon')
                ->icon('megaphone')
                ->description('Alert sources, the scheduler, and how fresh the banner can be.')
                ->view('beacon::utilities.beacon', fn () => $this->utilityData())
        ));
    }

    /**
     * Statamic calls this in console only, and calls it before the addon's
     * config has merged, so the registration waits for boot.
     */
    protected function schedule(Schedule $schedule)
    {
        $register = fn () => $schedule->command('beacon:tick')->everyMinute()->withoutOverlapping(5);

        // `booted()` on an application that has already booted runs the
        // callback now and keeps it for any later boot, which under the
        // test harness registers the command twice.
        $this->app->isBooted() ? $register() : $this->app->booted($register);
    }

    /** @return array<string, mixed> */
    private function utilityData(): array
    {
        $settings = $this->app->make(Settings::class);
        $effective = $settings->effective();
        $status = $this->app->make(Beacon::class)->status();

        return array_merge($status, [
            'previewParameter' => (string) ($effective['preview']['parameter'] ?? 'beacon-preview'),
            'collection' => (string) ($effective['collection'] ?? 'alerts'),
            'hasRemote' => $status['freshness_budget_seconds'] !== null,
            'settingsSaved' => $settings->saved(),
            'settingsUrl' => \Statamic\Facades\Addon::get(Settings::PACKAGE)?->settingsUrl(),
        ]);
    }
}
