<?php

namespace Jurnskie\VisualBard\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/** Structural checks only; per-set field rules come from the Bard fieldtype itself. */
trait ValidatesNodes
{
    public function rules(): array
    {
        return [
            'nodes' => ['present', 'array'],
            'nodes.*' => ['array'],
            'nodes.*.type' => ['required', 'string'],
            'nodes.*.attrs.values.type' => [
                'required_if:nodes.*.type,set',
                'string',
                Rule::in($this->bardField()->fieldtype()->flattenedSetsConfig()->keys()->all()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nodes.*.attrs.values.type.in' => __('visual-bard::messages.unknown_set'),
        ];
    }

    /** @return array<int, array> */
    public function nodes(): array
    {
        return $this->input('nodes');
    }
}
