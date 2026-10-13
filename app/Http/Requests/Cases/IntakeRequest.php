<?php

namespace App\Http\Requests\Cases;

use App\Services\Cases\IntakeSchema;

class IntakeRequest extends CaseRequest
{
    public function rules(): array
    {
        return app(IntakeSchema::class)->rules() + ['expectedVersion' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1']];
    }
}
