<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\Coroutine;
use Amp\Promise;

interface CanHandlePacket
{
    /**
     * @return callable|\Generator|Coroutine|Promise|mixed
     */
    public function handle();
}