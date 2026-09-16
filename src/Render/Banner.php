<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Render;

use Bpmore\Beacon\Alert\Alert;

/**
 * Alerts to HTML.
 *
 * Server-rendered, so a landmark and not a live region: `<section
 * role="region">` with an accessible name from its heading. No `aria-live`,
 * no `role="alert"`. Those announce content that changes after load; on
 * content present at load they double-announce or interrupt.
 *
 * The heading defaults to `h2` and can never be `h1`. Severity is a
 * visible word, never only a colour. The more link's accessible name
 * includes the title, so a list of links reads "Read more about Inclement
 * Weather", not "Read more" three times. No dismiss control is rendered
 * here: the script adds one, so without JavaScript the banner shows and
 * cannot be hidden, which is the correct degradation.
 *
 * Nothing here knows which source an alert came from.
 */
final class Banner
{
    public function __construct(private readonly Options $options = new Options) {}

    /**
     * @param  list<Alert>  $alerts
     */
    public function render(array $alerts, bool $preview = false): string
    {
        if ($alerts === []) {
            return '';
        }

        $out = '<div class="beacon-stack" data-beacon>';

        foreach ($alerts as $i => $alert) {
            $out .= $this->options->compat ? $this->compat($alert, $i, $preview) : $this->one($alert, $i, $preview);
        }

        return $out.'</div>';
    }

    private function one(Alert $alert, int $index, bool $preview): string
    {
        $h = $this->options->headingLevel;
        $id = 'beacon-'.$this->slug($alert->id).'-'.$index;
        $severity = $alert->severity->value;
        $label = $this->options->labels[$severity] ?? ucfirst($severity);
        $title = $this->e($alert->title);

        $attrs = ' class="beacon beacon--'.$severity.($preview ? ' beacon--preview' : '').'"'
            .' role="region"'
            .' aria-labelledby="'.$id.'-title"'
            .' data-beacon-id="'.$this->e($alert->id).'"'
            .' data-beacon-severity="'.$severity.'"';

        if (! $preview) {
            $attrs .= ' data-beacon-key="'.$this->e($alert->dismissalKey()).'"';
            $attrs .= ' data-beacon-dismissible="'.($alert->dismissible ? '1' : '0').'"';
        }

        $out = '<section'.$attrs.'>';
        $out .= '<div class="beacon__inner">';

        if ($preview) {
            $out .= '<p class="beacon__preview">'.$this->e($this->options->previewText).'</p>';
        }

        $out .= '<p class="beacon__severity">'.$this->e($label).'</p>';
        $out .= '<'.$h.' class="beacon__title" id="'.$id.'-title">'.$title.'</'.$h.'>';

        $body = $alert->visibleBody();
        if ($body !== '') {
            $out .= '<div class="beacon__body">'.$body.'</div>';
        }

        if ($alert->hasMoreLink()) {
            $out .= '<p class="beacon__more"><a class="beacon__link" href="'.$this->e((string) $alert->url).'">'
                .$this->e($this->options->moreText)
                .'<span class="beacon__sr"> about '.$title.'</span></a></p>';
        }

        $out .= '</div></section>';

        return $out;
    }

    /**
     * The legacy client's markup structure and class names, for a
     * stylesheet written against them. A migration aid. The prefix is
     * configurable so it can match whatever the old banner was called; the
     * structure is the old client's; the accessibility fixes (region,
     * heading level, link name, no body side effects) stay.
     */
    private function compat(Alert $alert, int $index, bool $preview): string
    {
        $h = $this->options->headingLevel;
        $id = 'beacon-'.$this->slug($alert->id).'-'.$index;
        $prefix = $this->options->compatPrefix;
        $colour = $prefix.match ($alert->severity->value) {
            'emergency' => '-urgent',
            'warning' => '-alert',
            default => '-fyi',
        };
        $title = $this->e($alert->title);
        $label = $this->options->labels[$alert->severity->value] ?? ucfirst($alert->severity->value);
        $hasLink = $alert->hasMoreLink();

        $attrs = ($index === 0 ? ' id="'.$prefix.'-message"' : '')
            .' class="'.$prefix.'-module less-padding cta-bar cta-bar-sm '.$colour.($hasLink ? ' cta-bar-weighted' : ' cta-bar-centered no-link').' beacon beacon--'.$alert->severity->value.($preview ? ' beacon--preview' : '').'"'
            .' role="region"'
            .' aria-labelledby="'.$id.'-title"'
            .' data-beacon-id="'.$this->e($alert->id).'"'
            .' data-beacon-severity="'.$alert->severity->value.'"';

        if (! $preview) {
            $attrs .= ' data-beacon-key="'.$this->e($alert->dismissalKey()).'"';
            $attrs .= ' data-beacon-dismissible="'.($alert->dismissible ? '1' : '0').'"';
        }

        $out = '<section'.$attrs.'><div class="container-fluid"><div class="row"><div class="col-12"><div class="inner-container">';

        if ($preview) {
            $out .= '<p class="beacon__preview">'.$this->e($this->options->previewText).'</p>';
        }

        $out .= '<div class="cta-heading"><span class="beacon__sr">'.$this->e($label).': </span><'.$h.' class="beacon__title" id="'.$id.'-title">'.$title.'</'.$h.'></div>';
        $out .= '<div class="cta-body"><div class="text-container">'.$alert->visibleBody().'</div>';

        if ($hasLink) {
            $out .= '<div class="btn-container"><a class="btn btn-white beacon__link" href="'.$this->e((string) $alert->url).'">'
                .$this->e($this->options->moreText).'<span class="beacon__sr"> about '.$title.'</span></a></div>';
        }

        $out .= '</div></div></div></div></div></section>';

        return $out;
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function slug(string $id): string
    {
        return substr((string) preg_replace('/[^a-z0-9]+/i', '-', $id), 0, 40).'-'.substr(hash('crc32b', $id), 0, 6);
    }
}
