<?php

declare(strict_types=1);

namespace PHPAML\Security;

use PHPAML\Http\Request;

final class CspNonce
{
    public const ATTRIBUTE = '_phpaml_csp_nonce';

    public static function generate(): string
    {
        return base64_encode(random_bytes(18));
    }

    public static function from(Request $request): string
    {
        $nonce = $request->attribute(self::ATTRIBUTE);
        if (!is_string($nonce) || preg_match('/^[A-Za-z0-9+\/_-]{8,256}={0,2}$/', $nonce) !== 1) {
            throw new \LogicException("Le nonce CSP de la requête est absent ou invalide.");
        }
        return $nonce;
    }
}
