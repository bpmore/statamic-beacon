<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Alert;

use DateTimeInterface;

/**
 * Which alerts a visitor sees: the ones live now, for this audience, most
 * severe first, then in the order the sources gave them (newest first).
 */
final class Selector
{
    /**
     * @param  list<Alert>  $alerts
     * @return list<Alert>
     */
    public static function active(array $alerts, DateTimeInterface $now, string $audience): array
    {
        $live = array_values(array_filter(
            $alerts,
            fn (Alert $a) => $a->isActiveAt($now) && Audience::matches($a->audiences, $audience),
        ));

        // A stable sort: PHP's usort is stable since 8.0, so equal severities
        // keep source order.
        usort($live, fn (Alert $a, Alert $b) => $b->severity->rank() <=> $a->severity->rank());

        return $live;
    }

    /**
     * The alerts with a start or end between two instants: the ones whose
     * visibility changed in that window, so the page cache is stale.
     *
     * @param  list<Alert>  $alerts
     * @return list<Alert>
     */
    public static function crossingBoundary(array $alerts, DateTimeInterface $since, DateTimeInterface $now): array
    {
        return array_values(array_filter($alerts, function (Alert $a) use ($since, $now) {
            foreach ([$a->startsAt, $a->endsAt] as $at) {
                if ($at !== null && $at > $since && $at <= $now) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * A digest of a whole set, independent of order. Two polls that produce
     * the same alerts produce the same digest, and only a different digest
     * is worth clearing a page cache for.
     *
     * @param  list<Alert>  $alerts
     */
    public static function fingerprint(array $alerts): string
    {
        $prints = array_map(fn (Alert $a) => $a->fingerprint(), $alerts);
        sort($prints);

        return hash('sha256', implode("\n", $prints));
    }
}
