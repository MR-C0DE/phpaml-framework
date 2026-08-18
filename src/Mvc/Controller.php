<?php

declare(strict_types=1);

namespace PHPAML\Mvc;

use PHPAML\Http\Response;

abstract class Controller
{
    public function __construct(private ?View $viewEngine = null)
    {
    }

    /** @param array<string, mixed> $data */
    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        if ($this->viewEngine === null) {
            throw new \LogicException('Aucun moteur de vues PHP classique n’est configuré.');
        }
        return $this->viewEngine->render($name, $data, $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}
