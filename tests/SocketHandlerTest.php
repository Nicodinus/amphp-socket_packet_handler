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
     * @return \Generator
     *
     * @throws \Throwable
     */
    public function testSocketHandler1(): \Generator
    {
        $serverSocket = Server::listen("127.0.0.1:0");
        $testReadyDefer = new Deferred();

        $sendString = \random_bytes(128);

        asyncCall(function () use (&$serverSocket, &$testReadyDefer) {

            try {

                while (!$serverSocket->isClosed()) {

                    $socket = yield $serverSocket->accept();
                    if (!$socket) {
                        continue;
                    }

                    new class ($socket, $testReadyDefer) extends AbstractSocketHandler {
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
                    };

                    yield $testReadyDefer->promise();

                }

            } catch (\Throwable $exception) {
                $testReadyDefer->fail($exception);
            }

        });

        try {

            $client = yield Socket\connect($serverSocket->getAddress());
            try {

                yield $client->write($sendString);

                $result = yield $testReadyDefer->promise();

                $this->assertSame($sendString, $result);

            } finally {
                $client->close();
            }

        } finally {
            $serverSocket->close();
        }

    }
}