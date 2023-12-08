<?php

namespace Nicodinus\SocketPacketHandler;

interface HasPacketHandlerCreate
{
    /**
     * @param AbstractPacketHandler $packetHandler
     * @param mixed|null $data
     *
     * @return PacketInterface
     */
    public static function create(AbstractPacketHandler $packetHandler, $data = null): PacketInterface;
}