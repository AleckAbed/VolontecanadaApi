<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Suivi des erreurs (Sentry ou GlitchTip hébergé au Canada) sans renseignement personnel :
 * on garde la pile d'appels et l'adresse de la page (sans paramètres), on retire le corps
 * des requêtes, les en-têtes, les cookies, l'utilisateur et les messages qui pourraient
 * contenir une adresse courriel.
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();
        if ($request) {
            $url = isset($request['url']) ? strtok((string) $request['url'], '?') : null;
            $event->setRequest(array_filter([
                'url' => $url,
                'method' => $request['method'] ?? null,
            ]));
        }
        $event->setUser(null);
        $event->setExtra([]);

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrub($exception->getValue()));
        }
        if ($event->getMessage()) {
            $event->setMessage(self::scrub($event->getMessage()));
        }

        return $event;
    }

    /** Masque les adresses courriel et les longues suites de chiffres (téléphones, passeports). */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[courriel]', $text);

        return preg_replace('/\b[A-Z]{0,2}\d{6,}\b/i', '[numéro]', $text);
    }
}
