# Assets Plugin

Payments, credits, subscriptions, badges, leaderboards, NFTs, and Web3 commerce for the Qbix platform. Assets is the economic layer — it handles virtual currency (credits), real-money charges (Stripe, Authorize.Net), subscription plans, gamification (badges and leaderboards), on-chain asset management (ERC-721/ERC-1155 NFTs, community coins, auctions, staking), unified invoicing across payment methods, and Bitcoin payments via OpenNode.

## Core Concepts

### Credits

Credits are the platform's virtual currency. Each user has an `Assets/credits/{communityId}` stream (per community) whose `amount` attribute tracks their balance. The `credits` table is a double-entry journal — every credit movement creates a row recording `fromUserId`, `toUserId`, the streams involved, a `reason` key, and the `amount` (positive = credit received, negative = debit).

Credits are community-scoped: a user can have different balances in different communities. The `communityId` field on every credits row determines which community's economy the transaction belongs to.

The exchange rate between credits and fiat currency is configured in `Assets.credits.exchange` — by default, 100 credits = 1 USD. `Assets_Credits::convert()` handles bidirectional conversion.

### Invoices

An `Assets/invoice` stream is the unified entry point for any payment. It carries the amount, currency, accepted payment methods, and status. The publisher is whoever receives the payment. Because it's a stream, you get access control, real-time status updates over the socket, and message history for free.

```php
$invoice = Streams::create($publisherId, $publisherId, 'Assets/invoice', array(
    'title' => 'Event ticket',
    'attributes' => Q::json_encode(array(
        'amount'   => 25.00,
        'currency' => 'USD',
        'status'   => 'pending',
        'payments' => array('credits', 'stripe', 'web3'),
        'web3'     => array(
            'address'        => '0xDEFAULT...',
            'tokens'         => array('USDC', 'USDT'),
            'allowance'      => true,
            'directTransfer' => false,
            'chains'         => array(
                '0x89' => array(
                    'address'        => '0xPOLYGON_WALLET...',
                    'accept'         => array('0x...otherToken'),
                    'uniswapRouter'  => '0xa5E0829CaCEd8fFDD4De3c43696c57F7D7A678ff'
                ),
                '0x1'  => array(),
                '0x38' => array(
                    'address'       => '0xBSC_WALLET...',
                    'uniswapRouter' => '0x10ED43C718714eb63d5aA57B78B54704E256024E'
                )
            )
        )
    ))
));
```

#### Web3 Attribute Format

The `web3` attribute uses a unified format that handles everything from simple single-chain payments to multi-chain configurations with Uniswap swap support:

- **`address`** — Default recipient wallet. Per-chain `address` inside `chains` overrides it.
- **`tokens`** — Array of accepted token symbols (e.g. `["USDC", "USDT"]`). Resolved to contract addresses via `Assets.currencies.tokens` config, so the invoice doesn't hardcode chain-specific addresses.
- **`allowance`** — (default `true`) Enable the approve flow. The user calls `approve()` on the token contract, granting the site's spender wallet permission to pull tokens. This is the crypto equivalent of saving a credit card — subsequent payments are zero-interaction.
- **`directTransfer`** — (default `false`) Enable direct token transfers. The user sends tokens directly from their wallet. One-shot, no pre-authorization needed, but requires wallet interaction every time.
- **`chains`** — Object keyed by chain ID. Keys define which chains are accepted. An empty object `{}` means "accepted, use top-level defaults." Per-chain overrides:
  - **`address`** — Override the recipient wallet for this chain.
  - **`accept`** — Array of additional token contract addresses the user can pay with (swapped via Uniswap to an accepted token). Only used when `uniswapRouter` is set.
  - **`uniswapRouter`** — Uniswap V2-compatible router address. When present, the tool offers swap capability: users can pay with tokens they hold that aren't directly accepted, and the tool routes through Uniswap automatically.

The simple case uses just `address`, `tokens`, and `chains` with empty objects. The complex case adds per-chain overrides. The `Assets/web3/invoice` tool resolves the effective config per chain via `_getChainConfig()`.

#### Status Lifecycle

`pending → paid`, `pending → expired`, or `pending → canceled`. Status changes post messages (`Assets/invoice/paid`, `Assets/invoice/expired`, `Assets/invoice/canceled`) so subscribers receive notifications.

Each user has an `Assets/user/invoices` category stream. New invoices are related to it automatically. A community-level `Assets/invoices` category exists for admin dashboards.

### The Pay Flow

`Assets::pay()` is the unified payment method for credits and Stripe. It converts the requested amount to credits, checks the user's balance, and either deducts credits directly or triggers a real-money charge if the balance is insufficient. The method never throws — it returns an array with a `success` key.

Server-side steps:

1. Convert `$amount` in `$currency` to credits via `Assets_Credits::convert()`.
2. Check the user's credit balance via `Assets_Credits::amount()`.
3. Apply inviter-based discounts if a stream is involved.
4. If credits are sufficient, call `Assets_Credits::spend()` or `Assets_Credits::transfer()` to deduct. Return `success: true`.
5. If insufficient, reuse an existing `Users_Intent` (if the client passed `intentToken`) or mint a new one. Look up the user's saved payment method via `Assets::paymentMethod()`. Return `success: false` with `needCredits`, `haveCredits`, `paymentMethod`, and `intentToken`.
6. If `autoCharge` is true, call `Assets::autoCharge()` to charge the saved payment method. If the charge fails because the card is gone, clear the stale payment method via `Assets::rememberPaymentMethod()` and return the error with the `intentToken` so the client can fall through to interactive checkout.
7. Fire `Assets/pay` after-event with the result.

Intent reuse prevents double-charges: when the client passes `intentToken` back on the second call, the server retrieves the existing intent instead of creating a new one.

### The Probe/Confirm/Charge Pattern (Client-Side)

`Assets.pay()` in JavaScript never charges without asking. The flow:

1. **Probe:** First request always sends `autoCharge: false`. The server checks credits, mints an intent, looks up the saved payment method, and returns `{success: false, needCredits, haveCredits, paymentMethod, intentToken}`.

2. **Branch:** The client examines the response:
   - Credits were sufficient → server already spent them, returned `success: true`. Done.
   - `autoCharge` not requested, or no `paymentMethod` on file → falls through to `_buy()`, which opens the interactive Stripe checkout. The webhook completes the intent when Stripe confirms.
   - `autoCharge` requested and `paymentMethod` exists → shows a `Q.confirm()` dialog.

3. **Confirm dialog:** Shows the user which card or allowance will be charged, with the amount and reason. Picks the right text template based on `pm.type` (ERC-20 allowance), `pm.last4` (specific card), or generic (grandfathered card with no details).

4. **Charge:** If confirmed, re-sends the request with `autoCharge: true` and the same `intentToken`.

5. **Failure handling:** If the charge fails because the card is gone, the server clears the stale payment method and returns the error. The client sees `autoCharge` was already true, so it falls through to `_buy()` — the interactive flow opens with the same intent. Self-healing.

### Saved Payment Methods

Payment method info is stored locally at webhook time — no payment-provider API calls on the read path.

**Storage:** `Assets::rememberPaymentMethod($userId, $payments, $info)` writes to two places: the `Assets_Customer.attributes` column (durable record) and the user's `Assets/credits` stream attributes (live mirror for real-time client updates). Pass `null` for `$info` to clear.

**Card lifecycle:**
- `setup_intent.succeeded` webhook → retrieves payment method from Stripe → stores `{brand, last4, expMonth, expYear}` via `rememberPaymentMethod`.
- `payment_method.detached` webhook → looks up userId via `Assets_Customer::userIdFromCustomerId()` → clears via `rememberPaymentMethod(null)`.
- Failed auto-charge with "no attached payment" error → clears the stale record in the `pay()` catch block.

**Reading:** `Assets::paymentMethod($userId, $options)` does a local DB read on `Assets_Customer`, checks card expiry, returns the info array or null. For grandfathered customers (existing rows with `attributes = NULL`), returns a generic hint with a 10-year expiry. First successful charge writes real details via the webhook. First failed charge clears the hint. Self-healing.

**Web3 allowances:** An ERC-20 `approve()` is the blockchain equivalent of saving a card. The server verifies the allowance on-chain after the user approves, then stores it via `rememberPaymentMethod` with `{type: 'erc20_allowance', chainId, token, tokenAddress, decimals, walletAddress, spender, allowance}`.

**Session extras:** `Assets_before_Q_sessionExtras` pushes all `Assets_Customer` rows (with `customerId` and `hash` stripped by `exportArray`) to `Q.Assets.customers` on page load, so the UI can render payment method indicators before any request.

### Charges

The `charge` table records real-money payment attempts. Each charge tracks `userId`, `amount`, `currency`, `credits` (how many credits were purchased), `paymentProvider` (stripe, authnet, web3, opennode), `providerCustomerId`, `status` (pending → completed → failed → reversed), `communityId`, `reason`, and `description`.

Payment providers are implemented as adapter classes: `Assets_Payments_Stripe`, `Assets_Payments_Authnet`, `Assets_Payments_Opennode`. Stripe is the primary provider, with Stripe.js loaded on the client and server-side charge/webhook handling via the `Assets/stripeWebhook` handler.

`Assets::charge()` initiates a charge and returns immediately — the webhook records the result. `Assets::charged()` is the idempotent recording method called by the webhook, creating the charge row and handling referral tracking. `Assets::autoCharge()` wraps the charge flow with quota checking and rollback.

### Web3 Payments

The plugin supports three Web3 payment paths, all unified under the same invoice and payment method infrastructure:

**Allowance (pull-based, like a saved card):**
The user calls `approve(siteWallet, amount)` on an ERC-20 contract, granting the site permission to pull tokens. The server verifies the allowance on-chain via `Assets/Web3approved`, then stores it via `Assets::rememberPaymentMethod()`. At payment time, the server calls `transferFrom(userWallet, siteWallet, amount)` using the site's private key via `Assets/Web3charge` — no user interaction needed. The allowance is re-verified on-chain before every charge (never trust the cached value). Functionally identical to charging a saved credit card.

**Direct transfer (push-based):**
The user's wallet sends tokens directly to the invoice's recipient address. For native coins, `Users.Web3.transaction()` is used. For ERC-20 tokens, `Users.Web3.execute()` calls `transfer()` on the token contract via the `Assets/templates/R1/ERC20` ABI. After the transaction is mined, the client sends the `txHash` to `Assets/Web3verify`, where the server verifies the transaction on-chain. Before opening the wallet, the client records the txHash on the invoice via `Assets/Web3pending` so a server-side cron can recover unconfirmed payments if the browser closes.

**Uniswap swap (automatic conversion):**
When a chain config includes a `uniswapRouter` address, the `Assets/web3/invoice` tool checks which tokens the user holds that have Uniswap liquidity against the accepted token. The user can pay with any swappable token — the tool gets a price quote via `getAmountsIn`, shows the required amount with slippage, and executes the swap (approve → `swapTokensForExactTokens` or `swapETHForExactTokens`) in a multi-step flow.

Accepted tokens and chains are configured per-invoice in the `web3` attribute and globally in `Assets.currencies.tokens`.

### Bitcoin Payments (OpenNode)

Bitcoin payments are handled through OpenNode, which provides both Lightning Network (instant, sub-cent fees) and on-chain Bitcoin payment support through a single API. The integration follows the same pattern as Stripe: create a charge on the server, get back a checkout URL and payment details, and receive a webhook when paid.

`Assets_Payments_Opennode::createCharge()` creates a charge with OpenNode's API, returning both a Lightning invoice (BOLT11) and an on-chain Bitcoin address. The `Assets/OpennodeCharge` handler creates the charge and returns it to the client. The client either redirects to OpenNode's hosted checkout or renders a QR code inline using the charge's `uri` field. The `Assets/OpennodeWebhook` handler (registered as a route, not an action) validates webhook signatures via HMAC-SHA256 and marks the invoice paid when the charge is confirmed.

`auto_settle: true` in the config means OpenNode converts BTC to fiat immediately. `false` means you keep the BTC.

### Customers

The `customer` table maps Qbix users to payment provider customer IDs (e.g. Stripe `cus_XXX`). The `hash` field disambiguates between test/live keys and different provider configurations. The `attributes` column (varchar 1023, JSON) stores saved payment method details and metadata. Customers are created on first charge and reused for subsequent payments and auto-charging.

`Assets_Customer` provides `getAllAttributes()`, `getAttribute()`, `setAttribute()`, `clearAttribute()`, and `attributesLock()` methods for structured access to the JSON attributes. `exportArray()` strips `customerId` and `hash` before sending to clients.

`Assets_Customer::userIdFromCustomerId()` provides reverse lookup from a payment provider's customer ID to a Qbix userId, used by webhooks like `payment_method.detached` to identify which user's payment method was removed.

### Connected Accounts

The `connected` table stores Stripe Connect or equivalent merchant credentials, enabling platform marketplace flows where the app takes a cut of payments routed to content creators or service providers.

### Subscriptions and Plans

`Assets/plan` streams define subscription tiers — recurring payment plans with configurable pricing, intervals, and access grants. When a user subscribes (via `Assets_Subscription::start()`), an `Assets/subscription` stream is created under their publisherId, related to the plan. The subscription controls access to gated content by adding `inheritAccess` entries on the streams the plan governs.

`Assets_Subscription::checkStreamPaid()` verifies whether a user has an active subscription that grants access to a specific stream. Plans can be interrupted and continued, and the system handles expiration checking via `isCurrent()`.

### Credits Operations

`Assets_Credits::grant()` — Add credits to a user's balance (system → user). Used for welcome bonuses, referral rewards, and admin grants. Fires `Assets/credits/grant` event.

`Assets_Credits::spend()` — Deduct credits from a user's balance (user → system or user → user). Fires `Assets/credits/spent` event. Throws `Assets_Exception_NotEnoughCredits` if balance is insufficient.

`Assets_Credits::transfer()` — Move credits between two users. Creates two journal entries (debit + credit). Supports `forcePayment` option to auto-charge if sender lacks credits.

`Assets_Credits::refund()` — Reverse a previous spend, adding credits back.

`Assets_Credits::awardBonus()` — Grant bonus credits when a user purchases above certain thresholds (configured in `Assets.credits.bonus.bought`).

### Auto-Charging

When a user's credit balance drops below `Assets.credits.amount.min`, the system can automatically charge their saved payment method for `Assets.credits.amount.add` credits. This requires a previously saved card (via Stripe's `setup_future_usage`). The `checkMinCredits.php` script handles this on a cron schedule.

### Gamification

**Badges** — Named achievements defined in the `badge` table with a title, description, icon, and point value. `Assets_Earned::badge()` awards a badge to a user, optionally preventing duplicates.

**Leaderboards** — The `leader` table aggregates daily point totals per user. `Assets_Badge::badgesAndTotals()` computes leaderboard rankings over a date range, returning per-user badge lists and point totals.

**Credit grants for user actions** — The `Assets.credits.grant` config awards credits when users fill out profile fields (firstName, lastName, icon, location), accept invites, or post greetings. This incentivizes onboarding completion.

### NFTs

The plugin provides a full NFT lifecycle built on Qbix streams and EVM smart contracts:

`Assets/NFT` — Individual NFT streams with metadata, icon, and on-chain provenance. `Assets/NFT/series` — Series that group NFTs with shared properties. `Assets/NFT/contract` — Smart contract streams representing deployed ERC-721/ERC-1155 contracts. `Assets/NFT/collection` — Category streams grouping NFTs for display.

On-chain operations use factory contracts deployed on Polygon, BSC, and testnets. The `Assets_NFT` class handles minting, transferring, and metadata management. `Assets_NFT_Series` manages series-level properties like pricing and supply limits.

### Web3 Commerce

Beyond NFTs, the plugin includes on-chain smart contract templates (with ABIs) for: Community Coins (ERC-20 with governance), Staking Pools, Auctions (standard, community, NFT, subscription), Sales (standard, stable-price, token-denominated), Escrow, Income/UBI distribution, Rewards, Contests, and Subscription billing.

### Currency Management

`Assets.currencies.tokens` defines ERC-20 token addresses across chains (USDT, USDC, DAI, BUSD, BNB, ETH, MATIC). `Assets::currency()` and `Assets::format()` handle multi-currency display formatting. The Moralis API integration (`Assets_Web3_Moralis`) provides real-time token balances and price feeds.

## Tools

### Assets/invoice

The unified payment tool. Loads an `Assets/invoice` stream, discovers available payment methods, and renders them as tappable options. Delegates to specialized tools rather than implementing payment flows itself:

- **Credits** — Shown only if the user has enough. Delegates to `Assets.pay()`.
- **Saved card** — From `Q.Assets.customers` session data. Delegates to `Assets.pay()` with `autoCharge: true`, which triggers the probe/confirm/charge pattern.
- **New card** — Embeds the existing `Assets/payment` tool (Stripe checkout button with Google Pay support).
- **Saved web3 allowance** — Calls `Assets/Web3charge` server-side (no wallet interaction needed).
- **Pay with crypto wallet** — Opens `Assets/web3/invoice` via `Q.invoke()`, which handles wallet connection, chain detection, balance display, token selection, approve/transfer/swap flows.
- **Bitcoin** — Creates an OpenNode charge, shows QR code or redirects to hosted checkout.

The invoice stream's `onFieldChanged` fires when status changes, so the dialog updates in real time if a webhook or cron completes while the user is watching.

Open it Telegram-style from anywhere:

```javascript
Q.Dialogs.push({
    title: 'Payment',
    className: 'Assets_invoice_dialog',
    apply: false,
    content: Q.Tool.prepare('div', 'Assets/invoice', {
        publisherId: publisherId,
        streamName: invoiceStreamName,
        onPaid: function (method, details) {
            Q.Dialogs.pop();
        }
    })
});
```

### Assets/web3/invoice

The Web3 payment tool, invoked by `Assets/invoice` when the user taps "Pay with crypto wallet." Handles the full crypto payment flow:

1. Connects the user's wallet via `Users.Web3.connect()`
2. Detects the current chain and checks if it's accepted
3. If wrong chain, shows a "Switch Network" button
4. Loads token balances via an embedded `Assets/web3/balance` tool, filtered to tokens the invoice accepts
5. If `uniswapRouter` is configured for the chain, also discovers swappable tokens via Uniswap factory pair checks and shows price quotes
6. Shows each available token with action buttons:
   - **Authorize** (when `allowance` is enabled) — calls `approve()`, verifies on server, then charges
   - **Pay now** (when `directTransfer` is enabled) — transfers tokens directly, verified via `Assets/Web3verify`
   - **Swap** (when `uniswapRouter` is configured) — routes through Uniswap V2

The tool uses `_getChainConfig(chainId)` to resolve the effective configuration per chain, merging top-level defaults with per-chain overrides. One code path handles all configurations.

Also supports a legacy `recipients` attribute format for backward compatibility, converting it to the unified format on init.

### Assets/web3/balance

Shows wallet token balances with optional chain selection. Used as a child tool inside `Assets/web3/invoice`.

- `acceptedTokens` option filters displayed tokens to specific symbols
- `onTokenSelect` event fires when the user selects a token, providing `{chainId, tokenAmount, tokenName, tokenAddress, decimals}`
- `getValue()` returns the currently selected token info
- `selectToken(address)` programmatically selects a token

### Assets/invoice/preview

Extends `Streams/preview`. Two modes:

- **View mode** (streamName set): Compact card showing title, amount, currency, and status badge. Clicking opens the `Assets/invoice` tool in a dialog. Live status updates flow through `onFieldChanged`.
- **Composer mode** (streamName empty): Shows an add icon. Clicking reveals an inline form or opens a dialog to collect title and amount. Submitting creates the invoice stream via `Streams/preview.create()` → `Assets/invoice/post.php`.

### Assets/payment

Low-level pay button that wraps a single payment processor (Stripe or Authnet). Renders one button, opens one checkout flow, and fires `onPay`. Supports Google Pay via the `cordova-plugin-stripe-google-apple-pay` plugin. Used standalone for simple checkout pages, and embedded inside `Assets/invoice` for the "new card" option.

### Assets/credits/balance

Displays the user's current credit balance with optional text-fill scaling. Updates in real time via the `Assets/credits` stream's `onFieldChanged`. Used inside the credits badge overlay.

## Server-Side Handlers

### Invoice Handlers

| Handler | Method | Purpose |
|---|---|---|
| `Assets/invoice` | POST | Create an invoice stream with amount, currency, accepted payments, web3 config |
| `Assets/invoice` | PUT | Update invoice status (cancel, expire). Publisher or admin only. |

### Web3 Handlers

| Handler | Method | Purpose |
|---|---|---|
| `Assets/Web3spender` | GET | Returns the site's spender wallet address (for approve flows) |
| `Assets/Web3approved` | POST | Verify an allowance approval on-chain, store via `rememberPaymentMethod`, optionally charge |
| `Assets/Web3verify` | POST | Verify a direct transfer on-chain, mark invoice paid |
| `Assets/Web3pending` | POST | Record a txHash on the invoice stream for browser-close recovery |
| `Assets/Web3charge` | POST | Server-side `transferFrom` using a saved allowance |

### OpenNode Handlers

| Handler | Method | Purpose |
|---|---|---|
| `Assets/OpennodeCharge` | POST | Create an OpenNode charge, return checkout URL and payment details |
| `Assets/OpennodeWebhook` | POST | Validate webhook signature, mark invoice paid on Bitcoin confirmation |

### Recovery

`scripts/Assets/web3/check_pending.php` runs on a cron schedule. It finds invoices with a recorded `web3TxHash` attribute that are still `pending`, verifies the transaction on-chain, and marks them paid if confirmed. This handles the case where the user's browser closed after the wallet confirmed the transaction but before the client called `Assets/Web3verify`.

## Database Schema

### credits (journal — every credit movement)
```
id               varbinary(31)   PK
fromUserId       varbinary(31)   KEY
toUserId         varbinary(31)   KEY
fromPublisherId  varbinary(31)
fromStreamName   varbinary(255)
toPublisherId    varbinary(31)
toStreamName     varbinary(255)
reason           varchar(255)         — key in Q.Text
communityId      varbinary(31)   NOT NULL
amount           decimal(10,4)   NOT NULL
attributes       varchar(1023)        — JSON
insertedTime     timestamp
updatedTime      timestamp
```

### charge
```
userId               varbinary(31)   PK
id                   varbinary(255)  PK
publisherId          varbinary(31)
streamName           varbinary(255)
amount               decimal(10,2)
currency             char(3)
credits              bigint
paymentProvider      varchar(32)
providerCustomerId   varchar(255)
autoCharge           tinyint(1)      DEFAULT 0
communityId          varbinary(31)
app                  varchar(64)
reason               varchar(64)
description          varchar(255)
attributes           varchar(1023)
status               enum('pending','completed','failed','reversed')  DEFAULT 'pending'
insertedTime         timestamp
updatedTime          timestamp
```

### customer
```
userId       varbinary(31)              PK
payments     enum('stripe','authnet')   PK
hash         varchar(32)               PK
customerId   varbinary(255)                — e.g. cus_XXXX
attributes   varchar(1023)                 — JSON: paymentMethod, etc.
insertedTime timestamp
updatedTime  timestamp
```

### connected (Stripe Connect merchant accounts)
```
userId        varbinary(8)   PK
payments      varchar(255)   PK
accountId     varchar(255)
refreshToken  varchar(255)
```

### badge
```
appId        varbinary(31)   PK
communityId  varbinary(31)
name         varchar(63)     PK
icon         varbinary(255)
title        varchar(255)
description  text
points       smallint        DEFAULT 0
```

### earned
```
appId        varbinary(31)
communityId  varbinary(31)
earnedTime   timestamp
userId       varchar(31)
badgeName    varchar(255)
publisherId  varbinary(31)
streamName   varbinary(255)
KEY byTime (appId, communityId, earnedTime)
KEY byUser (appId, communityId, userId)
```

### leader
```
communityId  varbinary(31)  PK
day          date           PK
userId       varchar(31)    PK
points       smallint       DEFAULT 0
```

### nft_attributes
```
Stores per-NFT metadata attributes (trait types, values, display types)
```

## Stream Types

| Type | Purpose |
|---|---|
| `Assets/credits` | Per-user credit balance (amount in attributes) |
| `Assets/plan` | Subscription plan definition |
| `Assets/subscription` | User's active subscription |
| `Assets/product` | Purchasable product |
| `Assets/service` | Purchasable service |
| `Assets/invoice` | Payment invoice with amount, currency, accepted methods, status |
| `Assets/NFT` | Individual NFT |
| `Assets/NFT/series` | NFT series grouping |
| `Assets/NFT/contract` | Deployed smart contract |
| `Assets/NFT/collection` | NFT display collection |
| `Assets/NFT/pointer` | Reference to external NFT |
| `Assets/fundraise` | Crowdfunding campaign |

## Architecture Overview

| Layer | Purpose |
|---|---|
| `Assets/invoice` stream | Source of truth: amount, currency, status, accepted methods, web3 config |
| `Assets_Customer.attributes` | Saved payment method info (card details, web3 allowances) |
| `Assets/credits` stream attributes | Client-side mirror of payment method (real-time socket updates) |
| `Q.Assets.customers` (session extras) | Page-load snapshot of customer records for immediate UI |
| `Users_Intent` | Prevents double-charges, carries payment instructions across async gaps |
| `Assets::pay()` | Server-side orchestrator: credits → discount → intent → charge |
| `Assets.pay()` (JS) | Client-side orchestrator: probe → confirm → charge → fallback |
| `Assets/invoice` tool | Payment method picker, delegates to specialized tools |
| `Assets/web3/invoice` tool | Full crypto payment flow: wallet → chain → balances → pay/approve/swap |
| `Assets/web3/balance` tool | Chain selection + token balances with filtering |
| `Assets/invoice/preview` tool | Compact invoice card + composer |
| `Assets/payment` tool | Low-level Stripe checkout button (embedded in invoice tool) |
| Stripe webhooks | Card lifecycle: setup, detach, charge success |
| OpenNode webhooks | Bitcoin payment confirmation |
| `Assets/Web3verify` handler | On-chain verification for direct token transfers |
| `Assets/Web3charge` handler | Server-side `transferFrom` for web3 allowances |
| `Assets/Web3approved` handler | Verify and store new allowances |
| `Assets/Web3pending` handler | Record txHash for browser-close recovery |
| Cron: `check_pending.php` | Recover unconfirmed web3 payments |

## Configuration

Key config paths:
```
Assets.credits.exchange.USD = 100          — credits per dollar
Assets.credits.amount.min = 1000           — auto-charge threshold
Assets.credits.amount.add = 20000          — auto-charge amount
Assets.credits.bonus.bought                — bonus tiers {50000: 5000, 100000: 15000}
Assets.credits.grant.*                     — credits for user actions
Assets.credits.spend.*                     — credit costs for operations
Assets.payments.stripe.*                   — Stripe configuration
Assets.payments.stripe.webhookSecret       — Stripe webhook signing secret
Assets.payments.opennode.apiKey            — OpenNode API key
Assets.payments.opennode.environment       — "live" or "dev"
Assets.payments.opennode.autoSettle        — true to convert BTC to fiat immediately
Assets.currencies.tokens.*                 — ERC-20 token addresses per chain
Assets.web3.spender.address                — Site's spender wallet address (public)
Assets.web3.spender.privateKey             — Site's spender private key (server-only, never sent to client)
Assets.customers.restricted.attributes.prefixes — attribute prefixes only server can set
Assets.NFT.URI.base                        — NFT metadata URI template
```

## Migration

### 1.3 → 1.4
```sql
ALTER TABLE assets_customer
ADD COLUMN `attributes` varchar(1023) DEFAULT NULL
COMMENT 'attributes are stored as JSON' AFTER `hash`;
```

Adds the `attributes` column to `Assets_Customer` for storing saved payment method details locally. Also adds `status` enum column to `Assets_Charge` with values `pending`, `completed`, `failed`, `reversed`.