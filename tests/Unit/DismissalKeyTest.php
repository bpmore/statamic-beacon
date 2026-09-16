<?php

declare(strict_types=1);

/** Brief test 13. A corrected alert must come back for people who dismissed the old one. */
it('changes the dismissal key when the message changes', function () {
    $before = alert(['body' => '<p>Campus closed.</p>']);
    $after = alert(['body' => '<p>Campus open.</p>']);
    $same = alert(['body' => '<p>Campus closed.</p>']);

    expect($before->dismissalKey())->toStartWith('beacon:a1:')
        ->and($before->dismissalKey())->not->toBe($after->dismissalKey())
        ->and($before->dismissalKey())->toBe($same->dismissalKey());
});

it('changes the key when the title, severity, or url changes', function () {
    $base = alert();

    expect(alert(['title' => 'x'])->dismissalKey())->not->toBe($base->dismissalKey())
        ->and(alert(['severity' => 'emergency'])->dismissalKey())->not->toBe($base->dismissalKey())
        ->and(alert(['url' => 'https://other'])->dismissalKey())->not->toBe($base->dismissalKey());
});
