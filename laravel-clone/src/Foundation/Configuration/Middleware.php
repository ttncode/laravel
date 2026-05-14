<?php

namespace Framework\Foundation\Configuration;

class Middleware
{
    protected array $globalMiddleware = [];

    /**
     * Append a global middleware to the stack.
     */
    public function append(string $middleware): static
    {
        $this->globalMiddleware[] = $middleware;
        return $this;
    }

    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }
}
