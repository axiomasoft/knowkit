<?php
declare(strict_types=1);

namespace KnowKit\Domain\Contract;

/**
 * Trusted scope binding checks. hostScopeHash is compared verbatim and never recomputed;
 * scopeBindingDigest = HashDomain::ScopeBinding over {tenantId,kbId,actorId,purpose,hostScopeHash,
 * policyBindingDigest}. Any binding difference is denied(scope_mismatch); epoch drift is
 * denied(POLICY_CHANGED) (approved code, original case); refs outside the selection are
 * denied(selection_ref_mismatch). The trusted run binding (Selection.runRef, null for a direct
 * library call) must equal the request usageContext.runRef (absent = null), otherwise
 * denied(scope_mismatch); runRef is not part of the scopeBinding payload.
 */
final class ScopeBinding
{
    public static function policyBindingDigest(JsonObject $binding): string
    {
        return HashDomain::PolicyBinding->digest($binding);
    }

    public static function scopeBindingDigest(JsonObject $scope, string $policyBindingDigest): string
    {
        return HashDomain::ScopeBinding->digest(new JsonObject([
            'tenantId' => $scope->get('tenantId'), 'kbId' => $scope->get('kbId'), 'actorId' => $scope->get('actorId'),
            'purpose' => $scope->get('purpose'), 'hostScopeHash' => $scope->get('hostScopeHash'),
            'policyBindingDigest' => $policyBindingDigest,
        ]));
    }

    /** @return array{outcome: string, code?: string} */
    public static function checkSelection(JsonObject $scope, JsonObject $selection): array
    {
        if ($scope->get('tenantId') !== $selection->get('tenantId') || $scope->get('hostScopeHash') !== $selection->get('hostScopeHash')) {
            return ['outcome' => 'denied', 'code' => 'scope_mismatch'];
        }
        $policy = $selection->get('policyBinding');
        if (self::scopeBindingDigest($scope, self::policyBindingDigest($policy)) !== $selection->get('scopeBindingDigest')) {
            return ['outcome' => 'denied', 'code' => 'scope_mismatch'];
        }
        if ($scope->get('policyEpoch') !== $policy->get('kbPolicyEpoch')) {
            return ['outcome' => 'denied', 'code' => 'POLICY_CHANGED'];
        }
        return ['outcome' => 'success'];
    }

    /** @return array{outcome: string, code?: string} */
    public static function checkDisclosure(JsonObject $request, JsonObject $selection): array
    {
        if ($request->get('selectionId') !== $selection->get('selectionId')) {
            return ['outcome' => 'denied', 'code' => 'selection_ref_mismatch'];
        }
        $context = $request->has('usageContext') ? $request->get('usageContext') : null;
        $runRef = $context instanceof JsonObject ? $context->get('runRef') : null;
        if ($runRef !== $selection->get('runRef')) {
            return ['outcome' => 'denied', 'code' => 'scope_mismatch'];
        }
        foreach ($request->get('chunkRefs') as $ref) {
            $found = false;
            foreach ($selection->get('candidateRefs') as $candidate) {
                $found = $found || SchemaSet::jsonEqual($ref, $candidate);
            }
            if (!$found) {
                return ['outcome' => 'denied', 'code' => 'selection_ref_mismatch'];
            }
        }
        return ['outcome' => 'success'];
    }
}
