<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Http\Request;
use PHPAML\Http\Response;

final class LocaleMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $supported
     * @param list<string> $detection
     * @param Closure(string, Closure): Response $within
     */
    public function __construct(
        private array $supported,
        private string $fallback,
        private array $detection,
        private string $cookie,
        private Closure $within,
    ) {
        if ($supported === [] || !in_array($fallback, $supported, true)) {
            throw new \InvalidArgumentException('La langue de repli doit faire partie des langues prises en charge.');
        }
        foreach ($supported as $locale) {
            if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
                throw new \InvalidArgumentException("Langue non valide : {$locale}");
            }
        }
    }

    public function process(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);
        $response = ($this->within)(
            $locale,
            static fn (): Response => $next($request->withAttribute('locale', $locale)),
        );
        $vary = trim((string) ($response->headers()['Vary'] ?? ''));
        $varyValues = array_map('trim', explode(',', $vary));
        if (in_array('header', $this->detection, true)) {
            $varyValues[] = 'Accept-Language';
        }
        if (in_array('cookie', $this->detection, true)) {
            $varyValues[] = 'Cookie';
        }
        $varyValues = array_values(array_unique(array_filter($varyValues)));
        return $response
            ->withHeader('Content-Language', $locale)
            ->withHeader('Vary', implode(', ', $varyValues));
    }

    private function resolve(Request $request): string
    {
        foreach ($this->detection as $strategy) {
            $candidate = match ($strategy) {
                'route' => explode('/', trim($request->path(), '/'))[0],
                'cookie' => (string) $request->cookie($this->cookie, ''),
                'header' => $this->fromAcceptLanguage((string) $request->header('Accept-Language', '')),
                default => '',
            };
            $match = $this->match($candidate);
            if ($match !== null) {
                return $match;
            }
        }
        return $this->fallback;
    }

    private function fromAcceptLanguage(string $header): string
    {
        $candidates = [];
        foreach (explode(',', $header) as $position => $part) {
            [$locale, $quality] = array_pad(explode(';q=', trim($part), 2), 2, '1');
            $candidates[] = ['locale' => trim($locale), 'quality' => (float) $quality, 'position' => $position];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['position'] <=> $b['position']);
        foreach ($candidates as $candidate) {
            if ($this->match($candidate['locale']) !== null) {
                return $candidate['locale'];
            }
        }
        return '';
    }

    private function match(string $locale): ?string
    {
        if ($locale === '') {
            return null;
        }
        $normalized = strtolower(str_replace('_', '-', $locale));
        foreach ($this->supported as $supported) {
            $candidate = strtolower(str_replace('_', '-', $supported));
            if ($candidate === $normalized || explode('-', $candidate)[0] === explode('-', $normalized)[0]) {
                return $supported;
            }
        }
        return null;
    }
}
