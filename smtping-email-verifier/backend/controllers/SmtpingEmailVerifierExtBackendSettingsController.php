<?php defined('MW_PATH') or exit('No direct script access allowed');

class SmtpingEmailVerifierExtBackendSettingsController extends ExtensionController
{
    public function getViewPath()
    {
        return $this->getExtension()->getPathOfAlias('backend.views.settings');
    }

    public function actionIndex()
    {
        /** @var SmtpingEmailVerifierExt $ext */
        $ext = $this->getExtension();

        if (request()->getIsPostRequest()) {
            $action = (string)request()->getPost('smtping_action', 'save');
            try {
                if ($action === 'save') {
                    $this->saveSettings($ext);
                } elseif ($action === 'verify_list') {
                    $this->startListJob($ext, (int)request()->getPost('list_id', 0));
                } elseif ($action === 'fetch') {
                    $this->fetchJob($ext, (string)request()->getPost('job_id', ''));
                } elseif ($action === 'forget') {
                    $this->forgetJob($ext, (string)request()->getPost('job_id', ''));
                }
            } catch (Exception $e) {
                notify()->addError('SMTPing: ' . $e->getMessage());
            }
            $this->redirect($ext->createUrl('settings/index'));
        }

        $credits = null;
        if ($ext->getApiKey() !== '') {
            try {
                $credits = $ext->getClient(10)->credits();
            } catch (Exception $e) {
                notify()->addWarning('SMTPing: ' . $e->getMessage());
            }
        }

        $lists = db()->createCommand()
            ->select('l.list_id, l.name, COUNT(s.subscriber_id) AS subscribers')
            ->from('{{list}} l')
            ->leftJoin('{{list_subscriber}} s', 's.list_id = l.list_id AND s.status IN ("confirmed", "unconfirmed")')
            ->where('l.status = "active"')
            ->group('l.list_id')
            ->order('l.name ASC')
            ->queryAll();

        $this->setData([
            'pageMetaTitle'   => $this->getData('pageMetaTitle') . ' | SMTPing Email Verifier',
            'pageHeading'     => 'SMTPing Email Verifier',
            'pageBreadcrumbs' => [
                t('app', 'Extensions') => createUrl('extensions/index'),
                'SMTPing Email Verifier' => $ext->createUrl('settings/index'),
            ],
        ]);

        $this->render('index', [
            'ext'     => $ext,
            'credits' => $credits,
            'lists'   => $lists,
            'jobs'    => $this->jobs($ext),
            'stats'   => SmtpingEmailVerifierExtCache::stats(),
        ]);
    }

    private function saveSettings(SmtpingEmailVerifierExt $ext): void
    {
        $key = trim((string)request()->getPost('api_key', ''));
        if ($key !== '') {
            $ext->setOption('api_key', $key);
        }
        $ext->setOption('enabled', (int)request()->getPost('enabled', 0) === 1 ? 1 : 0);
        $ext->setOption('check_on_send', (int)request()->getPost('check_on_send', 0) === 1 ? 1 : 0);
        $ext->setOption('form_timeout', max(3, min(30, (int)request()->getPost('form_timeout', 8))));
        $ext->setOption('block_bands', request()->getPost('block_bands') === 'avoid_judgement' ? 'avoid_judgement' : 'avoid');
        notify()->addSuccess(t('app', 'Your form has been successfully saved!'));
    }

    private function startListJob(SmtpingEmailVerifierExt $ext, int $listId): void
    {
        if ($ext->getApiKey() === '') {
            throw new Exception('Save your API key first.');
        }
        $list = db()->createCommand()->select('list_id, name')->from('{{list}}')->where('list_id = :id', [':id' => $listId])->queryRow();
        if (!$list) {
            throw new Exception('List not found.');
        }
        $emails = db()->createCommand()
            ->selectDistinct('LOWER(TRIM(email)) AS email')
            ->from('{{list_subscriber}}')
            ->where('list_id = :id AND status IN ("confirmed", "unconfirmed")', [':id' => $listId])
            ->queryColumn();
        $emails = array_values(array_diff($emails, array_keys(SmtpingEmailVerifierExtCache::getMany($emails))));
        if (!$emails) {
            notify()->addInfo('Every subscriber of this list was verified in the last 30 days.');
            return;
        }

        $jobs = $this->jobs($ext);
        foreach (array_chunk($emails, SmtpingEmailVerifierExtClient::BULK_MAX) as $chunk) {
            $job = $ext->getClient()->createBulk($chunk);
            $id = (string)($job['jobId'] ?? '');
            if ($id === '') {
                throw new Exception('The API did not return a job id.');
            }
            $jobs[$id] = ['list' => $list['name'], 'count' => count($chunk), 'created' => date('Y-m-d H:i'), 'status' => 'Queued'];
        }
        $ext->setOption('jobs', json_encode($jobs));
        notify()->addSuccess(sprintf('Verification started for %d addresses of "%s". Come back in a few minutes and click Fetch results.', count($emails), $list['name']));
    }

    private function fetchJob(SmtpingEmailVerifierExt $ext, string $jobId): void
    {
        $jobs = $this->jobs($ext);
        if (!isset($jobs[$jobId])) {
            throw new Exception('Unknown job.');
        }
        $client = $ext->getClient();
        $state = $client->getBulk($jobId);
        $status = (string)($state['status'] ?? '');
        $jobs[$jobId]['status'] = $status;

        if (strcasecmp($status, 'Succeeded') === 0) {
            $counts = ['safe' => 0, 'judgement' => 0, 'avoid' => 0];
            $blocked = $ext->getBlockedBands();
            foreach ($client->bulkResults($jobId) as $row) {
                $email = strtolower(trim((string)($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }
                $st = (string)($row['status'] ?? 'unknown');
                $band = SmtpingEmailVerifierExtClient::band($st);
                SmtpingEmailVerifierExtCache::set($email, $st, $band);
                $counts[$band]++;
                if (in_array($band, $blocked, true) && method_exists('EmailBlacklist', 'addToBlacklist')) {
                    EmailBlacklist::addToBlacklist($email, 'SMTPing: ' . $st);
                }
            }
            $jobs[$jobId]['status'] = 'Done';
            $jobs[$jobId]['result'] = $counts;
            notify()->addSuccess(sprintf('Results applied. safe: %d, judgement: %d, avoid: %d. Blocked addresses were added to the email blacklist.', $counts['safe'], $counts['judgement'], $counts['avoid']));
        } else {
            notify()->addInfo(sprintf('Job still %s: %d of %d processed.', strtolower($status), (int)($state['processedEmails'] ?? 0), (int)$jobs[$jobId]['count']));
        }
        $ext->setOption('jobs', json_encode($jobs));
    }

    private function forgetJob(SmtpingEmailVerifierExt $ext, string $jobId): void
    {
        $jobs = $this->jobs($ext);
        unset($jobs[$jobId]);
        $ext->setOption('jobs', json_encode($jobs));
    }

    private function jobs(SmtpingEmailVerifierExt $ext): array
    {
        $jobs = json_decode((string)$ext->getOption('jobs', '[]'), true);
        return is_array($jobs) ? $jobs : [];
    }
}
