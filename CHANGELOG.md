# Changelog

## 2026.11.15c

First public release, prepared for Community Applications review.

- Ships the plugin with its original icon and public release metadata for
  Community Applications listing.
- Includes earlier fixes: the plugin's own CSRF validation now works alongside
  Unraid's standard `csrf_token` handling on the settings page.
- Includes earlier fixes: signed relative Silo artwork URLs load through the
  same-origin Unraid poster endpoint, so posters render without direct browser
  access to the internal Silo address.
- Adds a stable public installer URL for Unraid's update checker.
