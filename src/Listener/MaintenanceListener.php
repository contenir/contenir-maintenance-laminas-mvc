<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Listener;

use Closure;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use DateTimeInterface;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Short-circuits dispatch with a 503 response when maintenance mode is active.
 *
 * Resolution order:
 *   1. Repository reports inactive → return null, request continues.
 *   2. Bypass callable returns true → return null, request continues.
 *   3. Otherwise → build a 503 Response with Retry-After header and the
 *      configured body template (sprintf-style, single %s for message),
 *      attach it to the event, and stop propagation.
 *
 * The body template receives two arguments: the HTML-escaped message
 * (`%s` or `%1$s`) and the `since` time as ISO 8601 (`%2$s`), which is an
 * empty string when the state has no `since`. A template that only uses
 * `%s` ignores the second argument.
 *
 * @api
 */
final readonly class MaintenanceListener
{
    public const string DEFAULT_BODY_TEMPLATE = '<!doctype html><title>503</title><h1>Service Unavailable</h1><p>%s</p>';

    /**
     * Event name used to signal page-cache opt-out. Matches the
     * EVENT_DISABLE constant published by contenir/contenir-cache-laminas-mvc.
     * Hardcoded here so this package doesn't take a hard dependency on
     * the cache adapter; if cache-laminas-mvc isn't installed, firing
     * the event is a harmless no-op.
     */
    private const string PAGECACHE_DISABLE_EVENT = 'pagecache.disable';

    /**
     * Only a `true` return lets the request through.
     */
    private ?Closure $bypass;

    /**
     * @param callable|null $bypass Called with the MvcEvent; only a `true` return lets the request through.
     */
    public function __construct(
        private MaintenanceRepositoryInterface $repository,
        private int $retryAfter = 600,
        private string $bodyTemplate = self::DEFAULT_BODY_TEMPLATE,
        ?callable $bypass = null,
    ) {
        $this->bypass = null === $bypass ? null : $bypass(...);
    }

    private function bypassed(MvcEvent $event): bool
    {
        return null !== $this->bypass && true === ($this->bypass)($event);
    }

    /**
     * Tell any listening page-cache that this 503 must not be stored.
     * cache-laminas-mvc's CacheStrategy listens to this event on the
     * same identifier(s) it uses for dispatch/finish; on receipt it
     * flips its disabled flag and onFinish skips storage.
     */
    private function disablePageCache(MvcEvent $event): void
    {
        $event->getApplication()?->getEventManager()->trigger(self::PAGECACHE_DISABLE_EVENT);
    }

    public function __invoke(MvcEvent $event): ?Response
    {
        $state = $this->repository->get();

        if (! $state->active || $this->bypassed($event)) {
            return null;
        }

        $this->disablePageCache($event);

        $response = new Response();
        $response->setStatusCode(Response::STATUS_CODE_503);
        $response->getHeaders()->addHeaderLine('Retry-After', (string) $this->retryAfter);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/html; charset=utf-8');
        $response->setContent(sprintf(
            $this->bodyTemplate,
            htmlspecialchars($state->message, ENT_QUOTES, encoding: 'UTF-8'),
            $state->since?->format(DateTimeInterface::ATOM) ?? '',
        ));

        $event->setResponse($response);
        $event->stopPropagation(true);

        return $response;
    }
}
