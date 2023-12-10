<?php

namespace Nicodinus\SocketPacketHandler;

abstract class AbstractPacket implements PacketInterface
{
    /** @var AbstractPacketHandler */
    protected AbstractPacketHandler $packetHandler;

    /** @var mixed|null */
    protected $data;

    /** @var string|null */
    private ?string $requestId;

    //

    /**
     * @param AbstractPacketHandler $packetHandler
     * @param mixed|null $data
     * @param string|null $requestId
     */
    public function __construct(AbstractPacketHandler $packetHandler, $data = null, ?string $requestId = null)
    {
        $this->packetHandler = $packetHandler;
        $this->data = $data;
        $this->requestId = $requestId;
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return string|null
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}