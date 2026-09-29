<?php

namespace Jurnskie\VisualBard\Actions;

use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Field;
use Statamic\Support\Arr;

class BuildBuilderPayload
{
    public function __construct(
        private ResolveBardField $resolve,
        private RenderBardNodes $render,
    ) {}

    public function handle(Entry $entry, string $handle): array
    {
        $field = $this->resolve->handle($entry, $handle)->preProcess();
        $value = $field->value();

        return [
            'entry' => [
                'id' => $entry->id(),
                'title' => $entry->value('title'),
                'edit_url' => $entry->editUrl(),
            ],
            'field' => [
                'handle' => $field->handle(),
                'display' => $field->display(),
                'instructions' => $field->instructions(),
            ],
            'sets' => $this->sets($field),
            'value' => $value,
            'meta' => $field->meta(),
            'previews' => $this->render->handle($entry, $handle, $value, $field)->all(),
            'canvas_url' => cp_route('visual-bard.canvas', [$entry->id(), $field->handle()]),
        ];
    }

    /** Set groups with publish fields plus the "new set" scaffolding Bard::preload() skips for real entries. */
    private function sets(Field $field): array
    {
        $fieldtype = $field->fieldtype();

        return collect($fieldtype->config('sets'))->map(fn (array $group, string $groupHandle) => [
            'handle' => $groupHandle,
            ...Arr::only($group, ['display', 'instructions', 'icon']),
            'sets' => collect($group['sets'] ?? [])->map(function (array $set, string $setHandle) use ($fieldtype) {
                $fields = $fieldtype->fields($setHandle);
                $defaults = $fields->all()
                    ->map(fn (Field $field) => $field->fieldtype()->preProcess($field->defaultValue()))
                    ->all();

                return [
                    'handle' => $setHandle,
                    ...Arr::only($set, ['display', 'instructions', 'icon']),
                    'fields' => $fields->toPublishArray(),
                    'defaults' => $defaults,
                    'new' => $fields->addValues($defaults)->meta()->put('_', '_')->toArray(),
                ];
            })->values()->all(),
        ])->values()->all();
    }
}
