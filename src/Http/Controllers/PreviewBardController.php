<?php

namespace Jurnskie\VisualBard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Jurnskie\VisualBard\Actions\RenderBardNodes;
use Jurnskie\VisualBard\Http\Requests\PreviewBardRequest;

class PreviewBardController
{
    public function __invoke(PreviewBardRequest $request, RenderBardNodes $render): JsonResponse
    {
        return response()->json([
            'previews' => $render->handle($request->entry(), $request->fieldHandle(), $request->nodes(), $request->bardField()),
        ]);
    }
}
