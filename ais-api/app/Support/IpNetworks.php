<?php

namespace App\Support;

/**
 * Проверка адреса по списку сетей (CIDR) — для входа по токену быстрого
 * входа, который действует только в доверенных сетях.
 *
 * Поддерживаются IPv4 и IPv6, запись без маски считается одиночным адресом.
 */
class IpNetworks
{
    /** @param  array<int, string>  $networks  список вида 192.168.0.0/16 */
    public static function matches(?string $ip, array $networks): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }

        foreach ($networks as $network) {
            if (self::inNetwork($address, trim($network))) {
                return true;
            }
        }

        return false;
    }

    private static function inNetwork(string $address, string $network): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $network, 2), 2, null);

        $subnetBinary = @inet_pton((string) $subnet);
        if ($subnetBinary === false || strlen($subnetBinary) !== strlen($address)) {
            // Разные семейства адресов (IPv4 против IPv6) не сравниваем.
            return false;
        }

        $bits = $bits === null ? strlen($address) * 8 : (int) $bits;
        $bits = max(0, min($bits, strlen($address) * 8));

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($subnetBinary, 0, $wholeBytes)) {
            return false;
        }

        $restBits = $bits % 8;
        if ($restBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $restBits) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
