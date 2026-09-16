<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Render;

use InvalidArgumentException;

/**
 * How the banner is drawn. Read from config once.
 */
final class Options
{
    /**
     * @param  array<string, string>  $labels  severity => visible word
     */
    public function __construct(
        public readonly string $headingLevel = 'h2',
        public readonly bool $compat = false,
        public readonly string $compatPrefix = 'legacy-alert',
        public readonly array $labels = ['info' => 'Information', 'warning' => 'Warning', 'emergency' => 'Emergency'],
        public readonly string $moreText = 'Read more',
        public readonly string $dismissText = 'Dismiss',
        public readonly string $previewText = 'Preview. This banner is visible only to you.',
    ) {
        if (preg_match('/^h[2-6]$/', $headingLevel) !== 1) {
            throw new InvalidArgumentException("heading_level must be h2 to h6, not `{$headingLevel}`. An h1 belongs to the page.");
        }
    }

    /** @param  array<string, mixed>  $config */
    public static function fromArray(array $config): self
    {
        $labels = ['info' => 'Information', 'warning' => 'Warning', 'emergency' => 'Emergency'];
        foreach ((array) ($config['labels'] ?? []) as $k => $v) {
            if (isset($labels[$k]) && is_string($v) && $v !== '') {
                $labels[$k] = $v;
            }
        }

        return new self(
            strtolower((string) ($config['heading_level'] ?? 'h2')),
            (bool) ($config['compat'] ?? false),
            preg_replace('/[^a-z0-9_-]/i', '', (string) ($config['compat_prefix'] ?? 'legacy-alert')) ?: 'legacy-alert',
            $labels,
            (string) ($config['more_text'] ?? 'Read more'),
            (string) ($config['dismiss_text'] ?? 'Dismiss'),
            (string) ($config['preview_text'] ?? 'Preview. This banner is visible only to you.'),
        );
    }
}
