{{--
    Beacon under Tools: every source, the scheduler, the freshness budget.

    Compiled as a Vue template, not printed as HTML, so any value that could
    hold a brace goes through @plain. Dates and counts are safe as they are.
--}}

<ui-header title="Beacon" icon="megaphone" />

<div class="space-y-6">

    @if ($hasRemote && $scheduler['late'])
        <ui-card-panel heading="{{ $scheduler['last_tick_at'] === null ? 'The scheduler has never run' : 'The scheduler has stopped' }}">
            <div class="space-y-2">
                <p>Remote alert sources are fetched by <code>php please schedule:run</code>, once a minute, from cron. @if ($scheduler['last_tick_at'] === null) It has never run on this site. @else It last ran at <strong>@plain($scheduler['last_tick_at'])</strong>, longer ago than the {{ $scheduler['warn_after'] }} seconds this page warns at. @endif</p>
                <p>Until it runs, a remote alert cannot reach this site. The cron line is in the README's first section.</p>
            </div>
        </ui-card-panel>
    @endif

    <ui-card-panel heading="Settings">
        <div class="space-y-2">
            @if ($settingsSaved)
                <p>Sources, audiences and the banner's wording are set on the <a href="@plain($settingsUrl)">settings screen</a>. Anything left blank there comes from the developer's config file.</p>
            @else
                <p>Everything below comes from the developer's config file. To add a WordPress.com alert site or another source yourself, or to change audiences and wording, open the <a href="@plain($settingsUrl)">settings screen</a>. Once saved, it takes over from the file for whatever you fill in.</p>
            @endif
        </div>
    </ui-card-panel>

    <ui-card-panel heading="This site">
        <dl class="grid grid-cols-2 gap-2">
            <dt>Audience</dt><dd><code>@plain($audience)</code></dd>
            <dt>Active alerts for this audience now</dt><dd>{{ $active }}</dd>
            @if ($hasRemote)
                <dt>Freshness budget</dt><dd>Up to {{ $freshness_budget_seconds }} seconds from a change at a remote source to a visitor seeing it.</dd>
                <dt>Scheduler last ran</dt><dd>@plain($scheduler['last_tick_at'] ?? 'never')</dd>
                <dt>Page cache last cleared by Beacon</dt><dd>@plain($scheduler['last_flush_at'] ?? 'never')</dd>
            @endif
        </dl>
    </ui-card-panel>

    <ui-card-panel heading="Sources">
        @if (count($sources) === 0)
            <p>No sources are configured. Add at least one under <code>sources</code> in <code>config/statamic-beacon.php</code>.</p>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Source</th>
                        <th scope="col">Driver</th>
                        <th scope="col">State</th>
                        <th scope="col">Alerts</th>
                        <th scope="col">Last fetched</th>
                        <th scope="col">Last error</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($sources as $source)
                    <tr>
                        <th scope="row"><code>@plain($source['key'])</code></th>
                        <td>@plain($source['driver'])</td>
                        <td>
                            @if (! $source['remote'])
                                Local
                            @elseif ($source['ok'])
                                OK
                            @elseif ($source['never_fetched'])
                                <strong>Never fetched</strong>
                            @else
                                <strong>Stale</strong>: nothing from this source is shown until a fetch succeeds
                            @endif
                            @if ($source['remote'] && $source['backoff_until'])
                                <br><small>Backing off until @plain($source['backoff_until'])</small>
                            @endif
                            @if ($source['remote'] && $source['rate_limit_remaining'] !== null)
                                <br><small>GitHub rate limit: {{ $source['rate_limit_remaining'] }} left@if ($source['rate_limit_reset']), resets @plain($source['rate_limit_reset'])@endif</small>
                            @endif
                        </td>
                        <td>{{ $source['remote'] ? $source['alerts'] : '' }}</td>
                        <td>@plain($source['remote'] ? ($source['fetched_at'] ?? 'never') : '')</td>
                        <td>@if ($source['remote'] && $source['last_error']) @plain($source['last_error'])<br><small>@plain($source['last_error_at'])</small> @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </ui-card-panel>

    <ui-card-panel heading="Previewing a banner">
        <div class="space-y-2">
            <p>Open any page of the site with <code>?@plain($previewParameter)=emergency</code>, <code>=warning</code> or <code>=info</code> while signed in. The newest alert, published or not, shows at that severity with a preview marker. Only users with the "View Beacon previews" permission see it; everyone else sees the page as it is. A preview is never cached.</p>
            <p>Local alerts are entries in the <code>@plain($collection)</code> collection.</p>
        </div>
    </ui-card-panel>

</div>
