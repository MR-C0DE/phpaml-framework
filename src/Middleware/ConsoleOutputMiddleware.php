<?php

declare(strict_types=1);

namespace PHPAML\Middleware;

use Closure;
use PHPAML\Console;
use PHPAML\Http\Request;
use PHPAML\Http\Response;
/** Sends accidental or intentional echo output to the development terminal. */
final class ConsoleOutputMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Closure $next): Response
    {
        $level = ob_get_level();
        $pending = '';
        ob_start(static function (string $chunk) use (&$pending): string {
            $pending .= $chunk;
            if (strlen($pending) >= 8192) {
                Console::output($pending);
                $pending = '';
            }
            return '';
        }, 8192);

        try {
            return $next($request);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_flush();
            }
            Console::output($pending);
        }
    }
}
