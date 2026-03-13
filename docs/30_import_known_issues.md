# Known import issues from old system

## Observed in logs
- PDO::beginTransaction(): MySQL server has gone away
- Error reading result set header
- Undefined variable legacyImg in bin/import_orders_master_v2.php

## Rule for now
- do not refactor import blindly
- keep current import path stable
- fix issues in isolated way after verification
