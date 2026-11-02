<?php

declare(strict_types=1);

namespace ContextGate\Settings;

use LogicException;

/**
 * A value was read from settings that did not validate. The read fails; the caller never gets a default.
 *
 * @internal May change without notice.
 */
final class InvalidSettings extends LogicException
{
}
