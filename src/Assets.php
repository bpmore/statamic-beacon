<?php

declare(strict_types=1);

namespace Bpmore\Beacon;

use Bpmore\Beacon\Render\Options;

/**
 * The stylesheet and the dismiss script, inline or linked.
 *
 * Inline by default: no second request, nothing to publish, and correct
 * behind a static cache. Never from a remote host; the reference banner's
 * layout depended on a cross-origin stylesheet request succeeding.
 */
final class Assets
{
    public function __construct(
        private readonly string $mode,
        private readonly string $distPath,
        private readonly string $publicUrl,
    ) {}

    public function markup(Options $options): string
    {
        return match ($this->mode) {
            'none' => '',
            'linked' => '<link rel="stylesheet" href="'.$this->e($this->publicUrl.'/beacon.css').'">'
                .'<script src="'.$this->e($this->publicUrl.'/beacon.js').'" data-beacon-dismiss="'.$this->e($options->dismissText).'" defer></script>',
            default => '<style>'.$this->file('beacon.css').'</style>'
                .'<script data-beacon-dismiss="'.$this->e($options->dismissText).'">'.$this->file('beacon.js').'</script>',
        };
    }

    public function css(): string
    {
        return $this->file('beacon.css');
    }

    public function js(): string
    {
        return $this->file('beacon.js');
    }

    private function file(string $name): string
    {
        $path = rtrim($this->distPath, '/').'/'.$name;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
