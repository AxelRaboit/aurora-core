<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Concern;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Gives back a test its full allowance on a rate-limited route.
 *
 * The limiters keep their counters in `cache.rate_limiter`, which is a
 * filesystem pool: they outlive the process. A suite that posts a handful of
 * times per run therefore spends a shared hourly budget, and starts failing on
 * the second or third run of the hour - measured on `FormCaptchaTest`, which
 * posts three times and went red from the third consecutive run.
 *
 * The failure looks nothing like its cause: a 429 answered by a route the test
 * never meant to exercise, on a request that would have been fine in
 * production. And because the allowance is shared per address, an unrelated
 * test resetting it can hide the problem in a full-suite run while the file on
 * its own stays red - which is exactly what was happening here.
 *
 * Requires the consuming class to expose the container via
 * {@see WebTestCase::getContainer()}.
 */
trait ResetsRateLimiters
{
    /** What BrowserKit presents as the client address. */
    protected const string LIMITER_CLIENT_IP = '127.0.0.1';

    /**
     * @param string $name the limiter's configured name, e.g. `form_submission`
     *                     for `framework.rate_limiter.form_submission`
     */
    protected function resetRateLimiter(string $name, string $key = self::LIMITER_CLIENT_IP): void
    {
        $factory = static::getContainer()->get('limiter.'.$name);

        if (!$factory instanceof RateLimiterFactoryInterface) {
            self::fail(sprintf('No rate limiter named "%s" is configured.', $name));
        }

        $factory->create($key)->reset();
    }
}
