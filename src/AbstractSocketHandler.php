<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Promise;
use Amp\Socket;
use function Amp\asyncCall;
use function Amp\call;
use function Amp\delay;

abstract class AbstractSocketHandler
{
    const PACKET_HEADER = [
        1, 0, 3, 0, 3, 0, 7, 0,
    ];
    const PACKET_FOOTER = [
        4, 0, 6, 0, 5, 0, 9, 0,
    ];

    /** @var int */
    const PACKET_MAX_SIZE = 4 * 1000 * 1000;

    /** @var Socket\Socket */
    private Socket\Socket $socket;

    /** @var string */
    private string $packetHeader;

    /** @var string */
    private string $packetFooter;

    //

    /**
     * @param Socket\Socket $socket
     */
    public function __construct(Socket\Socket $socket)
    {
        $this->socket = $socket;

        $this->packetHeader = "";
        foreach (self::PACKET_HEADER as $v) {
            if (\is_string($v)) {
                $this->packetHeader .= $v;
            } else if (\is_integer($v)) {
                $this->packetHeader .= \chr($v);
            } else {
                throw new \InvalidArgumentException("Unsupported type for PACKET_HEADER!");
            }
        }

        $this->packetFooter = "";
        foreach (self::PACKET_FOOTER as $v) {
            if (\is_string($v)) {
                $this->packetFooter .= $v;
            } else if (\is_integer($v)) {
                $this->packetFooter .= \chr($v);
            } else {
                throw new \InvalidArgumentException("Unsupported type for PACKET_FOOTER!");
            }
        }

        asyncCall(function () {

            $_handleCallable = \Closure::fromCallable([&$this, '_handle']);

            try {

                $data = "";
                $headerPos = false;

                while (!$this->socket->isClosed()) {

                    $chunk = yield $this->socket->read();
                    if (!$chunk) {
                        continue;
                    }

                    if (\strlen($chunk) + \strlen($data) > self::PACKET_MAX_SIZE) {
                        throw new \RuntimeException("Processed data length is greater than limit " . self::PACKET_MAX_SIZE);
                    }
                    $data .= $chunk;

                    while (true) {

                        if ($headerPos === false) {

                            $headerPos = \strpos($data, $this->packetHeader);
                            if ($headerPos === false) {
                                $data = "";
                                continue 2;
                            }

                        }

                        $footerPos = \strpos($data, $this->packetFooter, $headerPos + \strlen($this->packetHeader));
                        if ($footerPos === false) {
                            continue 2;
                        }

                        $packet = \substr($data, $headerPos + \strlen($this->packetHeader), $footerPos - $headerPos - \strlen($this->packetFooter));
                        $data = \substr($data, $footerPos + \strlen($this->packetFooter));
                        $headerPos = false;

                        yield call($_handleCallable, $packet);

                    }

                }

            } catch (\Throwable $exception) {
                $this->_handleException($exception);
            } finally {
                $this->_onClosed();
            }

        });
    }

    /**
     * @param string $data
     *
     * @return Promise<int>
     *
     * @throws ClosedException
     * @throws StreamException
     */
    public function send(string $data): Promise
    {
        return $this->socket->write( $this->packetHeader . $data . $this->packetFooter);
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->socket->close();
    }

    /**
     * @return Socket\SocketAddress
     */
    public function getRemoteAddress(): Socket\SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    /**
     * @param \Throwable $throwable
     *
     * @return void
     */
    protected abstract function _handleException(\Throwable $throwable): void;

    /**
     * @return void
     */
    protected abstract function _onClosed(): void;

    /**
     * @param string $data
     *
     * @return callable|\Generator|Coroutine|Promise|mixed
     */
    protected abstract function _handle(string $data);
}