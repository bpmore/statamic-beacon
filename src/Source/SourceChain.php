<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;
use Closure;
use Throwable;

/**
 * Sources in order, merged, each under its own ceiling.
 *
 * A source's `max_severity` caps what it can raise: a public weather feed
 * capped at `warning` cannot put an emergency banner up. A source's
 * `audiences` restricts who sees its alerts: an alert with no audiences
 * takes the source's, and one with its own keeps only the overlap. Alert
 * ids are namespaced by source so two sources cannot collide.
 *
 * A source that throws contributes nothing and is logged. One broken
 * source must not take the others down with it.
 */
final class SourceChain implements AlertSource
{
    /** @var list<array{0: AlertSource, 1: SourceDefinition}> */
    private array $sources = [];

    /** @param  (Closure(string, string): void)|null  $log */
    public function __construct(private readonly ?Closure $log = null) {}

    public function add(AlertSource $source, SourceDefinition $definition): self
    {
        $this->sources[] = [$source, $definition];

        return $this;
    }

    /** @return list<SourceDefinition> */
    public function definitions(): array
    {
        return array_map(fn (array $pair) => $pair[1], $this->sources);
    }

    public function fetch(): array
    {
        $all = [];

        foreach ($this->sources as [$source, $definition]) {
            try {
                $alerts = $source->fetch();
            } catch (Throwable $e) {
                if ($this->log !== null) {
                    ($this->log)('error', "Beacon: source `{$definition->key}` threw and was skipped: ".$e->getMessage());
                }

                continue;
            }

            foreach ($alerts as $alert) {
                $all[] = self::constrain($alert, $definition);
            }
        }

        return $all;
    }

    public static function constrain(Alert $alert, SourceDefinition $definition): Alert
    {
        $severity = $definition->maxSeverity === null ? $alert->severity : $alert->severity->cappedAt($definition->maxSeverity);

        $audiences = $alert->audiences;
        if ($definition->audiences !== []) {
            $audiences = $audiences === []
                ? $definition->audiences
                : array_values(array_intersect($audiences, $definition->audiences));

            // Overlap of nothing: the alert was for audiences this source
            // may not address. Rather than widen it to everyone, it goes
            // to an audience nobody is configured as.
            if ($audiences === []) {
                $audiences = ['__none__'];
            }
        }

        $namespaced = new Alert(
            $definition->key.':'.$alert->id,
            $alert->title,
            $alert->body,
            $alert->teaser,
            $severity,
            $audiences,
            $alert->startsAt,
            $alert->endsAt,
            $alert->url,
            $alert->dismissible,
        );

        return $namespaced;
    }
}
