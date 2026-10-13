<?php

namespace App\Enums;

enum ExpertServiceType: string
{
    case WrittenConsultation = 'written_consultation';
    case VideoConsultation = 'video_consultation';
    case AudioConsultation = 'audio_consultation';
    case DocumentReview = 'document_review';
    case ProjectAdvisory = 'project_advisory';
}
