<?php

namespace Nicodinus\SocketPacketHandler;

abstract class AbstractPacket implements PacketInterface
{
    /** @var AbstractPacketHandler */
    protected AbstractPacketHandler $packetHandler;

    /** @var mixed|null */
    protected $data;

    //

    /**
     * @param AbstractPacketHandler $packetHandler
     * @param mixed|null $data
     */
    public function __construct(AbstractPacketHandler $packetHandler, $data = null)
    {
        $this->packetHandler = $packetHandler;
        $this->data = $data;
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        return $this->data;
    }
}