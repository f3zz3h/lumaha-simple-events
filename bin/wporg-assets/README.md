# WordPress.org listing images

How `.wordpress-org/` (screenshots, banner, icon) was made for 0.3.0. Re-run after visible UI changes.
All names and data are made up ("Oakfield Primary PTA"), so no real school shows.

1. A throwaway WordPress with WooCommerce and this plugin, Twenty Twenty-Five, timezone Europe/London,
   currency GBP, pretty permalinks, `woocommerce_coming_soon` = `no`, admin user `a` / password `a`.
2. Put event pictures named `disco.jpg`, `quiz.jpg`, `xmas.jpg`, `summer.jpg` next to `seed.php`, then
   `wp eval-file bin/wporg-assets/seed.php` (events, tickets, fields, 12 paid bookings). Pages "Events"
   (`[lset_events]`, in a wide group) and "Past events" are made too.
3. Serve it at http://localhost:8080: `WP_DIR=/path/to/wordpress php -S localhost:8080 -t /path/to/wordpress bin/wporg-assets/router.php`
4. `node bin/wporg-assets/final.js .wordpress-org <disco event id> <a ticket code>` (Playwright). Before running,
   set the buyer tickets URL in final.js from `wp eval 'echo LSET_Tickets::order_url( wc_get_order( ID ) );'`.
   Screenshot 1 hides Twenty Twenty-Five's "Written by … in …" line, which is blank for events.

The banner (772×250, 1544×500) and icon (128, 256) are flat drawings of a ticket with a QR code, in the
ticket colour (#1d3557). WordPress.org puts the plugin name over the banner's bottom left, so that stays clear.
