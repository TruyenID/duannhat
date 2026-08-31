// Package workstation provides embedded migration files.
// This file must live at the project root so the go:embed directive
// can reach both migrations/ directories.
package workstation

import "embed"

// OmnifyMigrations contains auto-generated SQLite migrations from omnify codegen.
// These run AFTER the hand-written migrations in internal/store/migrations/.
//
//go:embed migrations/omnify/*.sql
var OmnifyMigrations embed.FS
