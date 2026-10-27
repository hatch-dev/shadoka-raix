<?php

declare(strict_types=1);

namespace Platform\Core;

/**
 * A response, built then sent once.
 *
 * Returning a Response rather than echoing means a controller can be called
 * from a test or the CLI without producing output as a side effect.
 */
final class Response
{
    private function __construct(
        private string $content,
        private int $status,
        /** @var array<string, string> */
        private array $headers,
    ) {
    }

    public static function make(string $content = '', int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return new self($body, $status, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return self::make($message, 404);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * An ETag for this body, and an empty 304 when the browser already has it.
     *
     * For pages sent `Cache-Control: no-cache`: the browser keeps its copy but
     * asks each time, and an unchanged page costs a few hundred bytes instead
     * of the whole body (the scorecard pages are ~690KB compressed). The tag is
     * the body's md5, so any change to what would be sent is a new tag.
     *
     * Apache's mod_deflate and mod_brotli append -gzip / -br to the ETag on a
     * compressed response, and the browser sends it back that way, so the
     * suffix is dropped before comparing.
     */
    public function revalidatable(Request $request): self
    {
        $etag = '"' . md5($this->content) . '"';
        $this->headers['ETag'] = $etag;

        foreach (explode(',', (string) ($request->server['HTTP_IF_NONE_MATCH'] ?? '')) as $sent) {
            $sent = (string) preg_replace('/-(gzip|br)"$/', '"', trim($sent));

            if ($sent === $etag || $sent === 'W/' . $etag) {
                return new self('', 304, $this->headers);
            }
        }

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }

            // Baseline hardening. The scorecard loads no third-party scripts at
            // runtime, so these can be strict without breaking anything.
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        echo $this->content;
    }
}
