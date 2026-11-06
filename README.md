# Lumaha Simple Event Tickets

A small WordPress plugin for school events: list events, sell (or give away) tickets through the
normal WooCommerce checkout, collect a few details per child, and print the register for the night.

Built for [Horfield SA](https://www.horfieldsa.co.uk) to replace EventPrime. Same style as
[Lumaha Simple Raffle Tickets](https://github.com/f3zz3h/lumaha-simple-raffle-tickets): plain
WordPress and WooCommerce, no build step, no licence server.

## What it does

- **Events** (Events → Add event): title, description, image, start and end, venue, bookings on/off,
  when tickets go on sale (now, a date, or N days before) and when sales close (default: event start),
  and a message for when bookings are closed. Times are saved exactly as typed, in the site timezone.
- **Tickets** per event: price (0 = free), capacity, hidden, an optional "only N left" note when low, and
  optional **groups** that share a capacity (e.g. "Volunteering" capped at 40 across all volunteer slots).
  There's no per-order limit; the quantity list goes up to 50 or what's left.
- **Event lists**: Events → Settings → *Event pages* makes an "Events" and a "Past events" page in one click.
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
- **Tickets with QR codes**: each person booked gets their own ticket, emailed when the order is paid
  (or free), with a link to a page to show or print them. Scanning a code with a phone camera opens the
  ticket; logged-in staff see whether it's valid (paid, refunded, already used) and tap **Check in**.
  Colour, logo and text on the tickets are in Events → Settings → *Tickets*; the email's subject and
  heading in WooCommerce → Settings → Emails → *Event tickets*.
- **Caching-safe**: event pages hold no counts or nonces; live availability comes from
  `/wp-json/lset/v1/availability/<event>` (sent with `no-store`).
- Old EventPrime links (`/events/?event=123`) redirect to the migrated event.
- Updates come from this repo's GitHub releases.

## Shortcodes

| Shortcode | Shows |
| --- | --- |
| `[lset_events]` | Upcoming events, soonest first: image, date, venue, price, Book button. `limit="6"` shows only the next 6. |
| `[lset_events past="1"]` | Past events only, newest first. Add `upcoming="1"` for both; `order="asc"` or `"desc"` to change the order. |
| `[lset_event id="123"]` | One event's details and booking box, for an Elementor page. `details="0"` for just the booking box. |

Each event also has its own page at `/events/<event-name>/` (the slug is a setting).

## How to…

**Create an event.** Events → Add event. Fill in the title, description and featured image. In *Event
details* set the dates, times and venue, and leave *Take bookings* ticked (untick it for info-only events).
Set *Tickets on sale* if they shouldn't go on sale straight away. Add tickets in *Tickets*. Pick fields in
*Checkout fields*. Publish.

**List events on the site.** Events → Settings → *Event pages*: **Create "Events" page** and **Create
"Past events" page**, then add them to the menu. Or put `[lset_events]` on any page.

**Make next year's event.** Events → All events, hover the event, **Duplicate for next year**. Choose
"same date" or "same weekday" (52 weeks later). The copy is a draft with the same tickets, groups and
fields, all dates moved, no bookings, and the year in the title updated. Check it and publish.

**Print the register.** Events → Attendees, pick the event, **Printable register** (sorted by surname,
one table per ticket, with a tick column), or **Download CSV** for a spreadsheet.

**Check people in at the door.** Log in on the phone you'll use (admins and shop managers can check in;
give volunteers the `lset_check_in` capability with the Members plugin). Scan each ticket's QR code with
the phone's camera, open the link and tap **Check in**. A second scan of the same ticket shows "Already
checked in". If a code won't scan, type the code printed under it at `/?lset_t=` (also linked as **Door
check-in** on the Attendees screen). The Attendees list and CSV show who has checked in.

**Resend someone's tickets.** Open the order, pick **Email event tickets to the customer** in *Order
actions* and click the arrow. The order screen also links to the customer's tickets page.

## Moving from EventPrime

The migration is a separate, one-off plugin, **Lumaha Simple Event Tickets: EventPrime
Migrator** (`addons/lumaha-set-eventprime-migrator`, released as its own
`lumaha-set-eventprime-migrator-<version>.zip`). It isn't part of the main plugin. Install and
activate it next to the main plugin, run the command, check the result, then deactivate and
delete it. The copied events and bookings stay.

Run this with WP-CLI, on a staging copy first:

```sh
wp lset migrate-eventprime --dry-run                      # what it would do; saves nothing
wp lset migrate-eventprime --report=/tmp/lset-report.csv  # the real run
```

It copies venues, checkout fields, events, ticket categories (as ticket groups), tickets,
bookings and attendee names. It only reads EventPrime's data and never changes it or any
WooCommerce order. Each copy remembers its EventPrime id, so running it again updates what it
made last time rather than making duplicates. `--from=2026-01-01` limits it to events from
that date.

At the end it prints, for every ticket, how many EventPrime says it sold and how many the new
bookings add up to, plus any warnings (for example dates where EventPrime's own timestamp is
an hour out, or bookings with no Woo order). The dry run needs InnoDB tables, which is the
WordPress default.

These stay in the main plugin, because they matter after the migrator is gone: old orders keep
their EventPrime line details, and with EventPrime switched off those show as
"Tickets: 2 × Child". Copied bookings follow their order if it's later cancelled or refunded.
Links in the old `/events/?event=123` form redirect to the copied event.

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
