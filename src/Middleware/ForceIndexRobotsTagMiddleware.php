<?php
// src/Middleware/ForceIndexRobotsTagMiddleware.php

namespace App\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ForceIndexRobotsTagMiddleware implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ResponseEvent::class => 'onResponse',
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        // Injection forcée du header pour permettre l'indexation
        $response->headers->set('X-Robots-Tag', 'index, follow');
    }
}