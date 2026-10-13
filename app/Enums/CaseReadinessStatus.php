<?php

namespace App\Enums;

enum CaseReadinessStatus: string
{
    case Draft = 'draft';
    case IntakeInProgress = 'intake_in_progress';
    case NeedsClarification = 'needs_clarification';
    case UnsupportedDomain = 'unsupported_domain';
    case UnsupportedJurisdiction = 'unsupported_jurisdiction';
    case RegulatedServiceNotEnabled = 'regulated_service_not_enabled';
    case NotSuitableForRemoteService = 'not_suitable_for_remote_service';
    case WaitingForExpertSupply = 'waiting_for_expert_supply';
    case SafetyReferralRequired = 'safety_referral_required';
    case ReadyForMatching = 'ready_for_matching';
    case RequiresReview = 'requires_review';
    case Cancelled = 'cancelled';
}
