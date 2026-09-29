<?php

namespace Jurnskie\VisualBard\Http\Requests;

use Jurnskie\VisualBard\Http\Requests\Concerns\ValidatesNodes;

class PreviewBardRequest extends BardFieldRequest
{
    use ValidatesNodes;
}
