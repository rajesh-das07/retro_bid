# Retro-bid | Technical Documentation & Data Flow

This document outlines the underlying architecture, data flow, and technical implementation of the Retro-bid platform.

## Architecture Overview
Retro-bid operates on a traditional LAMP/XAMPP stack, optimized for secure, stateful interactions without relying on heavy JavaScript frameworks.
* **Frontend:** HTML5, Pure CSS3 (Vanilla).
* **Backend:** PHP 8+ (Session-based state management).
* **Database:** MySQL / MariaDB (Interfaced via PHP Data Objects - PDO).

---

## 1. The Bidding Engine & Data Flow
The core mechanic of Retro-bid is the `process_bid.php` engine. When a user attempts to place a bid, the system executes a strict, atomic workflow to prevent fraud, race conditions, and over-drafting.

### Step-by-Step Execution:
1. **The Security Gate:** The script verifies the user's active session via `$_SESSION['user_id']`. If missing, the request is immediately killed.
2. **Rate Limiting:** A 2-second cooldown is enforced to prevent script-kiddie spam or automated network flooding.
3. **Pessimistic Locking (Anti-Race Condition):** The database row for the specific auction is locked using `FOR UPDATE`. This prevents two users from bidding at the exact same millisecond and causing database corruption.
4. **Timeline Verification:** The system checks if the current server time is between the `start_time` and `end_time`.
5. **The Penny Pincher Rule:** The bid must exceed the current high bid by a dynamic minimum increment (default $200, controlled via `settings.json`).
6. **The Escrow Hold (Financial Logic):** 
   * The platform checks the user's `available_funds`. If sufficient, the funds are instantly deducted from the user's balance and logged as an `escrow_hold` in the `transaction_ledger`.
   * The previous high bidder (if any) is immediately refunded. Their held funds are returned to their balance, logged as an `escrow_release`.
7. **The Soft Ending (Anti-Snipe Engine):** If the bid is placed with less than 120 seconds remaining on the clock, the auction's `end_time` is dynamically extended by an additional 2 minutes.
8. **Commit:** The database transaction is committed and the lock is released.

---

## 2. Security Protocols
* **SQL Injection Prevention:** 100% of database queries utilize PDO prepared statements (`bindParam` and `execute`).
* **Anti-Shill Mechanism:** A user is programmatically blocked from placing a bid if they already hold the current highest bid.
* **Global Suspension System:** The `db_connect.php` file runs a continuous check on every page load. If a user's `banned_until` timestamp is in the future, their session is instantly destroyed, halting all interactions.
* **Maintenance Mode:** An admin toggle in `settings.json` allows the syndicate to lock out all non-admin sessions instantly during upgrades.

---

## 3. Frontend Architecture (CSS Deep-Dive)
The UI was constructed completely from scratch without Bootstrap or Tailwind.

### Key CSS Implementations:
* **Cinematic Scroll Snapping:** 
  * `scroll-snap-type: y mandatory;` forces the browser to lock onto vertical sections.
  * `scroll-snap-type: x mandatory;` handles the horizontal hero slider nested inside the vertical flow.
* **The Alpha Channel & Halos:** 
  * Instead of standard backgrounds, `rgba(0, 0, 0, 0.5)` is used to create frosted glass effects.
  * `text-shadow: 0px 4px 20px rgba(0,0,0,0.8);` creates a localized dark pocket behind typography, ensuring readability over bright, complex imagery without relying on ugly text boxes.
* **Editorial Flexbox:** 
  * `flex: 1;` is used to split screens precisely 50/50 for a heritage magazine aesthetic.
  * `gap: 40px;` manages perfect negative space in the 3-column feature grids.
* **Stateful Theming:** 
  * PHP injects `data-theme="dark"` or `"light"` into the root `<html>` element based on `$_SESSION['theme']`, which triggers native CSS variables (`var(--bg-primary)`) to swap color palettes instantly.

---

## 4. Database Schema (Core Tables)
* **`users`**: Stores `user_id`, hashed passwords, `available_funds`, `alias` (display name), and `banned_until` timestamps.
* **`auctions`**: Stores `auction_id`, `title`, `start_time`, `end_time`, and tracks the `current_high_bid`.
* **`bids`**: A historical log mapping `auction_id` to `user_id` and the specific `bid_amount`.
* **`transaction_ledger`**: An immutable financial record tracking all `deposit`, `withdrawal`, `escrow_hold`, `escrow_release`, and `fee` movements.