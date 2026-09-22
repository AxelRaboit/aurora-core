<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

/**
 * The content type of a stored object, read off its key.
 *
 * Object storage answers a listing with a key, a size and a date, and not with
 * the type the bytes were stored under: asking for that is a second billed
 * request per object. The extension is what is left, and it is what the file
 * was named by the code that stored it, so it is not a guess about the bytes
 * so much as a record of them.
 *
 * Wrong beats absent. A response with no type at all is `text/html` by
 * default, and a browser sniffs its way out of that for a JPEG or a PNG but
 * never for an SVG - vector images are deliberately outside the sniffing
 * rules, because sniffing markup is how a document becomes script. So the one
 * kind of file that most needs its type declared is the one that silently
 * fails without it.
 */
final readonly class StoredContentType
{
    public static function forKey(string $key): string
    {
        return match (mb_strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'json' => 'application/json',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
