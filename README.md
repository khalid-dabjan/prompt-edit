# Pixelforge image generation

A small image-generation app built on the [Pixelforge](https://pixelforge.promptedit.dev/docs) API for the Senior Product Engineer take-home. It does three things:

- quotes a price before you generate
- charges exactly what Pixelforge billed plus a 25% markup, and nothing for failures
- keeps the history accurate even when webhooks arrive twice, late or out of order

**Live:** https://prompt-edit-6jbs81fn.on-forge.com/

- **Test accounts:** login details for a test user and a test admin are in the application form. The admin account also has the Admin report (`/admin/report`).
- **Your own account:** you can also sign up (no email verification), buy credits on the Credits page, then generate.

**Stack:** Laravel 13 · Inertia v3 · Vue 3 · Tailwind 4 · MySQL · Pest

## Features

|                                            |                                                                                                                                                                                                                                                  |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Generate** (`/generations/create`)       | Prompt, size, quality and up to 4 reference image URLs. The price range updates live as you type, and if your balance can't cover the maximum, the page says so before you submit.                                                               |
| **History** (`/generations`)               | Live status (pending → queued → processing → succeeded/failed), the estimated and actual price, the image, and a failure reason in plain words. A failed generation has a "Try again" button that pre-fills the form and quotes the price again. |
| **Credits** (`/credits`)                   | Your balance, a fake "Buy credits" button ($5 / $10 / $25), and every ledger entry.                                                                                                                                                              |
| **Admin report** (`/admin/report`)         | Credit spend, provider cost and profit per UTC day and per user, with each day checked against Pixelforge's `GET /v1/usage` to the nanodollar.                                                                                                   |
| **Webhooks** (`POST /webhooks/pixelforge`) | Signed, deduplicated and safe to receive in any order. A scheduled poller picks up anything the webhooks miss.                                                                                                                                   |

## Running it locally

Requirements: PHP 8.3+, Composer, Node 20+, and MySQL (SQLite also works).

```bash
composer run setup          # install, copy .env, generate the key, migrate, build
```

Then set these in `.env`:

```dotenv
PIXELFORGE_API_KEY=pf_...
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_WEBHOOK_SECRET=whsec_...   # from the webhook settings in the Pixelforge dashboard
GENERATION_IMAGES_DISK=local          # use an S3-compatible disk in production
```

To run everything:

```bash
composer run dev   # web server, queue worker, scheduler (poller), Vite
```

The **queue worker** submits generations and downloads images. The **scheduler** runs the poller every minute.

**Webhooks locally:** Pixelforge needs a public URL to reach you. Expose the site with `herd share` or `expose share http://<your-site>.test`, then set `https://<tunnel>/webhooks/pixelforge` as the webhook URL in Pixelforge. You can skip this step: without a tunnel the poller still brings generations to their final state, roughly a minute later.

**Accounts:** register in the app, then buy credits on `/credits` (new users start at $0). To open the admin report, run:

```bash
php artisan app:grant-admin you@example.com
```

## Checks

```bash
php artisan test --compact    # Pest
vendor/bin/pint               # code style
vendor/bin/phpstan analyse    # Larastan, level 7
npm run types:check           # vue-tsc
npm run check                 # frontend lint and format
```

Tests never reach the network: `Http::preventStrayRequests()` is enabled for every feature test, and Pixelforge responses come from fixtures in `tests/Fixtures/Pixelforge`.

## How it works

### Money

- **Every amount is an integer number of nanodollars** (1 USD = 10⁹). That's exactly Pixelforge's precision (`cost_usd` has 9 decimals), so provider costs compare with `/v1/usage` as plain integers. No floats are used anywhere.
- **Rates** from `GET /v1/pricing` are parsed as exact decimal strings, so `"12.50"` per million tokens becomes 12,500 nanodollars per token. A rate with more precision than that throws an error instead of being rounded.
- **Provider cost** is the final token usage times the **rate snapshot stored on the generation**, which is how Pixelforge bills.
- **Charge** = ⌈provider cost × (100 + markup) / 100⌉. The markup is stored on each generation, so a change to the config never affects work that's already running. At the current rates this is exact; rounding up only matters if the rates change.
- **Estimate** is a min–max range. Text tokens are ⌈characters / 4⌉ ± 15% and reasoning tokens use the published range, with both bounds rounded outward so the real price always lands inside the range. The same calculator serves the live quote and the stored quote.
- **Display** rounds only on screen. Ranges round outward, amounts under a cent show 4 decimals, and hovering shows the exact 9-decimal amount.

### Generation lifecycle

```
POST /generations
  └─ CreateGeneration (one DB transaction)
       ├─ quote the price, save a `pending` generation with a fresh Idempotency-Key
       ├─ hold the maximum quoted price from the balance (atomic, fails if it can't be covered)
       └─ dispatch SubmitGeneration after commit

SubmitGeneration (queued)
  ├─ POST /v1/generations with the same Idempotency-Key on every attempt
  ├─ overloaded / 429 / 5xx / network error → release() using Retry-After, or back off 5s → 60s, for up to 1 h
  └─ permanent refusal (content policy, daily limit, …) → failed, $0, hold returned

Webhook · poller · create response
  └─ ApplyGenerationSnapshot  ← the only code that changes status or money
```

`ApplyGenerationSnapshot` locks the generation row and only lets the status **move forward**. Succeeded and failed are final. Cost, charge and ledger settlement are written **only on the move into a final status**, inside the same transaction. As a result, a duplicate, late or racing `generation.succeeded` can never charge twice, and a stale `processing` event can never undo a finished generation.

### Webhooks

- The route lives outside the `web` middleware group, so it has no session or CSRF.
- The signature is HMAC-SHA256 over `"{t}.{raw body}"`, compared with `hash_equals`, with a ±300 s timestamp tolerance. A bad signature gets a 400.
- Each event is processed **inline, in one transaction**: insert into `webhook_events` (unique `event_id`), apply the snapshot, mark it processed.
    - A unique-key clash means the event is a duplicate, so we return 200 and do nothing.
    - Any other error rolls back the whole transaction and returns 500, so Pixelforge's retry starts clean.
- A webhook can arrive **before** the create response. We send `metadata.generation_id` with every create, so the event still finds its generation.

### Polling fallback

Pixelforge doesn't guarantee webhook delivery, so `pixelforge:poll-generations` runs every minute. It re-fetches up to 20 generations that are queued or processing and haven't changed for 60 s, checking the longest-unchecked first. With healthy webhooks it makes no requests at all.

### Images

Pixelforge's image URLs expire 24 h after completion. When a generation succeeds, `StoreGenerationImage` copies the PNG to our own disk. The app shows every image through `/generations/{id}/image`, which works like this:

- it serves our copy if we have one
- otherwise it redirects to Pixelforge's URL until that expires
- after that it returns 404, and the UI says the image is no longer available

Only the owner can see an image; anyone else gets 404.

### Credits

- `credit_transactions` is an append-only ledger with four entry types: `purchase`, `hold`, `hold_release` and `charge`. `users.balance_nanodollars` is a cached total, written in the same transaction as each entry.
- **Submitting** holds the maximum quoted price. **Finishing** returns the hold and charges the actual price. **Failing** returns the hold and charges nothing.
- A unique `(generation_id, type)` index means the database itself prevents a generation from being charged twice.

### Where things live

```
app/Services/Pixelforge/   API client, typed DTOs, error codes, webhook signature, cached pricing
app/Services/Billing/      PriceCalculator (estimate, provider cost, charge), CreditLedger
app/Actions/               CreateGeneration, ApplyGenerationSnapshot, BuildAdminReport
app/Jobs/                  SubmitGeneration, StoreGenerationImage
app/Console/Commands/      pixelforge:poll-generations, app:grant-admin
resources/js/pages/        generations/Create, generations/Index, credits/Index, admin/Report
```

## Assumptions and decisions

The docs and the brief leave some gaps. These are the main calls I made.

1. **Price shown as a range.** Text and reasoning token counts are only known after completion, so a single number would be a guess.
2. **Reserving the maximum price.** "Block generations the user can't afford" is read as: on submit, hold the highest quoted price, and settle to the actual price at the end. The balance can never go negative, and two submits at the same moment can't spend the same credits.
3. **Submission runs in a queued job.** This lets the app wait out `Retry-After` and the 3-concurrent limit without blocking the user. The Pixelforge limits apply to the whole account, so each user is also limited to 10 submits per minute.
4. **Retrying gives up after 1 hour.** The generation is then marked failed with "We could not confirm this generation started… You will not be charged for it." If Pixelforge later reports that it _did_ run (for example, the response was lost to a timeout), we show its real result but **don't charge the user** (`charge_waived`). We still record the real provider cost, so the admin report matches `/v1/usage` and shows that generation as a loss.
5. **Daily limit and content policy failures are final.** Both fail the generation immediately at $0 with a plain explanation, because retrying wouldn't help.
6. **Webhook timestamp tolerance is 300 s.** This comes from the docs' Laravel example; the prose gives no number.
7. **Poll interval and batch size** (every minute, 20 at a time, after 60 s of no change) are my own choices; the docs give no polling guidance.
8. **Admins are marked by an `is_admin` flag**, set with an artisan command. No admin management UI was asked for.
9. **The report groups by the UTC day a generation completed** and counts succeeded generations only, matching how `/v1/usage` reports.
10. **Deleted accounts keep their generations.** `user_id` is set to null rather than cascading the delete, so billing history and reconciliation survive.

## Known limitations

- **"Buy credits" is simulated.** No payment provider is involved.
- **The live estimate depends on cached pricing.** If `/v1/pricing` can't be reached and nothing is cached, the Generate page says prices are unavailable instead of guessing.
- **One environment per Pixelforge key.** The local and deployed apps should use separate keys and webhook secrets. Pixelforge's rate limits apply to the whole account, so all keys on it share them.
