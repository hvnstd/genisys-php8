<?php
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
$magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
$ping = "\x01" . str_repeat("\x00", 8) . $magic . str_repeat("\x00", 8);
socket_sendto($s, $ping, strlen($ping), 0, "127.0.0.1", 19132);
$r = @socket_recvfrom($s, $buf, 2048, 0, $ip, $port);
if ($r === false) {
    echo "NO REPLY\n";
    exit(1);
}
echo "reply from $ip:$port len=$r id=0x" . dechex(ord($buf[0])) . "\n";
$motdLen = unpack("n", substr($buf, 33, 2))[1];
echo "motd: " . substr($buf, 35, $motdLen) . "\n";
