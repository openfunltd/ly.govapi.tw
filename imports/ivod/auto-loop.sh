#!/bin/sh
cd $(dirname $0)
while true; do
  php whisper-transcript.php
  sleep 1
done
