<?php

namespace Jurnskie\VisualBard\Actions;

use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

class SaveBardValue
{
    public function __construct(private ResolveBardField $resolve) {}

    /**
     * Validate and process the pre-processed client nodes the same way
     * EntriesController::update() does, scoped to this single field.
     *
     * @param  array<int, array>  $nodes
     *
     * @throws ValidationException
     */
    public function handle(Entry $entry, string $handle, array $nodes): Entry
    {
        $this->resolve->handle($entry, $handle);

        $fields = $entry->blueprint()->fields()->only($handle)->addValues([$handle => $nodes]);

        $fields->validator()->withReplacements([
            'id' => $entry->id(),
            'collection' => $entry->collectionHandle(),
            'site' => $entry->locale(),
        ])->validate();

        $entry->set($handle, $fields->process()->values()->get($handle));

        if ($user = User::current()) {
            $entry->updateLastModified($user);
        }

        return tap($entry)->save();
    }
}
