<?php

declare(strict_types=1);

namespace Bpmore\Beacon\Fetch;

use RuntimeException;

/** No response at all: timeout, DNS, refused connection. */
final class TransportFailed extends RuntimeException {}
