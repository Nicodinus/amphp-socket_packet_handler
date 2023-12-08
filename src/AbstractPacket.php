<?php

namespace Nicodinus\SocketPacketHandler;

abstract class AbstractPacket implements PacketInterface, HasPacketHandlerCreate
{
    /** @var AbstractPacketHandler */
    protected AbstractPacketHandler $packetHandler;

    /** @var mixed */
    protected $data;

    //

    /**
     * @param AbstractPacketHandler $packetHandler
     * @param array $data
     *
     * @return self
     */
    public static function create(AbstractPacketHandler $packetHandler, $data = null): self
    {
        $instance = new static();
        $instance->packetHandler = $packetHandler;
        $instance->data = $data;
        return $instance;
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        return $this->data;
    }
}