# SMTPing Email Verifier for MailWizz

MailWizz 2.x extension that verifies email addresses with [SMTPing](https://smtping.com).

- **New subscribers**: subscribe forms and the MailWizz API refuse invalid, spamtrap, disposable and other risky addresses, the same way MailWizz refuses blacklisted ones.
- **List imports**: each import chunk is checked in parallel; risky addresses are skipped and reported as failures.
- **Existing lists**: pick a list in the extension settings, start a bulk verification, then fetch the results. Risky addresses are added to the MailWizz email blacklist.
- **No surprise costs**: results are stored for 30 days, so an address is never paid for twice, and campaign sending never spends credits unless you turn it on.
- Fails open: if SMTPing cannot be reached or does not answer within the form timeout (8 seconds by default, adjustable from 3 to 30), addresses go through.

Requires MailWizz 2.0 or later, PHP 7.4 or later with cURL.

## Install

1. Download `smtping-email-verifier.zip` from the [latest release](https://github.com/smtping/smtping-mailwizz/releases/latest).
2. In MailWizz, open **Backend > Extend > Extensions**, click **Upload extension** and select the zip.
3. Click **Enable** next to SMTPing Email Verifier.
4. Click the extension name to open its settings, paste your API key from the [SMTPing dashboard](https://app.smtping.com), tick **Check new subscribers and imports** and save.

## Bands

| band | statuses | default action |
| --- | --- | --- |
| `safe` | valid, alias | accepted |
| `avoid` | invalid, spamtrap, disposable, blacklisted, complainer, spambot, inbox_full | blocked |
| `judgement` | catch_all, unknown, role and others | accepted (blocked if you choose "Avoid and judgement") |

## Credits

1 credit per verified address. Every SMTPing account gets 25 free credits per day. See [pricing](https://smtping.com/pricing).

## Links

- [SMTPing API documentation](https://smtping.com/docs)
- Support: support@smtping.com
- Blog: [SMTPedia](https://smtpedia.com)

MIT License
