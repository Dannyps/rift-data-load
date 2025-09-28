# Copilot Instructions for Rift Data Load

## Project Overview
- This project ingests people counter data from RIFT devices via JSON requests and loads it into a PostgreSQL database.
- Data flows from HTTP requests (device endpoints) into the database, with intermediate file storage for batch processing.
- Main scripts: `load_files.php` (batch file loader) and `load_from_request.php` (HTTP endpoint handler).

## Key Components
- `sql/utils.php`: Contains core DB logic: schema validation, seeding, and data insertion.
- `sql/seed.sql`: Defines the `records` table schema.
- `load_files.php`: Reads lines from a file (`../repo/records`), validates schema, seeds DB if needed, and inserts each line.
- `load_from_request.php`: Accepts incoming JSON via HTTP POST, writes to `records` file, and inserts into DB.

## Data Flow
1. RIFT device sends JSON to HTTP endpoint (`load_from_request.php`).
2. Data is appended to `records` file and inserted into DB.
3. For batch loads, `load_files.php` processes all lines in `records` and clears the file after processing.

## Database
- Only PostgreSQL is supported.
- Table: `records` (see `sql/seed.sql` for schema).
- Schema is validated and seeded automatically if missing.

## Patterns & Conventions
- All DB operations use `pg_*` functions.
- JSON structure must match expected format: top-level `CountLogs[0]->Counts` array.
- Error handling: If schema is missing, DB is seeded; malformed JSON throws an exception.
- File paths are relative and may assume a specific directory structure (e.g., `../repo/records`).

## Developer Workflows
- No build or test scripts; code is run directly via PHP CLI or web server.
- To reset DB, delete/recreate `records` table using `sql/seed.sql`.
- For debugging, use `var_dump` and exception messages in PHP scripts.

## Integration Points
- External: RIFT devices (JSON POST requests).
- Internal: PostgreSQL database (connection string required in scripts).

## Example: Adding a New Data Field
- Update `sql/seed.sql` and `insert_line` in `sql/utils.php` to handle new fields.
- Ensure JSON parsing logic matches new structure.

## References
- See `README.md` for high-level project description.
- See `sql/utils.php` for DB logic and conventions.
- See `load_files.php` and `load_from_request.php` for data ingestion patterns.

---
If any section is unclear or missing details, please provide feedback for further refinement.
