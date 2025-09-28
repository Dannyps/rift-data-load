
# Rift Data Load

This project ingests people counter data from RIFT devices and loads it into a PostgreSQL database for analysis and reporting.

## Architecture & Data Flow

This project supports two main data ingestion flows:

### 1. Direct HTTP Ingestion
- RIFT devices send JSON via HTTP POST to a configurable endpoint (`load_from_request.php`).
- Each request is immediately:
	- Appended to the `records` file
	- Inserted into the PostgreSQL database

### 2. Batch File Ingestion
- Data is collected in the `records` file (e.g., from device requests or other sources).
- Run `load_files.php` to:
	- Read all lines from the file
	- Validate and seed the database schema if needed
	- Insert each line into the database
	- Clear the file after processing

## Main Components
- `load_from_request.php`: HTTP endpoint for device data; writes to file and DB.
- `load_files.php`: Batch loader; reads file, validates schema, seeds DB if needed, inserts data.
- `sql/utils.php`: Core DB logic (schema validation, seeding, insertion).
- `sql/seed.sql`: Defines the `records` table schema.

## Database
- Only PostgreSQL is supported.
- Table: `records` (see `sql/seed.sql` for schema).
- Schema is validated and seeded automatically if missing.

## Usage
1. Configure RIFT devices to POST JSON to your server endpoint (`load_from_request.php`).
2. Run `load_files.php` via PHP CLI to batch load data from the file to the database.
3. To reset DB, delete/recreate the `records` table using `sql/seed.sql`.

## Conventions
- All DB operations use `pg_*` functions.
- JSON must match expected format: top-level `CountLogs[0]->Counts` array.
- File paths are relative and may assume a specific directory structure.

## References
- See `.github/copilot-instructions.md` for AI agent guidance and deeper codebase conventions.

---
For questions or improvements, please open an issue or pull request.
