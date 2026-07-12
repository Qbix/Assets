# Assets Plugin — LLM Coding Primer

Supplement to the Q Framework and Streams primers. Covers credits, payments,
subscriptions, badges, leaderboards, and NFTs.

---

## 1. Credits — The Virtual Currency

```php
// Check balance
$balance = Assets_Credits::amount($communityId, $userId);

// Grant credits (system → user)
Assets_Credits::grant($communityId, 100, 'MyPlugin/reward', $userId, array(
    'toPublisherId' => $publisherId,
    'toStreamName'  => $streamName
));

// Spend credits (user → system)
Assets_Credits::spend($communityId, 50, 'MyPlugin/purchase', $userId, array(
    'toPublisherId' => $publisherId,
    'toStreamName'  => $streamName
));
// Throws Assets_Exception_NotEnoughCredits if balance < 50

// Transfer credits between users
Assets_Credits::transfer($communityId, 25, 'MyPlugin/tip',
    $toUserId, $fromUserId, array(
        'forcePayment' => false  // true = auto-charge if insufficient
    )
);

// Refund a previous spend
Assets_Credits::refund($communityId, 50, 'MyPlugin/refund',
    $fromUserId, $toUserId
);

// Convert currencies
$credits = Assets_Credits::convert(2.50, 'USD', 'credits');  // 250 (at 100:1)
$usd     = Assets_Credits::convert(250, 'credits', 'USD');   // 2.50

// Format for display
$label = Assets_Credits::format(250);  // "250 credits" or localized

// Get the credits stream (per community per user)
$stream = Assets_Credits::stream($communityId, $userId);
// Stream name: "Assets/credits/{communityId}"
// Attribute 'amount' = current balance, 'peak' = highest ever
```

**NEVER write to `assets_credits` directly — always use grant/spend/transfer/refund.** These methods maintain the journal, update the stream balance, and fire events.

---

## 2. Unified Payment — Assets::pay()

```php
// Pay for something — the main entry point
$result = Assets::pay(
    $communityId,
    $userId,
    5.00,                          // amount
    'MyPlugin/premium',            // reason — REQUIRED, appears in ledger
    array(
        'currency'      => 'USD',       // default 'USD'
        'payments'      => 'stripe',    // 'stripe', 'authnet', 'web3'
        'toPublisherId' => $publisherId,
        'toStreamName'  => $streamName,
        'toUserId'      => $recipientId, // for marketplace payments
        'autoCharge'    => false,        // true = charge saved card
        'items'         => array(        // line items
            array('amount' => 3.00, 'description' => 'Item A'),
            array('amount' => 2.00, 'description' => 'Item B')
        )
    )
);

// NEVER throws — check result:
if ($result['success']) {
    // credits were deducted
} else {
    $needCredits = Q::ifset($result, 'details', 'needCredits', 0);
    $intentToken = Q::ifset($result, 'details', 'intentToken', null);
    // Client should prompt user to buy credits
    Q_Response::setSlot('result', array(
        'success' => false,
        'needCredits' => $needCredits,
        'intentToken' => $intentToken
    ));
}
```

---

## 3. Real-Money Charges

```php
// Direct charge (bypasses credit system — use pay() instead when possible)
Assets::charge('stripe', 10.00, 'USD', array(
    'userId'      => $userId,
    'description' => 'Premium upgrade',
    'metadata'    => array('streamName' => $streamName)
));

// After charge succeeds (webhook or synchronous)
Assets::charged('stripe', 10.00, 'USD', array(
    'userId'      => $userId,
    'communityId' => $communityId
));
// Converts to credits and calls Assets_Credits::grant()

// Auto-charge saved payment method
Assets::autoCharge(20000, 'BoughtCredits', array(
    'userId'      => $userId,
    'communityId' => $communityId
));
// Requires prior Stripe checkout with setup_future_usage = 'off_session'

// charge.status: 'pending' → 'completed' | 'failed' | 'reversed'
```

---

## 4. Subscriptions

```php
// Start subscription to a plan
$subscription = Assets_Subscription::start($planStream, $user, array(
    'payments' => 'stripe',
    'amount'   => 9.99,
    'currency' => 'USD'
));
// Creates Assets/subscription stream related to the plan
// Adds inheritAccess so subscriber can access gated content

// Check if user is subscribed
$isSubscribed = Assets_Subscription::isSubscribed($planStream, $user);

// Check if subscription is current (not expired)
$isCurrent = Assets_Subscription::isCurrent($subscriptionStream);

// Check if a stream requires payment (via related plan)
$paid = Assets_Subscription::checkStreamPaid($stream, $user, false);
// $throwIfNotPaid = false returns boolean; true throws

// Get plans related to a stream
$plans = Assets_Subscription::getPlansRelated($stream);

// Unsubscribe
Assets_Subscription::unsubscribe($subscriptionStream);

// Plan stream attributes typically include:
// amount, currency, interval (monthly/yearly), trialDays, features[]
```

---

## 5. Badges & Leaderboards

```php
// Award a badge
Assets_Earned::badge($userId, 'first_post', array(
    'publisherId' => $stream->publisherId,
    'streamName'  => $stream->name,
    'duplicate'   => false  // false = prevent awarding twice
));

// Badge definition (in DB or via code)
$badge = new Assets_Badge();
$badge->appId = Q::app();
$badge->communityId = $communityId;
$badge->name = 'first_post';
$badge->title = 'First Post';
$badge->description = 'Made your first post';
$badge->points = 10;
$badge->save(true);

// Leaderboard — get badges and totals for a date range
$results = Assets_Badge::badgesAndTotals(
    strtotime('-30 days'),  // fromTime
    time(),                  // untilTime
    array('communityId' => $communityId)
);
// Returns: ['userId' => ['badges' => [...], 'total' => 37], ...]
```

---

## 6. Products & Services

```php
// Create a product stream
$product = Streams::create($asUserId, $publisherId, 'Assets/product', array(
    'title' => 'Premium Widget',
    'content' => 'Description of the widget',
    'attributes' => Q::json_encode(array(
        'price' => 9.99,
        'currency' => 'USD'
    ))
));

// Create a service stream
$service = Streams::create($asUserId, $publisherId, 'Assets/service', array(
    'title' => 'Consulting Hour',
    'attributes' => Q::json_encode(array(
        'price' => 150,
        'currency' => 'USD',
        'duration' => 3600
    ))
));
```

---

## 7. Credit Grants for User Actions

Config in `Assets.credits.grant`:
```json
{
    "Users/insertUser": 0,
    "forStreams": {
        "Streams/user/firstName": 5,
        "Streams/user/lastName": 5,
        "Streams/user/icon": 5,
        "Places/user/location": 5
    },
    "Users/newUserAcceptedYourInvite": 10,
    "invitedUserEntered": {
        "Streams/user/firstName": 1,
        "Streams/user/lastName": 1
    }
}
```
Credits are awarded automatically when users fill profile fields, accept invites, or complete onboarding steps. The `Assets/after/Streams_save` hook checks these configs on every stream save.

---

## 8. NFTs

```php
// Create an NFT stream
$nft = Streams::create($asUserId, $publisherId, 'Assets/NFT', array(
    'title' => 'My NFT #1',
    'attributes' => Q::json_encode(array(
        'chainId' => '0x89',
        'contractAddress' => $contractAddress,
        'tokenId' => $tokenId,
        'price' => '0.1',
        'currency' => 'ETH'
    ))
));

// NFT contract stream
$contract = Streams::create($asUserId, $communityId, 'Assets/NFT/contract', array(
    'title' => 'My Collection',
    'attributes' => Q::json_encode(array(
        'chainId' => '0x89',
        'contractAddress' => $address,
        'symbol' => 'MYC',
        'standard' => 'ERC721'
    ))
));

// NFT series
$series = Streams::create($asUserId, $publisherId, 'Assets/NFT/series', array(
    'title' => 'Genesis Series',
    'attributes' => Q::json_encode(array(
        'maxSupply' => 1000,
        'price' => '0.05',
        'currency' => 'ETH'
    ))
));

// User streams (auto-created):
// Assets/user/NFTs — category of user's NFTs
// Assets/NFT/contracts — user's custom contracts
// Assets/NFT/series — user's series

// On-chain ABIs at: views/Assets/templates/R1/NFT/contract.abi.json
// Factory ABIs at: views/Assets/templates/R1/NFT/factory.abi.json
```

---

## 9. Currency & Formatting

```php
// App's default currency
$currency = Assets::appCurrency();

// Format amount in a currency
$formatted = Assets::format('USD', 9.99, false);  // "$9.99"
$formatted = Assets::format('USD', 9.99, true);   // "$9.99 USD"

// Currency info
$info = Assets::currency('USD');
// Returns: name, symbol, decimals, ...

// ERC-20 token addresses (from config)
// Assets.currencies.tokens.USDC.0x89 = "0x2791..."
```

---

## 10. JS Client-Side

```javascript
// Pay for something
Assets.pay({
    communityId: communityId,
    amount: 5.00,
    currency: 'USD',
    reason: 'MyPlugin/premium',
    toPublisherId: publisherId,
    toStreamName: streamName,
    onSuccess: function (result) { ... },
    onFailure: function (result) {
        if (result.needCredits) {
            // Show buy-credits dialog
            Assets.Credits.buy({
                amount: result.needCredits,
                onSuccess: function () {
                    // retry payment
                }
            });
        }
    }
});

// Get credits stream
Assets.Credits.getStream(function (stream) {
    var balance = stream.getAttribute('amount');
});
```

---

## 11. Common Mistakes

| Wrong | Right |
|-------|-------|
| Writing to `assets_credits` table directly | Use `Assets_Credits::grant/spend/transfer/refund` — maintains journal + stream |
| `Assets::pay()` and checking for exceptions | `pay()` never throws — check `$result['success']` |
| Calculating credit totals manually | Balance is `Assets_Credits::amount()` or stream attribute `amount` |
| Stripe charge without `setup_future_usage` | Auto-charge only works if first checkout used `'off_session'` |
| Using `Assets_Credits::spend()` for user-to-user | Use `Assets_Credits::transfer()` — creates both debit and credit entries |
| Setting `amount` attribute on credits stream directly | Use credit operations — they handle stream updates via hooks |
| `Assets_Earned::badge()` without `'duplicate' => false` | Omitting this allows the same badge to be awarded multiple times |
| Hardcoding exchange rate | Use `Assets_Credits::convert()` — reads `Assets.credits.exchange` config |

---

## 12. Key Schema

### assets_credits
```sql
id               varbinary(31)   PK
fromUserId       varbinary(31)   KEY  -- null for system grants
toUserId         varbinary(31)   KEY  -- null for system debits
fromPublisherId  varbinary(31)   NULL
fromStreamName   varbinary(255)  NULL
toPublisherId    varbinary(31)   NULL
toStreamName     varbinary(255)  NULL
reason           varchar(255)         -- Q.Text key
communityId      varbinary(31)   NOT NULL
amount           decimal(10,4)   NOT NULL  -- positive=credit, negative=debit
attributes       varchar(1023)   NULL      -- JSON
insertedTime     timestamp
updatedTime      timestamp       NULL
```

### assets_charge
```sql
userId               varbinary(31)   PK
id                   varbinary(255)  PK
publisherId          varbinary(31)   DEFAULT ''
streamName           varbinary(255)  DEFAULT ''
amount               decimal(10,2)   NULL
currency             char(3)         NULL
credits              bigint          NULL
paymentProvider      varchar(32)     NULL
providerCustomerId   varchar(255)    NULL
autoCharge           tinyint(1)      DEFAULT 0
communityId          varbinary(31)   NULL
app                  varchar(64)     NULL
reason               varchar(64)     NULL
description          varchar(255)
attributes           varchar(1023)
status               enum('pending','completed','failed','reversed') DEFAULT 'pending'
insertedTime         timestamp
updatedTime          timestamp       NULL
```

### assets_customer
```sql
userId       varbinary(31)              PK
payments     enum('stripe','authnet')   PK
hash         varchar(32)               PK  -- disambiguates test/live keys
customerId   varbinary(255)                -- e.g. cus_XXXX
insertedTime timestamp
updatedTime  timestamp
```

### assets_badge
```sql
appId        varbinary(31)   PK
communityId  varbinary(31)   NULL
name         varchar(63)     PK
icon         varbinary(255)  NULL
title        varchar(255)
description  text            NULL
points       smallint        DEFAULT 0
```

### assets_earned
```sql
appId        varbinary(31)        -- no single PK (multiple earns OK)
communityId  varbinary(31)   NULL
earnedTime   timestamp
userId       varchar(31)
badgeName    varchar(255)
publisherId  varbinary(31)   NULL
streamName   varbinary(255)  NULL
KEY byTime (appId, communityId, earnedTime)
KEY byUser (appId, communityId, userId)
```

### assets_leader
```sql
communityId  varbinary(31)  PK
day          date           PK
userId       varchar(31)    PK
points       smallint       DEFAULT 0
```

---

## 13. Configuration Reference

```
Assets.credits.exchange.USD = 100              — credits per dollar
Assets.credits.amount.min = 1000               — auto-charge when balance ≤ this
Assets.credits.amount.add = 20000              — how many credits to auto-charge
Assets.credits.bonus.bought = {50000:5000,...}  — bonus tiers
Assets.credits.grant.*                         — credits for user actions
Assets.credits.spend.*                         — credit costs per operation
Assets.credits.buyLink                         — URL for buying credits
Assets.payments.stripe.jsLibrary               — Stripe.js URL
Assets.payments.reasons.*                      — payment reason configs
Assets.charges.simulate.failed                 — simulate failed charges (testing)
Assets.currencies.tokens.*                     — ERC-20 addresses per chain
Assets.NFT.URI.base                            — NFT metadata URI template
```