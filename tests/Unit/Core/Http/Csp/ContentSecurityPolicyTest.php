<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Http\Csp;

use Aurora\Core\Content\RawHtmlSanitizer;
use Aurora\Core\Http\Csp\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;

use function explode;
use function sprintf;

/**
 * The third wall, and the two ways it gets quietly demolished.
 *
 * A policy is easy to add and easy to hollow out: somebody hits a blocked
 * script, reaches for `unsafe-inline`, and the header stays in place saying
 * nothing. These cases are the ones that would notice.
 */
final class ContentSecurityPolicyTest extends TestCase
{
    /**
     * The regression this file exists for.
     *
     * Either keyword in `script-src` gives back most of what the policy was
     * written for: `unsafe-inline` lets an injected `<script>` run, and
     * `unsafe-eval` lets a string become code. Aurora needs neither - the
     * handful of scripts that must be inline carry a nonce, and the one
     * `new Function` in the codebase was replaced by an arithmetic evaluator
     * for exactly this reason.
     */
    public function testScriptSrcNeverAdmitsInlineOrEval(): void
    {
        foreach ([true, false] as $devMode) {
            $scriptSrc = $this->directive(new ContentSecurityPolicy($devMode)->header('abc'), 'script-src');

            self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc, sprintf('devMode=%s', var_export($devMode, true)));
            self::assertStringNotContainsString("'unsafe-eval'", $scriptSrc, sprintf('devMode=%s', var_export($devMode, true)));
        }
    }

    public function testTheNonceReachesScriptSrc(): void
    {
        $header = new ContentSecurityPolicy()->header('deadbeef');

        self::assertStringContainsString("'nonce-deadbeef'", $this->directive($header, 'script-src'));
    }

    /**
     * A page with nothing inline advertises no nonce.
     *
     * Not cosmetic: a policy listing a nonce that nothing used reads as though
     * a script were missing, and sends the next reader looking for it.
     */
    public function testNoNonceIsAdvertisedWhenNothingAskedForOne(): void
    {
        $header = new ContentSecurityPolicy()->header(null);

        self::assertStringNotContainsString('nonce-', $header);
    }

    /**
     * `style-src` keeps `unsafe-inline`, and must not gain a nonce.
     *
     * Vue injects a component's styles from JavaScript at runtime and an
     * injected tag carries no nonce. Worse, a nonce present in `style-src`
     * makes browsers ignore `unsafe-inline` altogether - so adding one there,
     * which looks like tightening, would break every styled component.
     */
    public function testStyleSrcAllowsInlineAndCarriesNoNonce(): void
    {
        $styleSrc = $this->directive(new ContentSecurityPolicy()->header('abc'), 'style-src');

        self::assertStringContainsString("'unsafe-inline'", $styleSrc);
        self::assertStringNotContainsString('nonce-', $styleSrc);
    }

    /**
     * One embed list, two enforcement points.
     *
     * The sanitiser strips an `<iframe>` whose host is not on the list when
     * content is saved; this stops the browser loading one that got past it.
     * Two lists would drift, and the drift would show as an embed that saves
     * and then refuses to display.
     */
    public function testFrameSrcFollowsTheSanitisersOwnList(): void
    {
        $frameSrc = $this->directive(new ContentSecurityPolicy()->header(null), 'frame-src');

        self::assertStringContainsString("'self'", $frameSrc);

        foreach (RawHtmlSanitizer::IFRAME_HOSTS as $host) {
            self::assertStringContainsString('https://'.$host, $frameSrc);
        }
    }

    /**
     * The development origins are development's alone.
     *
     * Vite serves modules from its own port and keeps a socket open for hot
     * reload. Neither exists in a build, and a production policy naming
     * localhost is a policy somebody stopped reading.
     */
    public function testTheViteOriginIsAbsentFromAProductionPolicy(): void
    {
        $production = new ContentSecurityPolicy(false)->header('abc');

        self::assertStringNotContainsString('localhost:5173', $production);
        self::assertStringNotContainsString('ws://', $production);

        $development = new ContentSecurityPolicy(true)->header('abc');

        self::assertStringContainsString('http://localhost:5173', $this->directive($development, 'script-src'));
        self::assertStringContainsString('ws://localhost:5173', $this->directive($development, 'connect-src'));
    }

    /**
     * A configured check that the policy refuses fails every submission.
     *
     * Measured on 2026-09-25: Turnstile was switched on with valid keys, its
     * script was blocked here, the widget never drew, and the contact form
     * could not send a token the server would accept.
     */
    public function testTheCaptchaScriptAndFrameAreAllowed(): void
    {
        $header = new ContentSecurityPolicy()->header('abc');

        self::assertStringContainsString('https://challenges.cloudflare.com', $this->directive($header, 'script-src'));
        self::assertStringContainsString('https://challenges.cloudflare.com', $this->directive($header, 'frame-src'));
        self::assertStringContainsString('https://www.gstatic.com', $this->directive($header, 'script-src'));
    }

    public function testThePageCannotBeFramedAndHasNoPluginSurface(): void
    {
        $header = new ContentSecurityPolicy()->header(null);

        self::assertStringContainsString("frame-ancestors 'none'", $header);
        self::assertStringContainsString("object-src 'none'", $header);
        self::assertStringContainsString("base-uri 'self'", $header);
        self::assertStringContainsString("form-action 'self'", $header);
    }

    private function directive(string $header, string $name): string
    {
        foreach (explode('; ', $header) as $directive) {
            if (str_starts_with($directive, $name.' ')) {
                return $directive;
            }
        }

        self::fail(sprintf('The policy carries no "%s" directive: %s', $name, $header));
    }
}
