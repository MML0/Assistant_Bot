# Telegram GPT Bot (PHP Edition)

🧠 A fully-featured Telegram AI bot written in PHP.  
Supports free & PRO users, persistent memory, auto-summary, model selection, admin controls, and MySQL storage.

![Project Banner](./assets/banner.png)

---

## ✨ Features

- 🆓 **Free users** with daily message limits  
- ⭐ **PRO users** with unlimited messages & model selection  
- 🧠 **Auto conversation summarization** → long-term memory  
- 🗄️ **MySQL storage** for users & messages  
- 🔧 **Config-based setup** (API keys, Telegram token, etc.)  
- 🤖 **Command handler + buttons**
- 🆓 **Free-first AI routing**: best available free OpenRouter model first, existing paid API only as a controlled fallback
- 🎙️ **Voice-to-text for paid premium users** (server-side entitlement check)

---

## 📁 Project Structure
project/
- │
- ├── api.php # Main bot logic
- ├── commands.php # /commands handler
- ├── buttons.php # Inline keyboards handler
- ├── migrate.php # Fresh DB migration tool (drops tables!)
- ├── migrate_upgrade.php # Non-destructive upgrade (premium + voice tables)
- ├── src/ # Model routing, discovery, budgets, entitlements, voice transcription
- ├── cron/ # refresh_models.php, routing_stats.php (CLI only)
- ├── tests/ # Offline test suite (php tests/run.php) + opt-in live smoke test
- ├── storage/ # Runtime state + routing logs (created automatically, never served)
- ├── config.php # Configuration (DB + API keys)
- ├── vendor/ # Composer packages
- └── README.md # You're reading this!

---

# 🚀 Installation

### 1. Clone the project
```sh
git clone <your-repo-url>
cd your-project
```
## 2. Install dependencies
```sh
composer install
```

## 3. Edit config.php
Copy `config-example.php` to `config.php`. Prefer environment variables for secrets (see below).

## 4. Upgrading an existing installation
```sh
composer install
php migrate_upgrade.php          # adds premium_entitlements + voice_usage, keeps all data
php cron/refresh_models.php --force   # optional: warm the free-model catalogue
```
Make sure the web server user can write to `storage/`. Apache is blocked from it by the
generated `.htaccess`; on nginx add `location ~ ^/(storage|src|tests|cron)/ { deny all; }`.

## 5. Database Migration (Fresh)
GET  
```link
https://yourhost.com/migration.php?pass=ADMIN_PASSWORD
```
## 6. Webhook Setup
GET 
```link
https://api.telegram.org/bot<token>/setWebhook?url=https://yourhost.com/api.php
```
---

# 🧭 Free-first model routing

Every AI request (chat replies and history summaries) goes through `src/Router.php`:

1. **Pick** the best eligible free model from a cached, ranked catalogue. No network call is made to choose a model.
2. **Try it once.** On a recoverable, model-specific error, try the next model. The default limit is 3 free attempts per message. Requests are never sent in parallel, and the same prompt is never fanned out to many models.
3. **Fall back** to the existing paid API (MetisAI, the user's `/setmodel` model) only when the free route fails. It must also be allowed by policy and within the daily budget.
4. **Give up cleanly.** If nothing is allowed, the user gets the usual short error. Internal details never go into the chat.

### Discovery and ranking (`src/ModelCatalog.php`)
- **Sources:** `GET /api/v1/models?sort=intelligence-high-to-low` (falls back to the unsorted list if the sort is rejected), plus `GET /api/v1/benchmarks?source=artificial-analysis` for Artificial Analysis intelligence and coding indices. `GET /api/v1/key` reports whether the key is free-tier.
- **What counts as free:** a model is free only when every published price component is exactly `0`. A `:free` suffix or the word "free" in the name is never enough, and routers priced at `-1` are excluded. The model must also output text, have at least 8k context, and not expire within a day.
- **Ranking:** models are ranked by benchmark score, adjusted by observed reliability, latency and rate-limit history. A missing benchmark is estimated as *median × 0.9*, never zero. Popularity is never used.
- **Refresh:** runs after the reply has been sent (`fastcgi_finish_request`) or from cron. The default interval is 6 hours, with a 15-minute back-off after failures. A failed or empty refresh keeps the last known-good list. Before the first discovery, `gpt.free_model` is used as a seed.

### Protecting scarce quota (`src/ModelHealth.php`)
- **Cooldowns by error type.** Upstream rate limits honour `Retry-After` and `X-RateLimit-Reset`, otherwise back off exponentially. Provider outages and timeouts also get exponential cooldowns. Invalid model IDs are blocked for 24 hours or until the catalogue lists them again. A model in cooldown is skipped without a request; there are no "is it back?" probes.
- **Account-wide errors stop the free chain at once.** These are `free-models-per-day` / `per-min` limits, 401, 402 and network failures. The free route is paused until the reset time, or until 00:00 UTC for the daily limit, and requests go straight to the paid policy.
- **Scarce models are saved for hard work.** A model is treated as scarce if it often hits rate limits or matches `routing.reserved_model_patterns`. Ordinary chat uses it last; hard reasoning and coding requests may use it first.
- **Local counters** track the documented 20 requests/minute and daily allowance. They are only our own estimate: the provider's real remaining quota stays *unknown* unless OpenRouter reports it in a header.

### Modes (`routing.mode` or `BOT_ROUTING_MODE`)
| mode | behaviour |
|---|---|
| `free_first` (default) | Free models, protecting scarce ones for hard tasks, then paid fallback |
| `quality_first` | Strongest free model first (scarce ones included), then paid fallback |
| `cost_min` | Free models only; paid is never used automatically |
| `paid_only` | Existing paid API only |

`routing.pro_model_choice_is_explicit_paid = true` treats a PRO user's `/setmodel` choice as an explicit paid request.

### Paid fallback limits (`paid.*`)
| setting | default | purpose |
|---|---|---|
| `fallback_enabled` | `true` | Allow automatic paid fallback |
| `max_requests_per_day` | `300` | Paid request cap |
| `max_cost_per_day_usd` | `2.0` | Spending cap, estimated from the price table in `src/Settings.php` |
| `max_output_tokens` | `700` | Output cap per paid request |
| `max_attempts` | `3` | Paid retries |
| `attempt_timeout` | `20` | Seconds per paid attempt |

Before each paid request, its worst-case cost is reserved against the daily budget. When the cap is reached, the bot stops, tells the user it is at its limit for now, and alerts the admin.

### Free-inference caveats
- OpenRouter documents **20 requests/minute** for free models, and **50 requests/day** until the account has bought at least 10 credits (**1000/day** after). These limits are shared by **all** free models on the key.
- Free endpoints may log prompts or use them for training, depending on your OpenRouter privacy settings. To restrict this, set `openrouter.provider_preferences = ['data_collection' => 'deny']`; fewer free models will then be available.
- Free models come and go. Quality and availability can change without notice.

### Environment variables
| variable | meaning |
|---|---|
| `OPENROUTER_API_KEY` | OpenRouter key (overrides `gpt.free_api_key`) |
| `TRANSCRIPTION_API_KEY` | Speech-to-text key (GapGPT) |
| `TRANSCRIPTION_BASE_URL`, `TRANSCRIPTION_MODEL` | Optional provider override (any OpenAI-compatible `/audio/transcriptions` API) |
| `BOT_ROUTING_MODE` | `free_first`, `quality_first`, `cost_min` or `paid_only` |
| `BOT_PAID_FALLBACK_ENABLED` | `true` or `false` |
| `BOT_STORAGE_DIR` | Location of runtime state and logs |

### Diagnostics
- **Logs:** `storage/logs/routing-YYYY-MM-DD.jsonl` has one line per attempt and one per request. Each line records request id, model, free/paid, reason, latency, tokens, cost, error class, fallback reason and success. Prompts, replies and keys are never logged.
- **`/routingstats [days]`** (admin, private chat) or `php cron/routing_stats.php` shows:
  - free, paid and failed percentages
  - success rate and latency per model
  - fallback rate and estimated spend
  - cooldowns and repeatedly failing models

# 🎙️ Premium voice-to-text

Voice and audio messages are transcribed only for users with an **active, paid premium entitlement**.

- **Where entitlements live:** rows in the `premium_entitlements` table, created only by the admin command `/grantpremium <telegram_user_id> <days> [payment_ref]`. The admin runs it *after verifying the payment*. Purchases are still handled manually, via the contact shown in `/getpro`.
- **Who counts as the admin:** the sender's Telegram id must equal `telegram.admin_chatid`, in a private chat.
- **Other admin commands:** `/revokepremium <id>` cancels. `/premiumstatus` shows a user their own status; the admin can add an id to check anyone.
- **What does not unlock voice:** `users.is_pro`, which is handed out free via referrals and the hourly code; `/start`; being a friend or group admin; usernames; Telegram Premium; or anything the user says.
- **Order of checks:**
  1. Entitlement check on the server, before anything is downloaded or sent to the provider. If the database fails, access is denied.
  2. Duration, size and MIME limits, then a content sniff of the real file.
  3. Per-user hourly limit and daily minutes.
  4. One job per user, a few globally.
  5. Duplicate update and duplicate audio checks.
- **Metering:** each call is recorded in `voice_usage` with an estimated cost. Temp audio is deleted right away.
- **Provider:** unchanged, GapGPT `gapgpt/whisper-1`, billed per minute. Its key now comes from config/env instead of source code.
- **Alternatives:**
  - OpenAI's current transcription models are cheaper per minute than `whisper-1`.
  - Groq hosts `whisper-large-v3` with a free tier.

  Both work by changing `TRANSCRIPTION_BASE_URL`, `TRANSCRIPTION_MODEL` and the key. Neither is enabled without your decision. OpenRouter's free *chat* models are **not** a transcription service, and audio is never sent to them.

# ✅ Tests
```sh
php tests/run.php                                   # offline, all HTTP mocked, no quota or credit used
OPENROUTER_API_KEY=... php tests/live_smoke.php     # opt-in: discovery only (no chat requests)
OPENROUTER_API_KEY=... php tests/live_smoke.php --chat  # + exactly ONE tiny free chat request
```

---

### 🧑‍💻 Contributing
PRs welcome!
You can contribute by:
- Fixing bugs
- Improving documentation
- Adding new Telegram commands
- make tarnslation like not hard coded replys
- Enhancing memory, models, or UI buttons

---

### 🐞 Bug Reports
Create an issue with:
- Expected behavior
- Actual behavior
- Logs (if possible)
- Steps to reproduce
- PHP version
- Hosting environment

Or contact admin Telegram ID: @MML_0

---

### 📄 License (MIT)
MIT License

Copyright (c) 2025 MML

You can use it commercially.
