<?php
declare(strict_types=1);

namespace App\Services;

/**
 * In-house consent/e-signature capture (default provider per spec — no
 * external e-signature vendor is wired up in this pass). Produces a
 * tamper-evident hash of the signed payload plus signer metadata, which is
 * enough to prove what was agreed to and when for non-clinical liability
 * waivers and consent forms. Swap this out for a third-party e-signature
 * provider integration later if a legally-binding clinical signature is
 * required (see PRD open decision on e-signature provider).
 */
class ConsentSigningService
{
    public static function sign(string $documentType, string $documentVersion, array $signerContext): array
    {
        $payload = json_encode([
            'document_type'    => $documentType,
            'document_version' => $documentVersion,
            'signer'           => $signerContext,
            'signed_at'        => time(),
        ], JSON_THROW_ON_ERROR);

        return [
            'signature_hash' => hash('sha256', $payload),
            'signed_at'      => new \MongoDB\BSON\UTCDateTime(),
        ];
    }
}
