# Lumaha Simple Event Tickets: EventPrime Migrator

A one-off add-on for moving from EventPrime to Lumaha Simple Event Tickets. It adds one WP-CLI
command and nothing else: no settings, no tables, nothing on page loads. Once the move is done,
deactivate and delete it. The copied events and bookings stay.

Needs WooCommerce and Lumaha Simple Event Tickets active. EventPrime can be active or not, as
long as its data is still in the database.

```sh
wp lset migrate-eventprime --dry-run                      # what it would do; saves nothing
wp lset migrate-eventprime --report=/tmp/lset-report.csv  # the real run
```

See "Moving from EventPrime" in the main plugin's README for what it copies and what to check.
