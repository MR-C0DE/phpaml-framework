<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class ApiVersionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $version,
        private ?string $deprecationDate = null,
        private ?string $sunsetDate = null,
        private ?string $successorUrl = null,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $response = $next($request)->withHeader('API-Version', $this->version);
        if ($this->deprecationDate !== null) {
            $response = $response->withHeader('Deprecation', '@' . $this->timestamp($this->deprecationDate));
        }
        if ($this->sunsetDate !== null) {
            $response = $response->withHeader('Sunset', gmdate('D, d M Y H:i:s', $this->timestamp($this->sunsetDate)) . ' GMT');
        }
        if ($this->successorUrl !== null) {
            $response = $response->withHeader('Link', '<' . $this->successorUrl . '>; rel="successor-version"');
        }
        return $response;
    }

    private function timestamp(string $date): int
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Date de version API invalide.');
        }
        return $timestamp;
    }
}
