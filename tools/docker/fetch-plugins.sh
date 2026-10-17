#!/usr/bin/env bash
# Télécharge les extensions tierces de test (versions analysées) dans tools/docker/plugins/.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p plugins
for spec in wp-job-manager:2.4.7 go-fetch-jobs-wp-job-manager:1.8.5.2; do
  slug=${spec%%:*}
  ver=${spec##*:}
  [ -d "plugins/$slug" ] && continue
  curl -sSL -o "plugins/$slug.zip" "https://downloads.wordpress.org/plugin/$slug.$ver.zip"
  (cd plugins && unzip -q "$slug.zip" && rm "$slug.zip")
done
ls plugins
