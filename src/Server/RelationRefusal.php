<?php

declare(strict_types=1);

namespace ContextGate\Server;

use ContextGate\Contract\ContextContract;
use ContextGate\Contract\Field;

final class RelationRefusal
{
    /**
     * A related row carries no text field and no relation of its own, and only the session scope for a
     * pseudonym. A day or week pseudonym refuses as unknown, the reason the scope test pins.
     *
     * @param array<string, Field> $fields the parent contract's fields
     */
    public static function of(array $fields): ?ReasonCode
    {
        foreach ($fields as $field) {
            if ($field->form !== Field::RELATION) {
                continue;
            }

            /** @var class-string<ContextContract> $relatedClass */
            $relatedClass = $field->options['contract'];

            foreach (app($relatedClass)->fields() as $relatedField) {
                if (in_array($relatedField->form, [Field::TEXT, Field::RELATION], true)) {
                    return ReasonCode::PolicyDenied;
                }

                if ($relatedField->form === Field::PSEUDONYM && ($relatedField->options['scope'] ?? 'session') !== 'session') {
                    return ReasonCode::PseudonymUnknown;
                }
            }
        }

        return null;
    }
}
