# RideCare: Bike Service & Maintenance Reminder System

Track your bikes, set service reminders by **kilometres and/or days**, and log every
service. The dashboard tells you what is overdue, what is due soon and how much you spent.

PHP 8.2 + Apache + MySQL, Docker-ready (same stack as the SWM project).

## Features

- Register / sign in (hashed passwords, CSRF tokens, prepared statements, escaped output)
- Multiple bikes per user: nickname, make/model, registration, odometer
- Reminders such as *Engine oil every 3,000 km or 180 days*; whichever limit comes first triggers it
- Status per reminder: **On track / Due soon (85%+ used) / Overdue**, with a progress bar
- **Log service**: picking a reminder resets its clock and updates the odometer automatically
- Service history per bike and across all bikes, filter by bike/year, CSV download
- Quick-add presets when creating a bike (oil, chain, general service, air filter, spark plug, brake pads, insurance)
- Works on phone: the sidebar turns into a bottom tab bar

## How "due" is calculated

For each reminder: `next due = last done + interval` (km and/or days).
`used % = max(km used / km interval, days used / day interval)`.
`>= 85%` shows *Due soon*, `>= 100%` shows *Overdue*. Keep the odometer updated on the bike page
so the km side stays accurate.

## Files

    index.php / register.php / logout.php     auth
    dashboard.php                              overview + needs-attention list
    bikes.php                                  garage + add bike
    bike.php                                   one bike: odometer, reminders, history, edit/delete
    service_add.php                            log a service
    history.php                                all services, filters, CSV
    includes/bootstrap.php                     session, DB connection, helpers, reminder maths
    includes/schema.php                        creates / verifies / repairs the 4 tables on every request
    includes/layout.php                        sidebar shell, auth screen, reminder card
    assets/css/app.css, assets/js/app.js
    config/db.php                              mysqli connection ($conn), configured by environment variables
    setup_db.php                               visible database check: open /setup_db.php to see each table's status
    sql/schema_reference.sql                   reference only (the app creates tables itself)
    Dockerfile                                 Apache + PHP config (written inside the Dockerfile)

## Deploying on Render with Aiven MySQL

`config/db.php` reads `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` and `DB_PORT` from the environment
(set them in the Render dashboard) and connects over SSL, which Aiven requires. `APP_TIMEZONE` is optional
(defaults to `Asia/Kolkata`).

The tables `users`, `bikes`, `reminders` and `services` are created automatically: on every request the app
runs one read-only check, and only when something is missing does it create or repair it (see `includes/schema.php`).
If a table cannot be created (for example the database user lacks CREATE rights), the page says which tables are
affected and the exact MySQL error is written to the Render logs, so nothing fails silently.
Open `/setup_db.php` any time to see the status of each table.

PHP errors are logged, not shown to visitors (see the PHP section of the `Dockerfile`).

## Ideas for next steps

- Email/SMS/WhatsApp reminders from a daily cron job (needs a mail provider)
- Photo/receipt upload for each service
- Per-bike fuel log and mileage
