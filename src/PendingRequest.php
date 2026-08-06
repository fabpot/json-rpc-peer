<?php

/*
 * This file is part of the fabpot/json-rpc-peer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\JsonRpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;

/** @internal */
final class PendingRequest
{
    /** @var DeferredFuture<mixed> */
    private readonly DeferredFuture $deferred;
    private ?string $cancellationSubscription = null;

    public function __construct(
        private readonly ?Cancellation $cancellation,
    ) {
        $this->deferred = new DeferredFuture();
    }

    /**
     * @param \Closure(CancelledException): void $onCancelled
     */
    public function subscribeToCancellation(\Closure $onCancelled): void
    {
        if (null === $this->cancellation) {
            return;
        }

        $subscription = $this->cancellation->subscribe($onCancelled);
        if ($this->deferred->isComplete()) {
            $this->cancellation->unsubscribe($subscription);

            return;
        }

        $this->cancellationSubscription = $subscription;
    }

    /** @return Future<mixed> */
    public function getFuture(): Future
    {
        return $this->deferred->getFuture();
    }

    public function complete(mixed $value): void
    {
        $this->unsubscribe();
        $this->deferred->complete($value);
    }

    public function error(\Throwable $error): void
    {
        $this->unsubscribe();
        $this->deferred->error($error);
    }

    public function abandon(\Throwable $error): void
    {
        $this->unsubscribe();
        if (!$this->deferred->isComplete()) {
            $this->deferred->error($error);
        }
        $this->deferred->getFuture()->ignore();
    }

    private function unsubscribe(): void
    {
        if (null === $this->cancellationSubscription) {
            return;
        }

        $this->cancellation?->unsubscribe($this->cancellationSubscription);
        $this->cancellationSubscription = null;
    }
}
