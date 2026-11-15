# Real Quark integration

From the repository root on Windows:

```powershell
rtk wsl -- bash tools/integration/run.sh
```

On Linux, run `bash tools/integration/run.sh`. Override `QUARK_PHP` and `QUARK_SOURCE` if the native PHP runtime or Quark checkout lives elsewhere. The default runtime is `/home/trix/quark-idle-docker-20261111/runtime/bin/php`; the default source is `../Quark`.

The runner packages both plugins into `.runtime/integration/plugins`, writes an isolated localhost server configuration, and runs the actual source server for at most 900 seconds plus 10 seconds for shutdown. Functional steps have a 30-second deadline; benchmark jobs have a 300-second deadline. Logs and checkpointed JSON results are under `.runtime/integration`. A missing report, failed check, failed server startup, or timeout returns a nonzero status. Each run creates a fresh world and preserves it for diagnosis.

`bootstrap.php` verifies installed package references against Quark's Composer lock before refreshing a stale root revision **in memory**. It changes no source or dependency files. Results record both the tested source revision and the original Composer root revision.

Benchmarks compare ordinary `World::setBlockAt(..., update: true)` writes with scheduled edits on populated, lighted chunks for 512, 16,384 and 32,768 blocks, repeated three times. Both paths change every block. Scheduled work also pays for reads, history and scheduler accounting; its purpose is to bound tick work. Reports include wall/CPU time, editor tick time, PHP peak memory, peak RSS and average server TPS. No players connect, so client rendering, interaction and multiplayer behavior remain unverified.

`AWE_FUNCTIONAL_ONLY=1` skips benchmarks. Run the separate 1,048,576-block stress pair with `AWE_ONLY_MILLION=1 AWE_BUDGET_MICROS=10000 bash tools/integration/run.sh`. The explicitly configured 10ms stress budget stays within the bounded run time; default-budget million throughput is not measured. Results record actual settings. `AWE_BUDGET_MICROS` otherwise defaults to 2,000µs.
