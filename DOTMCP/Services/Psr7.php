<?php

namespace Modules\DOTMCP\Services;

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * Bridges Laravel 5.5's Symfony-based requests to the PSR-7 objects
 * league/oauth2-server expects.
 *
 * Uses guzzlehttp/psr7, which FreeScout already ships - no extra dependency.
 * symfony/psr-http-message-bridge would be the conventional choice but is not
 * present, and adding it would mean another package to keep patched for very
 * little gain.
 */
class Psr7
{
    /** Laravel request -> PSR-7 ServerRequest. */
    public static function fromRequest($request)
    {
        $psr = new ServerRequest(
            $request->method(),
            $request->fullUrl(),
            self::headers($request),
            $request->getContent() ?: null,
            '1.1',
            $_SERVER
        );

        return $psr
            ->withQueryParams($request->query->all())
            // The library reads client_id, code_verifier and grant_type from
            // the parsed body, so form and JSON payloads must both arrive here.
            ->withParsedBody(self::parsedBody($request))
            ->withCookieParams($request->cookies->all())
            ->withUploadedFiles([]);
    }

    /** A blank PSR-7 response for the library to populate. */
    public static function response(): ResponseInterface
    {
        return new GuzzleResponse();
    }

    /** PSR-7 response -> Laravel response. */
    public static function toResponse(ResponseInterface $psrResponse)
    {
        $body = (string) $psrResponse->getBody();

        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return response($body, $psrResponse->getStatusCode(), $headers);
    }

    protected static function headers($request)
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = $values;
        }

        return $headers;
    }

    /**
     * Token endpoints are form-encoded per the spec, but some clients send
     * JSON. Accept both rather than failing with an unhelpful "missing
     * client_id".
     */
    protected static function parsedBody($request)
    {
        if ($request->isJson()) {
            $json = $request->json()->all();
            if (is_array($json) && !empty($json)) {
                return $json;
            }
        }

        return $request->request->all();
    }
}
