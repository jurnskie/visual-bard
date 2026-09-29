<?php

namespace Jurnskie\VisualBard\Http\Requests;

use Jurnskie\VisualBard\Http\Requests\Concerns\ValidatesNodes;

class SaveBardRequest extends BardFieldRequest
{
    use ValidatesNodes;
}
