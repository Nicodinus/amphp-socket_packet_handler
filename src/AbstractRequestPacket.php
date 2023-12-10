<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\Promise;

abstract class AbstractRequestPacket extends AbstractPacket implements RequestPacketInterface, CanSendPacket
{
    /**
     * @inheritDoc
     */
    public function send(): Promise
    {
        return $this->packetHandler->sendPacket($this);
    }

    /**
     * @inheritDoc
     */
    public function sendWaitResponse(int $responseTimeoutSeconds = 5): Promise
    {
        return $this->packetHandler->sendPacketWithResponse($this, $responseTimeoutSeconds);
    }
}