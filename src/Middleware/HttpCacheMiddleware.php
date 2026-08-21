<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class HttpCacheMiddleware implements MiddlewareInterface
{
    public function __construct(private int $maxAge = 0, private bool $isPublic = false)
    {
        if ($maxAge < 0) {
            throw new \InvalidArgumentException('La durée du cache ne peut pas être négative.');
        }
    }

    public function process(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (!in_array($request->method(), ['GET', 'HEAD'], true) || $response->status() !== 200) {
            return $response;
        }
        $etag = '"' . hash('sha256', $response->content()) . '"';
        $headers = $response->headers();
        $cacheControl = ($this->isPublic ? 'public' : 'private') . ', max-age=' . $this->maxAge;
        if ($this->etagMatches((string) $request->header('If-None-Match', ''), $etag)) {
            $notModified = new Response('', 304, $headers);
            return $notModified->withHeader('ETag', $etag)->withHeader('Cache-Control', $cacheControl);
        }
        return $response->withHeader('ETag', $etag)->withHeader('Cache-Control', $cacheControl);
    }

    private function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            if (preg_replace('/^W\//', '', trim($candidate)) === $etag) {
                return true;
            }
        }
        return false;
    }
}
