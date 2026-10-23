# ربات هوش مصنوعی بله (Persian Bale AI bot)

A Persian AI assistant for the **Bale** messenger, written in plain PHP. It has free and PRO users, paid packages through Bale invoices, referral rewards, chat memory with summaries, and voice-to-text for paying users.

![Project Banner](./assets/banner.png)

## What this version adds
- **Three model tiers instead of GPT names:**
  - ⚡ عادی (normal) is for free users.
  - 🧠 هوشمند (smart) and 🚀 فوق هوشمند (extra) are for PRO users, whether they paid or got PRO through a referral.
  - Each tier first tries free OpenRouter models, then falls back to its paid MetisAI model:

  | tier | paid fallback |
  |---|---|
  | normal | gpt-4.1-nano (as before for free users) |
  | smart | gpt-4.1-mini |
  | extra | gpt-4.1 |

- **🎤 Voice-to-text** using the existing GapGPT Whisper. Only users with a **verified paid purchase** can use it: Bale must confirm the payment as `paid`, or the admin grants it with `/grantpremium`. Referral or free PRO does **not** unlock it unless you turn on `transcription.allow_referral_pro`.
- **New tables (`v2_*`).** The old `users`/`messages` tables are never changed, so you can roll back at any time.
- **Payments are safer:**
  - every payment is stored in `v2_payments`;
  - a payment Bale delivers twice is credited only once;
  - the wallet token moved to `config.php`.
- **`check.php`**: one page that tests the database, Bale, OpenRouter, MetisAI and GapGPT, and can set the webhook for you.
- **Unchanged:** all Persian texts, commands, packages and prices, referral rewards, the daily limit (5), history and summaries (5 messages for free users, 15 for PRO), bans, monitor copies to `monitor_chat_id`, and ignoring groups.

## 📁 Files
| file | purpose |
|---|---|
| `api.php` | Webhook: chat, payments, voice |
| `commands.php` | `/start` `/help` `/setmodel` `/newchat` `/getpro` `/buypro` and the admin commands |
| `buttons.php` | Inline buttons, tier selection, invoices |
| `migrate.php` | **New, safe migration**: creates `v2_*` tables and copies users and history |
| `check.php` | Deployment check and webhook setup |
| `src/` | Model routing, free-model list, paid budget, voice-to-text, premium entitlements |
| `vendor/` | Libraries, already included; no Composer needed. Requires PHP 8.2 or newer. |
| `get_users.php`, `send_msg.php`, `send_to_all_users.py`, `ir_side_transport.php` | Admin helpers, as before |

---

# 🚀 Deploying (step by step)

The easiest setup to roll back is to upload into a **new folder**, for example `gpt_bot_v2/`, next to the old one. Both folders use the same database.

1. **Upload.** Upload the zip to the host and extract it into the new folder.
2. **Config.** Copy your current `config.php` from the old folder into the new folder. Then add these blocks to it (see `config-example.php`):
   ```php
   'payment' => ['provider_token' => 'WALLET-…'],          // the wallet token that was in buttons.php
   'transcription' => ['api_key' => 'YOUR_GAPGPT_KEY'],   // voice-to-text
   'monitor_chat_id' => 617483778,                        // receives chat copies, as before
   ```
   The `gpt.free_api_key` (OpenRouter) and `gpt.api_key` (MetisAI) entries you already have are used as they are.
3. **Migrate.** Open this once in the browser:
   `https://YOUR-HOST/gpt_bot_v2/migrate.php?pass=ADMIN_PASSWORD`

   It creates `v2_users`, `v2_messages`, `v2_payments`, `v2_premium_entitlements` and `v2_voice_usage`. It copies all users (PRO status, expiry, ban, and model converted to a tier) and the whole chat history. Running it again is harmless.
4. **Check.** Open `https://YOUR-HOST/gpt_bot_v2/check.php?pass=ADMIN_PASSWORD`. Every line should show ✔.
   - If **OpenRouter** shows ❌, your host can't reach openrouter.ai. The bot still works and simply answers with the paid MetisAI models.
   - Add `&chat=1` to send one tiny free test message. It never uses the paid API.
5. **Switch the bot to the new folder:** `…/gpt_bot_v2/check.php?pass=ADMIN_PASSWORD&setwebhook=1`
6. **Test in Bale:**
   - `/help`
   - a normal message
   - `/setmodel`
   - a voice message from a non-paying account (it should get the "خریداری" message)
   - optionally, a real `/buypro` payment

Running the bot needs no cron, Composer or command line.

Optional: refresh the free-model list every 6 hours with cron: `php /path/gpt_bot_v2/cron/refresh_models.php`. The bot also refreshes it on its own after replying.

## ↩️ Rolling back
Point the webhook back to the old folder:
`https://tapi.bale.ai/bot<TOKEN>/setWebhook?url=https://YOUR-HOST/OLD_FOLDER/api.php`

The old version uses the old `users`/`messages` tables, which were never touched. Anything that happened while the new version ran (new messages, payments, PRO time) exists only in the `v2_*` tables.

## 🔐 Security (please do this)
- **Delete `f.php` and `ftp.php` from the host.** `f.php` lets *anyone* read, edit and upload files on your server with no password. `ftp.php` does the same behind the password `admin123`. Neither file is part of this version.
- **Rotate these secrets.** They were stored in code or shared in plain text:
  - the bot token
  - the MetisAI, OpenRouter and GapGPT keys
  - the Bale wallet token
  - the ir_side bot token
  - the admin and database passwords
- The `storage/`, `src/`, `cron/` and `tests/` folders are blocked from the web by `.htaccess` on Apache. On nginx, deny them yourself.

---

# ⚙️ How it works

## Model tiers
Each tier has an ordered list of free OpenRouter models in `src/Settings.php`. You can override it in `config.php` under `'tiers'`.

| tier | free models, in order |
|---|---|
| ⚡ normal | nemotron-3.5-lightning, nemotron-3-super-120b, laguna-s-2.1 |
| 🧠 smart | nemotron-3-super-120b, inkling, nemotron-3.5-super-vl, laguna-s-2.1 |
| 🚀 extra | nemotron-3-ultra-550b, inkling, dots-3-note-preview, nemotron-3-super-120b |

- **Order of attempts.** The bot tries at most 3 free models per message, one at a time. If a tier's own models fail, it tries the free models of the lower tiers, then the tier's paid model.
- **Unavailable models.** A model that is rate-limited or down is skipped for a while, with no wasted requests. A model that OpenRouter no longer lists as free (retired or now paid) is skipped automatically.
- **Who gets which tier.** Free users always use `normal`. Buying PRO moves a `normal` user to `smart`. PRO users can choose any tier with `/setmodel`.
- **Paid budget.** The paid fallback is capped at `paid.max_requests_per_day` (500) and `paid.max_cost_per_day_usd` (about $3). Both are estimates, because MetisAI doesn't report costs.
- **OpenRouter free limits.** These are shared by all free models: 20 requests a minute, and 50 a day until the account has bought 10 credits (1000 a day after that). When the limit is reached, the bot switches to paid until the next day (UTC).

## Payments
The flow is the same as before:

`/buypro` → package button → **sendInvoice** → user pays → **pre-checkout** → **successful_payment** → **inquireTransaction**

- **Same requests as before:** the invoice, the pre-checkout answer and the transaction check are sent to Bale exactly as the old code sent them (form fields, `ok=1`). Only if Bale rejects one is it retried once as JSON.
- **Invoice:** if Bale refuses it (for example because the wallet token is wrong), the user gets an error message and the admin sees Bale's exact reason.
- **Accepting the payment (pre-checkout):** every payment is accepted, as before, within Bale's 10-second limit. The admin gets a short "pre-checkout تایید شد" line for each one, or Bale's error if the answer failed. Turn the line off with `'payment' => ['debug' => false]`.
- **Confirming:** the bot asks `inquireTransaction` (with `transaction_id`) up to 4 times over about 6 seconds, because the transaction can still be "pending" when the payment message arrives. `paid` is accepted in any letter case.
- **Only after confirmation** does the bot add PRO time, upgrade the tier and turn on voice-to-text for the same number of days.
- **Never credited twice:** a payment Bale sends twice, or one you already credited by hand, is never credited again.
- **Stuck payments:** if Bale reports a payment but never confirms it, the user gets the old "contact support" message. You get the details plus a ready command, `/grantpremium <id> <days> <receipt>`, to use after checking your wallet.
- **Record:** every payment is saved in `v2_payments`.

## Voice-to-text
- **Who:** only users with an active **paid** entitlement, checked on the server **before** anything is downloaded.
- **Limits:**
  - 5 minutes per message
  - 10 MB per file
  - 20 per hour
  - 30 minutes per day per user
  - 1 at a time per user
- **Duplicates:** the same voice message is transcribed only once.
- **Files and cost:** the audio file is deleted right away, and usage and estimated cost go to `v2_voice_usage`.
- **Messages:** everyone else gets a short Persian message that points to `/buypro`. Voice files are still forwarded to `monitor_chat_id`, as before.

## Admin commands (from `admin_chatid`, private chat only)
| command | action |
|---|---|
| `/grantpremium <chat_id> <days> [receipt]` | Grant PRO and voice-to-text, for card-to-card or other manual payments |
| `/revokepremium <chat_id>` | Cancel voice-to-text |
| `/premiumstatus [chat_id]` | Show voice-to-text status. Any user can check their own. |
| `/routingstats [days]` | Free vs paid share, success rate per model, spending |

## Tests
`php tests/run.php` runs about 250 automated checks. Every external service is mocked, so it uses no quota and no credit.
