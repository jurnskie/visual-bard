<?php

namespace Jurnskie\VisualBard\Actions;

use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Field;

class ResolveBardField
{
    /**
     * A fresh copy of the entry's Bard field holding its stored value, or a 404
     * when the handle is unknown, not a Bard field, or a Bard field without sets.
     */
    public function handle(Entry $entry, string $handle): Field
    {
        $field = $entry->blueprint()->field($handle);

        abort_unless($field && self::supports($field), 404);

        return $field->newInstance()->setValue($entry->value($handle));
    }

    /** Only Bard fields with sets have widgets to build with. */
    public static function supports(Field $field): bool
    {
        return $field->type() === 'bard' && filled($field->get('sets'));
    }
}
