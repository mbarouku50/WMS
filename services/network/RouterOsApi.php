<?php
/**
 * WMS - MikroTik RouterOS API protocol client.
 *
 * A small, dependency-free implementation of the RouterOS binary API:
 * length-prefixed "words" grouped into sentences.  Supports the modern
 * plain login (RouterOS 6.43+) and falls back to the older MD5 challenge.
 *
 * This class only speaks the protocol - the business meaning of each command
 * lives in MikroTikService.
 */
class RouterOsApi
{
    /** @var resource|null */
    private $socket = null;

    private string $host;
    private int $port;
    private bool $useTls;
    private int $timeout;
    private string $lastError = '';

    /**
     * Absolute deadline for the current exchange.
     *
     * The socket timeout alone is not enough: a host that accepts the
     * connection but never speaks RouterOS (an HTTP server on the wrong
     * port, say) makes every read block for the full timeout, so a handful
     * of reads becomes a multi-second stall on a page load. This caps the
     * whole conversation instead of each individual read.
     */
    private float $deadline = 0.0;

    public function __construct(string $host, int $port = 8728, bool $useTls = false, int $timeout = 5)
    {
        $this->host    = $host;
        $this->port    = $port;
        $this->useTls  = $useTls;
        $this->timeout = max(2, min(20, $timeout));
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    /** Opens the TCP (or TLS) socket and logs in. */
    public function connect(string $username, string $password): bool
    {
        $this->lastError = '';

        $target = ($this->useTls ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;

        /*
         * RouterOS only serves a certificate on api-ssl once one has been
         * assigned to the service. Until then it offers nothing but the
         * anonymous-DH suites (ADH-AES256-SHA256 and friends), which OpenSSL 3
         * will not even put in the ClientHello at its default security level -
         * so the handshake dies before login with an empty error string.
         *
         * HIGH comes first so a router that does have a certificate still
         * negotiates a strong authenticated suite; ADH at SECLEVEL=0 is only
         * there as the fallback a certificate-less api-ssl leaves us.
         */
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'ciphers'          => 'HIGH:ADH:@SECLEVEL=0',
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            /*
             * A failed TLS handshake reports errno 0 and an empty message,
             * which is not the same thing as an unreachable host - saying
             * "could not reach" there sends the operator to check cables when
             * the port answered fine. Separate the two.
             */
            if ($errstr !== '') {
                $this->lastError = $errstr;
            } elseif ($this->useTls && $errno === 0) {
                $this->lastError = 'The TLS handshake with ' . $this->host . ':' . $this->port
                    . ' failed. The port answered but would not negotiate TLS - check that this is the api-ssl'
                    . ' port and that a certificate is assigned to it on the router.';
            } else {
                $this->lastError = 'Could not reach the router on ' . $this->host . ':' . $this->port;
            }
            return false;
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->resetDeadline();

        return $this->login($username, $password);
    }

    private function login(string $username, string $password): bool
    {
        // RouterOS 6.43+ : plain login in a single sentence.
        $response = $this->command('/login', ['name' => $username, 'password' => $password]);

        if ($this->sentenceOk($response)) {
            return true;
        }

        // No reply at all means this is not a RouterOS API port - something
        // else is listening. Retrying the older login would just burn a
        // second timeout, so stop here.
        if ($response === []) {
            $this->lastError = $this->lastError
                ?: 'Nothing answered the RouterOS API on this port. Check the port number and that /ip service api is enabled.';
            $this->disconnect();
            return false;
        }

        // Older RouterOS: request a challenge, then reply with the MD5 digest.
        $challengeResponse = $this->command('/login');
        $challenge = '';
        foreach ($challengeResponse as $sentence) {
            if (isset($sentence['ret'])) {
                $challenge = (string)$sentence['ret'];
            }
        }

        if ($challenge === '') {
            $this->lastError = $this->lastError ?: 'The router rejected the API login.';
            $this->disconnect();
            return false;
        }

        $digest = chr(0) . $password . pack('H*', $challenge);
        $reply  = $this->command('/login', [
            'name'     => $username,
            'response' => '00' . md5($digest),
        ]);

        if ($this->sentenceOk($reply)) {
            return true;
        }

        $this->lastError = $this->lastError ?: 'Invalid API username or password.';
        $this->disconnect();
        return false;
    }

    /** True when a parsed response contains a !done and no !trap. */
    private function sentenceOk(array $response): bool
    {
        $done = false;
        foreach ($response as $sentence) {
            if (($sentence['_type'] ?? '') === '!trap' || ($sentence['_type'] ?? '') === '!fatal') {
                $this->lastError = (string)($sentence['message'] ?? 'The router refused the request.');
                return false;
            }
            if (($sentence['_type'] ?? '') === '!done') {
                $done = true;
            }
        }
        return $done;
    }

    /** Starts a fresh deadline for one request/response exchange. */
    private function resetDeadline(): void
    {
        $this->deadline = microtime(true) + $this->timeout;
    }

    private function pastDeadline(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    /**
     * Sends a command and returns the parsed reply sentences.
     *
     * @param array<string,string|int> $attributes  "=name=value" pairs
     * @param array<int,string>        $queries     "?query" words
     * @return array<int,array<string,string>>
     */
    public function command(string $command, array $attributes = [], array $queries = []): array
    {
        if (!is_resource($this->socket)) {
            $this->lastError = 'Not connected to the router.';
            return [];
        }

        $this->resetDeadline();

        $this->writeWord($command);
        foreach ($queries as $query) {
            $this->writeWord($query);
        }
        foreach ($attributes as $key => $value) {
            $this->writeWord('=' . $key . '=' . $value);
        }
        $this->writeWord('');

        return $this->readSentences();
    }

    /** Returns only the data rows (!re sentences) of a command. */
    public function query(string $command, array $attributes = [], array $queries = []): array
    {
        $rows = [];
        foreach ($this->command($command, $attributes, $queries) as $sentence) {
            if (($sentence['_type'] ?? '') === '!re') {
                unset($sentence['_type']);
                $rows[] = $sentence;
            }
        }
        return $rows;
    }

    /* ------------------------------------------------------------- wire IO */

    private function writeWord(string $word): void
    {
        $this->writeLength(strlen($word));
        if ($word !== '') {
            @fwrite($this->socket, $word);
        }
    }

    /** RouterOS variable-length integer encoding. */
    private function writeLength(int $length): void
    {
        if ($length < 0x80) {
            $bytes = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $bytes = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $bytes = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $bytes = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } else {
            $bytes = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        @fwrite($this->socket, $bytes);
    }

    private function readLength(): int
    {
        $byte = $this->readBytes(1);
        if ($byte === '') {
            return -1;
        }
        $first = ord($byte);

        if (($first & 0x80) === 0x00) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            return (($first & ~0xC0) << 8) + ord($this->readBytes(1));
        }
        if (($first & 0xE0) === 0xC0) {
            $rest = $this->readBytes(2);
            return (($first & ~0xE0) << 16) + (ord($rest[0]) << 8) + ord($rest[1]);
        }
        if (($first & 0xF0) === 0xE0) {
            $rest = $this->readBytes(3);
            return (($first & ~0xF0) << 24) + (ord($rest[0]) << 16) + (ord($rest[1]) << 8) + ord($rest[2]);
        }
        $rest = $this->readBytes(4);
        return (ord($rest[0]) << 24) + (ord($rest[1]) << 16) + (ord($rest[2]) << 8) + ord($rest[3]);
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            if ($this->pastDeadline()) {
                $this->lastError = 'The router did not respond in time.';
                break;
            }

            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    $this->lastError = 'The router did not respond in time.';
                } elseif ($this->lastError === '') {
                    $this->lastError = 'The connection closed unexpectedly. Is this really a RouterOS API port?';
                }
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * Reads sentences until !done / !fatal.
     * Each sentence is an associative array plus a "_type" key.
     */
    private function readSentences(): array
    {
        $sentences = [];
        $current   = [];
        $guard     = 0;

        while ($guard++ < 20000) {
            if ($this->pastDeadline()) {
                $this->lastError = $this->lastError ?: 'The router did not respond in time.';
                break;
            }

            $length = $this->readLength();
            if ($length < 0) {
                break;
            }
            if ($length === 0) {
                if ($current) {
                    $sentences[] = $current;
                    $type = $current['_type'] ?? '';
                    $current = [];
                    if ($type === '!done' || $type === '!fatal') {
                        break;
                    }
                }
                continue;
            }

            $word = $this->readBytes($length);
            if ($word === '') {
                break;
            }

            if ($word[0] === '!') {
                $current['_type'] = $word;
            } elseif ($word[0] === '=') {
                $parts = explode('=', substr($word, 1), 2);
                $current[$parts[0]] = $parts[1] ?? '';
            } elseif (str_starts_with($word, '.tag=')) {
                $current['tag'] = substr($word, 5);
            } else {
                $current['ret'] = $word;
            }
        }

        return $sentences;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
