<?php

namespace Jurnskie\VisualBard\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Jurnskie\VisualBard\Actions\BuildBuilderPayload;
use Jurnskie\VisualBard\Http\Requests\BardFieldRequest;

class ShowBuilderController
{
    public function __invoke(BardFieldRequest $request, BuildBuilderPayload $payload): Response
    {
        return Inertia::render('visual-bard::Builder', $payload->handle($request->entry(), $request->fieldHandle()));
    }
}
