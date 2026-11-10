# Money spent — shared expenses for two

[Русская версия](README.ru.md)

Two people record what they spend with one short line, such as `17000 groceries` or `18,5 eur chatgpt 10.11`. They
can do it in a native macOS app, in the browser at `http://localhost:8080`, or through a Telegram bot. Every amount is
converted to AMD at the Central Bank of Armenia rate for the day of the expense. The app keeps:

- monthly totals and charts;
- who owes whom;
- shared and personal spending;
- recurring payments.

It can also import an old phone note and export a month as PDF or CSV. The interface is in English and Russian.

Stack: PHP 8.5, Symfony 8.1, Doctrine DBAL 4 without the ORM (DoctrineBundle 3, MigrationsBundle 4), PostgreSQL 16,
Symfony Messenger + Scheduler, Symfony Translation and Intl, PHPUnit 13, PHPStan level 8, php-cs-fixer (PSR-12).

## macOS app (no Docker)

Open `build/Traty-1.0.0.dmg`, drag the app to Applications and start it. The bundle carries:

- PHP 8.5;
- PostgreSQL 16;
- the web app itself.

Data lives in `~/Library/Application Support/MoneySpent`: the database, logs, and `secrets.json` (mode 0600) with a
random database password and `APP_SECRET`. To open the folder, use the app menu → Open data folder.

To rebuild, you need Xcode Command Line Tools, Homebrew `php` and `postgresql@16`, and composer:

```bash
make app
```

What `packaging/macos/build.sh` does:

- copies the code with `composer install --no-dev`;
- moves PHP and PostgreSQL out of Homebrew together with every library they load (`bundle-runtime.php` rewrites the
  paths to `@loader_path`);
- builds the Swift WKWebView wrapper;
- signs everything ad hoc.

On launch the wrapper:

- starts PostgreSQL on a random `127.0.0.1` port with a password;
- runs the migrations;
- starts `php -S` and the scheduler worker.

It stops everything on quit. The wrapper follows the macOS language (Russian or English). The pages start in the
same language and have their own RU / EN switch.

The build is not notarized. It opens right away on the Mac that built it. On another Mac, macOS asks for confirmation
on first launch (right click → Open). It is built for the architecture of the build machine (Apple Silicon).

### Sharing with a second Mac

1. On your Mac choose **Allow connections** in the app menu. The app then listens to the home network on port 8765 and
   announces itself over Bonjour. If macOS asks whether to accept incoming connections, allow it. If the firewall is on
   and no question appears, the **Sharing** page shows the two `socketfilterfw` commands to run; repeat them after
   every reinstall. Then open **Sharing → Show code** for the second person.
2. On the second Mac:
   - install the app from the same `.dmg`;
   - choose your Mac from the list on first launch, or type `http://name.local:8765`;
   - enter the code. It is valid for 10 minutes, works once, and is burned after 5 wrong tries.
3. Sharing works while the first Mac is on and the app is open. New expenses and transfers of the other person arrive
   as macOS notifications, checked every 30 seconds.

Nothing opens from the network without a code. The Sharing page is visible on the main Mac only, and devices are
revoked there. Traffic on the network is not encrypted, so turn sharing on at home, not on public Wi-Fi.

## Quick start (Docker and Telegram)

1. Create a bot with @BotFather and find your Telegram id, for example with @userinfobot.
2. Put the secrets into `.env.local` (not committed):

   ```dotenv
   TELEGRAM_BOT_TOKEN=123456:ABC...
   TELEGRAM_WEBHOOK_SECRET=a-long-random-string
   ALLOWED_TELEGRAM_IDS=111111111,222222222
   ```

3. Start everything from scratch:

   ```bash
   docker compose up -d --build
   ```

   The `init` service:

   - installs dependencies when `vendor/` is missing;
   - runs migrations and seeds currencies and categories;
   - warms the cache and fetches rates for the last 7 days.

   After it, `php-fpm`, `nginx` (port `8080`) and `worker` start. Check with `curl localhost:8080/health`.

4. Open `http://localhost:8080` and add the two participants. Telegram is optional.

5. To connect Telegram, use one of these:
   - a webhook, which needs public HTTPS (for example through cloudflared or ngrok):
     `make webhook URL=https://bot.example.com`;
   - long polling for local development, with no public address: `make poll`.

   Both people send `/start` to the bot. On the Participants page each person gets their Telegram id, so that entries
   from the bot and from the browser belong to the same people.

## Web app

### Overview `/`

- **Entry line.** Choose who paid: one person, or **both, half each**. Choose what to record: an expense, or a transfer
  to the other participant or to someone else. Choose whose expense it is: shared, the payer's own, or for the other
  person.
- **Month list.** Every row is edited in place, and the record keeps its id:
  - date;
  - amount and currency, with the base amount recalculated at the rate for that day;
  - category, payer and whose expense;
  - note.
  On a narrow window each row folds into two or three lines.
- **Filter** by shared and personal.
- **Side panel:**
  - debt line;
  - total;
  - categories;
  - participants (paid / share);
  - 12-month chart;
  - transfer form.
- **Export:** PDF and CSV buttons, and month switching.

### Other pages

| Page | What it has |
|---|---|
| Analytics `/analytics` | Periods: a month, 3, 6 or 12 months, all time, or custom. Filter by all, shared, or each person's own. Charts, drawn on the server as SVG with no libraries: shared vs personal, payer → owner → category flows, a chord diagram of whose money ↔ categories, a category donut, monthly columns, a category × month table, a calendar, weekdays, the largest expenses and the most frequent places from notes. |
| Recurring `/recurring` | Subscriptions and anything that repeats monthly. Each row is edited in place: day of month (31 means the last day), category, note, payer (one person or both, half each), amount and currency, and whose expense it is. Pause and delete. The expense is written on its day: the worker checks hourly, and the app also checks at launch. Missed months are filled in, and a month can't be written twice (the UUID comes from the id and the month). The ↻ button on the overview makes any expense recurring. |
| Import `/import` | Paste an old note or upload a `.txt` file. The preview shows the date, category, payer and note of every line, with a checkbox for each. A line holding only a date (`07.11`) dates the lines below it, lines without digits are skipped, and a name in the line sets the payer. Importing the same text twice adds nothing, and deleted lines can be imported again. A sign before the amount means who paid (`+` one person, `−` the other); it can be switched to "minus is a refund". |
| Categories `/categories` | Your own expense types: add, rename, set keywords, hide and show again. A hidden category keeps its old expenses. "Other" can't be hidden. |
| Currencies `/currencies` | Add any ISO 4217 currency, with optional words for the entry line (`лари, ₾`). Hide and show again; a hidden currency keeps the records made in it. The base currency can't be hidden. Rates come from the Central Bank of Armenia (and the Bank of Russia for display) when they publish that currency. |
| Participants `/participants` | Names, optional Telegram id, and a badge color. |
| Sharing `/sharing` | Network address, codes for a second Mac, connected devices, firewall help. Visible on the main Mac only. |
| `/report/2026-11.pdf`, `/export/2026-11.csv` | One month as a file. |

The currency switch in the header (every shown currency) recalculates all totals, the debt, charts and the PDF at the
latest rate. Under it are the current rates of the other currencies, from the Central Bank of Armenia or the Bank of
Russia. The amount of an expense in its own currency never changes.

In the Docker setup the web app listens on `127.0.0.1` only and answers only to the hosts in `TRUSTED_HOSTS`. There is
no password login: the app is local, and a second Mac connects with a one-time code.

## Writing expenses

The entry line understands Russian and English words at the same time, whatever the interface language.

| Message | Result |
|---|---|
| `17000 groceries` / `17000 продукты` | 17,000 AMD, Groceries, today, shared |
| `18,5 eur chatgpt 10.11` | 18.50 EUR → AMD at the rate of 10 November, Subscriptions |
| `9700 delivery curry yesterday` | yesterday's date, note "curry" |
| `700 coffee personal` / `700 кофе лично` | the payer's own expense, no debt |
| `3000 flowers her` / `3000 цветы ей` | entirely for the other person |
| `$12.99 icloud`, `40 usd`, `50 лари` | any shown currency → AMD at the bank rate |
| `alice sent 5000`, `алиса скинула 5000` | a transfer between the two: lowers the debt |
| `sent mom 10000` | a transfer to someone else: the sender's own expense in Transfers, note "→ mom" |
| `-2500 rub from alice` | a refund: a negative amount lowers totals and debt |

- **Amount.** The first number. The decimal separator is `,` or `.`. The currency goes after the number as a separate
  word or joined to it (`2500р`, `€18.5`); the default is AMD.
- **Date.** `dd.mm`, `dd.mm.yy(yy)`, `today` / `сегодня`, `yesterday` / `вчера`. A date without a year that would be in
  the future means last year.
- **Category.** Found by keywords; a keyword of 4+ letters also matches the start of a word. No match means Other.
  Several matches save the expense in Other, and the bot offers buttons to pick one.
- **Note.** Everything else becomes the note.

## Bot commands

| Command | What it does |
|---|---|
| `/start` | register and show help |
| `/month`, `/month 10`, `/month 10.2025` | month totals: total in AMD, by category, by person (paid / share) |
| `/balance` | who owes whom, all time |
| `/settle 2500 rub` | my transfer to the other person |
| `/undo` | soft-delete my last entry (expense or transfer) |
| `/export`, `/export 10` | the month's expenses as a CSV file |
| `/language en`, `/language ru` | reply language; by default it follows the Telegram app language |

The buttons under an expense card are Category and Delete. Only the author of an expense can change or delete it
through the bot. Messages from ids outside `ALLOWED_TELEGRAM_IDS` are silently ignored. On the 1st of every month at
10:00 Yerevan time, the worker sends both people last month's summary and the current balance.

## Development

All commands run in the containers:

```bash
make check      # cs + phpstan + all tests
make test-unit  # domain tests only, no database
make test       # creates app_test, migrates, runs unit + integration
make rates      # fetch 7 days of rates and recalculate expenses without a rate
make sh         # shell in php-fpm
```

## Architecture

```
src/Domain          pure logic, no database, Telegram or Symfony: Money, Currency, ExchangeRate, SplitCalculator,
                    BalanceCalculator, ExpenseParser.
src/Application     use cases: recording expenses and transfers, inline edits, recurring expenses, categories,
                    currencies, month report, balance, undo, export, rate updates, sharing.
src/Infrastructure  DBAL repositories (Query Builder), cb.am and cbr.ru rate sources, ISO currency data,
                    Telegram HTTP client.
src/Bot             update parsing, command and button routing, reply texts.
src/Web             the web app: controllers, overview builder, analytics, chart layouts (chords, Sankey, donut —
                    pure geometry), PDF (dompdf), language, request protection.
translations        English texts; the keys are the Russian source strings, so a missing translation shows Russian.
packaging/macos     the .app/.dmg build: Swift wrapper (en/ru), bundled PHP and PostgreSQL, php.ini, router for php -S.
templates/web       Twig templates of the pages and the PDF; public/app.css holds the styles.
```

- **Bot extensions.** A new bot command is a class implementing `BotCommand`, and a new button is a `BotCallback`.
  Registration goes through tags, so the routers don't change.
- **Replies.** Handlers return `Reply` objects instead of sending messages. `UpdateProcessor` stores the `update_id`
  and every record in one transaction, and sends the replies after the commit.

## Decisions worth knowing

- **Money.** Amounts are stored only as `bigint` minor units. Currencies live in the `currency` table: the exponent,
  the words that name them, and whether they are shown. Seeding only adds what is missing. Conversion uses bcmath,
  rounding half away from zero.
- **Shared and personal.** Whose expense it is comes from `split`:
  - `equal` is shared;
  - `payer_only` belongs to the payer;
  - `other_only` belongs to the other person (`SplitType::ownerId`).

  Changing the payer keeps the owner.
- **Both paid.** "Both, half each" is stored as two shared halves, one paid by each person. The totals count the whole
  amount, and the debts cancel out. An odd minor unit goes to the first person.
- **Splitting.** An odd minor unit goes to the payer; the shares always add up to the amount.
- **Idempotency.** The `telegram_update` table ignores a repeated `update_id`. On top of that, `expense.uuid` is a
  UUID v5 of `chat_id:message_id`, so one message never makes two expenses.
- **Rates.** The Central Bank of Armenia (SOAP).
  - For a date not yet published, cb.am returns the last working day's rate, so the rate is stored under the date the
    bank returned.
  - The worker asks for the last 3 days every day at 13:30 Yerevan time. An expense takes the rate of its day or the
    closest earlier one.
  - With no rate at all, the expense is saved with `rate_to_base = NULL` and filled in once the rate appears. Until
    then it is left out of totals, and the bot says how many such expenses there are.
  - Editing the date or currency of an expense takes the rate anew.
- **Languages.** The `lang` cookie or the browser's Accept-Language picks ru or en for the pages. Bot replies use the
  participant's saved language, or the Telegram app language. Default category titles are translated; renamed ones
  show as typed.
- **Web without sessions.** CSRF protection checks `Sec-Fetch-Site` / `Origin` on every changing request
  (`SameOriginGuard`). `TRUSTED_HOSTS` blocks DNS rebinding; CSP and `X-Frame-Options: DENY` are set. A second Mac
  gets a 256-bit device token in an HttpOnly cookie; only its SHA-256 is stored.
- **Logs.** The `http_client` channel is off, because Telegram request URLs contain the bot token.
- **PDF.** Rendered on the server by dompdf with no external resources; charts are tables, not JS.

## Ideas for later

- Shares other than 50/50.
- Category budgets with alerts.
- Password login for phone access.
