=== Lumaha Simple Event Tickets ===
Contributors: f3zz3h
Tags: events, tickets, woocommerce, school, booking
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

School and club events with tickets sold through your normal WooCommerce checkout, a QR ticket for every attendee, and a printable register.

== Description ==

Lumaha Simple Event Tickets is a small events plugin for schools, PTAs, clubs and charities that already sell through WooCommerce. List your events, sell (or give away) tickets through the checkout you already use, collect a few details for each person, and print the register for the night.

Tickets go in the basket as one hidden "Event ticket" product, so your payment gateways, coupons, refunds and order emails all work as normal. There is nothing to set up with your payment provider.

= Events =

* Title, description, image, start and end, venue, and bookings on or off
* When tickets go on sale (straight away, on a date, or a number of days before) and when sales close
* Event list pages made in one click (grid, list or compact; upcoming or past; by venue or year)
* "Duplicate for next year" copies an event with its tickets, groups and fields, dates moved on
* An optional photo gallery that appears on the event page once the event is over

= Tickets =

* Paid or free tickets, each with its own capacity, an optional "only N left" note, and hidden tickets
* Ticket groups that share one capacity (for example "Volunteering", capped at 40 across all volunteer slots)
* No overselling: tickets in a basket are held for 15 minutes, and holds are taken inside a database lock, so two buyers can't both get the last ticket
* Cancelled, failed and refunded orders free their tickets; a partial refund frees just the refunded quantity

= Checkout fields =

* A reusable list of fields (text, number, long text, drop-down, tick box)
* Each event picks which to ask for each ticket (a child's class, dietary needs) or once per booking (a contact phone number), and which are required

= On the night =

* Every attendee gets their own ticket with a QR code, emailed when the order is paid, with a page to show or print them
* Staff scan a ticket with a phone camera and tap **Check in**; a second scan says "Already checked in"
* Attendee list per event with sold and remaining places, a CSV download and a printable register sorted by surname

= Works with caching =

Event pages hold no live counts or nonces, so they can be cached. Live availability comes from a small REST endpoint sent with `no-store`.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Lumaha Simple Event Tickets from **Plugins → Add New Plugin**.
3. Go to **Events → Settings → Event pages** and create the "Events" and "Past events" pages, then add them to your menu.
4. Add an event in **Events → Add event**: set the dates and venue in *Event details*, add tickets in *Tickets*, pick any *Checkout fields*, and publish.

== Frequently Asked Questions ==

= Does it need WooCommerce? =

Yes. Tickets are sold through the WooCommerce checkout, so any WooCommerce payment gateway works.

= Does it work with the block checkout? =

Yes, with both the classic and the block cart and checkout, and with High-Performance Order Storage.

= Can I show events on a page built with Elementor or another page builder? =

Yes. Use the shortcodes: `[lset_events]` for upcoming events, `[lset_events past="1"]` for past ones, `[lset_event id="123"]` for one event's details and booking box, and `[lset_gallery id="123"]` for an event's photos. The Settings screen lists every display option.

= Who can see the attendee list and check people in? =

Administrators and shop managers. To let others see attendees or check people in at the door, give them the `lset_view_attendees` or `lset_check_in` capability with a role editor plugin.

= What happens to my data if I delete the plugin? =

Nothing, unless you tick the box under **When the plugin is deleted** in Events → Settings. Then deleting the plugin removes its events, venues, tickets, bookings, settings and tables. WooCommerce orders are never touched, so old orders keep their ticket details.

= Does it send data anywhere? =

No. The plugin makes no requests to other sites. QR codes are drawn by the plugin itself.

== Screenshots ==

1. An event page with its booking box.
2. The list of upcoming events.
3. Editing an event: details, ticket groups and tickets.
4. The attendee list for an event, with the printable register and CSV download.
5. A ticket with its QR code, and the check-in screen staff see when they scan it.

== Changelog ==

= 0.3.0 =
* Ready for WordPress.org: a readme.txt, a translation template, and a WordPress.org build of the plugin.
* Form input is sanitised as soon as it is received.
* The availability REST route has its own permission check (still public, as the event page is); the `lset_rest_can_read` filter can close it.
* Translators' notes for strings with placeholders.
* A suggested privacy policy section, under Settings → Privacy.

= 0.2.1 =
* Event pages: when a page already uses the events address, choose to replace it or keep it.

= 0.2.0 =
* Tickets with QR codes, emailed on payment, and door check-in.
* Optional photo gallery on past events.
* Ticket groups, full-width event pages, and more event list options.
* One on-sale window per event; tickets say when they go on sale.

= 0.1.0 =
* First release: events, tickets through the WooCommerce checkout, bookings and attendee lists.

== Upgrade Notice ==

= 0.3.0 =
Preparation for WordPress.org. No changes to your events or bookings.
