<?php

namespace Nicodinus\SocketPacketHandler;

interface PacketInterface
{
    /**
     * @return string|null
     */
    public static function getId(): ?string;

    /**
     * @return mixed
     */
    public function getData();
}