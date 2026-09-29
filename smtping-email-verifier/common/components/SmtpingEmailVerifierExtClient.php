<?php defined('MW_PATH') or exit('No direct script access allowed');

/**
 * Minimal SMTPing API client using cURL. No dependencies.
 */
class SmtpingEmailVerifierExtClient
{
    const BASE_URL = 'https://api.smtping.com/api/v1';
    const USER_AGENT = 'smtping-mailwizz/1.0.0';
    const BULK_MAX = 100000;

    const SAFE = ['valid', 'alias'];
    const AVOID = ['invalid', 'spamtrap', 'disposable', 'blacklisted', 'complainer', 'spambot', 'inbox_full'];

    /** @var string */
    private $apiKey;

    /** @var int */
    private $timeout;

    public function __construct(string $apiKey, int $timeout = 60)
    {
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public static function band(?string $status): string
    {
        if (in_array($status, self::SAFE, true)) {
            return 'safe';
        }
        if (in_array($status, self::AVOID, true)) {
            return 'avoid';
        }
        return 'judgement';
    }

    /** @return array{status: string, band: string} */
    public function verify(string $email): array
    {
        $r = $this->request('POST', '/verify/single', ['email' => $email]);
        $status = (string)($r['status'] ?? 'unknown');
        return ['status' => $status, 'band' => self::band($status)];
    }

    /**
     * Parallel single checks within a time budget. Errors and timeouts are skipped.
     *
     * @param string[] $emails
     * @return array<string, array{status: string, band: string}>
     */
    public function verifyMany(array $emails, int $concurrency = 10, int $budgetSeconds = 60): array
    {
        $out = [];
        $deadline = time() + $budgetSeconds;
        foreach (array_chunk($emails, max(1, $concurrency)) as $chunk) {
            if (time() >= $deadline) {
                break;
            }
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $email) {
                $ch = $this->handle('POST', '/verify/single', ['email' => $email]);
                curl_multi_add_handle($mh, $ch);
                $handles[$email] = $ch;
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $st === CURLM_OK);
            foreach ($handles as $email => $ch) {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = (string)curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($code >= 200 && $code < 300) {
                    $data = json_decode($body, true);
                    $status = (string)($data['status'] ?? 'unknown');
                    $out[$email] = ['status' => $status, 'band' => self::band($status)];
                } elseif ($code === 401 || $code === 403 || $code === 402) {
                    curl_multi_close($mh);
                    return $out;
                }
            }
            curl_multi_close($mh);
        }
        return $out;
    }

    /** @param string[] $emails */
    public function createBulk(array $emails): array
    {
        return $this->request('POST', '/verify/bulk', ['emails' => array_values($emails)]);
    }

    public function getBulk(string $jobId): array
    {
        return $this->request('GET', '/verify/bulk/' . rawurlencode($jobId));
    }

    public function bulkResults(string $jobId): array
    {
        $data = $this->request('GET', '/verify/bulk/' . rawurlencode($jobId) . '/result');
        if (isset($data['results']) && is_array($data['results'])) {
            $data = $data['results'];
        }
        return array_values(array_filter($data, 'is_array'));
    }

    public function credits(): array
    {
        return $this->request('GET', '/credits');
    }

    private function request(string $method, string $path, ?array $body = null, int $maxRetries = 3): array
    {
        for ($attempt = 0; ; $attempt++) {
            $ch = $this->handle($method, $path, $body);
            $text = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($text === false) {
                if ($attempt < $maxRetries) {
                    usleep($this->backoff($attempt + 1));
                    continue;
                }
                throw new Exception('Network error: ' . $error);
            }
            if ($code >= 200 && $code < 300) {
                $data = json_decode((string)$text, true);
                return is_array($data) ? $data : [];
            }
            if (($code === 429 || $code >= 500) && $attempt < $maxRetries) {
                usleep($this->backoff($attempt + 1));
                continue;
            }
            $data = json_decode((string)$text, true);
            $message = is_array($data) ? ($data['error'] ?? $data['message'] ?? null) : null;
            throw new Exception(is_string($message) ? $message : 'SMTPing API returned HTTP ' . $code, $code);
        }
    }

    private function handle(string $method, string $path, ?array $body)
    {
        $ch = curl_init(self::BASE_URL . $path);
        $headers = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json', 'User-Agent: ' . self::USER_AGENT];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        return $ch;
    }

    private function backoff(int $attempt): int
    {
        return (int)(min(1000 * (2 ** ($attempt - 1)), 15000) + mt_rand(0, 250)) * 1000;
    }
}
