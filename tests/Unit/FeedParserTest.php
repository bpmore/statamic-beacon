<?php

declare(strict_types=1);

use Bpmore\Beacon\Source\FeedParser;
use Bpmore\Beacon\Source\ParserFactory;

function feedParser(): FeedParser
{
    return new FeedParser(factory(), ParserFactory::LABEL_PATTERN, ParserFactory::WORDPRESS_SEVERITY_MAP);
}

it('reads RSS 2.0 with categories carrying severity and audience', function () {
    $rss = <<<XML
<?xml version="1.0"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel><title>Alerts</title>
<item>
  <title>Boil water &#8217;til noon</title>
  <link>https://example.org/boil</link>
  <guid>https://example.org/?p=1</guid>
  <pubDate>Sun, 01 Mar 2026 12:00:00 GMT</pubDate>
  <category>urgent-clinic</category>
  <category>fyi-campus</category>
  <category>General</category>
  <description>&lt;p&gt;Short.&lt;/p&gt;</description>
  <content:encoded><![CDATA[<p>Short.</p><!--noteaser--><p>Long <script>x</script>.</p>]]></content:encoded>
</item>
<item>
  <title>Unlabeled</title>
  <description>x</description>
</item>
</channel></rss>
XML;

    $alerts = feedParser()->parse($rss);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->title)->toBe("Boil water \u{2019}til noon")
        ->and(severityOf($alerts[0]))->toBe('emergency')
        ->and($alerts[0]->audiences)->toBe(['clinic', 'campus'])
        ->and($alerts[0]->teaser)->toBe('<p>Short.</p>')
        ->and($alerts[0]->body)->toBe('<p>Short.</p><p>Long .</p>')
        ->and($alerts[0]->url)->toBe('https://example.org/boil')
        ->and($alerts[0]->startsAt?->format(DATE_ATOM))->toBe('2026-03-01T12:00:00+00:00');
});

it('reads Atom with category terms', function () {
    $atom = <<<XML
<?xml version="1.0"?>
<feed xmlns="http://www.w3.org/2005/Atom">
<entry>
  <id>tag:example.org,2026:1</id>
  <title>Snow</title>
  <link rel="self" href="https://example.org/self"/>
  <link rel="alternate" href="https://example.org/snow"/>
  <published>2026-03-01T12:00:00Z</published>
  <category term="alert-northwest"/>
  <content type="html">&lt;p&gt;Closed &lt;b onclick="x"&gt;today&lt;/b&gt;.&lt;/p&gt;</content>
</entry>
</feed>
XML;

    $alerts = feedParser()->parse($atom);

    expect($alerts)->toHaveCount(1)
        ->and(severityOf($alerts[0]))->toBe('warning')
        ->and($alerts[0]->audiences)->toBe(['northwest'])
        ->and($alerts[0]->url)->toBe('https://example.org/snow')
        ->and($alerts[0]->body)->toBe('<p>Closed <b>today</b>.</p>');
});

it('treats a document that is not a feed as malformed', function () {
    feedParser()->parse('<html><body>nope</body></html>');
})->throws(\Bpmore\Beacon\Source\MalformedPayload::class);
