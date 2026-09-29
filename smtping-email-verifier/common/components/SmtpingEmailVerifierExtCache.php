<?php defined('MW_PATH') or exit('No direct script access allowed');

/**
 * Stores verification results so an address is never paid for twice within 30 days.
 */
class SmtpingEmailVerifierExtCache
{
    const TABLE = '{{smtping_ev_cache}}';
    const TTL_DAYS = 30;

    /** @var bool */
    private static $ready = false;

    public static function install(): void
    {
        db()->createCommand('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            email VARCHAR(190) NOT NULL,
            status VARCHAR(32) NOT NULL,
            band VARCHAR(16) NOT NULL,
            checked_at DATETIME NOT NULL,
            PRIMARY KEY (email),
            KEY checked_at (checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        self::$ready = true;
    }

    public static function uninstall(): void
    {
        db()->createCommand('DROP TABLE IF EXISTS ' . self::TABLE)->execute();
    }

    public static function get(string $email): ?array
    {
        $rows = self::getMany([$email]);
        return $rows[$email] ?? null;
    }

    /**
     * @param string[] $emails
     * @return array<string, array{status: string, band: string}>
     */
    public static function getMany(array $emails): array
    {
        if (!$emails) {
            return [];
        }
        self::ensure();
        $out = [];
        $cutoff = date('Y-m-d H:i:s', time() - self::TTL_DAYS * 86400);
        foreach (array_chunk($emails, 500) as $chunk) {
            $rows = db()->createCommand()
                ->select('email, status, band')
                ->from(self::TABLE)
                ->where(['and', ['in', 'email', $chunk], 'checked_at >= :cutoff'], [':cutoff' => $cutoff])
                ->queryAll();
            foreach ($rows as $row) {
                $out[$row['email']] = ['status' => $row['status'], 'band' => $row['band']];
            }
        }
        return $out;
    }

    public static function set(string $email, string $status, string $band): void
    {
        self::ensure();
        db()->createCommand('REPLACE INTO ' . self::TABLE . ' (email, status, band, checked_at) VALUES (:e, :s, :b, :t)')
            ->execute([':e' => strtolower(trim($email)), ':s' => substr($status, 0, 32), ':b' => $band, ':t' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, int> */
    public static function stats(): array
    {
        self::ensure();
        $rows = db()->createCommand('SELECT band, COUNT(*) AS c FROM ' . self::TABLE . ' GROUP BY band')->queryAll();
        $out = ['safe' => 0, 'judgement' => 0, 'avoid' => 0];
        foreach ($rows as $row) {
            $out[$row['band']] = (int)$row['c'];
        }
        return $out;
    }

    private static function ensure(): void
    {
        if (!self::$ready) {
            self::install();
        }
    }
}
