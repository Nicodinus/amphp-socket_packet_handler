<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\Coroutine;
use Amp\Promise;

interface CanHandlePacket
{
    /**
     * @param string|null $requestId
     *
     * @return callable|\Generator|Coroutine|Promise|mixed
     *
     * @throws \Throwable
     */
    public function handle(?string $requestId = null);
}