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
    /** @var Socket\Socket */
    private Socket\Socket $socket;

    /** @var array<array{data: string, defer: Deferred}> */
    private array $sendQueue;

    //

    /**
     * @param Socket\Socket $socket
     */
    public function __construct(Socket\Socket $socket)
    {
        $this->socket = $socket;
        $this->sendQueue = [];

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
        if ($this->socket->isClosed()) {
            throw new ClosedException();
        }

        //return $this->socket->write($data);

        $defer = new Deferred();
        $this->sendQueue[] = [
            'data' => $data,
            'defer' => $defer,
        ];

        asyncCall(function () {

            while (!$this->isClosed() && \sizeof($this->sendQueue) > 0) {

                /** @var array{data: string, defer: Deferred} $item */
                $item = \array_shift($this->sendQueue);

                try {
                    $item['defer']->resolve(yield $this->socket->write($item['data']));
                } catch (\Throwable $exception) {
                    $item['defer']->fail($exception);
                }

                yield delay(10);

            }

            $exception = new ClosedException();
            foreach ($this->sendQueue as $item) {
                $item['defer']->fail($exception);
            }
            $this->sendQueue = [];

        });

        return $defer->promise();
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