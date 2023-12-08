<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Promise;
use Amp\Serialization\SerializationException;

interface CanSendPacket
{
    /**
     * @return Promise<int>
     *
     * @throws ClosedException
     * @throws StreamException
     * @throws SerializationException
     */
    public function send(): Promise;
}