<?php

declare(strict_types=1);

namespace PHPAML\Api;

use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class AuthController
{
    public function __construct(private AuthManager $auth, private TokenManager $tokens) {}

    public function register(Request $request): Response
    {
        try {
            $user = $this->auth->register((string) $request->input('name', ''), (string) $request->input('email', ''), (string) $request->input('password', ''));
            $session = $this->auth->login($user['email'], (string) $request->input('password'), (string) $request->input('device_name', 'api'));
            return ApiResponse::created($session);
        } catch (AuthException $error) { return ApiResponse::error($error->errorCode, $error->getMessage(), $error->status); }
    }

    public function login(Request $request): Response
    {
        try {
            return ApiResponse::ok($this->auth->login((string) $request->input('email', ''), (string) $request->input('password', ''), (string) $request->input('device_name', 'api')));
        } catch (AuthException $error) { return ApiResponse::error($error->errorCode, $error->getMessage(), $error->status); }
    }

    public function me(Request $request): Response
    {
        $user = $this->auth->user((string) $request->attribute('auth.id', ''));
        return $user === null ? ApiResponse::error('USER_NOT_FOUND', 'Utilisateur introuvable.', 404) : ApiResponse::ok($user);
    }

    public function logout(Request $request): Response
    {
        $plain = self::bearer($request);
        if ($plain !== null) { $this->tokens->revoke($plain); }
        return ApiResponse::noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $count = $this->tokens->revokeOwner((string) $request->attribute('auth.id', ''));
        return ApiResponse::ok(['revoked' => $count]);
    }

    public function rotate(Request $request): Response
    {
        $plain = self::bearer($request);
        $replacement = $plain === null ? null : $this->tokens->rotate($plain);
        return $replacement === null
            ? ApiResponse::error('INVALID_TOKEN', 'Le token est invalide ou expiré.', 401)
            : ApiResponse::ok(['token' => $replacement]);
    }

    private static function bearer(Request $request): ?string
    {
        return preg_match('/^Bearer\s+([^\s]+)$/i', trim((string) $request->header('Authorization', '')), $matches) ? $matches[1] : null;
    }
}
