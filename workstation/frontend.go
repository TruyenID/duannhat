// Package workstation provides the embedded frontend assets.
// This file must live at the project root so the go:embed directive
// can reach the frontend/dist directory.
package workstation

import "embed"

//go:embed all:frontend/dist
var FrontendAssets embed.FS
