<?php

namespace Nicodinus\SocketPacketHandler\Tests;

use Amp\Deferred;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\Server;
use Nicodinus\SocketPacketHandler\AbstractSocketHandler;
use function Amp\asyncCall;

class SocketHandlerTest extends AsyncTestCase
{
    /**
     * @param Socket\Socket $socket
     * @param Deferred $deferred
     *
     * @return AbstractSocketHandler
     */
    protected function createSocketHandler(Socket\Socket $socket, Deferred $deferred): AbstractSocketHandler
    {
        return new class ($socket, $deferred) extends AbstractSocketHandler {
            /** @var Deferred */
            private Deferred $testReadyDefer;

            //

            public function __construct(Socket\Socket $socket, Deferred $testReadyDefer)
            {
                parent::__construct($socket);

                $this->testReadyDefer = $testReadyDefer;
            }

            /**
             * @inheritDoc
             */
            protected function _handle(string $data)
            {
                $this->testReadyDefer->resolve($data);
            }

            /**
             * @inheritDoc
             */
            protected function _handleException(\Throwable $throwable): void
            {
                //
            }

            /**
             * @inheritDoc
             */
            protected function _onClosed(): void
            {
                //
            }

        };
    }

    /**
     * @return \Generator
     *
     * @throws \Throwable
     */
    public function testSocketHandler1(): \Generator
    {
        $this->setTimeout(1000);

        $serverSocket = Server::listen("127.0.0.1:0");
        $serversideDefer = new Deferred();
        $clientsideDefer = new Deferred();

        $sendString = \random_bytes(128);

        asyncCall(function () use (&$serverSocket, &$serversideDefer) {

            try {

                while (!$serverSocket->isClosed()) {

                    $socket = yield $serverSocket->accept();
                    if (!$socket) {
                        continue;
                    }

                    $this->createSocketHandler($socket, $serversideDefer);

                    yield $serversideDefer->promise();

                }

            } catch (\Throwable $exception) {
                $serversideDefer->fail($exception);
            }

        });

        try {

            $client = yield Socket\connect($serverSocket->getAddress());
            $clientHandler = $this->createSocketHandler($client, $clientsideDefer);

            try {

                yield $clientHandler->send($sendString);

                $result = yield $serversideDefer->promise();

                $this->assertSame($sendString, $result);

            } finally {
                $client->close();
            }

        } finally {
            $serverSocket->close();
        }

    }
}