<?php

declare(strict_types=1);

namespace Ddns\Http\OpenApi;

/**
 * Where the Swagger UI assets come from.
 *
 * Two sources, in order of preference. If the operator has dropped the
 * `swagger-ui-dist` files into `public/vendor/swagger-ui/`, they are served
 * from this host: nothing leaves the network, and the page works on a machine
 * with no route to the internet. Otherwise they come from a CDN.
 *
 * The CDN copy is pinned to an exact version and carries a subresource
 * integrity hash, so the browser refuses to run anything but the bytes checked
 * here. That is the same reasoning behind the pinned `composer.lock`: a
 * compromised upstream should not be able to change what this server serves.
 * The hashes were taken from bytes fetched independently from two CDNs and
 * compared.
 */
final class SwaggerUiAssets
{
    public const VERSION = '5.32.12';

    private const CDN = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@' . self::VERSION;

    public const CSS_INTEGRITY = 'sha384-9Q2fpS+xeS4ffJy6CagnwoUl+4ldAYhOs9pgZuEKxypVModhmZFzeMlvVsAjf7uT';
    public const SCRIPT_INTEGRITY = 'sha384-aPw2h1Un96ObRq1fD7AOgyf0r9jgkhMD51uBltHKtT0++4LsgMUkQD52RFNWcAil';

    /** Relative to the document root, so it is reachable without a route. */
    private const LOCAL_DIRECTORY = '/vendor/swagger-ui';

    private const CSS = 'swagger-ui.css';
    private const SCRIPT = 'swagger-ui-bundle.js';

    /**
     * @param string $publicDirectory where the front controller lives, used to
     *                                detect a local copy
     */
    public function __construct(private readonly string $publicDirectory)
    {
    }

    public function isLocal(): bool
    {
        return $this->localPath(self::CSS) !== null && $this->localPath(self::SCRIPT) !== null;
    }

    /**
     * The absolute path of a locally installed asset, or null when it is not
     * there.
     *
     * @param string $file one of the names this class knows about
     */
    public function localPath(string $file): ?string
    {
        if ($file !== self::CSS && $file !== self::SCRIPT) {
            return null;
        }

        $path = $this->publicDirectory . self::LOCAL_DIRECTORY . '/' . $file;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    public function cssUrl(string $basePath = ''): string
    {
        return $this->isLocal()
            ? $basePath . self::LOCAL_DIRECTORY . '/' . self::CSS
            : self::CDN . '/' . self::CSS;
    }

    public function scriptUrl(string $basePath = ''): string
    {
        return $this->isLocal()
            ? $basePath . self::LOCAL_DIRECTORY . '/' . self::SCRIPT
            : self::CDN . '/' . self::SCRIPT;
    }

    /**
     * Integrity attributes only apply to the CDN copy: a local file is already
     * under the operator's control, and a stale hash there would break the page
     * after a deliberate upgrade.
     */
    public function cssIntegrity(): ?string
    {
        return $this->isLocal() ? null : self::CSS_INTEGRITY;
    }

    public function scriptIntegrity(): ?string
    {
        return $this->isLocal() ? null : self::SCRIPT_INTEGRITY;
    }

    /**
     * A policy tight enough to be worth having: no framing, no plugins, and
     * scripts only from the two places the page actually uses.
     *
     * Swagger UI builds styles at runtime, so 'unsafe-inline' for styles is
     * unavoidable; scripts need no such exception beyond the small inline
     * bootstrap, which is covered by its own nonce.
     */
    public function contentSecurityPolicy(string $nonce): string
    {
        $origin = $this->isLocal() ? "'self'" : "'self' https://cdn.jsdelivr.net";

        return implode('; ', [
            "default-src 'none'",
            sprintf("script-src %s 'nonce-%s'", $origin, $nonce),
            sprintf('style-src %s %s', $origin, "'unsafe-inline'"),
            "img-src 'self' data:",
            "font-src 'self' data:",
            // The page fetches the description from this server, and Swagger
            // UI's "Try it out" calls the API - both same-origin.
            "connect-src 'self'",
            "form-action 'none'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
        ]);
    }
}
