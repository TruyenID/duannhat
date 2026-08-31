#!/usr/bin/env bash
# EAS Build hook: runs on the build server BEFORE `npm ci`.
# Configures git so private github.com dependencies (@godxjp/ui-native and
# @godxjp/mobile-shared, which the lockfile resolves via
# git+ssh://git@github.com/...) can be cloned
# using a Personal Access Token instead of an SSH key the build server lacks.
#
# Requires the EAS environment variable GITHUB_TOKEN (a GitHub PAT with read
# access to the private repos). Set it with `eas env:create` — see README/notes.
set -euo pipefail

if [ -n "${GITHUB_TOKEN:-}" ]; then
  echo "[eas-build-pre-install] Rewriting github.com git URLs to use GITHUB_TOKEN"
  git config --global url."https://${GITHUB_TOKEN}@github.com/".insteadOf "ssh://git@github.com/"
  git config --global url."https://${GITHUB_TOKEN}@github.com/".insteadOf "git@github.com:"
  git config --global url."https://${GITHUB_TOKEN}@github.com/".insteadOf "https://github.com/"
else
  echo "[eas-build-pre-install] WARNING: GITHUB_TOKEN is not set — private github.com deps will fail to clone" >&2
fi
