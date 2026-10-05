<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\R2;

use SensitiveParameter;

/**
 * What it takes to talk to one R2 bucket.
 *
 * A value object rather than five constructor arguments passed around,
 * because in phase 3 these stop coming from the environment and start coming
 * from the settings table, and the day that happens only
 * {@see R2ConfigurationProviderInterface} should have to change.
 *
 * `publicBaseUrl` is the odd one out: optional, and not a credential. It is
 * the hostname an administrator mapped onto the bucket, when they mapped one.
 * Without it the adapter simply has no public URL to offer.
 */
final readonly class R2Configuration
{
    /** R2 issues a 32-character access key id; anything else is a wrong paste. */
    private const int ACCESS_KEY_LENGTH = 32;

    /** And a 64-character secret alongside it. */
    private const int SECRET_KEY_LENGTH = 64;

    public function __construct(
        public string $endpoint,
        public string $bucket,
        #[SensitiveParameter]
        public string $accessKeyId,
        #[SensitiveParameter]
        public string $secretAccessKey,
        public ?string $publicBaseUrl = null,
    ) {}

    public static function empty(): self
    {
        return new self('', '', '', '');
    }

    /**
     * The endpoint with the bucket taken back off, when it was pasted on.
     *
     * Cloudflare's console shows an "S3 API" address ending in the bucket
     * name, and copying that whole string is the single most likely way to
     * configure this screen wrong: the bucket then appears twice in every
     * request and the error that comes back talks about neither. The field's
     * own hint has said so since the screen was written, and the mistake still
     * happened - a sentence under an input catches nothing, so the value is
     * corrected instead of merely described.
     *
     * Only a trailing segment is removed, and only when it is exactly the
     * bucket name. A bucket that legitimately shares a name with a path
     * segment earlier in the URL is left alone.
     */
    public function withNormalisedEndpoint(): self
    {
        $endpoint = mb_rtrim(mb_trim($this->endpoint), '/');
        $bucket = mb_trim($this->bucket);
        $suffix = '/'.$bucket;

        if ('' !== $bucket && str_ends_with($endpoint, $suffix)) {
            $endpoint = mb_rtrim(mb_substr($endpoint, 0, -mb_strlen($suffix)), '/');
        }

        return new self($endpoint, $bucket, $this->accessKeyId, $this->secretAccessKey, $this->publicBaseUrl);
    }

    /**
     * Whether there is enough here to build a client at all.
     *
     * Four values, all required. A configuration missing one of them is not
     * "partly configured", it is off: attempting a call would produce a
     * signature error from Cloudflare that says nothing useful about which
     * field an administrator forgot.
     */
    public function isComplete(): bool
    {
        return !in_array('', [$this->endpoint, $this->bucket, $this->accessKeyId, $this->secretAccessKey], true);
    }

    /**
     * What is wrong with this configuration before anything is sent.
     *
     * Returns translation keys under `suite.settings.storage.errors.`, empty
     * when nothing is obviously wrong. These are the mistakes R2 would reject
     * anyway, and its answer is opaque: a 24-character key comes back as
     * `InvalidArgument` inside a URL, naming neither the field nor the screen.
     * Catching them here means the message names the field.
     *
     * A bucket pasted onto the endpoint is not listed, on purpose. It is
     * repaired by {@see withNormalisedEndpoint()} rather than reported, and
     * every caller normalises before asking - so a problem raised for it could
     * never fire. Refusing what can be fixed would be worse behaviour and
     * worse code.
     *
     * Only shapes that cannot work are listed. Whether a well-formed
     * credential is the right one is not knowable without asking Cloudflare,
     * which is what the connection test is for.
     *
     * @return list<string>
     */
    public function shapeProblems(): array
    {
        $problems = [];

        if ('' !== $this->endpoint && !str_starts_with($this->endpoint, 'https://')) {
            $problems[] = 'endpoint_not_https';
        }

        if ('' !== $this->accessKeyId && self::ACCESS_KEY_LENGTH !== mb_strlen($this->accessKeyId)) {
            $problems[] = 'access_key_length';
        }

        if ('' !== $this->secretAccessKey && self::SECRET_KEY_LENGTH !== mb_strlen($this->secretAccessKey)) {
            $problems[] = 'secret_key_length';
        }

        return $problems;
    }

    /**
     * The fields that are missing, for a message a human can act on.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach ([
            'endpoint' => $this->endpoint,
            'bucket' => $this->bucket,
            'access key id' => $this->accessKeyId,
            'secret access key' => $this->secretAccessKey,
        ] as $label => $value) {
            if ('' === $value) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
