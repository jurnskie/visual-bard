<?php

namespace Jurnskie\VisualBard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Jurnskie\VisualBard\Actions\ResolveBardField;
use Jurnskie\VisualBard\Actions\SaveBardValue;
use Jurnskie\VisualBard\Http\Requests\SaveBardRequest;

class SaveBardController
{
    public function __invoke(SaveBardRequest $request, SaveBardValue $save, ResolveBardField $resolve): JsonResponse
    {
        $entry = $save->handle($request->entry(), $request->fieldHandle(), $request->nodes());

        return response()->json([
            'message' => __('visual-bard::messages.saved'),
            'value' => $resolve->handle($entry, $request->fieldHandle())->preProcess()->value(),
        ]);
    }
}
