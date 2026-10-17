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
    includes/bootstrap.php                     session, DB, schema, helpers, reminder maths
    includes/layout.php                        sidebar shell, auth screen, reminder card
    assets/css/app.css, assets/js/app.js
    config/db.example.php                      copy to config/db.php
    sql/schema_reference.sql                   reference only (the app creates tables itself)
    Dockerfile, docker/                        Apache + PHP config



## Ideas for next steps

- Email/SMS/WhatsApp reminders from a daily cron job (needs a mail provider)
- Photo/receipt upload for each service
- Per-bike fuel log and mileage
