<?php

declare(strict_types=1);

namespace ContextGate\Source\Fetch;

use RuntimeException;

/** The transport gave up before a complete response arrived (FR-009). */
final class TransportTimeout extends RuntimeException
{
}
