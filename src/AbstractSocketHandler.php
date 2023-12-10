<?php

namespace Nicodinus\SocketPacketHandler;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Coroutine;
use Amp\Promise;
use Amp\Socket;
use function Amp\asyncCall;
use function Amp\call;

abstract class AbstractSocketHandler
{
    /** @var Socket\Socket */
    private Socket\Socket $socket;

    //

    /**
     * @param Socket\Socket $socket
     */
    public function __construct(Socket\Socket $socket)
    {
        $this->socket = $socket;

        asyncCall(function () {

            $_handleCallable = \Closure::fromCallable([&$this, '_handle']);

            try {

                while (!$this->socket->isClosed()) {

                    $data = yield $this->socket->read();
                    if (!$data) {
                        continue;
                    }

                    yield call($_handleCallable, $data);

                }

                $this->_onClosed();

            } catch (\Throwable $exception) {
                $this->_onClosed($exception);
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
        return $this->socket->write($data);
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
        $this->socket->close();
        $this->_onClosed();
    }

    /**
     * @return Socket\SocketAddress
     */
    public function getRemoteAddress(): Socket\SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    /**
     * @param \Throwable|null $throwable
     *
     * @return void
     */
    protected function _onClosed(?\Throwable $throwable = null): void
    {
        if ($throwable) {
            throw $throwable;
        }
    }

    /**
     * @param string $data
     *
     * @return callable|\Generator|Coroutine|Promise|mixed
     */
    protected abstract function _handle(string $data);
}