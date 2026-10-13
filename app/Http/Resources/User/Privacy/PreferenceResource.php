<?php

namespace App\Http\Resources\User\Privacy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        return ['contactChannels' => $this->contact_channels, 'essentialChannels' => ['email'],
            'aiAssistanceEnabled' => $this->ai_assistance_enabled, 'recordingPreference' => $this->recording_preference,
            'contextVisibility' => $this->context_visibility, 'aiTrainingEnabled' => false, 'version' => $this->version];
    }
}
