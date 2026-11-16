<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Carbon\CarbonImmutable;
use Closure;
use Gunner42\LaravelEetModule\Models\CashRegister;
use Gunner42\LaravelEetModule\Models\CashRegisterCertificate;
use Gunner42\LaravelEetModule\Models\Taxpayer;
use OpenSSLAsymmetricKey;

/**
 * Selects the signing certificate at every attempt (R16, R69): the pinned
 * candidate, otherwise candidates with CN = EIČ first, the latest validTo,
 * the latest import. Never a certificate of another taxpayer.
 */
final class CertificateSelector
{
    /**
     * Calls $use with the selected certificate and its decrypted key, or
     * returns null without calling it when there is no candidate.
     *
     * @template T
     *
     * @param  Closure(CashRegisterCertificate, OpenSSLAsymmetricKey): T  $use
     * @return T|null
     */
    public function withCertificate(Taxpayer $taxpayer, ?CashRegister $cashRegister, Closure $use): mixed
    {
        $now = CarbonImmutable::now();
        $pinned = $cashRegister?->certificate;

        if ($pinned instanceof CashRegisterCertificate && $pinned->taxpayer_id === $taxpayer->id && $pinned->isValidAt($now)) {
            $key = $pinned->privateKey();

            if ($key !== null) {
                return $use($pinned, $key);
            }
        }

        $candidates = CashRegisterCertificate::query()
            ->where('taxpayer_id', $taxpayer->id)
            ->get()
            ->sort(static function (CashRegisterCertificate $a, CashRegisterCertificate $b) use ($taxpayer): int {
                return [$b->subject_eic === $taxpayer->eic, $b->valid_to->getTimestamp(), $b->id]
                    <=> [$a->subject_eic === $taxpayer->eic, $a->valid_to->getTimestamp(), $a->id];
            });

        foreach ($candidates as $candidate) {
            if (! $candidate->isValidAt($now)) {
                continue;
            }

            $key = $candidate->privateKey();

            if ($key !== null) {
                return $use($candidate, $key);
            }
        }

        return null;
    }
}
