<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\HttpKernel\Event\{ExceptionEvent, ResponseEvent};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['kernel.response' => 'headers', 'kernel.exception' => ['exception', 10]];
    }

    public function headers(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    }

    public function exception(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503;
        $message = $error instanceof HttpExceptionInterface && $status < 500 ? $error->getMessage() : 'Le transfert est momentanément indisponible. Réessayez dans un instant.';
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->setResponse(new JsonResponse(['error' => $message], $status));
        } else {
            $event->setResponse(new Response('<!doctype html><html lang="fr"><meta charset="utf-8"><title>SecureBox</title><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="/">Revenir à SecureBox</a></html>', $status));
        }
    }
}
