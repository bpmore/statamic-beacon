<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Source;

use RuntimeException;

/** The source answered, but not with anything a parser could read. The last good payload is kept. */
final class MalformedPayload extends RuntimeException {}
