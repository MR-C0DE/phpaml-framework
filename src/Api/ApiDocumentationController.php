<?php

declare(strict_types=1);

namespace PHPAML\Api;

use PHPAML\Http\Request;
use PHPAML\Http\Response;
use PHPAML\Routing\Router;

final class ApiDocumentationController
{
    public function __construct(private Router $router) {}

    public function openapi(Request $request): Response
    {
        return Response::json((new OpenApiGenerator())->generate($this->router->routes(), '/api'));
    }

    public function docs(Request $request): Response
    {
        return Response::html(<<<'HTML'
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>PHPAML API</title>
<style>body{font:16px system-ui;max-width:960px;margin:40px auto;padding:0 20px;background:#0d0b12;color:#f4f1ff}code{color:#c7ff3d}.route{padding:14px;border:1px solid #332b44;border-radius:8px;margin:10px 0}</style></head><body><h1>PHPAML API</h1><p>Documentation générée depuis les routes actives.</p><main id="routes">Chargement…</main><script>
fetch('/api/openapi.json').then(r=>r.json()).then(spec=>{document.querySelector('#routes').innerHTML=Object.entries(spec.paths).flatMap(([path,ops])=>Object.keys(ops).map(method=>`<div class="route"><code>${method.toUpperCase()}</code> ${path}</div>`)).join('')}).catch(()=>{document.querySelector('#routes').textContent='Documentation indisponible.'})
</script></body></html>
HTML);
    }
}
