<?php

declare(strict_types=1);

namespace Ddns\Tests\Integration;

use Ddns\Http\Action\RedirectToDocsAction;
use Ddns\Http\OpenApi\SwaggerUiAssets;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The browsable documentation at `/api`, and the redirect that leads to it.
 *
 * The page itself is a shell around Swagger UI, so what is worth testing is
 * everything that would leave a reader staring at a blank page: the assets it
 * asks for, the integrity attributes that let the browser run them, and the
 * content security policy that has to permit exactly those and nothing else.
 */
#[CoversNothing]
final class DocsTest extends HttpTestCase
{
    /** @var list<string> */
    private array $assetDirectories = [];

    #[Test]
    public function the_root_redirects_temporarily(): void
    {
        $response = $this->request('GET', '/');

        // 302, not 301: a permanent redirect is cached by browsers
        // indefinitely and is painful to undo.
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(RedirectToDocsAction::TARGET, $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function the_redirect_target_is_a_path_not_an_absolute_url(): void
    {
        // Behind a TLS-terminating proxy the request's own scheme and host are
        // the proxy's inward view; sending a client there would be sending it
        // somewhere that may not be reachable.
        $location = $this->request('GET', '/')->getHeaderLine('Location');

        self::assertStringStartsWith('/', $location);
        self::assertStringNotContainsString('://', $location);
    }

    #[Test]
    public function the_documentation_page_is_served(): void
    {
        $response = $this->request('GET', '/api');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('SwaggerUIBundle', $this->body($response));
    }

    #[Test]
    public function the_page_reads_the_generated_description(): void
    {
        // It carries no documentation of its own: anything written into the
        // page would be a second copy, free to disagree with the first.
        $body = $this->body($this->request('GET', '/api'));

        self::assertStringContainsString('url: "/openapi.json"', $body);
    }

    #[Test]
    public function it_needs_no_credentials(): void
    {
        self::assertSame(200, $this->request('GET', '/api')->getStatusCode());
    }

    #[Test]
    public function cdn_assets_carry_integrity_hashes(): void
    {
        // Without these the browser will run whatever the CDN serves. With
        // them a compromised CDN produces a blank page instead of an attack.
        $body = $this->body($this->request('GET', '/api'));

        self::assertStringContainsString(SwaggerUiAssets::CSS_INTEGRITY, $body);
        self::assertStringContainsString(SwaggerUiAssets::SCRIPT_INTEGRITY, $body);
        self::assertSame(2, substr_count($body, 'crossorigin="anonymous"'));
    }

    #[Test]
    public function the_asset_version_is_pinned(): void
    {
        $body = $this->body($this->request('GET', '/api'));

        // An integrity hash is only meaningful against a fixed version; a
        // floating tag would break the page the moment upstream published.
        self::assertStringContainsString('swagger-ui-dist@' . SwaggerUiAssets::VERSION . '/', $body);
        self::assertStringNotContainsString('swagger-ui-dist@latest', $body);
    }

    #[Test]
    public function the_policy_allows_exactly_what_the_page_uses(): void
    {
        $response = $this->request('GET', '/api');
        $policy = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringContainsString('https://cdn.jsdelivr.net', $policy);
        // "Try it out" calls this server, so same-origin fetches must be allowed.
        self::assertStringContainsString("connect-src 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function the_inline_bootstrap_is_allowed_by_a_fresh_nonce(): void
    {
        $response = $this->request('GET', '/api');
        $body = $this->body($response);

        preg_match('/<script nonce="([^"]+)">/', $body, $matches);

        self::assertArrayHasKey(1, $matches, 'The page has no nonced inline script.');

        $nonce = html_entity_decode($matches[1] ?? '');

        self::assertNotSame('', $nonce);
        self::assertStringContainsString(
            sprintf("'nonce-%s'", $nonce),
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    #[Test]
    public function the_nonce_is_not_reused_between_responses(): void
    {
        // A fixed nonce would let anything that could guess it inject a script
        // the policy then trusts.
        $first = $this->request('GET', '/api')->getHeaderLine('Content-Security-Policy');
        $second = $this->request('GET', '/api')->getHeaderLine('Content-Security-Policy');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function it_degrades_to_a_link_without_javascript(): void
    {
        $body = $this->body($this->request('GET', '/api'));

        self::assertStringContainsString('<noscript>', $body);
        self::assertStringContainsString('href="/openapi.json"', $body);
    }

    #[Test]
    public function a_local_copy_of_the_assets_is_preferred(): void
    {
        // For an air-gapped install, or an operator who would rather no browser
        // of theirs talked to a CDN.
        $directory = $this->withLocalAssets();

        $assets = new SwaggerUiAssets($directory);

        self::assertTrue($assets->isLocal());
        self::assertSame('/vendor/swagger-ui/swagger-ui.css', $assets->cssUrl());
        self::assertSame('/vendor/swagger-ui/swagger-ui-bundle.js', $assets->scriptUrl());
        // A hash pinned here would break the page after a deliberate upgrade of
        // files the operator controls.
        self::assertNull($assets->cssIntegrity());
        self::assertStringNotContainsString('jsdelivr', $assets->contentSecurityPolicy('n'));
    }

    #[Test]
    public function the_page_serves_local_assets_when_they_are_installed(): void
    {
        $this->assetDirectory = $this->withLocalAssets();

        $body = $this->body($this->request('GET', '/api'));

        self::assertStringContainsString('href="/vendor/swagger-ui/swagger-ui.css"', $body);
        self::assertStringNotContainsString('jsdelivr', $body);
        // A hash pinned here would break the page after a deliberate upgrade of
        // files the operator controls.
        self::assertStringNotContainsString('integrity=', $body);
    }

    #[Test]
    public function a_partial_local_copy_falls_back_to_the_cdn(): void
    {
        // Half an install would otherwise produce a page that loads no styles
        // or, worse, no script at all.
        $directory = $this->withLocalAssets(['swagger-ui.css' => 'body{}']);

        $assets = new SwaggerUiAssets($directory);

        self::assertFalse($assets->isLocal());
        self::assertStringContainsString('jsdelivr', $assets->scriptUrl());
    }

    /**
     * A document root holding a local copy of the assets, cleaned up with the
     * test.
     *
     * @param array<string, string> $files
     */
    private function withLocalAssets(array $files = ['swagger-ui.css' => 'body{}', 'swagger-ui-bundle.js' => 'void 0;']): string
    {
        $directory = sys_get_temp_dir() . '/ddns-ui-' . bin2hex(random_bytes(6));
        mkdir($directory . '/vendor/swagger-ui', 0700, true);

        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/vendor/swagger-ui/' . $name, $contents);
        }

        $this->assetDirectories[] = $directory;

        return $directory;
    }

    protected function tearDown(): void
    {
        foreach ($this->assetDirectories as $directory) {
            foreach ((array) scandir($directory . '/vendor/swagger-ui') as $entry) {
                if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                    unlink($directory . '/vendor/swagger-ui/' . $entry);
                }
            }

            rmdir($directory . '/vendor/swagger-ui');
            rmdir($directory . '/vendor');
            rmdir($directory);
        }

        $this->assetDirectories = [];

        parent::tearDown();
    }

    #[Test]
    public function locally_installed_assets_are_served_by_the_application(): void
    {
        // PHP's built-in server hands every request to the router script, so
        // without a route these would 404 in the Docker image and in the quick
        // start - the setups most likely to be trying them.
        $this->assetDirectory = $this->withLocalAssets();

        $css = $this->request('GET', '/vendor/swagger-ui/swagger-ui.css');
        $js = $this->request('GET', '/vendor/swagger-ui/swagger-ui-bundle.js');

        self::assertSame(200, $css->getStatusCode());
        self::assertSame('text/css', $css->getHeaderLine('Content-Type'));
        self::assertSame('body{}', $this->body($css));

        self::assertSame(200, $js->getStatusCode());
        self::assertSame('text/javascript', $js->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function the_asset_route_serves_nothing_but_the_two_files(): void
    {
        // The name is matched against a fixed list rather than joined onto a
        // path, so there is no traversal to defend against - and no way to
        // reach public/.htaccess, which a naive passthrough would disclose.
        $this->assetDirectory = $this->withLocalAssets();

        foreach (['../../.htaccess', '.htaccess', 'index.php', 'swagger-ui.js.map'] as $name) {
            self::assertSame(
                404,
                $this->request('GET', '/vendor/swagger-ui/' . $name)->getStatusCode(),
                sprintf('%s should not be servable.', $name),
            );
        }
    }

    #[Test]
    public function the_asset_route_reports_a_missing_file(): void
    {
        // Nothing installed: the page is using the CDN, and these paths should
        // not pretend otherwise.
        self::assertSame(404, $this->request('GET', '/vendor/swagger-ui/swagger-ui.css')->getStatusCode());
    }

    #[Test]
    public function an_unknown_endpoint_points_at_the_documentation(): void
    {
        $response = $this->request('GET', '/nope');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('/api', $this->atString($response, 'error.message'));
    }
}
