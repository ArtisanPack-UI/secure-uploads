<?php

declare( strict_types=1 );

namespace ArtisanPackUI\SecureUploads\FileUpload;

use Illuminate\Http\Request;

/**
 * Serializable request context for events.
 *
 * Contains only the necessary request data that can be
 * safely serialized for queued event listeners.
 */
class RequestContext
{
    /**
     * Create a new request context instance.
     */
    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $url = null,
        public readonly ?string $method = null,
        public readonly array $headers = [],
    ) {
    }

    /**
     * Create a request context from an HTTP request.
     */
    public static function fromRequest( ?Request $request ): self
    {
        if ( null === $request ) {
            return new self();
        }

        return new self(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            url: $request->getPathInfo(),
            method: $request->method(),
            headers: self::extractSafeHeaders( $request ),
        );
    }

    /**
     * Create an empty context (for CLI usage).
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'url'        => $this->url,
            'method'     => $this->method,
            'headers'    => $this->headers,
        ];
    }

    /**
     * Extract only safe headers for logging.
     */
    protected static function extractSafeHeaders( Request $request ): array
    {
        $safeHeaders = [
            'accept',
            'accept-language',
            'content-type',
            'origin',
            'x-requested-with',
        ];

        $headers = [];
        foreach ( $safeHeaders as $header ) {
            $value = $request->header( $header );
            if ( null !== $value ) {
                $headers[ $header ] = $value;
            }
        }

        // Strip query strings (and any auth fragments) from referer-style headers
        // so signed-URL tokens can't leak into serialized event payloads.
        $referer = $request->header( 'referer' );
        if ( null !== $referer ) {
            $host = parse_url( $referer, PHP_URL_HOST );
            $path = parse_url( $referer, PHP_URL_PATH );
            if ( is_string( $host ) && '' !== $host ) {
                $headers['referer'] = $host . ( is_string( $path ) ? $path : '' );
            }
        }

        return $headers;
    }
}
