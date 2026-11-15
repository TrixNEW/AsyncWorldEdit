# Validation

PHP 8.4.27 ZTS, Quark v6 `dev`, native qmath/chunkutils2/morton/igbinary/bedwire runtime. Initial API inspection used `3ba56651a`; the live million-block run used `897c04f3d`. Quark was updated independently during development; the plugin did not modify it.

The installed Composer root revision was stale. The test bootstrap verifies installed packages against Quark's lock file and refreshes that root revision in memory. Server data and plugins are isolated under `.runtime/integration`.

## Automated checks

- PHPUnit: **93 tests, 61,862 assertions**, all passed with the native runtime and `QUARK_ROOT` set. Portable PHP 8.4.14 passed 72 tests with 44,231 assertions; 21 native tests skipped.
- PHPStan level 8: full plugin analysis passed against the Quark checkout and native runtime; portable core analysis also passed.
- Live checks cover command registration, negative chunk boundaries, world heights, unchanged states, undo/redo, cancellation, competing editors, exact sign NBT, inventory and secondary-layer rejection, directional clipboard rotation, lighting, chunk-loader cleanup, save/unload/reload and shutdown.
- The [audit functional run](../benchmarks/quark-review-functional.json) passed 38 live checks, including idle scheduling, worker wakeup/cleanup and tile data cached by synchronous chunk listeners. The default-budget run repeated these checks and passed nine workload samples.
- Regression tests cover reentrant cancellation/shutdown, retained history cleanup, reservation escapes, masked cuts, clipboard admission, stale smoothing snapshots, item-bearing tiles/pistons, malformed compression/metadata, concurrent/cancelled saves and stopped-worker replacement. A 200-cycle, 800-job cancellation test checks job references, ownership, pins and history cleanup. Composer validation, PHP syntax checks and PHAR packaging passed.
- CI runs portable tests, PHPStan core analysis, syntax checks and PHAR packaging. Live tests are separate and require Quark's runtime.

## Benchmarks

[Audit default-budget run](../benchmarks/quark-review-default.json): 2 ms editor budget, three repeats per size, 47 live checks passed. Times exclude harness logging and report writing.

| Blocks | Median wall time | Largest editor slice |
| --- | --- | --- |
| 512 | 0.250 s | 2.014 ms |
| 16,384 | 7.011 s | 2.227 ms |
| 32,768 | 13.203 s | 2.182 ms |

[Audit million-block run](../benchmarks/quark-review-million.json): **1,048,576 changes in 69.98 s**, approximately **14,984 changes/s**, using an explicitly configured **10 ms** editor budget. All 39 live checks passed. The editor consumed 14.01 s across 1,400 measured slices; the maximum slice was **10.27 ms**. The final rolling TPS average was **20.0** on a server with **zero connected clients**.

The ordinary placement baseline took 5.14 s in one synchronous loop. It excludes history and expected-state reads, and stalls the main thread; it is not a throughput-equivalent replacement for the scheduler. Both paths keep Quark's normal updates enabled. Peak PHP allocation was 513.5 MiB and peak process RSS 690.6 MiB across the combined baseline/scheduled run, including loaded worlds and Quark update queues. These are not plugin-only memory measurements.

Earlier [default samples](../benchmarks/quark-default.json) and the [0.1.0 million-block run](../benchmarks/quark-million.json) remain available. Runtime load and measurement boundaries differ, so these runs do not establish a controlled before/after speedup. Connected-client synchronization, outlines and hard-crash recovery were not exercised by these runs.

The [packed-memory benchmark](../benchmarks/engine.php) isolates scheduling/history overhead. It excludes Quark, physics, lighting, network traffic and tick sleeps; its throughput must not be advertised as server throughput.
