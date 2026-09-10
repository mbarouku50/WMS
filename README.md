# WMS — Wi-Fi Management System

A complete **multi-provider** hotspot management platform: one installation
hosts many independent Wi-Fi businesses, each with its own customers,
packages, vouchers, payments, routers and staff — completely isolated from
one another.

Customers, packages, vouchers, mobile-money payments, sessions, devices,
routers, usage accounting and reporting — built to be uploaded to ordinary
shared hosting and connected to MikroTik routers when you are ready.

Plain PHP 8 with MySQLi (object oriented), HTML, CSS and vanilla JavaScript.
No framework, no build step, no Composer, no `public/` folder.

---

## Table of contents

1. [Multi-provider architecture](#multi-provider-architecture)
2. [What it does](#what-it-does)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Upgrading an existing WMS](#upgrading-an-existing-wms)
6. [Signing in](#signing-in)
7. [Running a provider](#running-a-provider)
8. [Testing tenant isolation](#testing-tenant-isolation)
9. [Money: wallets, fees and payouts](#money-wallets-fees-and-payouts)
10. [Connecting a real MikroTik](#connecting-a-real-mikrotik)
11. [The Network module](#the-network-module)
12. [Demo mode vs live mode](#demo-mode-vs-live-mode)
13. [Folder structure](#folder-structure)
14. [Payments (SonicPesa)](#payments-sonicpesa)
15. [MikroTik integration](#mikrotik-integration)
16. [Scheduled tasks](#scheduled-tasks)
17. [Deploying to shared hosting](#deploying-to-shared-hosting)
18. [Security notes](#security-notes)
19. [The API](#the-api)
20. [Architecture](#architecture)
21. [Troubleshooting](#troubleshooting)

---

## Multi-provider architecture

One WMS installation is a **platform** hosting many **providers**. A provider
is an independent Wi-Fi business; its data never touches another's.

```
                        WMS PLATFORM
                             │
                      SUPER ADMIN  ── platform scope, sees everything
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
   Mbaruku WiFi         FastNet WiFi         City WiFi
   (provider 1)         (provider 2)         (provider 3)
        │                    │                    │
   Provider users       Provider users       Provider users
   Customers            Customers            Customers
   Packages             Packages             Packages
   Vouchers             Vouchers             Vouchers
   Payments             Payments             Payments
   Routers  ×4          Routers  ×2          Routers  ×1
   Access points        Access points        Access points
   Devices              Devices              Devices
   Sessions             Sessions             Sessions
   Usage                Usage                Usage
   Reports              Reports              Reports
```

### The three kinds of account

| | Who they are | Scope |
|---|---|---|
| **Super Admin** | You, the platform owner | Global — every provider |
| **Provider Administrator** | The person running one Wi-Fi business | That provider only |
| **Provider staff** | Their sales, network and support people | That provider, narrowed by role |
| **Customer** | The end user buying Wi-Fi | Their own account, on one provider's portal |

A provider user can never reach platform administration, and can never hold a
platform permission — that is enforced in `Permission::has()` regardless of
what the `role_permissions` table says.

### How isolation actually works

Three layers, each independent of the last:

**1. The tenant context is established at login, from the database.**

`ProviderContext` reads `users.provider_id` when you sign in and keeps it in
the session. It is never read from a query string, form field or JSON body —
a request cannot choose which tenant it belongs to.

```php
$providerId = ProviderContext::providerId();   // from the session
// never:  $providerId = $_POST['provider_id'];
```

**2. The scope is applied in SQL, in the base model.**

`Model::find()`, `findBy()`, `all()`, `updateById()`, `deleteById()` and
`countAll()` all add `AND provider_id = ?` for tenant-owned tables. Asking for
another provider's id returns `null` and updating it affects zero rows — so
the IDOR hole closes everywhere at once, not page by page:

```php
$customer = $customers->find($id);   // scoped: another tenant's id misses
```

`create()` stamps `provider_id` automatically, and `updateById()` strips it
from the payload so a record can never be moved between tenants by an
ordinary edit.

**3. Services and pages check explicitly where it matters.**

`TenantGuard` turns a missing or foreign record into a clean refusal, logs the
attempt and writes an audit entry. `NetworkService::providerFor()` refuses to
hand back a live provider for a router the caller does not own — the gate that
stops one provider ever issuing a RouterOS command to another's hardware.

### What is tenant-owned, and what is not

| Provider-owned (`provider_id`) | Platform-level |
|---|---|
| customers, packages, bandwidth_profiles | roles, permissions, role_permissions |
| voucher_batches, vouchers, subscriptions | login_attempts |
| routers, access_points, devices, sessions | platform rows in `settings` (`provider_id = 0`) |
| usage_records, payments, transactions | |
| alerts, notifications, audit_logs | |
| users (`NULL` = platform account) | |

**Settings** carry both levels in one table: `provider_id = 0` is the platform
default, a real id is that provider's override. `Setting::get()` returns the
provider's value when it has one and falls back to the platform's otherwise.
Platform-only keys (demo mode, router poll intervals) are refused from a
provider even if posted.

**Voucher codes stay globally unique**, so a customer typing a code at the
portal is never ambiguous — and the voucher's own `provider_id` becomes the
context for activating it.

### Viewing as a provider

A Super Admin with the `impersonate_provider` permission can open **Providers →
⋯ → View as this provider**. While that is active:

* every query runs in that provider's scope
* an orange banner names the provider and offers the way back
* start and stop are both written to the audit log
* the Super Admin's own account is never modified

---

## What it does

| Module | What you get |
|---|---|
| **Providers** | Create and run many independent Wi-Fi businesses on one installation. Each gets its own dashboard, staff, branding, currency, packages and routers. Suspend one without losing a single record. |
| **Provider wallets** | Customer mobile-money payments credit the selling provider's wallet automatically. Full ledger with a running balance, withdrawals to bank or mobile wallet through SonicPesa payouts, and manual adjustments with an audit trail. |
| **Platform billing** | A recurring fee per provider — amount, cycle and start date agreed individually, so you can give a new business a grace period. Invoices are raised and settled from the wallet automatically; shortfalls are flagged, never enforced by cutting anyone off. |
| **Customers** | Accounts, devices, sessions, usage and payment history on one profile. Suspend, activate, search, filter, export. |
| **Packages** | Fully configurable: price, duration (minutes/hours/days/months), data cap or unlimited, download/upload speed, device limit. Nothing is hard-coded. |
| **Vouchers** | Single or bulk generation with prefixes and custom code length, batch tracking, the full lifecycle (available → activated → active → expired/exhausted, plus suspended/cancelled), printable cards, CSV export. |
| **Payments** | Provider abstraction with **SonicPesa** (M-Pesa, Tigo Pesa, Airtel Money, Halopesa) and a **demo provider** so you can test the whole flow without moving money. Webhooks, status polling, refunds, manual counter sales. |
| **Captive portal** | Mobile-first customer portal: enter a voucher, buy a package, sign in, check time and data remaining. |
| **Sessions & devices** | Who is online, on what device, through which access point, using how much. Disconnect or block from the same screen. Server-side device-limit enforcement. |
| **Routers & access points** | Multiple MikroTik routers with encrypted credentials, status polling, a Test Connection button, and alerts when one goes quiet. |
| **Bandwidth profiles** | Define a rate limit once, reuse it across packages, push it to routers as a hotspot user profile. |
| **Usage & reports** | Daily/monthly data and time accounting; revenue, customer, voucher, network and usage reports — on screen, printable, CSV. |
| **Alerts** | Offline routers and access points, failed payments, expiring packages, high usage, high CPU/memory, suspicious logins. |
| **Staff & roles** | Super Admin, Network Administrator, Sales Administrator and Support, with server-side permission checks and a full audit log. |

---

## Requirements

* PHP **8.0 or newer** with `mysqli`, `curl`, `openssl`, `mbstring` and `gd`
* MySQL **5.7+** or MariaDB **10.3+**
* Apache with `mod_rewrite` (nginx works too — see [Security notes](#security-notes))
* Write access to `config/`, `logs/` and `uploads/`

No Composer, no Node, no build pipeline. Upload and run.

---

## Installation

1. **Upload** the `WMS` folder into your web root, for example:

   ```
   public_html/
   └── WMS/
   ```

2. **Create a database** (or let the installer create it, if your database
   user has the privilege).

3. **Set permissions** so PHP can write to three folders:

   ```bash
   chmod 755 config logs uploads
   chmod 755 uploads/logos uploads/documents
   ```

4. **Open the installer** in your browser:

   ```
   https://example.com/WMS/install.php
   ```

   It walks through four steps:

   | Step | What happens |
   |---|---|
   | 1. Requirements | Checks PHP version, extensions and folder permissions. |
   | 2. Database | Tests the connection and creates the database if it is missing. |
   | 3. Administrator | Your account, system name, timezone, currency, and (optionally) your SonicPesa keys. |
   | 4. Finish | Imports `database/schema.sql`, optionally loads demo data, writes `config/app.local.php` and locks the installer. |

5. **Delete `install.php`** once you are in. The Settings page reminds you
   until you do.

The installer imports three files, in this order:

| File | What it does |
|---|---|
| `database/schema.sql` | The base tables, plus the system roles and permissions |
| `database/upgrade_multi_provider.sql` | The `providers` table, `provider_id` on every tenant table, role/permission scopes, and the default provider |
| `database/seed.sql` | Optional demo data — two providers, so you can see the isolation working |

### Manual installation

If you would rather not use the installer, run them in the same order:

```bash
mysql -u youruser -p yourdb < database/schema.sql
mysql -u youruser -p yourdb < database/upgrade_multi_provider.sql
mysql -u youruser -p yourdb < database/seed.sql        # optional demo data
```

Then copy the config template and fill it in:

```php
<?php
// config/app.local.php
return [
    'db_host'     => 'localhost',
    'db_name'     => 'wms_db',
    'db_user'     => 'wms_user',
    'db_pass'     => 'your-password',
    'db_port'     => 3306,
    'app_name'    => 'WMS',
    'app_url'     => 'https://example.com/WMS',
    'app_key'     => 'GENERATE_64_RANDOM_HEX_CHARACTERS_HERE',
    'timezone'    => 'Africa/Dar_es_Salaam',
    'environment' => 'production',
];
```

Generate the `app_key` with:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

It encrypts stored router passwords — **changing it later makes existing
router credentials unreadable**, and you will need to re-enter them.

Finally create `config/installed.lock` with any content so the app knows it
is installed.

---

## Upgrading an existing WMS

If you already run a single-business WMS with real data, the migration adds
multi-tenancy **without losing anything**.

```bash
# 1. Back up. Always.
mysqldump -u USER -p DBNAME > wms-backup-$(date +%F).sql

# 2. Run the migrations, in this order
mysql -u USER -p DBNAME < database/upgrade_multi_provider.sql
mysql -u USER -p DBNAME < database/upgrade_provider_billing.sql
mysql -u USER -p DBNAME < database/upgrade_network_production.sql
```

It ends by printing a verification table — read it before you trust the run:

```
what                            rows_
providers                       1
customers without a provider    0
packages without a provider     0
vouchers without a provider     0
routers without a provider      0
payments without a provider     0
platform users (super admin)    1
provider users                  3
```

Every "without a provider" line must be `0`.

### What the migration does

1. Creates the `providers` table.
2. Adds `scope` to `roles` and `permissions`, creates the Provider
   Administrator role and the platform-only permissions.
3. Adds `provider_id` (plus indexes, then foreign keys) to every tenant-owned
   table.
4. Reshapes `settings` so a provider can hold its own values.
5. Creates **"Default Wi-Fi Provider"** (`PRV-0001`) and gives it every
   existing customer, package, voucher, payment, router, session and usage
   row. Nothing is deleted.
6. Leaves Super Admin accounts global (`provider_id NULL`) and attaches all
   other staff to the default provider.

Afterwards, rename the default provider to your own business under
**Providers → Edit**, and carry on exactly as before — you now simply have one
provider instead of none.

### What the network production migration does

`database/upgrade_network_production.sql` prepares the Network module for
real hardware. It **only adds** — no row is deleted, no password is reset, no
status is cleared.

**New columns on `routers`**

| Column | Why |
|---|---|
| `hotspot_profile`, `default_user_profile` | Which RouterOS profiles new hotspot users get |
| `board` | Board/model, as reported by `/system/resource` |
| `last_sync_at` | Last *successful* poll, distinct from `last_seen_at` |
| `failed_checks` | Consecutive failed contacts, for the retry policy |
| `deleted_at` | Soft deletion, so retiring a router keeps its history |

**New columns on `access_points`**

| Column | Why |
|---|---|
| `monitoring_source` | Where the status actually came from |
| `last_status_change_at` | When it genuinely changed, for uptime reporting |
| `notes` | Free text for the operator |
| `deleted_at` | Soft deletion, as above |

**Widened enums** — `routers.status` gains `degraded` and `retired`;
`access_points.status` gains `retired`. Widening keeps every existing value,
so no row changes meaning.

**New indexes** — `routers(provider_id, mode)`, `routers(last_seen_at)`,
`routers(deleted_at)`, `access_points(provider_id, status)`,
`access_points(router_id, status)`, `access_points(last_seen_at)`,
`access_points(deleted_at)`, `access_points(ip_address)`.

**New constraints, added only when the existing data allows it**

* `UNIQUE (provider_id, mac_address)` on `access_points` — one physical radio,
  one record, per provider. If two live records already share a MAC inside one
  provider the index is **skipped**, and the duplicates are listed at the end
  of the script so you can decide which to retire. Nothing is deleted for you.
* `UNIQUE (provider_id, name)` on `routers`, replacing the old
  platform-wide unique name — so two providers can both have a "Main Router".
  Skipped the same way if the data does not allow it.

**Data transformation** — exactly two `UPDATE`s, both corrective:

1. Access points that already had a `router_id` and a `last_seen_at` are
   marked `monitoring_source = 'router'`, because that is where their status
   came from. Everything else stays `manual`, which the interface shows as
   *Not monitored* rather than as a measurement.
2. Any access point whose `provider_id` disagreed with its router's is
   corrected in favour of the router, which is the authoritative link.
   (Going forward this is enforced in PHP on every write — MySQL cannot
   express a cross-table CHECK.)

`management_ip` in the specification is the existing `ip_address` column on
`access_points`: it has always held the AP's management address. Only the
**label** changes, to "Management IP address". The column keeps its name so
existing rows, indexes and code continue to work.

### Safe to run twice

Every `ALTER` is guarded by a check against `information_schema`, so re-running
the file adds nothing twice and changes no data. If a run is interrupted, run
it again.

---

## Signing in

The demo data creates a platform owner and two complete Wi-Fi businesses, so
you can prove the isolation the moment you sign in:

| Account | Username | Password | Scope |
|---|---|---|---|
| Platform owner | `admin` | `Admin@123` | Global — sees both providers |
| Mbaruku WiFi — administrator | `mbaruku` | `Admin@123` | Provider 1 only |
| Mbaruku WiFi — sales | `baraka` | `Admin@123` | Provider 1, sales permissions |
| Mbaruku WiFi — network | `asha` | `Admin@123` | Provider 1, network permissions |
| FastNet WiFi — administrator | `fastnet` | `Admin@123` | Provider 2 only |
| FastNet WiFi — support | `juma` | `Admin@123` | Provider 2, support permissions |

Demo customer portal login: `0754000001` / `Customer@123`

Sign in as `mbaruku`, then as `fastnet`, and compare the customer lists: they
share nothing.

**Change these before going anywhere near production.**

URLs:

```
https://example.com/WMS/                     landing page
https://example.com/WMS/login.php            staff sign in
https://example.com/WMS/admin/               control centre
https://example.com/WMS/customer/            captive portal
```

---

## Running a provider

### 1. Create the provider

**Providers → Add provider**. You fill in the business details and its first
administrator in one form; both are created in a single transaction.

Tick *Give this provider a starter set-up* and they also get one bandwidth
profile and three sample packages, so their dashboard is not an empty room on
the first morning.

### 2. Give them a router

Routers are the security boundary, so only you assign them.

**Providers → ⋯ → Routers** offers two paths:

* **Add a router here** — created already belonging to this provider.
* **Assign** an existing router — moves it, and its access points, across.
  The previous provider loses access the instant you confirm.

```
Super Admin
     ↓
Add / choose router
     ↓
Configure the MikroTik API connection
     ↓
Test connection
     ↓
Assign to provider
     ↓
That provider — and nobody else — can now operate it
```

A provider can hold as many routers as you give them.

### 3. They sign in

The provider administrator signs in at the same `/WMS/login.php`. WMS reads
their `provider_id` and drops them into their own dashboard, branded with
their business name. They see their customers, their vouchers, their routers,
their money — and no trace of anybody else's.

### 4. They run their business

Everything in the single-business WMS is still there, now inside their
boundary: packages, voucher generation and printing, the captive portal,
payments, sessions, devices, usage, reports, alerts, their own staff, and
their own settings.

### Suspending a provider

**Providers → ⋯ → Suspend**. Nothing is deleted:

* their staff cannot sign in
* no new package can be bought and no voucher activated
* every customer, payment, voucher, session and audit record is preserved
* you can still inspect all of it from the provider overview

Activate them again and they carry on where they left off.

---

## Testing tenant isolation

Worth doing yourself before you trust it with real money.

**Sign in as `mbaruku` and try to reach FastNet's data:**

```
/WMS/admin/customers/view.php?id=<a FastNet customer id>
/WMS/admin/packages/edit.php?id=<a FastNet package id>
/WMS/admin/payments/index.php?id=<a FastNet payment id>
/WMS/admin/network/routers.php?edit=<a FastNet router id>
/WMS/admin/vouchers/print.php?ids=<a FastNet voucher id>
/WMS/admin/providers/index.php
```

Every one must refuse: a redirect with "could not be found", or an empty
result. None may show FastNet's data.

**Try it through the API too:**

```bash
curl -b cookies.txt -H "X-CSRF-Token: $TOKEN" \
     -d '{"router_id":<FastNet router>}' \
     https://example.com/WMS/api/network/test.php
# {"success":false,"message":"That router could not be found."}
```

**Try forging the tenant:**

Post `provider_id=2` alongside a FastNet record id to any admin form. It is
ignored — the server takes the tenant from your session, and the record still
does not resolve.

**Check the portal:**

Enter a FastNet voucher code on the Mbaruku portal. It is refused with
*"That voucher belongs to a different Wi-Fi network."*

**Compare the exports:**

Export vouchers as each provider. The row counts must add up to the platform's
total, and neither file may contain the other's codes.

Every refusal is written to `logs/app-*.log.php` and to the audit log as
`access_denied`, so attempts are visible after the fact.

---

## Money: wallets, fees and payouts

### How the money moves

The SonicPesa keys belong to **you**, the platform. So customer money lands
in your merchant account, and WMS credits the selling provider's wallet.
They draw it out when they want it, and your fee comes out of the same
wallet.

```
   Customer pays TSh 1,000 by mobile money
            ↓
   SonicPesa → YOUR merchant account
            ↓
   WMS credits the provider's wallet   +1,000
            ↓
   ┌────────────────────┬─────────────────────┐
   ↓                    ↓                     ↓
Provider withdraws   Platform fee taken   Refund reverses
   −amount              −10,000              −amount
   (SonicPesa payout)   (your income)
```

Every movement is one row in `wallet_transactions`, carrying the balance it
produced, so a wallet can always be audited or rebuilt from the ledger.

### Two ways a provider can sell

| | Voucher sales | Mobile money |
|---|---|---|
| Always available | Yes | No — you grant it |
| Who takes the money | The provider, in cash, at the counter | You, into your merchant account |
| Wallet credited | No | Yes, automatically |
| Default for a new provider | **On** | **Off** |

A provider starts on vouchers only. That is deliberate: until you have
agreed terms with a business, you do not want their customers' money
arriving in your account.

**To allow mobile money:** Providers → (them) → Edit → *How they may sell* →
tick *Allow this provider to sell packages by mobile money*.

**To withdraw the permission:** untick it. The change is audit logged with
your name on it, takes effect immediately, and existing wallet money is
untouched.

When it is off, the customer portal hides the Buy tab, the packages page
explains that this network sells vouchers, and `PaymentService` refuses the
purchase on the server — so opening the purchase URL by hand achieves
nothing.

### The platform fee

Set per provider when you register them, and changeable afterwards.

**Providers → Add provider → Platform fee:**

| Field | What it does |
|---|---|
| Fee per cycle | Default TSh 10,000. Set 0 for a provider you are not charging. |
| Charged every | Month, 2 months, quarter, 6 months or year |
| Start charging | Immediately, or after 1, 2, 3, 6 or 12 months |

That last field is the agreement you described: *"after two months you start
paying TSh 10,000 a month"*. Pick **After 2 months** and the first invoice
falls due two months from registration. Until then the provider shows as
**Grace** and owes nothing.

On the Edit form the grace dropdown becomes an explicit **Billing starts on**
date, so you can move an agreed start date later.

### The billing cycle

```
registration ──grace period──> first invoice ──7 days──> due
                                    │                     │
                                    │                     ├─ wallet covers it → taken automatically, marked Paid
                                    │                     └─ wallet short     → stands as a debt, provider flagged Overdue
                                    │
                                    └─ repeats every cycle
```

Run by `cron.php`, or by hand with **Billing → Run billing now**.

An overdue provider is **flagged and alerted, never switched off**. Cutting a
business off is a decision for a person, so it stays your call — suspend them
from the provider list if you decide to.

A provider also cannot withdraw money they owe you: the withdrawal form
refuses to leave the wallet short of the outstanding fee.

### Withdrawals

**The provider** goes to **Wallet → Withdraw money**, chooses a destination
and an amount. The money moves from *available* to *held* at once, so the
same shillings cannot be requested twice.

**You** see it under **Billing → Withdrawals** and either approve or decline:

- **Approve** → WMS calls the SonicPesa payout API. On success the held
  amount becomes a real debit; the webhook (`payout.success` /
  `payout.failed`) or the cron poll closes it out.
- **Decline** → the hold is released and the money returns to their wallet.

Destinations: M-Pesa, Tigo Pesa, Airtel Money, Halopesa, CRDB Bank, NMB Bank
and Selcom. Selcom's account-number rules (no leading `0` or `255`, 9-digit
phone or a longer card) are validated before anything is sent.

Set the minimum withdrawal and whether approval is required under
**Settings → Payments**.

### Correcting a wallet

**Billing → Adjust a wallet** credits or debits a provider by hand, with a
reason. It appears in their ledger as *Manual adjustment* and in the audit
log with your name on it.

### What each side sees

**Provider — Wallet**: available balance, what is held, earned this month,
fees owed, the full ledger with a running balance, their withdrawals, and
their unpaid invoices with a *Pay now* button.

**You — Billing**: total held across all providers, fees collected and
outstanding, withdrawals waiting for approval, every invoice, and every
provider wallet side by side.

---

## Connecting a real MikroTik

This is the procedure you follow, as platform owner, to put a provider on
real hardware. Everything below is done from the WMS interface except the
four blocks you paste into the router.

### The shape of it

```
   YOU (Super Admin)                      THE PROVIDER
   ─────────────────                      ────────────
   1. Create the provider
   2. Add their router
   3. Assign it to them
   4. Run the readiness check
   5. Switch it to Live                →  6. Creates packages
                                          7. Generates vouchers
                                          8. Sells them
                                             ↓
                                          Customer redeems on the portal
                                             ↓
                                          WMS creates a hotspot user
                                             ↓
                                          The router lets them online
```

### Step 1 — Create the provider

**Providers → Add provider.** Fill in the business, and its first
administrator in the same form. Both are created together, or neither is.

Tick *Give this provider a starter set-up* and they also get a bandwidth
profile and three sample packages to edit.

### Step 2 — Add their router

**Providers → (the provider) → Routers → Add a router here.**

The form is grouped into four blocks:

**Router identity**

| Field | What to enter |
|---|---|
| Router name * | Anything you will recognise: `FastNet-Main` |
| Location | Where the box physically is: `Main Building` |
| Notes | Anything the next engineer should know |

**Connection**

| Field | What to enter |
|---|---|
| API address * | The router's address **as seen from the WMS server** — IPv4, IPv6 or a hostname |
| Connection type * | `RouterOS API` (plain) or `RouterOS API TLS` (api-ssl) |
| API port * | `8728` for the plain API, `8729` for API-SSL |
| API username * | The dedicated account you create below — never `admin` |
| API password | Stored AES-256 encrypted; never shown again |

TLS is **never** inferred from the port. If the connection type and the port
disagree, WMS refuses the save and says which one to change, rather than
silently overriding your choice.

**Hotspot** — all optional

| Field | What to enter |
|---|---|
| Hotspot server | The server name from `/ip hotspot print`. Leave blank to use every server on the router |
| Hotspot profile | The server profile, if you use one |
| Default user profile | Applied to hotspot users when a package carries no bandwidth profile |

Once the router is saved and Live, **Read hotspot servers from the router**
fills these from the device itself. WMS never assumes a server called
`hotspot1` exists — if the router has none, it says so and leaves the field
empty.

**Operation**

| Field | What to enter |
|---|---|
| Mode | Start on **Demo**, switch to Live once the checks pass |

Creating it here assigns it to that provider in the same step.

> The router must be reachable *from the web server*. On shared hosting it
> usually is not — your MikroTik is on a private LAN and the host is in a
> data centre. Either run WMS on the same network, give the router a public
> address with the API firewalled to the server's IP, or connect the two
> with a VPN.

### Step 3 — Configure the router

**Network → Routers → ⋯ → Set up & readiness check** shows these with your
own values filled in. Paste them into Winbox → New Terminal.

**a. Let WMS in** — a dedicated account, restricted to your server:

```
/ip service enable api
/ip service set api port=8728 address=YOUR.WMS.SERVER.IP/32

/user group add name=wms policy=api,read,write,test,winbox
/user add name=wms-api group=wms password=A-STRONG-PASSWORD
```

The `address=` restriction is what stops the API being reachable from
anywhere else. Do not skip it in production.

**b. Build the hotspot** — if you do not already have one:

```
/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254
/ip address add address=10.5.50.1/24 interface=bridge-hotspot
/ip hotspot setup
```

The wizard asks for the interface, address pool, certificate (`none`), DNS
and one initial user. It creates the server, profile and DHCP for you.

**c. Open the walled garden** — so an unpaid customer can reach the portal
and pay:

```
/ip hotspot walled-garden
add dst-host=your-wms-domain.com comment="WMS portal"
add dst-host=api.sonicpesa.com comment="Mobile money"
```

Miss this and customers reach the login page but payment fails at the last
step, because the gateway is blocked.

**d. Point the login page at WMS.** In `/ip hotspot profile`, set the HTML
directory's `login.html` to redirect to:

```
https://your-wms-domain.com/WMS/customer/?nasid=$(server-address)
```

The `nasid` is what tells WMS which provider the customer is on — that is
why it comes from the router, not from a URL a customer could edit.

### Step 4 — Run the readiness check

**Network → Routers → ⋯ → Set up & readiness check → Run readiness check.**

WMS connects and tests six things, each with a plain answer and, where it
fails, the RouterOS command that fixes it:

| Check | What it proves |
|---|---|
| RouterOS API reachable | The address, port, credentials and firewall are all right |
| Identity and version | WMS is talking to the router you think it is |
| Hotspot server configured | There is something for a hotspot user to belong to |
| IP pool available | Clients will actually get an address |
| **WMS may create and remove hotspot users** | The API account has write access — the one that matters |
| Current hotspot state | How many users and live sessions exist now |

The write check creates a throwaway user called `wms-probe-xxxxxx` and
deletes it again. If it fails, voucher activation would silently do nothing,
which is exactly the failure you want to find before a customer does.

### Step 5 — Switch to Live

The same page has the switch. Until you flip it, WMS will not contact the
router and voucher activation will not create hotspot users.

Then turn the platform-wide switch off too, if you have not already:
**Settings → General → Demo mode → off.**

### What happens when a customer redeems a voucher

```
Customer types the code on the portal
        ↓
VoucherService validates it       status, expiry, data left, device limit
        ↓                          — all decided server side
Session and device recorded
        ↓
NetworkService picks the router    and refuses if it belongs to another provider
        ↓
MikroTikService creates a hotspot user
   name     = the voucher code
   password = the voucher code
   profile  = the package's bandwidth profile
   limit-uptime      = the package duration
   limit-bytes-total = the package data cap
        ↓
The router lets them online and enforces speed and caps itself
        ↓
Each poll reads the counters back into WMS
```

WMS decides **who may** connect. The router decides **what happens on the
wire**. If the router is unreachable, WMS records the grant, says plainly
that nothing was pushed, and raises an alert — it does not pretend.

### Keeping it in step

Set the cron job (see [Scheduled tasks](#scheduled-tasks)). Every few
minutes it polls each live router, pulls active sessions and traffic
counters back, expires finished vouchers and closes stale sessions. Without
it, the dashboard only refreshes when somebody loads a page.

### If the check fails

| Message | Usually means |
|---|---|
| *Connection refused* | `/ip service` has `api` disabled, or the port is wrong |
| *Did not answer in time* | A firewall in the way, or the router is not routable from the web server |
| *Rejected the API username or password* | Wrong credentials, or the account lacks the `api` policy |
| *Nothing answered the RouterOS API on this port* | Something else is listening — check the port number |
| *Write failed* | The API account can read but not write: add `write` to its group policy |

Every attempt is written to `logs/network-*.log.php` and to the audit log.

---

## The Network module

The Network module is what makes WMS usable with real hardware. It is built
on one rule: **a value is only shown if something actually measured it.**
Where a reading was never retrieved, the interface says *Unknown* — it never
prints a zero that looks like a measurement.

### MikroTik preparation checklist

Before adding a router in WMS, the administrator must:

* [ ] **Enable the API** — `/ip service enable api` (or `api-ssl` for TLS)
* [ ] **Restrict it to the WMS server** — `/ip service set api address=YOUR.WMS.SERVER.IP/32`
* [ ] **Create a dedicated WMS API user** — never reuse `admin`; it needs the
      `api`, `read`, `write` and `test` policies
* [ ] **Configure a hotspot** — `/ip hotspot setup`, so hotspot users have a
      server to belong to
* [ ] **Check routing and firewall** — the WMS server must be able to open a
      TCP connection to the router's API port
* [ ] **Note the real management address** — whatever the WMS server can
      reach. Do **not** assume `192.168.88.1`; WMS and the router are often
      on different networks
* [ ] **Confirm the API port** — `8728` plain, `8729` for API-SSL
* [ ] **Test connectivity from the WMS server itself**, not from your laptop:

```bash
# From the machine running WMS
nc -vz ROUTER.ADDRESS 8728
```

Then add the router in WMS and press **Test connection**.

### Test connection

Read-only, and safe to run against a gateway carrying live customers. It
runs the whole path and reports each step separately:

```
Test Connection
      ↓  validate input
      ↓  check provider ownership
      ↓  connect to MikroTik        (up to 3 attempts)
      ↓  authenticate
      ↓  read router identity
      ↓  read RouterOS version
      ↓  check the API access WMS needs
      ↓  check hotspot configuration
      ↓  record, audit, return
```

A success looks like this:

```
✓ Connection successful
✓ API connection            — 10.0.0.1:8728 answered in 12 ms
✓ Authentication            — the API account "wms-api" was accepted
✓ Router identity           — MIKROTIK-MAIN
✓ RouterOS version          — RouterOS 7.14.2 on hAP ax2
✓ Hotspot user/profile access — 24 hotspot user(s) readable
✓ Active session access     — 8 active session(s) right now
✓ Hotspot configuration     — Using "guest-hotspot".
```

A failure names the causes without ever echoing a credential:

```
✕ Connection failed
The router rejected the API username or password.

Possible causes:
• The API username or password is wrong
• The API account is disabled on the router
• The account lacks the "api" policy
```

The deeper **readiness check** (Network → Routers → ⋯) goes further and
writes a throwaway probe user to prove the account can create hotspot users.
That one changes the router, so it is a deliberate action rather than
something the interface does on its own.

### Router status model

Four states, not two:

| Status | Meaning |
|---|---|
| **Online** | Recent successful communication |
| **Degraded** | Reachable, but a health metric it reported is over threshold |
| **Offline** | Was reachable before, and is not now — after the retry budget |
| **Unknown** | Never successfully contacted, or nothing has been attempted |

A router is marked **Degraded** when it reports CPU ≥ 85%, memory ≥ 90%, or
answers more slowly than 3000 ms. Those same thresholds raise the matching
alert, so the badge and the alert can never disagree.

**A single timeout does not take a router offline.** Each contact runs up to
three attempts, and a router only becomes Offline after three consecutive
failed contacts:

```
Attempt 1 → short retry → Attempt 2 → short retry → Attempt 3 → mark unavailable
```

Authentication failures are the exception: a wrong password will be just as
wrong on the third try, so it fails immediately rather than provoking the
router's own brute-force protection.

A router that has **never** answered stays *Unknown* rather than becoming
*Offline* — calling it offline would claim knowledge WMS does not have.

`last_seen_at` is never cleared by a failure, which is what makes
*"Last seen: 17 minutes ago"* possible on a router that has just gone down.

### Stale data

Cached readings older than three polling intervals are labelled:

```
● Stale        Data may be outdated — last sync 12 minutes ago
```

Old numbers are never presented as if they were current.

### Access point monitoring

An access point is a **radio**, not a gateway. It broadcasts an SSID and
carries client devices; it authenticates nobody and enforces no bandwidth.
WMS therefore never infers an AP's state from its router:

> **Router online ≠ AP online.** They are different devices.

Status comes only from a real monitoring source, recorded per record in
`monitoring_source`:

| Source | Status |
|---|---|
| `router` | Implemented — through the parent MikroTik |
| `snmp`, `controller`, `vendor_api` | Reserved; see `AccessPointMonitor` |
| `manual` | A human entered it. Shown as *Not monitored*, never as a live reading |

What the router-based monitor counts as evidence:

1. **CAPsMAN** — if the router runs CAPsMAN and the AP is one of its CAPs,
   the router knows for certain whether it is connected, and how many
   clients it carries. This is a direct measurement.
2. **ARP** — a *complete* ARP entry means the AP answered the router
   recently. An entry that is present but *incomplete* means the router
   asked and got no reply, which is real evidence of an outage.

What is **not** evidence:

* The router being reachable.
* The *absence* of an ARP entry — entries age out, so silence is not an
  outage. Those access points are simply left **Unknown**.

Client counts come only from CAPsMAN. ARP cannot tell how many phones are
associated with a radio, so nothing is reported rather than guessed.

Adding UniFi, Omada, SNMP or another vendor later means writing one class
against `AccessPointMonitor` and adding one branch to
`NetworkService::monitorFor()`. Nothing else in WMS changes.

### Polling and sync

No page opens its own connection to MikroTik. The scheduler polls, stores,
and every page then reads the WMS database:

```
Cron / scheduled task
        ↓
   Network sync        (each router isolated from the others)
        ↓
  Router A   Router B   Router C
        ↓
  Store latest health / state
        ↓
  Dashboards read the WMS database
```

The interval is **Settings → Network → Router poll seconds** (default 60).
One router being offline never stops the sweep, never affects another
provider, and never fails the request.

### Retiring, not deleting

Sessions, vouchers, payments, usage and audit rows all reference a router.
Deleting the row would strand every one of them, so WMS **retires** instead:

* the record stays, marked `retired` with a `deleted_at` stamp;
* it disappears from every working list and is never contacted again;
* its access points are retired with it;
* all history keeps pointing at a record that still exists.

The same applies to access points. Nothing in the Network module hard-deletes
a record that history depends on.

### What is enforced where

| Concern | Where |
|---|---|
| Ownership of a router or AP | `Model::find()` / `updateById()` — in SQL, on every action |
| Cross-provider RouterOS commands | `NetworkService::providerFor()` — refuses and audits |
| AP ↔ router provider agreement | `admin/network/access-points.php`, on create and edit |
| One MAC per provider | `AccessPoint::isMacTaken()` + a unique index |
| Suspended provider | `NetworkService::refuseWhenSuspended()` |
| CSRF, permission, authentication | `api/_bootstrap.php` on every endpoint |

None of it lives only in the interface. Calling an endpoint directly with
another provider's router id returns *"That router could not be found."*

---

## Demo mode vs live mode

WMS is built to be fully usable before you own a single router.

**Demo mode** (Settings → General) lets every business feature work —
customers, packages, vouchers, payments, reports — while being honest about
what it cannot see:

* Router and access point status is reported as **`unknown`**, never a
  fabricated "online".
* Live readings (CPU, memory, interfaces, hotspot sessions) return
  "not available in demo mode" rather than invented numbers.
* Sessions, devices and usage rows created without a router are flagged
  `source = 'demo'` and carry a **Demo** badge everywhere they appear.
* Voucher activation and disconnect still work — they say plainly that
  nothing was pushed to hardware.

**Live mode** is per router: set a router's mode to *Live*, give it API
credentials, turn demo mode off in Settings, and WMS starts polling the real
device. What you see then comes from RouterOS.

Demo mode is a **platform** setting — a provider cannot turn it on or off for
the installation. The demo data ships with two providers precisely so tenant
isolation can be exercised before any hardware exists.

> The application never presents simulated network data as live. That rule is
> enforced in `DemoNetworkProvider`, which refuses to answer questions it
> cannot answer honestly.

---

## Folder structure

```
WMS/
├── index.php               Public landing page (the front door — no public/ folder)
├── login.php               Staff sign in
├── logout.php              Sign out (staff and portal)
├── dashboard.php           Convenience redirect
├── install.php             Installer — delete after installing
├── cron.php                Scheduled housekeeping
│
├── config/
│   ├── config.php          Bootstrap: errors, autoloader, URLs, session
│   ├── database.php        Credentials (reads config/app.local.php)
│   ├── constants.php       Paths and the shared status vocabularies
│   └── app.local.php       Written by the installer — never commit this
│
├── core/                   Framework-ish plumbing
│   ├── Database.php        MySQLi OOP wrapper, prepared statements only
│   ├── Model.php           Tiny CRUD + pagination base class
│   ├── Auth.php            Staff & customer login, remember-me, throttling
│   ├── Session.php         Secure sessions, idle timeout, flash messages
│   ├── CSRF.php            Token issue and verification
│   ├── Crypto.php          AES-256-CBC + HMAC for stored router passwords
│   ├── Permission.php      Role-based access control, platform vs provider
│   ├── ProviderContext.php Which tenant is this request? (the single source)
│   ├── TenantGuard.php     One place that refuses cross-tenant access
│   ├── Validator.php       Rule-based input validation
│   ├── Logger.php          Dated log files with secret masking
│   └── Response.php        JSON / redirect / CSV responses
│
├── classes/                One class per entity
│   ├── Provider.php        The tenant itself (platform-scope only)
│   ├── Wallet.php          Provider wallet ledger
│   ├── Withdrawal.php      Payout requests
│   ├── PlatformInvoice.php Platform fees
│   ├── User.php  Customer.php  Package.php  Voucher.php  Payment.php
│   ├── Subscription.php  Device.php  SessionModel.php  Router.php
│   ├── AccessPoint.php  BandwidthProfile.php  Usage.php  Alert.php
│   └── AuditLog.php  Setting.php
│
├── services/               Business logic — pages never talk to routers or gateways directly
│   ├── VoucherService.php  Generation, activation, the state machine
│   ├── PaymentService.php  Charging, fulfilment, webhooks, refunds
│   ├── SessionService.php  Device limits, opening/closing sessions
│   ├── NetworkService.php  The single door to network equipment
│   ├── MikroTikService.php RouterOS provider (live mode)
│   ├── WalletService.php   The only thing that moves provider money
│   ├── BillingService.php  Platform fees, grace periods, invoicing
│   ├── ReportService.php   Every report, one place
│   ├── network/            NetworkProvider, DemoNetworkProvider, RouterOsApi
│   └── payments/           PaymentProvider, DemoPaymentProvider, SonicPesaProvider
│
├── admin/                  Control centre (see the sidebar for the map)
│   ├── _platform_dashboard.php  What a Super Admin sees instead of a provider dashboard
│   ├── billing/            Platform-only: fees, invoices, payout approval
│   ├── wallet/             Provider-only: balance, ledger, withdrawals
│   └── providers/          Platform-only: create, edit, inspect, assign routers,
│                           manage staff and read the activity of each tenant
├── customer/               Captive portal (mobile first)
├── api/                    Small JSON endpoints, no router framework
├── includes/               Layout partials + the reusable component library
├── assets/css              style.css, admin.css, customer.css, net.css, responsive.css
├── assets/js               app.js, admin.js, customer.js
├── uploads/                Logos and documents (never executable)
├── database/               schema.sql, upgrade_multi_provider.sql,
│                           upgrade_provider_billing.sql, seed.sql
└── logs/                   Dated log files, not web readable
```

---

## Payments (SonicPesa)

### Configure

**Settings → Payments**:

1. Set **Active provider** to *SonicPesa*.
2. Paste your **Access key** (`X-API-KEY`) and **Secret key** (`X-API-SECRET`).
3. Save, then press **Test the connection**.

Keys are stored server-side, shown only as a mask afterwards, and never
appear in HTML, JavaScript or logs. Leaving a key box empty keeps the stored
value — it is write-only from the UI.

### Webhook

Set this URL in your SonicPesa API settings so payments confirm even when the
customer closes the page:

```
https://example.com/WMS/api/payments/webhook.php
```

The signature is verified with
`hash_hmac('sha256', $rawBody, $apiSecret)` against the
`X-SonicPesa-Signature` header **before** anything is written. An unsigned,
forged or unverifiable callback is rejected with 401 and changes nothing.

### The purchase flow

```
Customer picks a package
        ↓
PaymentService->purchasePackage()      creates a pending payment
        ↓
SonicPesaProvider->createOrder()       Push USSD to the customer's phone
        ↓
Customer enters their PIN
        ↓
Webhook  ─or─  api/payments/status.php polling
        ↓
PaymentService->fulfil()               issues the voucher + opens a subscription
        ↓
Access granted
```

Fulfilment is idempotent — a webhook and a poll arriving together will not
issue two vouchers. If a webhook is ever missed, `cron.php` re-polls pending
payments so a paying customer is never left stranded.

### Adding another provider

Write one class implementing `PaymentProvider` in `services/payments/`, then
add it to `PaymentService::provider()` and `availableProviders()`. Nothing
else in the system needs to change.

---

## MikroTik integration

WMS is MikroTik-ready but does not require it.

```
Admin page  →  NetworkService  →  NetworkProvider  →  MikroTikService  →  RouterOS API
                                        ↘  DemoNetworkProvider (no hardware)
```

`services/network/RouterOsApi.php` is a dependency-free implementation of the
RouterOS binary API (length-prefixed words), supporting both the modern plain
login and the older MD5 challenge.

### Connecting a router

1. On the router, enable the API service and create a dedicated user:

   ```
   /ip service enable api
   /user add name=wms-api password=STRONG group=full
   ```

   Restrict it further with `/ip service set api address=YOUR.SERVER.IP` and a
   custom group holding only what WMS needs.

2. In WMS: **Network → Routers → Add router** — name, IP, API port (8728, or
   8729 with TLS), username, password, and set **Mode** to *Live*.

3. Press **Test connection**. If it fails you get a plain explanation
   (refused / timed out / bad credentials), and an alert is raised.

4. Turn **Demo mode** off in Settings → General.

### What each side owns

| WMS (PHP) | MikroTik |
|---|---|
| Customers, packages, vouchers | Actual internet access |
| Payments and business rules | Hotspot authentication |
| Records, reports, alerts | Bandwidth enforcement |
| Deciding *who may* connect | Enforcing it, and reporting traffic |

WMS does not pretend PHP enforces anything on the wire. If the router is
offline, the application keeps working and says the router is offline.

---

## Scheduled tasks

`cron.php` expires vouchers, closes stale sessions, polls routers,
reconciles pending payments, raises usage/expiry alerts and prunes old rows.

**With CLI cron (preferred):**

```cron
*/5 * * * * /usr/bin/php /home/user/public_html/WMS/cron.php >/dev/null 2>&1
```

**Without CLI access**, call it over HTTP with a token. Visit
`https://example.com/WMS/cron.php` once — it prints a token, stores it, and
never shows it again. Then schedule:

```
https://example.com/WMS/cron.php?token=YOUR_TOKEN
```

Requests without the correct token get a 403.

If you never set up cron, nothing breaks: expiry housekeeping also runs
occasionally when a staff member loads a page.

---

## Deploying to shared hosting

There is deliberately **no `public/` folder**, so the whole thing works as a
plain sub-directory upload:

```
public_html/
└── WMS/
    ├── index.php
    ├── login.php
    ├── admin/
    ├── customer/
    └── …
```

Works at `https://example.com/WMS/` and equally well with a domain pointed
straight at the `WMS` folder — the base URL is detected at runtime, so no
path constants need editing.

**Checklist before you go live:**

- [ ] Delete or rename `install.php`
- [ ] Change every demo password
- [ ] Confirm `config/`, `core/`, `classes/`, `services/`, `database/` and `logs/` are not reachable over HTTP
- [ ] Force HTTPS, then uncomment the HSTS header in `.htaccess`
- [ ] Set up cron
- [ ] Turn demo mode off once a router is live
- [ ] Take a database backup schedule

---

## Security notes

| Area | How it is handled |
|---|---|
| **Passwords** | `password_hash()` / `password_verify()`, re-hashed when PHP's default cost changes. Never logged. |
| **SQL** | MySQLi prepared statements everywhere. Sort columns are whitelisted, never interpolated. No PDO. |
| **CSRF** | A per-session token on every form and every state-changing POST, compared with `hash_equals()`. |
| **Sessions** | HttpOnly, SameSite=Lax, Secure over HTTPS, strict mode, ID regenerated on login, 1-hour idle timeout. |
| **Authorisation** | `Permission::require()` runs server-side on every protected page and API endpoint. Hidden buttons are never the control. |
| **Brute force** | 5 failed attempts per identifier *or* IP within 15 minutes locks sign-in, for staff and customers alike. |
| **Router credentials** | AES-256-CBC encrypted with an HMAC, keyed from `app_key`. Never rendered into HTML or JSON — the edit form shows a blank box, not the stored password. |
| **Payment keys** | Stored server-side, masked in the UI, masked in logs, never sent to the browser. |
| **Webhooks** | HMAC-SHA256 verified before any state change. If no provider is configured to verify a callback, it is refused (503) rather than trusted. |
| **Output** | Everything escaped with `e()` on the way out. |
| **Uploads** | Type and size validated; `uploads/` has PHP execution disabled. |
| **Logs** | Written as `*.log.php` beginning with `<?php exit;`, so they return nothing even on a server that ignores `.htaccess`. Secrets are masked before writing. |
| **Errors** | Users see a friendly sentence; the technical detail goes to the log. |
| **Tenant isolation** | `provider_id` on every tenant table; the scope is applied in SQL by the base model, so a foreign id returns nothing and updates zero rows. Services and the network layer check ownership again before acting. |
| **No client-supplied tenant** | The provider comes from the session, established at login from the user's own row. A posted `provider_id` is ignored, and `updateById()` strips it so records cannot be moved between tenants. |
| **Router ownership** | `NetworkService::providerFor()` refuses a router the caller does not own, so one provider can never send a RouterOS command to another's hardware. |
| **Audit trail** | Logins, voucher generation and activation, payments, router changes, staff changes, settings edits, provider creation, router assignment and every refused cross-tenant attempt are recorded with actor, provider, IP and timestamp. |

### nginx

`.htaccess` does nothing on nginx. Add this to your server block:

```nginx
location ~ ^/WMS/(config|core|classes|services|database|logs)/ {
    deny all;
    return 403;
}

location ~ ^/WMS/uploads/.*\.(php|phtml|php[0-9])$ {
    deny all;
}
```

The log files protect themselves regardless, but the rules above are what
keep your configuration and source out of reach.

---

## The API

Small JSON endpoints under `api/`, no routing framework. All responses share
one envelope:

```json
{ "success": true, "message": "…", "data": [] }
```

| Endpoint | Method | Auth |
|---|---|---|
| `api/packages/index.php` | GET | public — the price list |
| `api/payments/status.php?id=` | GET | public — polls one payment |
| `api/payments/webhook.php` | POST | HMAC signature |
| `api/vouchers/activate.php` | POST | CSRF (browser session) |
| `api/customers/index.php` | GET | `manage_customers` |
| `api/vouchers/index.php` | GET | `manage_vouchers` |
| `api/payments/index.php` | GET | `manage_payments` |
| `api/sessions/index.php` | GET | `manage_sessions` |
| `api/sessions/disconnect.php` | POST | `manage_sessions` + CSRF |
| `api/devices/index.php` | GET | `manage_devices` |
| `api/devices/block.php` | POST | `manage_devices` + CSRF |
| `api/network/status.php` | GET | `view_dashboard` |
| `api/network/test.php` | POST | `manage_routers` + CSRF |
| `api/network/poll.php` | POST | `manage_routers` + CSRF |
| `api/reports/summary.php` | GET | `manage_reports` |

Every endpoint is tenant scoped: a provider's token only ever returns that
provider's rows, and an id belonging to another tenant answers exactly as a
missing one does. `api/payments/status.php` is public but answers only about
orders placed in the caller's own browser session, so voucher codes cannot be
harvested by walking the id space.

Failures return the right status code with a human-readable `message`:
401 unauthenticated, 403 unauthorised, 419 stale CSRF, 422 validation,
404 missing, 500 unexpected.

---

## Architecture

Business logic lives in services; pages render and delegate.

```
Voucher page  →  VoucherService  →  Voucher (model)  →  Database
Payment page  →  PaymentService  →  PaymentProvider  →  Gateway
Network page  →  NetworkService  →  NetworkProvider  →  MikroTik
```

A few conventions worth knowing if you are extending it:

* **The tenant is never a parameter.** `ProviderContext::providerId()` is the
  only source. If you find yourself reading a provider id from a request, the
  design has gone wrong.
* **Scope in the model, guard in the page.** Add `protected bool
  $tenantScoped = true;` to a new model and `find()`/`update`/`delete` are safe
  immediately. For custom SQL, call `$this->scope('alias')` and paste the
  fragment into your `WHERE`.
* **Vouchers snapshot their package.** Duration, data cap, speeds and device
  limit are copied onto the voucher at creation, so editing a package never
  changes access somebody already paid for.
* **`data_limit_mb = NULL` means unlimited.** Not `0`, not `-1`.
* **Statuses map to five tones** (`success`, `warning`, `danger`, `info`,
  `neutral`) through `WMS_STATUS_TONES`, so a status looks the same wherever
  it appears.
* **Components over copy-paste.** `includes/components.php` holds `badge()`,
  `stat_card()`, `empty_state()`, `pagination()`, `field_input()`,
  `voucher_card()` and friends. Use them rather than hand-rolling markup.
* **Tables restack on mobile.** Add `table--stack` and give every `<td>` a
  `data-label` — that is what turns a table into readable cards on a phone.
* **`Model::paginate()`** returns `rows`, `total`, `page`, `pages`, `from`,
  `to` — feed it straight to `pagination()`.

### The front end

No framework and no CDN — a captive portal often loads on a connection the
customer has not paid for yet, so nothing external is fetched. `assets/js/app.js`
provides toasts, modals, confirm dialogs, a CSRF-aware `fetch` wrapper and a
small SVG chart renderer (line, area, bar, donut) in about 25 KB unminified.

Responsive breakpoints are exercised at 320, 360, 375, 390, 414, 480, 768,
820, 1024, 1280, 1440 and 1920 px. The sidebar becomes a drawer at 1024 px;
tables restack at 768 px; nothing scrolls sideways except a wide table inside
its own scroll container.

---

## Troubleshooting

**"Something went wrong while processing your request."**
The friendly wrapper around a technical error. The detail is in
`logs/app-YYYY-MM-DD.log.php`.

**Redirected to the installer even though it is installed**
`config/installed.lock` or `config/app.local.php` is missing or unreadable.

**Router test says "connection refused"**
The API service is not enabled on the router, or a firewall is in the way.
Check `/ip service print` and that port 8728 (or 8729) is reachable *from the
web server*, which on shared hosting is often not on your LAN.

**Payments stay pending**
Check the webhook URL in your SonicPesa settings, and that cron is running so
pending payments get re-polled. Settings → Payments → *Test the connection*
confirms the credentials.

**Vouchers not expiring**
Cron is not running. Set it up, or rely on the occasional housekeeping pass
that runs when staff load a page.

**Logos will not upload**
`uploads/logos` is not writable by PHP.

**Styles look broken**
Check the browser console for 404s on `assets/css/*`. `BASE_PATH` is detected
from `DOCUMENT_ROOT`; if your host does something unusual there, set
`app_url` explicitly in `config/app.local.php`.

---

## Licence

Provided as-is for the operator it was built for. Review the security
checklist before exposing it to the public internet.
