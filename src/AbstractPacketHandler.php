<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Serialization\SerializationException;
use Amp\TimeoutException;
use function Amp\call;

abstract class AbstractPacketHandler extends AbstractSocketHandler
{
    /** @var class-string<PacketInterface>[] */
    private array $registry = [];

    /** @var array<string, array{defer: Deferred, timeout_watcher: string|null}> */
    private array $requestDefers = [];

    //

    /**
     * @param int $responseTimeoutSeconds
     *
     * @return string
     *
     * @throws \Throwable
     */
    protected function _registerResponseDefer(int $responseTimeoutSeconds): string
    {
        do {
            $requestId = \bin2hex(\random_bytes(8));
        } while (isset($this->requestDefers[$requestId]));

        $defer = new Deferred();
        $timeoutWatcher = null;

        if ($responseTimeoutSeconds > 0) {
            $timeoutWatcher = Loop::delay($responseTimeoutSeconds * 1000, function () use (&$defer, &$requestId) {

                unset($this->requestDefers[$requestId]);

                if (!$defer->isResolved()) {
                    $defer->fail(new TimeoutException());
                }

            });
        }

        $this->requestDefers[$requestId] = [
            'defer' => $defer,
            'timeout_watcher' => $timeoutWatcher,
        ];

        return $requestId;
    }

    /**
     * @param string $requestId
     *
     * @return Deferred|null
     */
    protected function _getResponsePromise(string $requestId): ?Promise
    {
        if (!isset($this->requestDefers[$requestId])) {
            return null;
        }

        return $this->requestDefers[$requestId]['defer']->promise();
    }

    /**
     * @param string $requestId
     * @param mixed|null $value
     * @param \Throwable|null $throwable
     *
     * @return void
     */
    protected function _resolveResponse(string $requestId, $value = null, ?\Throwable $throwable = null): void
    {
        if (!isset($this->requestDefers[$requestId])) {
            return;
        }

        if ($this->requestDefers[$requestId]['timeout_watcher']) {
            Loop::cancel($this->requestDefers[$requestId]['timeout_watcher']);
        }

        $defer = $this->requestDefers[$requestId]['defer'];
        unset($this->requestDefers[$requestId]);

        if (!$throwable) {
            $defer->resolve($value);
        } else {
            $defer->fail($throwable);
        }
    }

    /**
     * @param RequestPacketInterface $packet
     * @param int $responseTimeoutSeconds Value less than 1 equals infinity timeout
     *
     * @return Promise<PacketInterface|null>
     */
    public function sendPacketWithResponse(RequestPacketInterface $packet, int $responseTimeoutSeconds = 5): Promise
    {
        return call(function () use (&$packet, &$responseTimeoutSeconds) {

            $requestId = $this->_registerResponseDefer($responseTimeoutSeconds);

            yield $this->send($this->_serializePacket($packet::getId(), $requestId, $packet->getData()));

            $responsePromise = $this->_getResponsePromise($requestId);
            if (!$responsePromise) {
                throw new \RuntimeException("Can't wait response packet, empty defer!");
            }

            return $responsePromise->promise();

        });
    }

    /**
     * @param RequestPacketInterface $packet
     *
     * @return Promise<int>
     *
     * @throws ClosedException
     * @throws StreamException
     * @throws SerializationException
     */
    public function sendPacket(RequestPacketInterface $packet): Promise
    {
        return $this->send($this->_serializePacket($packet::getId(), null, $packet->getData()));
    }

    /**
     * @param class-string<PacketInterface> $packetClassname
     *
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public function registerPacket(string $packetClassname): self
    {
        if (!\is_a($packetClassname, PacketInterface::class, true)) {
            throw new \InvalidArgumentException("{$packetClassname} is not instance of " . PacketInterface::class);
        }

        $packetId = $packetClassname::getId() ?? $packetClassname;
        if (isset($this->registry[$packetId])) {
            throw new \InvalidArgumentException("{$packetClassname} already registered");
        }

        $this->registry[$packetId] = $packetClassname;
        return $this;
    }

    /**
     * @param class-string<PacketInterface>|string $packet Packet class-string or packet id
     *
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public function unregisterPacket(string $packet): self
    {
        if (\is_a($packet, PacketInterface::class, true)) {
            $packet = $packet::getId() ?? $packet;
        }
        unset($this->registry[$packet]);

        return $this;
    }

    /**
     * @param string $packetId
     *
     * @return class-string<PacketInterface>|null
     */
    public function findPacket(string $packetId): ?string
    {
        return $this->registry[$packetId] ?? null;
    }

    /**
     * @inheritDoc
     */
    protected function _handle(string $data)
    {
        return call(function () use (&$data) {

            $requestId = null;

            try {

                $packet = $this->_unserializePacket($data);
                if (!$packet) {
                    return null;
                }

                $packetClassname = $this->findPacket($packet['id']);
                if (!$packetClassname) {
                    return null;
                }

                $requestId = $packet['request_id'] ?? null;

                $packet = $this->_createPacket($packetClassname, $requestId, $packet['data'] ?? null);
                if (!$packet) {
                    throw new \RuntimeException("Can't create packet {$packetClassname}");
                }

                if (\is_a($packet, CanHandlePacket::class, true)) {
                    yield call(\Closure::fromCallable([&$packet, 'handle']), $requestId);
                }

                yield call(\Closure::fromCallable([&$this, '_handlePacket']), $packet, $requestId);

                if ($requestId) {
                    $this->_resolveResponse($requestId, $packet);
                }

            } catch (\Throwable $exception) {

                if ($requestId) {
                    $this->_resolveResponse($requestId, null, $exception);
                }

                $this->_handleException($exception);

            }

        });
    }

    /**
     * @param class-string<PacketInterface> $packetClassname
     * @param string|null $requestId
     * @param mixed|null $data
     *
     * @return PacketInterface|null
     */
    protected abstract function _createPacket(string $packetClassname, ?string $requestId = null, $data = null): ?PacketInterface;

    /**
     * @param PacketInterface $packet
     * @param string|null $requestId
     *
     * @return callable|\Generator|Coroutine|Promise|mixed
     */
    protected abstract function _handlePacket(PacketInterface $packet, ?string $requestId = null);

    /**
     * @param string $data
     *
     * @return array{id: string, request_id: string|null, data: mixed|null}|null
     *
     * @throws SerializationException
     */
    protected abstract function _unserializePacket(string $data): ?array;

    /**
     * @param string $id
     * @param string|null $requestId
     * @param mixed|null $data
     *
     * @return string
     *
     * @throws SerializationException
     */
    protected abstract function _serializePacket(string $id, ?string $requestId = null, $data = null): string;
}