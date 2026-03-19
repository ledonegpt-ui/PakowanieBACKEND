# Migrations policy

- current import must keep working
- new workflow tables are added beside current import tables
- no destructive changes without backup and verification
- each migration file should be atomic and reversible where possible
