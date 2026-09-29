<?php defined('MW_PATH') or exit('No direct script access allowed');

/**
 * SMTPing Email Verifier for MailWizz 2.x
 *
 * Checks new subscribers and list imports with the SMTPing API and treats risky
 * addresses as blacklisted. Existing lists can be verified in bulk from the
 * extension settings page.
 */

require_once __DIR__ . '/common/components/SmtpingEmailVerifierExtClient.php';
require_once __DIR__ . '/common/components/SmtpingEmailVerifierExtCache.php';

class SmtpingEmailVerifierExt extends ExtensionInit
{
    public $name = 'SMTPing Email Verifier';
    public $description = 'Block invalid, spamtrap, disposable and other risky addresses on subscribe and import, and verify existing lists in bulk with SMTPing.';
    public $version = '1.0.0';
    public $minAppVersion = '2.0.0';
    public $author = 'SMTPing';
    public $website = 'https://smtping.com/';
    public $email = 'support@smtping.com';
    public $allowedApps = ['*'];
    public $cliEnabled = true;

    public function run()
    {
        if ($this->isAppName('backend')) {
            $this->addUrlRules([
                ['settings/index', 'pattern' => 'extensions/smtping-email-verifier/settings'],
                ['settings/<action>', 'pattern' => 'extensions/smtping-email-verifier/settings/*'],
            ]);
            $this->addControllerMap([
                'settings' => ['class' => 'backend.controllers.SmtpingEmailVerifierExtBackendSettingsController'],
            ]);
        }

        if (!$this->isEnabledForChecks()) {
            return;
        }

        Yii::app()->hooks->addFilter('email_blacklist_is_email_blacklisted', [$this, '_filterIsBlacklisted'], 100);
        Yii::app()->hooks->addFilter('list_import_data_bulk_check_failures', [$this, '_filterImportFailures'], 100);
    }

    public function getPageUrl()
    {
        return $this->createUrl('settings/index');
    }

    public function beforeEnable()
    {
        SmtpingEmailVerifierExtCache::install();
        return parent::beforeEnable();
    }

    public function beforeDelete()
    {
        SmtpingEmailVerifierExtCache::uninstall();
        return parent::beforeDelete();
    }

    public function isEnabledForChecks(): bool
    {
        return (int)$this->getOption('enabled', 0) === 1 && $this->getApiKey() !== '';
    }

    public function getApiKey(): string
    {
        return trim((string)$this->getOption('api_key', ''));
    }

    /** @return string[] bands treated as blacklisted */
    public function getBlockedBands(): array
    {
        return $this->getOption('block_bands', 'avoid') === 'avoid_judgement' ? ['avoid', 'judgement'] : ['avoid'];
    }

    /** Seconds a subscribe form waits for SMTPing before letting the address through (3 to 30, default 8). */
    public function getFormTimeout(): int
    {
        return max(3, min(30, (int)$this->getOption('form_timeout', 8)));
    }

    public function getClient(int $timeout = 60): SmtpingEmailVerifierExtClient
    {
        return new SmtpingEmailVerifierExtClient($this->getApiKey(), $timeout);
    }

    /**
     * Called by MailWizz each time it checks whether an address is blacklisted:
     * on subscribe forms, the API, imports and campaign sending.
     * Cached results are reused for 30 days. Outside of the console app, unknown
     * addresses are checked live; in the console app (sending, cron) only the
     * cache is used unless "check on send" is on, so campaigns never spend credits
     * by surprise. Any API error lets the address through.
     */
    public function _filterIsBlacklisted($blacklisted, $email = '', $subscriber = null, $customer = null, $params = [])
    {
        if ($blacklisted !== false) {
            return $blacklisted;
        }
        $email = strtolower(trim((string)$email));
        if ($email === '' || strpos($email, '@') === false) {
            return $blacklisted;
        }

        $cached = SmtpingEmailVerifierExtCache::get($email);
        if ($cached !== null) {
            return in_array($cached['band'], $this->getBlockedBands(), true) ? true : $blacklisted;
        }

        if ($this->isAppName('console') && (int)$this->getOption('check_on_send', 0) !== 1) {
            return $blacklisted;
        }

        try {
            $result = $this->getClient($this->getFormTimeout())->verify($email);
        } catch (Exception $e) {
            Yii::log('SMTPing check skipped for ' . $email . ': ' . $e->getMessage(), CLogger::LEVEL_WARNING);
            return $blacklisted;
        }
        SmtpingEmailVerifierExtCache::set($email, $result['status'], $result['band']);

        return in_array($result['band'], $this->getBlockedBands(), true) ? true : $blacklisted;
    }

    /**
     * Called by MailWizz for each chunk of a list import. Returns the rejected
     * addresses with a reason. Unknown addresses are checked in parallel within a
     * 60 second budget; whatever is not checked in time is imported.
     */
    public function _filterImportFailures($failures, $bulkEmails = [])
    {
        $failures = (array)$failures;
        $emails = [];
        foreach ((array)$bulkEmails as $key => $item) {
            $value = is_array($item) ? ($item['email'] ?? '') : (is_string($key) && strpos($key, '@') !== false ? $key : $item);
            $value = strtolower(trim((string)$value));
            if ($value !== '' && strpos($value, '@') !== false && !isset($failures[$value])) {
                $emails[$value] = true;
            }
        }
        if (!$emails) {
            return $failures;
        }

        $results = SmtpingEmailVerifierExtCache::getMany(array_keys($emails));
        $missing = array_values(array_diff(array_keys($emails), array_keys($results)));
        if ($missing) {
            $fresh = $this->getClient(15)->verifyMany($missing, 10, 60);
            foreach ($fresh as $email => $r) {
                SmtpingEmailVerifierExtCache::set($email, $r['status'], $r['band']);
                $results[$email] = $r;
            }
        }

        foreach ($results as $email => $r) {
            if (in_array($r['band'], $this->getBlockedBands(), true)) {
                $failures[$email] = 'SMTPing: ' . $r['status'];
            }
        }

        return $failures;
    }
}
