<?php

namespace App\Http\Middleware;

use App\Services\PrivacyFilter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HideDeadnames
{
    public function __construct(private readonly PrivacyFilter $privacy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->routeIs('settings.*')) {
            return $response;
        }
        if ($response instanceof JsonResponse) {
            $response->setData($this->privacy->apply($response->getData(true)));
        } elseif ($response->getContent() !== false && str_contains((string) $response->headers->get('Content-Type'), 'text/')) {
            $response->setContent($this->privacy->apply($response->getContent()));
        }
        if ($disposition = $response->headers->get('Content-Disposition')) {
            $response->headers->set('Content-Disposition', $this->privacy->apply($disposition));
        }

        return $response;
    }
}
