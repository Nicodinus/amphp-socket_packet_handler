<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Coroutine;
use Amp\Promise;
use Amp\Serialization\SerializationException;
use function Amp\call;

abstract class AbstractPacketHandler extends AbstractSocketHandler
{
    /** @var class-string<PacketInterface>[] */
    private array $registry = [];

    //

    /**
     * @param RequestPacketInterface $packet
     *
     * @return Promise<int>
     *
     * @throws SerializationException
     * @throws ClosedException
     * @throws StreamException
     */
    public function sendPacket(RequestPacketInterface $packet): Promise
    {
        return $this->send($this->_serializePacket($packet));
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

            try {

                $packet = $this->_unserializePacket($data);
                if (!$packet) {
                    return null;
                }

                $packetClassname = $this->findPacket($packet['id']);
                if (!$packetClassname) {
                    return null;
                }

                $packet = $this->_createPacket($packetClassname, $packet['data'] ?? null);
                if (!$packet) {
                    throw new \RuntimeException("Can't create packet {$packetClassname}");
                }

                if (\is_a($packet, CanHandlePacket::class, true)) {
                    yield call(\Closure::fromCallable([&$packet, 'handle']));
                }

                yield call(\Closure::fromCallable([&$this, '_handlePacket']), $packet);

            } catch (\Throwable $exception) {
                $this->_handleException($exception);
            }

        });
    }

    /**
     * @param class-string<PacketInterface> $packetClassname
     * @param mixed $data
     *
     * @return PacketInterface|null
     */
    protected function _createPacket(string $packetClassname, $data): ?PacketInterface
    {
        if (\is_a($packetClassname, HasPacketHandlerCreate::class, true)) {
            return $packetClassname::create($this, $data);
        }

        return null;
    }

    /**
     * @return callable|\Generator|Coroutine|Promise|mixed
     */
    protected abstract function _handlePacket(PacketInterface $packet);

    /**
     * @param string $data
     *
     * @return array{id: string, data: mixed|null}|null
     *
     * @throws SerializationException
     */
    protected abstract function _unserializePacket(string $data): ?array;

    /**
     * @param PacketInterface $packet
     *
     * @return string
     *
     * @throws SerializationException
     */
    protected abstract function _serializePacket(PacketInterface $packet): string;

    /**
     * @param \Throwable $throwable
     *
     * @return void
     */
    protected abstract function _handleException(\Throwable $throwable): void;
}