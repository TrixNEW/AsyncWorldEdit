#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
php="${QUARK_PHP:-/home/trix/quark-idle-docker-20261111/runtime/bin/php}"
quark="${QUARK_SOURCE:-$(dirname "$root")/Quark}"
export QUARK_SOURCE="$quark"
runtime="$root/.runtime/integration"
"$php" -d phar.readonly=0 "$root/tools/integration/prepare.php"
cd "$quark"
set +e
timeout --signal=TERM --kill-after=10s 900s "$php" "$root/tools/integration/bootstrap.php" --no-wizard --disable-ansi --data="$runtime/data" --plugins="$runtime/plugins" > "$runtime/server.log" 2>&1
status=$?
set -e
cat "$runtime/server.log"
if [ "$status" -ne 0 ]; then
  echo "Quark exited with status $status" >&2
  exit "$status"
fi
"$php" -r '$p=$argv[1]; if(!is_file($p)){fwrite(STDERR,"Integration report missing\n");exit(1);} $r=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR); echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";exit(($r["passed"]??false)?0:1);' "$runtime/data/integration-results.json"

