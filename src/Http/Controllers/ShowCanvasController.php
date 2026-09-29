<?php

namespace Jurnskie\VisualBard\Http\Controllers;

use Illuminate\Http\Response;
use Jurnskie\VisualBard\Actions\RenderBuilderCanvas;
use Jurnskie\VisualBard\Http\Requests\BardFieldRequest;

class ShowCanvasController
{
    public function __invoke(BardFieldRequest $request, RenderBuilderCanvas $canvas): Response
    {
        return response($canvas->handle($request->entry()))
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
