<?php

namespace Framework\Http;

class Request
{
    public function __construct(
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $files,
        public readonly array $cookies,
    ) {}

    /**
     * Capture the current HTTP request.
     * 
     * @return static
     */
    public static function capture(): static
    {
        return new static($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    /**
     * Get the HTTP method.
     * 
     * @return string
     */
    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Get the request URI without the query string.
     *
     * @return string
     */
    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $position = strpos($uri, '?');

        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }

        return $uri !== '/' ? rtrim($uri) : '/';
    }

    /**
     * Get an input value from the request.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Get header value from request.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function header(string $key, mixed $default = null): mixed
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));

        return $this->server[$serverKey] ?? $default;
    }
}
