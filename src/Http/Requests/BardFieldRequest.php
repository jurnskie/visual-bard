<?php

namespace Jurnskie\VisualBard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Jurnskie\VisualBard\Actions\ResolveBardField;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Field;

/** Resolves the route's entry + Bard field: 403 without `edit` rights, 404 for an unusable field. */
class BardFieldRequest extends FormRequest
{
    private ?Field $bardField = null;

    public function authorize(): bool
    {
        if (Gate::denies('edit', $this->entry())) {
            return false;
        }

        $this->bardField();

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function entry(): Entry
    {
        return $this->route('entry');
    }

    public function fieldHandle(): string
    {
        return $this->route('field');
    }

    public function bardField(): Field
    {
        return $this->bardField ??= app(ResolveBardField::class)->handle($this->entry(), $this->fieldHandle());
    }
}
