# Database migrations

Create one immutable SQL file per schema change using this format:

`YYYYMMDD_NNN_short_description.sql`

Run pending migrations with:

```powershell
C:\xampp\php\php.exe scripts\migrate.php
```

The runner records applied files and their SHA-256 checksums in `schema_migrations`.
Never edit a migration after it has been applied; create a new migration instead.
