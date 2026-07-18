<?php

/*
 * This file is part of the Symfony\Component package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Agent\Acp\JsonRpc;

/**
 * A parsed inbound JSON-RPC 2.0 message.
 *
 * A message with a method is a request (when it also has an id) or a
 * notification (when it does not). Inbound responses are not modeled here.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class JsonRpcMessage
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly int|string|null $id,
        public readonly ?string $method,
        public readonly array $params,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $method = isset($data['method']) && \is_string($data['method']) ? $data['method'] : null;
        $params = $data['params'] ?? [];

        return new self(
            \is_int($id) || \is_string($id) ? $id : null,
            $method,
            \is_array($params) ? $params : [],
        );
    }

    /**
     * A request expects a response; a notification does not.
     */
    public function isNotification(): bool
    {
        return null === $this->id;
    }
}
