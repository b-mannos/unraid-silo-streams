# Security

For vulnerabilities, use [private vulnerability reporting](https://github.com/b-mannos/unraid-silo-streams/security/advisories/new). Include the affected plugin and Unraid versions, reproduction steps and impact.

Use [GitHub issues](https://github.com/b-mannos/unraid-silo-streams/issues) for ordinary bugs and support. Remove API keys, configuration files, viewer names, private addresses and media details before posting logs or screenshots. Never attach the saved configuration file.

The plugin stores an admin-owned monitoring key on Unraid's boot flash. Access to that flash share and its backups must remain restricted to trusted administrators. Monitoring uses the native Silo API, while poster requests use only signed artwork from the configured server.
