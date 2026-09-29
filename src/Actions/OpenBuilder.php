<?php

namespace Jurnskie\VisualBard\Actions;

use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Field;

/** "Open builder" in the entry editor's actions menu and the entry listing. */
class OpenBuilder extends Action
{
    protected static $handle = 'open_visual_bard';

    protected $confirm = false;

    public $icon = 'layout-grid';

    public static function title()
    {
        return __('visual-bard::messages.open_builder');
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry && $this->builderField($item) !== null;
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    public function redirect($items, $values)
    {
        $entry = $items->first();

        return cp_route('visual-bard.show', [$entry->id(), $this->builderField($entry)->handle()]);
    }

    /** The first Bard field the builder supports. */
    private function builderField(Entry $entry): ?Field
    {
        return $entry->blueprint()->fields()->all()
            ->first(ResolveBardField::supports(...));
    }
}
