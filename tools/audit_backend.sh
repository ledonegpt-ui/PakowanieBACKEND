#!/bin/bash

echo "===================================="
echo " Backend Audit"
echo "===================================="
echo ""

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$BASE_DIR"

echo "Project root:"
pwd
echo ""

echo "===================================="
echo "Modules"
echo "===================================="
ls app/Modules
echo ""

echo "===================================="
echo "Picking module structure"
echo "===================================="
ls app/Modules/Picking
echo ""

echo "===================================="
echo "API routes"
echo "===================================="
grep "/api/v1/" api/v1/index.php
echo ""

echo "===================================="
echo "Picking API routes"
echo "===================================="
grep "/picking/" api/v1/index.php
echo ""

echo "===================================="
echo "Database migrations"
echo "===================================="
ls database/migrations
echo ""

echo "===================================="
echo "Docs"
echo "===================================="
ls docs
echo ""

echo "===================================="
echo "Real picking code"
echo "===================================="

echo "Controllers:"
ls app/Modules/Picking/Controllers | grep -v ".bak"

echo ""

echo "Services:"
ls app/Modules/Picking/Services | grep -v ".bak"

echo ""

echo "Repositories:"
ls app/Modules/Picking/Repositories | grep -v ".bak"

echo ""

echo "===================================="
echo "Summary"
echo "===================================="

echo "Modules:"
ls app/Modules | wc -l

echo "API routes:"
grep -c "/api/v1/" api/v1/index.php

echo "Picking controllers:"
ls app/Modules/Picking/Controllers | grep -v ".bak" | wc -l

echo "Picking services:"
ls app/Modules/Picking/Services | grep -v ".bak" | wc -l

echo "Picking repositories:"
ls app/Modules/Picking/Repositories | grep -v ".bak" | wc -l

echo ""

echo "Audit finished."
