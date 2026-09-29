<?php

namespace Jurnskie\VisualBard\Actions;

use Statamic\Contracts\Entries\Entry;
use Statamic\View\View;

class RenderBuilderCanvas
{
    /**
     * The entry's real front-end page (layout, navigation, site assets) with an
     * empty mount point where its template would be; the builder fills it.
     */
    public function handle(Entry $entry): string
    {
        return app(View::class)
            ->template('visual-bard::canvas')
            ->layout($entry->layout())
            ->cascadeContent($entry)
            ->render();
    }
}
