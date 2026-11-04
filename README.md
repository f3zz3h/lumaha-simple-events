# Lumaha Simple Event Tickets

A small WordPress plugin for school events: list events, sell (or give away) tickets through the
normal WooCommerce checkout, collect a few details per child, and print the register for the night.

Built for [Horfield SA](https://www.horfieldsa.co.uk) to replace EventPrime. Same style as
[Lumaha Simple Raffle Tickets](https://github.com/f3zz3h/lumaha-simple-raffle-tickets): plain
WordPress and WooCommerce, no build step, no licence server.

## What it does

- **Events** (Events → Add event): title, description, image, start and end, venue, bookings on/off,
  and a message for when bookings are closed. Times are saved exactly as typed, in the site timezone.
- **Tickets** per event: price (0 = free), capacity, sale start (a date, or N days before), sale end
  (default: event start), min and max per order, hidden, "x left" when low, and optional **groups** that
  share a capacity (e.g. "Volunteering" capped at 40 across all volunteer slots).
- **Checkout fields** (Events → Checkout fields): a reusable list (text, number, long text, drop-down,
  tick box). Each event picks which to ask **for each ticket** (child's class, dietary needs) or **once per
  booking** (contact phone), and which are required.
- **Checkout**: tickets go in the basket as one hidden "Event ticket" product, so WooCommerce, Square,
  coupons, refunds and order emails all work as normal. Free bookings are £0 orders.
- **No overselling**: tickets in a basket are held for 15 minutes (setting), and every hold is taken
  inside a database lock for the event, so two buyers can't both get the last ticket. Cancelled, failed
  and refunded orders free their tickets; a partial refund frees just the refunded quantity.
- **Attendees** (Events → Attendees, or the link on each event): sold vs capacity per ticket and group,
  everyone's names and answers, CSV download and a printable register. Needs the `lset_view_attendees`
  capability (admins and shop managers have it; give it to others with the Members plugin).
- **Caching-safe**: event pages hold no counts or nonces; live availability comes from
  `/wp-json/lset/v1/availability/<event>` (sent with `no-store`).
- Old EventPrime links (`/events/?event=123`) redirect to the migrated event.
- Updates come from this repo's GitHub releases.

## Shortcodes

| Shortcode | Shows |
| --- | --- |
| `[lset_events upcoming="1" order="asc"]` | Upcoming events: image, date, venue, price, Book button. `limit="6"`, `past="1"` (past events only), `show_past="1"` with `upcoming="1"` (both). |
| `[lset_event id="123"]` | One event's details and booking box, for an Elementor page. `details="0"` for just the booking box. |

Each event also has its own page at `/events/<event-name>/` (the slug is a setting).

## How to…

**Create an event.** Events → Add event. Fill in the title, description and featured image. In *Event
details* set the dates, times and venue, and leave *Take bookings* ticked (untick it for info-only events).
Add tickets in *Tickets*. Pick fields in *Checkout fields*. Publish.

**Make next year's event.** Events → All events, hover the event, **Duplicate for next year**. Choose
"same date" or "same weekday" (52 weeks later). The copy is a draft with the same tickets, groups and
fields, all dates moved, no bookings, and the year in the title updated. Check it and publish.

**Print the register.** Events → Attendees, pick the event, **Printable register** (sorted by surname,
one table per ticket, with a tick column), or **Download CSV** for a spreadsheet.

## Development

Needs PHP 7.4+, Composer, and MySQL/MariaDB.

```sh
composer install
# Quick local site (Docker):
npx @wordpress/env start          # http://localhost:8888, admin / password
npx @wordpress/env run cli wp eval-file wp-content/plugins/lumaha-simple-event-tickets/bin/seed-demo.php
```

### Running the tests

The tests use the WordPress test suite with WooCommerce (HPOS on). Point them at a WordPress copy,
an unzipped WooCommerce and an empty database:

```sh
WP_CORE_DIR=/path/to/wordpress WC_DIR=/path/to/woocommerce \
WP_TESTS_DB_NAME=wordpress_test WP_TESTS_DB_USER=root WP_TESTS_DB_PASS=root \
vendor/bin/phpunit
```

GitHub Actions runs them on every pull request (PHP 8.3 + latest WooCommerce, PHP 7.4 + an older one).

### Releases

Bump the version in the plugin header, `LSET_VERSION` and `Lumaha_Simple_Event_Tickets::VERSION`,
then push a tag like `v0.2.0`. The Release workflow builds the zip and publishes it; sites see the update
in Dashboard → Updates.
