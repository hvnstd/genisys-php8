<?php
/** 协议层测试（Batch 5）：包格式单测 + 端到端握手/登录/出生链 */
declare(strict_types=1);

use Genisys\Module\NetworkModule;
use Genisys\Module\PlayerModule;
use Genisys\Module\LevelModule;
use Genisys\Module\GamePacket;
use Genisys\Module\MinecraftProtocol;
use Genisys\Module\PacketRegistry;
use Genisys\Module\LoginPacket;
use Genisys\Module\PlayStatusPacket;
use Genisys\Module\StartGamePacket;
use Genisys\Module\UpdateBlockPacket;
use Genisys\Module\FullChunkDataPacket;
use Genisys\Module\BatchPacket;
use Genisys\Module\TextPacket;
use Genisys\Module\EncapsulatedPacket;
use Genisys\Module\FrameSet;
use Genisys\Module\RakNetProtocol;

// ---------- 单测 ----------

test('PlayStatusPacket encodes to vanilla LE bytes', function () {
    $pk = new PlayStatusPacket();
    $pk->status = PlayStatusPacket::LOGIN_SUCCESS;
    check_same(chr(0x90) . "\x00\x00\x00\x00", $pk->encode());
    $pk->status = PlayStatusPacket::PLAYER_SPAWN;
    check_same(chr(0x90) . "\x03\x00\x00\x00", $pk->encode());
});

test('StartGamePacket byte layout matches 0.14.3 field order', function () {
    $pk = new StartGamePacket();
    $pk->seed = 1; $pk->dimension = 0; $pk->generator = 0; $pk->gamemode = 0;
    $pk->eid = 2; $pk->spawnX = 3; $pk->spawnY = 4; $pk->spawnZ = 5;
    $pk->x = 3.5; $pk->y = 4.5; $pk->z = 5.5; $pk->unknown = '';
    $raw = $pk->encode();
    // 对照原版 encode：ID + seed(int) + dim(byte) + gen(int) + mode(int) + eid(long)
    // + spawn(3×int) + pos(3×float) + 1,1,0 + unknown(str)
    $expect = chr(0x95)
        . pack('V', 1) . chr(0) . pack('V', 0) . pack('V', 0)
        . pack('P', 2)
        . pack('V', 3) . pack('V', 4) . pack('V', 5)
        . pack('g', 3.5) . pack('g', 4.5) . pack('g', 5.5)
        . chr(1) . chr(1) . chr(0)
        . pack('v', 0);
    check_same($expect, $raw);
});

test('UpdateBlockPacket packs flags and meta into one byte', function () {
    $pk = new UpdateBlockPacket();
    $pk->records = [[10, 20, 30, 41, 3, UpdateBlockPacket::FLAG_ALL_PRIORITY]]; // 0b1011
    $raw = $pk->encode();
    $expect = chr(0x9f) . pack('V', 1) . pack('V', 10) . pack('V', 20)
        . chr(30) . chr(41) . chr((0b1011 << 4) | 3);
    check_same($expect, $raw);
    // 解码往返
    $back = PacketRegistry::decode($raw);
    check($back instanceof UpdateBlockPacket);
    check_same([10, 20, 30, 41, 3, 0b1011], $back->records[0]);
});

test('BatchPacket pack/unpack round trip with inner packets', function () {
    $a = (new PlayStatusPacket())->status === 0 ? (function () {
        $p = new PlayStatusPacket(); $p->status = 0; return $p->encode();
    })() : '';
    $t = new TextPacket(); $t->type = TextPacket::TYPE_RAW; $t->message = 'hello';
    $payload = BatchPacket::packPackets([$a, $t->encode()]);
    $batch = new BatchPacket();
    $batch->payload = $payload;
    $raw = $batch->encode();
    check_same(chr(0x92), $raw[0]);
    $unpacked = BatchPacket::unpackPackets($payload);
    check_same(2, count($unpacked));
    check_same(chr(0x90), $unpacked[0][0]);
    check_same(chr(0x93), $unpacked[1][0]);
});

test('FullChunkDataPacket payload has vanilla column size', function () {
    $chunk = [
        'blocks' => array_fill(0, 32768, 0),
        'heightMap' => array_fill(0, 256, 5),
        'biomeColors' => array_fill(0, 256, 0x7fd760),
    ];
    $payload = FullChunkDataPacket::buildPayload($chunk);
    // 32768 + 16384×3 + 256 + 256×4 + 4(extra count)
    check_same(32768 + 49152 + 256 + 1024 + 4, strlen($payload));
});

test('LoginPacket decodes client fields', function () {
    $body = (function () {
        $s = new Genisys\Compat\BinaryStream();
        $s->putString('Steve');
        $s->putInt(70); $s->putInt(70);
        $s->putLong(12345);
        $s->put(str_repeat("\xab", 16)); // UUID
        $s->putString('127.0.0.1:0');
        $s->putString('secret');
        $s->putString('Standard_Custom');
        $s->putString(str_repeat('S', 64));
        return $s->getBuffer();
    })();
    $pk = new LoginPacket();
    $pk->decode(chr(0x8f) . $body);
    check_same('Steve', $pk->username);
    check_same(70, $pk->protocol1);
    check_same(70, $pk->protocol2);
    check_same(12345, $pk->clientId);
    check_same(70, strlen($pk->clientUUID) === 16 ? 70 : 0, 'uuid 16 bytes');
    check_same('Standard_Custom', $pk->skinName);
});

// ---------- E2E ----------

const E2E_PORT = 19199;
const E2E_WORLD = '/tmp/genisys-php8-e2e-world';

function e2e_client_send($sock, string $data): void
{
    socket_sendto($sock, $data, strlen($data), 0, '127.0.0.1', E2E_PORT);
}

function e2e_pump(NetworkModule $net, $sock, array &$inbox, int $rounds = 6): void
{
    for ($i = 0; $i < $rounds; ++$i) {
        $net->tick();
        // 收取期间到达的所有数据报
        while (true) {
            $r = [$sock];
            $w = null; $e = null;
            if (@socket_select($r, $w, $e, 0, 0) === 1) {
                $buf = '';
                $from = ''; $fromPort = 0;
                $len = @socket_recvfrom($sock, $buf, 65535, 0, $from, $fromPort);
                if ($len !== false && $len > 0) {
                    $inbox[] = $buf;
                }
                continue;
            }
            break;
        }
    }
}

function e2e_encapsulate(string $buffer, int &$seq, int &$msgIndex, int &$orderIndex): string
{
    $ep = new EncapsulatedPacket();
    $ep->reliability = RakNetProtocol::RELIABILITY_RELIABLE_ORDERED;
    $ep->messageIndex = $msgIndex++;
    $ep->orderIndex = $orderIndex++;
    $ep->orderChannel = 0;
    $ep->buffer = $buffer;
    $fs = new FrameSet();
    $fs->seqNumber = $seq++;
    $fs->packets = [$ep->toBinary()];
    return $fs->toBinary(0x84);
}

/** 从收到的 UDP 数据报里提取全部游戏包（含 ID 头） */
function e2e_extract_game_packets(array $inbox): array
{
    $game = [];
    foreach ($inbox as $datagram) {
        $pid = ord($datagram[0]);
        if ($pid >= 0x80 && $pid <= 0x8d) {
            $fs = FrameSet::fromBinary($datagram);
            foreach ($fs->packets as $epBinary) {
                $offset = 0;
                $ep = EncapsulatedPacket::fromBinary($epBinary, false, $offset);
                if ($ep->buffer !== '') {
                    $game[] = $ep->buffer;
                }
            }
        }
    }
    return $game;
}

test('e2e: raknet handshake -> login -> spawn full chain', function () {
    exec('rm -rf ' . E2E_WORLD);
    $pm = new PlayerModule();
    $level = new LevelModule('E2EWorld', E2E_WORLD, 4242, 'flat');
    $pm->setLevel($level);
    $net = new NetworkModule('E2E', E2E_PORT, 'E2E-Server');
    $net->setPlayerModule($pm);
    $net->bind();

    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($sock, '0.0.0.0', 0);
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    socket_getsockname($sock, $addr, $clientPort);

    $inbox = [];
    $seq = 0; $msgIndex = 0; $orderIndex = 0;
    $magic = RakNetProtocol::MAGIC;

    // 0. 未连接 Ping → Pong
    e2e_client_send($sock, chr(0x01) . RakNetProtocol::writeLong(77) . $magic . RakNetProtocol::writeLong(0));
    e2e_pump($net, $sock, $inbox);
    check_same(0x1c, ord($inbox[0][0]), 'pong received');

    // 1. OpenConnectionRequest1 → Reply1
    e2e_client_send($sock, chr(0x05) . $magic . chr(6) . str_repeat("\x00", 531));
    e2e_pump($net, $sock, $inbox);
    $last = $inbox[count($inbox) - 1];
    check_same(0x06, ord($last[0]), 'reply1 received');

    // 2. OpenConnectionRequest2 → Reply2
    e2e_client_send($sock, chr(0x07) . $magic
        . RakNetProtocol::writeAddress('127.0.0.1', E2E_PORT)
        . RakNetProtocol::writeShort(548)
        . RakNetProtocol::writeLong(98765));
    e2e_pump($net, $sock, $inbox);
    $last = $inbox[count($inbox) - 1];
    check_same(0x08, ord($last[0]), 'reply2 received');

    // 3. 封装的 ConnectionRequest(0x09) → Accepted(0x10)
    e2e_client_send($sock, e2e_encapsulate(
        chr(0x09) . RakNetProtocol::writeLong(98765) . RakNetProtocol::writeLong(1000),
        $seq, $msgIndex, $orderIndex
    ));
    e2e_pump($net, $sock, $inbox);
    $game = e2e_extract_game_packets($inbox);
    check_same(0x10, ord($game[0][0]), 'connection accepted encapsulated');

    // 4. 封装的 ClientHandshake(0x13) → RakNet 会话建立、玩家会话创建
    $hs = chr(0x13)
        . RakNetProtocol::writeAddress('127.0.0.1', E2E_PORT)
        . str_repeat(RakNetProtocol::writeAddress('0.0.0.0', 0), 10)
        . RakNetProtocol::writeLong(1000)
        . RakNetProtocol::writeLong(2000);
    e2e_client_send($sock, e2e_encapsulate($hs, $seq, $msgIndex, $orderIndex));
    e2e_pump($net, $sock, $inbox);
    check_same(1, $pm->getSessionCount(), 'player session created');

    // 5. LoginPacket(protocol 70) → 登录序列
    $loginBody = (function () {
        $s = new Genisys\Compat\BinaryStream();
        $s->putString('Steve');
        $s->putInt(70); $s->putInt(70);
        $s->putLong(4242);
        $s->put(str_repeat("\x01", 16));
        $s->putString('127.0.0.1:0');
        $s->putString('');
        $s->putString('Standard_Custom');
        $s->putString(str_repeat('S', 64));
        return chr(0x8f) . $s->getBuffer();
    })();
    e2e_client_send($sock, e2e_encapsulate($loginBody, $seq, $msgIndex, $orderIndex));
    $inbox = [];
    e2e_pump($net, $sock, $inbox);
    $game = e2e_extract_game_packets($inbox);

    // 序列：PlayStatus(0) → StartGame → 9×Batch(FullChunkData) → SetSpawnPosition
    //       → SetDifficulty → SetTime → AdventureSettings → PlayStatus(3)
    check(count($game) >= 14, 'login response sequence present: ' . count($game));
    $p0 = PacketRegistry::decode($game[0]);
    check($p0 instanceof PlayStatusPacket && $p0->status === PlayStatusPacket::LOGIN_SUCCESS, 'first = login success');

    $p1 = PacketRegistry::decode($game[1]);
    check($p1 instanceof StartGamePacket, 'second = StartGame');
    check_same(4242, $p1->seed, 'seed from world');
    check_same(1, $p1->eid, 'entity id');
    check_same(0, $p1->spawnX, 'spawn x');

    $chunkCount = 0;
    $spawnStatusSeen = false;
    foreach ($game as $raw) {
        $pk = PacketRegistry::decode($raw);
        if ($pk instanceof BatchPacket) {
            $inner = BatchPacket::unpackPackets($pk->payload);
            foreach ($inner as $rawInner) {
                $ipk = PacketRegistry::decode($rawInner);
                if ($ipk instanceof FullChunkDataPacket) {
                    $chunkCount++;
                    check_same(strlen(FullChunkDataPacket::buildPayload(['blocks' => array_fill(0, 32768, 0), 'heightMap' => array_fill(0, 256, 0), 'biomeColors' => array_fill(0, 256, 0)])), strlen($ipk->data), 'chunk payload size');
                }
            }
        } elseif ($pk instanceof PlayStatusPacket && $pk->status === PlayStatusPacket::PLAYER_SPAWN) {
            $spawnStatusSeen = true;
        }
    }
    check_same(9, $chunkCount, '3x3 chunks sent');
    check($spawnStatusSeen, 'PLAYER_SPAWN status sent');

    // 6. RequestChunkRadius → ChunkRadiusUpdate
    $req = new Genisys\Module\RequestChunkRadiusPacket();
    $req->radius = 4;
    $inbox = [];
    e2e_client_send($sock, e2e_encapsulate($req->encode(), $seq, $msgIndex, $orderIndex));
    e2e_pump($net, $sock, $inbox);
    $game = e2e_extract_game_packets($inbox);
    $resp = PacketRegistry::decode($game[0] ?? '');
    check($resp instanceof Genisys\Module\ChunkRadiusUpdatePacket, 'radius update received');
    check_same(4, $resp?->radius ?? -1);

    // 7. 服务器广播聊天 → TextPacket(TYPE_CHAT)
    $inbox = [];
    $pm->broadcastMessage('Server', 'welcome');
    e2e_pump($net, $sock, $inbox, 2);
    $game = e2e_extract_game_packets($inbox);
    $txt = PacketRegistry::decode($game[0] ?? '');
    check($txt instanceof TextPacket, 'chat text received');
    check_same(TextPacket::TYPE_CHAT, $txt?->type ?? -1);
    check_same('welcome', $txt?->message ?? '');

    socket_close($sock);
    $net->shutdown();
});
