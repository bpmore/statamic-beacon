<?php

declare(strict_types=1);

use Bpmore\Beacon\Source\ArraySource;
use Bpmore\Beacon\Source\SourceChain;
use Bpmore\Beacon\Source\SourceDefinition;

it('caps a low-trust source at its severity ceiling', function () {
    $chain = (new SourceChain)
        ->add(new ArraySource([alert(['id' => 'own', 'severity' => 'emergency'])]), SourceDefinition::fromArray(['driver' => 'http', 'url' => 'https://a', 'key' => 'own']))
        ->add(new ArraySource([alert(['id' => 'wx', 'severity' => 'emergency'])]), SourceDefinition::fromArray(['driver' => 'cap', 'url' => 'https://b', 'key' => 'wx', 'max_severity' => 'warning']));

    $alerts = $chain->fetch();

    expect(array_map(fn ($a) => [$a->id, severityOf($a)], $alerts))->toBe([['own:own', 'emergency'], ['wx:wx', 'warning']]);
});

it('restricts a source to its audiences', function () {
    $def = SourceDefinition::fromArray(['driver' => 'cap', 'url' => 'https://b', 'key' => 'wx', 'audiences' => ['campus', 'clinic']]);
    $chain = (new SourceChain)->add(new ArraySource([
        alert(['id' => 'all']),
        alert(['id' => 'some', 'audiences' => ['clinic', 'inside']]),
        alert(['id' => 'none', 'audiences' => ['inside']]),
    ]), $def);

    $by = [];
    foreach ($chain->fetch() as $a) {
        $by[$a->id] = $a->audiences;
    }

    expect($by['wx:all'])->toBe(['campus', 'clinic'])
        ->and($by['wx:some'])->toBe(['clinic'])
        ->and($by['wx:none'])->toBe(['__none__']);
});

it('keeps going when one source throws, and logs it', function () {
    $broken = new class implements \Bpmore\Beacon\Source\AlertSource
    {
        public function fetch(): array
        {
            throw new RuntimeException('disk on fire');
        }
    };

    $logged = [];
    $chain = (new SourceChain(function ($level, $msg) use (&$logged) {
        $logged[] = $msg;
    }))
        ->add($broken, SourceDefinition::fromArray(['driver' => 'null', 'key' => 'broken']))
        ->add(new ArraySource([alert()]), SourceDefinition::fromArray(['driver' => 'null', 'key' => 'fine']));

    expect($chain->fetch())->toHaveCount(1)
        ->and($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('broken');
});

it('rejects an unknown driver and a remote source with no url', function () {
    expect(fn () => SourceDefinition::fromArray(['driver' => 'carrier-pigeon']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SourceDefinition::fromArray(['driver' => 'http']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SourceDefinition::fromArray(['driver' => 'http', 'url' => 'https://x', 'max_severity' => 'critical']))->toThrow(InvalidArgumentException::class);
});
