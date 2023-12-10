<?php

namespace Nicodinus\SocketPacketHandler\Tests;

use Amp\Emitter;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\Server;
use Nicodinus\SocketPacketHandler\AbstractPacketHandler;
use Nicodinus\SocketPacketHandler\AbstractRequestPacket;
use Nicodinus\SocketPacketHandler\PacketInterface;
use function Amp\asyncCall;

class PacketHandlerTest extends AsyncTestCase
{
    /**
     * @param AbstractPacketHandler $packetHandler
     * @param mixed|null $data
     *
     * @return AbstractRequestPacket
     */
    protected function createRequestPacket1(AbstractPacketHandler $packetHandler, $data = null): AbstractRequestPacket
    {
        return new class ($packetHandler, $data) extends AbstractRequestPacket {
            /**
             * @inheritDoc
             */
            public static function getId(): ?string
            {
                return "test_packet";
            }
        };
    }

    /**
     * @param Socket\Socket $socket
     * @param Emitter $emitter
     *
     * @return AbstractPacketHandler
     */
    protected function createPacketHandler1(Socket\Socket $socket, Emitter $emitter): AbstractPacketHandler
    {
        return new class ($socket, $emitter) extends AbstractPacketHandler {
            /** @var Emitter */
            private Emitter $emitter;

            //

            /**
             * @param Socket\Socket $socket
             * @param Emitter $emitter
             */
            public function __construct(Socket\Socket $socket, Emitter $emitter)
            {
                parent::__construct($socket);

                $this->emitter = $emitter;
            }

            /**
             * @inheritDoc
             */
            protected function _handleException(\Throwable $throwable): void
            {
                $this->emitter->fail($throwable);
            }

            /**
             * @inheritDoc
             */
            protected function _handlePacket(PacketInterface $packet): ?\Generator
            {
                yield $this->emitter->emit($packet);
            }

            /**
             * @inheritDoc
             */
            protected function _unserializePacket(string $data): ?array
            {
                $data = \unserialize($data);
                if (!isset($data['id'])) {
                    return null;
                }

                return [
                    'id' => $data['id'],
                    'request_id' => $data['request_id'] ?? null,
                    'data' => $data['data'] ?? null,
                ];
            }

            /**
             * @inheritDoc
             */
            protected function _serializePacket(string $id, ?string $requestId = null, $data = null): string
            {
                return \serialize([
                    'id' => $id,
                    'request_id' => $requestId,
                    'data' => $data,
                ]);
            }

            /**
             * @inheritDoc
             */
            protected function _createPacket(string $packetClassname, ?string $requestId = null, $data = null): ?PacketInterface
            {
                return new $packetClassname($this, $data);
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
    public function testPacketHandler1(): \Generator
    {
        $serverSocket = Server::listen("127.0.0.1:0");
        $emitter = new Emitter();

        asyncCall(function () use (&$serverSocket, &$emitter) {

            try {

                while (!$serverSocket->isClosed()) {

                    $socket = yield $serverSocket->accept();
                    if (!$socket) {
                        continue;
                    }

                    $packetHandler = $this->createPacketHandler1($socket, $emitter);
                    yield $emitter->emit($packetHandler);

                }

            } catch (\Throwable $exception) {
                $emitter->fail($exception);
            }

        });

        try {

            $client = yield Socket\connect($serverSocket->getAddress());
            try {

                $clientPacketHandler = $this->createPacketHandler1($client, $emitter);
                $serversidePacketHandler = null;

                $sendString = \random_bytes(128);
                $sendPacket = $this->createRequestPacket1($clientPacketHandler, $sendString);

                $clientPacketHandler->registerPacket(\get_class($sendPacket));

                $count = 0;
                $max = 10;

                $iterator = $emitter->iterate();
                while (true === yield $iterator->advance()) {

                    $result = $iterator->getCurrent();
                    if ($result instanceof AbstractPacketHandler) {
                        $serversidePacketHandler = $result;
                        $serversidePacketHandler->registerPacket(\get_class($sendPacket));
                        yield $clientPacketHandler->sendPacket($sendPacket);
                    } else if ($result instanceof PacketInterface) {
                        $this->assertSame($sendString, $result->getData());
                        if ($count++ >= $max) {
                            $emitter->complete();
                        }
                        $sendString = \random_bytes(128);
                        $sendPacket = $this->createRequestPacket1($serversidePacketHandler, $sendString);
                        yield $sendPacket->send();
                    }

                }

            } finally {
                $client->close();
            }

        } finally {
            $serverSocket->close();
        }
    }
}