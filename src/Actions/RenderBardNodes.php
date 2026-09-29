<?php

namespace Jurnskie\VisualBard\Actions;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Field;

class RenderBardNodes
{
    public function __construct(private ResolveBardField $resolve) {}

    /**
     * Render each top-level (pre-processed) node on its own through Bard's own
     * process + augment pipeline and the same `sets/{type}` partials as the
     * front end, so the HTML matches `{{ page_builder }}`.
     *
     * @param  array<int, array>  $nodes
     * @return Collection<int, array{index: int, id: ?string, html: string}>
     */
    public function handle(Entry $entry, string $handle, array $nodes, ?Field $field = null): Collection
    {
        $fieldtype = ($field ?? $this->resolve->handle($entry, $handle))->fieldtype();

        return collect($nodes)->values()->map(fn (array $node, int $index) => [
            'index' => $index,
            'id' => data_get($node, 'attrs.id'),
            'html' => collect($fieldtype->augment($fieldtype->process([$node])))
                ->map(fn ($item) => view("sets.{$item->all()['type']}", $item->all())->render())
                ->implode(''),
        ]);
    }
}
