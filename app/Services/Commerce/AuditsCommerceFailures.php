<?php

namespace App\Services\Commerce;

use App\Exceptions\PrivacyException;
use App\Models\Expert;
use App\Models\User;
use Closure;

/** Important eligibility failures roll their transaction back, so their audit row is written after the rollback. */
trait AuditsCommerceFailures
{
    /** @var list<string> */
    private array $auditedFailures = ['EXPERT_NOT_ELIGIBLE', 'ELIGIBILITY_CHANGED', 'CAPACITY_REACHED', 'CASE_NOT_AVAILABLE'];

    public function auditFailures(User|Expert $actor, string $subject, int $subjectId, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (PrivacyException $e) {
            if (in_array($e->errorCode, $this->auditedFailures, true)) {
                $this->audit->write($actor, 'commerce.eligibility_failed', $subject, $subjectId, reason: $e->errorCode);
            }
            throw $e;
        }
    }
}
