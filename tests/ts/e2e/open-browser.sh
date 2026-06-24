#!/usr/bin/env bash
# Launch Chrome with remote debugging so Puppeteer can connect to an existing logged-in session.
# After running this, log in and navigate to the gallery, then run the Puppeteer test scripts.
BASE_URL="${1:-https://www.avphilipsvanhorne.nl/leden/foto-en-video/?avpvh-path-19d40080=1qwvgLfcbtjlZbdwW2SNjA4FoOkM6AjPd/1UtOZE-TOtPUpURFQffOZVdB4-8aZ3YPi/1SE8V-91UAspNusg2K2dUrAKghvPU4oIe}"

google-chrome \
  --remote-debugging-port=9222 \
  --user-data-dir=/tmp/chrome-debug \
  --no-first-run \
  --no-default-browser-check \
  --disable-infobars \
  "$BASE_URL" &
