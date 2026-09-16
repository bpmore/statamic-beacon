<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use Bpmore\Beacon\Alert\Alert;
use Bpmore\Beacon\Alert\Severity;
use Bpmore\Beacon\Mapping\Dates;
use Bpmore\Beacon\Sanitize\PlainText;
use DOMElement;
use JsonException;

/**
 * OASIS Common Alerting Protocol 1.2, as XML or as the JSON-LD the US
 * National Weather Service serves, to alerts.
 *
 * Only `status` of `Actual` renders. `Test`, `Exercise`, `System` and
 * `Draft` are dropped before anything else is read: a live-looking
 * emergency banner made from a federal test message is the worst thing
 * this addon could produce.
 *
 * Severity comes from CAP `severity` and `urgency` together, through a
 * matrix from config. Bodies are plain text, escaped and paragraphed, never
 * run through the HTML sanitizer as if they were markup. `expires` is
 * `ends_at` and is honored at render time whether or not the next poll
 * succeeds. `references` names earlier alerts an update replaces; those
 * are removed so an update replaces rather than stacks.
 */
final class CapParser implements Parser
{
    public const STATUS_ACTUAL = 'Actual';

    /**
     * Default matrix: a list of rules, first match wins. Each rule names
     * the severities and urgencies it covers (empty means any).
     *
     * @var list<array{severity?: list<string>, urgency?: list<string>, to: string}>
     */
    public const DEFAULT_MATRIX = [
        ['severity' => ['Extreme'], 'to' => 'emergency'],
        ['severity' => ['Severe'], 'urgency' => ['Immediate'], 'to' => 'emergency'],
        ['severity' => ['Severe', 'Moderate'], 'to' => 'warning'],
        ['to' => 'info'],
    ];

    /**
     * Audience targeting for CAP is geographic. Two ways, in order of
     * preference: `geocodes` maps a CAP geocode value (a SAME county code
     * such as `005119`, or a UGC zone such as `ARZ044`) to the audiences
     * it concerns, and an alert is aimed at the union of the audiences of
     * every code it carries; an alert carrying none of the configured
     * codes is dropped. Without `geocodes`, every alert goes to
     * `audiences`, the source-wide list.
     *
     * @param  list<array{severity?: list<string>, urgency?: list<string>, to: string}>  $matrix
     * @param  list<string>  $audiences  assigned to every alert when no geocode map is given
     * @param  array<string, list<string>>  $geocodes  geocode value => audiences
     */
    public function __construct(
        private readonly RemoteAlertFactory $factory,
        private readonly array $matrix = self::DEFAULT_MATRIX,
        private readonly array $audiences = [],
        private readonly bool $appendInstruction = true,
        private readonly array $geocodes = [],
    ) {}

    public function parse(string $body, string $contentType = ''): array
    {
        $trimmed = ltrim($body, "\xEF\xBB\xBF \t\r\n");

        $alerts = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')
            ? $this->json($body)
            : $this->xml($body);

        return $this->supersede($alerts);
    }

    /** @return list<array{alert: Alert, references: list<string>}> */
    private function xml(string $body): array
    {
        $doc = Xml::load($body);
        $root = $doc->documentElement;

        if ($root === null) {
            throw new MalformedPayload('No root element.');
        }

        // A single <alert>, or a feed (Atom or RSS) with <alert> elements
        // embedded, or with CAP fields inline in each entry.
        $alertElements = $root->localName === 'alert' ? [$root] : Xml::descendants($root, 'alert');

        $out = [];

        if ($alertElements !== []) {
            foreach ($alertElements as $el) {
                $one = $this->xmlAlert($el);
                if ($one !== null) {
                    $out[] = $one;
                }
            }

            return $out;
        }

        // NWS-style Atom: CAP fields as children of each entry.
        foreach (Xml::descendants($root, 'entry') as $entry) {
            $one = $this->xmlAlert($entry, inline: true);
            if ($one !== null) {
                $out[] = $one;
            }
        }

        return $out;
    }

    /** @return array{alert: Alert, references: list<string>}|null */
    private function xmlAlert(DOMElement $el, bool $inline = false): ?array
    {
        $status = Xml::text($el, 'status');

        if ($status !== self::STATUS_ACTUAL) {
            return null;
        }

        $info = $inline ? $el : (Xml::child($el, 'info') ?? $el);

        $fields = [
            'identifier' => Xml::text($el, 'identifier') ?? Xml::text($el, 'id'),
            'severity' => Xml::text($info, 'severity'),
            'urgency' => Xml::text($info, 'urgency'),
            'headline' => Xml::text($info, 'headline') ?? Xml::text($el, 'title') ?? Xml::text($info, 'event'),
            'description' => Xml::text($info, 'description') ?? Xml::text($el, 'summary') ?? '',
            'instruction' => Xml::text($info, 'instruction'),
            'effective' => Xml::text($info, 'effective') ?? Xml::text($info, 'onset') ?? Xml::text($el, 'sent'),
            'expires' => Xml::text($info, 'expires'),
            'web' => Xml::text($info, 'web'),
            'references' => Xml::text($el, 'references'),
            'geocodes' => $this->xmlGeocodes($info),
        ];

        if ($fields['web'] === null && $inline) {
            foreach (Xml::children($el, 'link') as $link) {
                if ($link->getAttribute('href') !== '') {
                    $fields['web'] = $link->getAttribute('href');
                    break;
                }
            }
        }

        return $this->build($fields);
    }

    /** @return list<array{alert: Alert, references: list<string>}> */
    private function json(string $body): array
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedPayload('The response is not JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($data)) {
            throw new MalformedPayload('The response is JSON but not an object.');
        }

        // GeoJSON FeatureCollection (NWS), a list of alerts, or one alert.
        $items = $data['features'] ?? (array_is_list($data) ? $data : [$data]);

        if (! is_array($items)) {
            throw new MalformedPayload('No alerts found in the JSON.');
        }

        $out = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $p = is_array($item['properties'] ?? null) ? $item['properties'] : $item;

            if (($p['status'] ?? null) !== self::STATUS_ACTUAL) {
                continue;
            }

            $references = [];
            foreach ((array) ($p['references'] ?? []) as $ref) {
                if (is_array($ref) && isset($ref['identifier'])) {
                    $references[] = (string) $ref['identifier'];
                } elseif (is_string($ref)) {
                    $references[] = $ref;
                }
            }

            $geocodes = [];
            foreach ((array) ($p['geocode'] ?? []) as $values) {
                foreach ((array) $values as $value) {
                    if (is_scalar($value)) {
                        $geocodes[] = (string) $value;
                    }
                }
            }

            $one = $this->build([
                'geocodes' => $geocodes,
                'identifier' => isset($p['identifier']) ? (string) $p['identifier'] : (isset($p['id']) ? (string) $p['id'] : null),
                'severity' => isset($p['severity']) ? (string) $p['severity'] : null,
                'urgency' => isset($p['urgency']) ? (string) $p['urgency'] : null,
                'headline' => isset($p['headline']) ? (string) $p['headline'] : (isset($p['event']) ? (string) $p['event'] : null),
                'description' => isset($p['description']) ? (string) $p['description'] : '',
                'instruction' => isset($p['instruction']) ? (string) $p['instruction'] : null,
                'effective' => isset($p['effective']) ? (string) $p['effective'] : (isset($p['onset']) ? (string) $p['onset'] : null),
                'expires' => isset($p['expires']) ? (string) $p['expires'] : null,
                'web' => isset($p['web']) ? (string) $p['web'] : null,
                'references' => $references,
            ]);

            if ($one !== null) {
                $out[] = $one;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{alert: Alert, references: list<string>}|null
     */
    private function build(array $f): ?array
    {
        if (! is_string($f['headline'] ?? null) || $f['headline'] === '') {
            return null;
        }

        $body = PlainText::toHtml((string) $f['description']);

        if ($this->appendInstruction && is_string($f['instruction'] ?? null) && trim($f['instruction']) !== '') {
            $body .= PlainText::toHtml($f['instruction']);
        }

        $id = is_string($f['identifier'] ?? null) && $f['identifier'] !== '' ? $f['identifier'] : hash('sha256', $f['headline']);

        $audiences = $this->audiencesFor((array) ($f['geocodes'] ?? []));

        if ($audiences === null) {
            return null;
        }

        $alert = $this->factory->fromSafeHtml(
            $id,
            $f['headline'],
            $body,
            $this->severity($f['severity'] ?? null, $f['urgency'] ?? null),
            $audiences,
            Dates::parse($f['effective'] ?? null),
            Dates::parse($f['expires'] ?? null),
            is_string($f['web'] ?? null) ? $f['web'] : null,
        );

        $references = $f['references'] ?? [];
        if (is_string($references)) {
            // XML form: "sender,identifier,sent" triples separated by spaces.
            $references = array_values(array_filter(array_map(
                fn (string $triple) => explode(',', $triple)[1] ?? null,
                preg_split('/\s+/', trim($references)) ?: [],
            )));
        }

        return ['alert' => $alert, 'references' => array_values(array_map('strval', (array) $references))];
    }

    /**
     * The audiences an alert is for, from its geocodes, or null when the
     * source filters by geocode and this alert matches none.
     *
     * @param  list<string>  $codes
     * @return list<string>|null
     */
    private function audiencesFor(array $codes): ?array
    {
        if ($this->geocodes === []) {
            return $this->audiences;
        }

        $out = [];

        foreach ($codes as $code) {
            $code = strtoupper(trim($code));

            foreach ($this->geocodes as $configured => $audiences) {
                if (strtoupper(trim((string) $configured)) !== $code) {
                    continue;
                }

                foreach ((array) $audiences as $a) {
                    if (is_scalar($a) && trim((string) $a) !== '') {
                        $out[] = trim((string) $a);
                    }
                }
            }
        }

        $out = array_values(array_unique($out));

        return $out === [] ? null : $out;
    }

    /** Every geocode value under an <info>, whatever its valueName. @return list<string> */
    private function xmlGeocodes(DOMElement $info): array
    {
        $out = [];

        foreach (Xml::children($info, 'geocode') as $geocode) {
            $value = Xml::text($geocode, 'value');
            if ($value !== null) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function severity(?string $capSeverity, ?string $capUrgency): Severity
    {
        $sev = ucfirst(strtolower(trim((string) $capSeverity)));
        $urg = ucfirst(strtolower(trim((string) $capUrgency)));

        foreach ($this->matrix as $rule) {
            $severities = array_map(fn ($s) => ucfirst(strtolower((string) $s)), (array) ($rule['severity'] ?? []));
            $urgencies = array_map(fn ($u) => ucfirst(strtolower((string) $u)), (array) ($rule['urgency'] ?? []));

            if ($severities !== [] && ! in_array($sev, $severities, true)) {
                continue;
            }

            if ($urgencies !== [] && ! in_array($urg, $urgencies, true)) {
                continue;
            }

            return Severity::tryFrom(strtolower((string) ($rule['to'] ?? 'info'))) ?? Severity::Info;
        }

        return Severity::Info;
    }

    /**
     * Drop every alert an update references, so a corrected warning
     * replaces the one it corrects rather than sitting beside it.
     *
     * @param  list<array{alert: Alert, references: list<string>}>  $items
     * @return list<Alert>
     */
    private function supersede(array $items): array
    {
        $superseded = [];

        foreach ($items as $item) {
            foreach ($item['references'] as $ref) {
                $superseded[$ref] = true;
            }
        }

        $out = [];

        foreach ($items as $item) {
            if (! isset($superseded[$item['alert']->id])) {
                $out[] = $item['alert'];
            }
        }

        return $out;
    }
}
