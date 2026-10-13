<?php

namespace App\Http\Requests\User\Privacy;

class TransitionRequest extends PrivacyRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $context = $this->route('context');
        if ($context !== null) {
            $query = $this->user()->contexts()->whereKey($context);
            if (! $this->routeIs('user.contexts.destroy')) {
                $query->where('status', '!=', 'deleted');
            }
            abort_unless($query->exists(), 404);
        } else {
            abort_unless($this->user()->dataRequests()->whereKey($this->route('dataRequest'))->exists(), 404);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'expectedVersion' => ['required', 'integer', 'min:1'],

        ];
    }
}
