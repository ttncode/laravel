<?php

namespace Framework\Http;

class Response
{
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        protected array $headers = [],
    ) {}

    /**
     * Set HTTP content.
     *
     * @param  mixed  $content
     * @return $this
     */
    public function setContent(mixed $content): static
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Set HTTP status code.
     *
     * @param  int  $status
     * @return $this
     */
    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Send the HTTP response to the browser.
     */
    public function send(): void
    {
        $this->sendHeaders();
        $this->sendContent();
    }

    /**
     * Send HTTP headers.
     */
    protected function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}", true, $this->status);
        }
    }

    /**
     * Send HTTP content.
     */
    protected function sendContent(): void
    {
        echo $this->content;
    }
}
