<?php

declare(strict_types=1);

namespace LaraGram\Server\Support;

/**
 * td::HttpUrl and td::parse_url: TDLib's URL parser and normaliser.
 */
final class HttpUrl
{
    public const HTTP = 0;
    public const HTTPS = 1;

    public function __construct(
        public readonly int $protocol,
        public readonly string $userinfo,
        public readonly string $host,
        public readonly bool $isIpv6,
        public readonly int $specifiedPort,
        public readonly int $port,
        public readonly string $query,
    ) {
    }

    public function getUrl(): string
    {
        $result = $this->protocol === self::HTTP ? 'http://' : 'https://';
        if ($this->userinfo !== '') {
            $result .= $this->userinfo.'@';
        }
        $result .= $this->host;
        if ($this->specifiedPort > 0) {
            $result .= ':'.$this->specifiedPort;
        }

        return $result.$this->query;
    }

    /**
     * @throws \InvalidArgumentException with TDLib's error message
     */
    public static function parse(string $url, int $defaultProtocol = self::HTTP): self
    {
        $protocolEnd = strcspn($url, ':/?#@[]');
        $protocolStr = strtolower(substr($url, 0, $protocolEnd));

        if (substr($url, $protocolEnd, 3) === '://') {
            $protocol = match ($protocolStr) {
                'http' => self::HTTP,
                'https' => self::HTTPS,
                default => throw new \InvalidArgumentException('Unsupported URL protocol'),
            };
            $rest = substr($url, $protocolEnd + 3);
        } else {
            $protocol = $defaultProtocol;
            $rest = $url;
        }

        $hostPortLength = strcspn($rest, '/?#');
        $userinfoHostPort = substr($rest, 0, $hostPortLength);
        $query = substr($rest, $hostPortLength);

        $port = 0;
        $colon = strlen($userinfoHostPort) - 1;
        while ($colon > 0 && !in_array($userinfoHostPort[$colon], [':', ']', '@'], true)) {
            $colon--;
        }
        if ($colon > 0 && $userinfoHostPort[$colon] === ':') {
            $portStr = substr($userinfoHostPort, $colon + 1);
            while (strlen($portStr) > 1 && $portStr[0] === '0') {
                $portStr = substr($portStr, 1);
            }
            $parsed = TdInteger::toInt32Safe($portStr);
            $port = $parsed === null || $parsed === 0 ? -1 : $parsed;
            $userinfoHost = substr($userinfoHostPort, 0, $colon);
        } else {
            $userinfoHost = $userinfoHostPort;
        }
        if ($port < 0 || $port > 65535) {
            throw new \InvalidArgumentException('Wrong port number specified in the URL');
        }

        $at = strrpos($userinfoHost, '@');
        $userinfo = $at === false ? '' : substr($userinfoHost, 0, $at);
        $host = $at === false ? $userinfoHost : substr($userinfoHost, $at + 1);

        $isIpv6 = false;
        if ($host !== '' && $host[0] === '[' && $host[strlen($host) - 1] === ']') {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException('Wrong IPv6 address specified in the URL');
            }
            $isIpv6 = true;
        }
        if ($host === '') {
            throw new \InvalidArgumentException('URL host is empty');
        }
        if ($host === '.') {
            throw new \InvalidArgumentException('Host is invalid');
        }

        $specifiedPort = $port;
        if ($port === 0) {
            $port = $protocol === self::HTTP ? 80 : 443;
        }

        $query = rtrim($query, " \t\r\n\0\x0B");
        if ($query === '') {
            $query = '/';
        }
        $queryStr = $query[0] !== '/' ? '/' : '';
        $length = strlen($query);
        for ($i = 0; $i < $length; $i++) {
            $c = ord($query[$i]);
            $queryStr .= $c <= 0x20 ? sprintf('%%%02X', $c) : $query[$i];
        }

        $hostStr = strtolower($host);
        if ($isIpv6) {
            for ($i = 1, $n = strlen($hostStr); $i + 1 < $n; $i++) {
                if (!preg_match('/[:0-9a-f.]/', $hostStr[$i])) {
                    throw new \InvalidArgumentException('Wrong IPv6 URL host');
                }
            }
        } else {
            self::checkUrlPart($hostStr, 'host', false);
            self::checkUrlPart($userinfo, 'userinfo', true);
        }

        return new self($protocol, $userinfo, $hostStr, $isIpv6, $specifiedPort, $port, $queryStr);
    }

    private static function checkUrlPart(string $part, string $name, bool $allowColon): void
    {
        $length = strlen($part);
        for ($i = 0; $i < $length; $i++) {
            $c = $part[$i];
            if (ctype_alnum($c) || str_contains(".-_!$,~*'();&+=", $c) || ($allowColon && $c === ':')) {
                continue;
            }
            if ($c === '%') {
                if (ctype_xdigit($part[$i + 1] ?? "\0") && ctype_xdigit($part[$i + 2] ?? "\0")) {
                    $i += 2;
                    continue;
                }
                throw new \InvalidArgumentException("Wrong percent-encoded symbol in URL {$name}");
            }
            if (ord($c) >= 128) {
                continue;
            }
            throw new \InvalidArgumentException("Disallowed character in URL {$name}");
        }
    }
}
