# Assets Plugin

Payments, credits, subscriptions, badges, leaderboards, NFTs, and Web3 commerce for the Qbix platform. Assets is the economic layer — it handles virtual currency (credits), real-money charges (Stripe, Authorize.Net), subscription plans, gamification (badges and leaderboards), and on-chain asset management (ERC-721/ERC-1155 NFTs, community coins, auctions, staking).

## Core Concepts

### Credits

Credits are the platform's virtual currency. Each user has an `Assets/credits/{communityId}` stream (per community) whose `amount` attribute tracks their balance. The `credits` table is a double-entry journal — every credit movement creates a row recording `fromUserId`, `toUserId`, the streams involved, a `reason` key, and the `amount` (positive = credit received, negative = debit).

Credits are community-scoped: a user can have different balances in different communities. The `communityId` field on every credits row determines which community's economy the transaction belongs to.

The exchange rate between credits and fiat currency is configured in `Assets.credits.exchange` — by default, 100 credits = 1 USD. `Assets_Credits::convert()` handles bidirectional conversion.

### The Pay Flow

`Assets::pay()` is the unified payment method. It converts the requested amount to credits, checks the user's balance, and either deducts credits directly or triggers a real-money charge if the balance is insufficient. The flow:

1. Convert `$amount` in `$currency` to credits via `Assets_Credits::convert()`.
2. Check the user's credit balance via `Assets_Credits::amount()`.
3. If sufficient credits exist, call `Assets_Credits::spend()` to deduct.
4. If insufficient, check for a saved payment method and auto-charge if configured, or return a `needCredits` response so the client can prompt for payment.
5. Fire `Assets/pay` after-event with the result.

`Assets::pay()` never throws — it returns an array with a `success` key. On failure, `details` includes `needCredits` (how many more are needed) and optionally an `intentToken` for completing payment.

### Charges

The `charge` table records real-money payment attempts. Each charge tracks `userId`, `amount`, `currency`, `credits` (how many credits were purchased), `paymentProvider` (stripe, authnet, web3), `providerCustomerId`, `status` (pending → completed → failed → reversed), `communityId`, `reason`, and `description`.

Payment providers are implemented as adapter classes: `Assets_Payments_Stripe`, `Assets_Payments_Authnet`, and `Assets_Payments_Web3`. Stripe is the primary provider, with Stripe.js loaded on the client and server-side charge/webhook handling via the `Assets/stripeWebhook` handler.

### Customers

The `customer` table maps Qbix users to payment provider customer IDs (e.g. Stripe `cus_XXX`). The `hash` field disambiguates between test/live keys and different provider configurations. Customers are created on first charge and reused for subsequent payments and auto-charging.

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
status               enum('pending','completed','failed','reversed')
insertedTime         timestamp
updatedTime          timestamp
```

### customer
```
userId       varbinary(31)              PK
payments     enum('stripe','authnet')   PK
hash         varchar(32)               PK
customerId   varbinary(255)                — e.g. cus_XXXX
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
| `Assets/NFT` | Individual NFT |
| `Assets/NFT/series` | NFT series grouping |
| `Assets/NFT/contract` | Deployed smart contract |
| `Assets/NFT/collection` | NFT display collection |
| `Assets/NFT/pointer` | Reference to external NFT |
| `Assets/fundraise` | Crowdfunding campaign |
| `Assets/invoice` | Payment invoice |

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
Assets.currencies.tokens.*                 — ERC-20 token addresses per chain
Assets.NFT.URI.base                        — NFT metadata URI template
```