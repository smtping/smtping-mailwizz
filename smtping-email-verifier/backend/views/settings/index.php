<?php defined('MW_PATH') or exit('No direct script access allowed');

/** @var SmtpingEmailVerifierExt $ext */
/** @var array|null $credits */
/** @var array $lists */
/** @var array $jobs */
/** @var array $stats */

$url = $ext->createUrl('settings/index');
$e = function ($v) { return CHtml::encode((string)$v); };
?>
<div class="box box-primary borderless">
    <div class="box-header"><h3 class="box-title">Settings</h3></div>
    <?php echo CHtml::beginForm($url, 'post'); ?>
    <?php echo CHtml::hiddenField('smtping_action', 'save'); ?>
    <div class="box-body">
        <div class="form-group">
            <label>API key</label>
            <input type="password" name="api_key" class="form-control" autocomplete="off" placeholder="<?php echo $ext->getApiKey() !== '' ? 'Saved. Paste a new key to replace it.' : 'sk_live_...'; ?>">
            <div class="help-block">Create a key in the <a href="https://app.smtping.com" target="_blank" rel="noopener">SMTPing dashboard</a>, under API keys.
            <?php if ($credits !== null) { ?> Remaining credits: <strong><?php echo $e($credits['remaining'] ?? '?'); ?></strong>.<?php } ?></div>
        </div>
        <div class="form-group">
            <label><?php echo CHtml::checkBox('enabled', (int)$ext->getOption('enabled', 0) === 1, ['value' => 1]); ?> Check new subscribers and imports</label>
            <div class="help-block">Subscribe forms, the API and list imports. Risky addresses are refused as blacklisted. If SMTPing cannot be reached, the address goes through.</div>
        </div>
        <div class="form-group">
            <label>Form check timeout (seconds)</label>
            <input type="number" name="form_timeout" min="3" max="30" class="form-control" style="max-width:120px" value="<?php echo (int)$ext->getFormTimeout(); ?>">
            <div class="help-block">How long a subscribe form waits for SMTPing, from 3 to 30. If there is no answer in time, the address is accepted. Most checks answer in 1 to 3 seconds; a higher value catches slow mail servers but keeps the visitor waiting longer.</div>
        </div>
        <div class="form-group">
            <label>Addresses to block</label>
            <?php echo CHtml::dropDownList('block_bands', $ext->getOption('block_bands', 'avoid'), [
                'avoid'           => 'Avoid only: invalid, spamtrap, disposable, blacklisted, complainer, spambot, inbox_full',
                'avoid_judgement' => 'Avoid and judgement: also catch-all, unknown, role',
            ], ['class' => 'form-control']); ?>
        </div>
        <div class="form-group">
            <label><?php echo CHtml::checkBox('check_on_send', (int)$ext->getOption('check_on_send', 0) === 1, ['value' => 1]); ?> Also check unknown addresses while sending campaigns</label>
            <div class="help-block">Off by default. When off, campaigns only use results already stored, so sending never spends credits.</div>
        </div>
    </div>
    <div class="box-footer"><button type="submit" class="btn btn-primary btn-flat">Save</button></div>
    <?php echo CHtml::endForm(); ?>
</div>

<div class="box box-primary borderless">
    <div class="box-header"><h3 class="box-title">Verify an existing list</h3></div>
    <div class="box-body">
        <p>Addresses verified in the last 30 days are skipped. 1 credit per address. Blocked addresses are added to the email blacklist when you fetch the results.</p>
        <?php echo CHtml::beginForm($url, 'post', ['class' => 'form-inline']); ?>
        <?php echo CHtml::hiddenField('smtping_action', 'verify_list'); ?>
        <select name="list_id" class="form-control">
            <?php foreach ($lists as $l) { ?>
                <option value="<?php echo (int)$l['list_id']; ?>"><?php echo $e($l['name']); ?> (<?php echo (int)$l['subscribers']; ?>)</option>
            <?php } ?>
        </select>
        <button type="submit" class="btn btn-primary btn-flat">Start verification</button>
        <?php echo CHtml::endForm(); ?>

        <?php if ($jobs) { ?>
        <table class="table table-striped" style="margin-top:20px">
            <thead><tr><th>List</th><th>Addresses</th><th>Started</th><th>Status</th><th>Result</th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($jobs, true) as $id => $j) { ?>
                <tr>
                    <td><?php echo $e($j['list']); ?></td>
                    <td><?php echo (int)$j['count']; ?></td>
                    <td><?php echo $e($j['created']); ?></td>
                    <td><?php echo $e($j['status']); ?></td>
                    <td><?php echo isset($j['result']) ? sprintf('safe %d, judgement %d, avoid %d', $j['result']['safe'], $j['result']['judgement'], $j['result']['avoid']) : ''; ?></td>
                    <td>
                        <?php echo CHtml::beginForm($url, 'post', ['style' => 'display:inline']); ?>
                        <?php echo CHtml::hiddenField('job_id', $id); ?>
                        <?php if (($j['status'] ?? '') !== 'Done') { ?>
                            <?php echo CHtml::hiddenField('smtping_action', 'fetch'); ?>
                            <button type="submit" class="btn btn-default btn-xs btn-flat">Fetch results</button>
                        <?php } else { ?>
                            <?php echo CHtml::hiddenField('smtping_action', 'forget'); ?>
                            <button type="submit" class="btn btn-default btn-xs btn-flat">Remove</button>
                        <?php } ?>
                        <?php echo CHtml::endForm(); ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php } ?>
        <p class="text-muted" style="margin-top:12px">Stored results: safe <?php echo (int)$stats['safe']; ?>, judgement <?php echo (int)$stats['judgement']; ?>, avoid <?php echo (int)$stats['avoid']; ?>.</p>
    </div>
</div>
