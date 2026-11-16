<?php

final class Supabase
{
    public function __construct(
        private string $url,
        private string $key,
        private ?string $jwt = null
    ) {
    }

    public function withJwt(?string $jwt): self
    {
        $next = clone $this;
        $next->jwt = $jwt;
        return $next;
    }

    /** @return array{ok:bool,status:int,data:mixed,error:string} */
    public function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $url = rtrim($this->url, '/') . $path;
        $hdr = array_merge([
            'apikey: ' . $this->key,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);
        if ($this->jwt) {
            $hdr[] = 'Authorization: Bearer ' . $this->jwt;
        }
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body === [] ? new stdClass() : $body);
        }
        if (function_exists('curl_init')) {
            return $this->viaCurl($method, $url, $hdr, $payload);
        }
        return $this->viaStream($method, $url, $hdr, $payload);
    }

    /** @param list<string> $hdr */
    private function viaCurl(string $method, string $url, array $hdr, ?string $payload): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_TIMEOUT => 25,
        ];
        $ca = __DIR__ . '/cacert.pem';
        if (is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $err ?: 'Network error'];
        }
        return $this->parse($status, (string) $raw);
    }

    /** @param list<string> $hdr */
    private function viaStream(string $method, string $url, array $hdr, ?string $payload): array
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $ca = __DIR__ . '/cacert.pem';
        if (is_file($ca)) {
            $ssl['cafile'] = $ca;
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $hdr),
                'content' => $payload ?? '',
                'timeout' => 25,
                'ignore_errors' => true,
            ],
            'ssl' => $ssl,
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'error' => 'Network error'];
        }
        return $this->parse($status, $raw);
    }

    /** @return array{ok:bool,status:int,data:mixed,error:string} */
    private function parse(int $status, string $raw): array
    {
        $data = json_decode($raw, true);
        $ok = $status >= 200 && $status < 300;
        $error = '';
        if (!$ok) {
            if (is_array($data)) {
                $error = $data['msg'] ?? $data['message'] ?? $data['error_description'] ?? $data['error'] ?? $raw;
                if (is_array($error)) {
                    $error = json_encode($error);
                }
            } else {
                $error = $raw !== '' ? $raw : 'Request failed';
            }
        }
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => (string) $error];
    }
}