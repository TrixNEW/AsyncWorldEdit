# Validation

PHP 8.4.27 ZTS, Quark v6 `dev`, native qmath/chunkutils2/morton/igbinary/bedwire runtime. Initial API inspection used `3ba56651a`; the live million-block run used `897c04f3d`. Quark was updated independently during development; the plugin did not modify it.

The installed Composer root revision was stale. The test bootstrap verifies installed packages against Quark's lock file and refreshes that root revision in memory. Server data and plugins are isolated under `.runtime/integration`.

## Automated checks

- PHPUnit: **58 tests, 58,145 assertions**, all passed with the native runtime and `QUARK_ROOT` set.
- PHPStan level 8: full plugin analysis passed against the Quark checkout and native runtime; portable core analysis also passed.
- Live checks cover command registration, negative chunk boundaries, world heights, unchanged states, undo/redo, cancellation, competing editors, exact sign NBT, inventory and secondary-layer rejection, directional clipboard rotation, lighting, chunk-loader cleanup, save/unload/reload and shutdown.
- The final [functional run](../benchmarks/quark-functional.json) passed 32 live checks, including distinct clipboard pivots, rotated redo, and export/reimport of transformed states.
- CI runs portable tests, PHPStan core analysis, syntax checks and PHAR packaging. Live tests are separate and require Quark's runtime.

## Benchmarks

[Default-budget samples](../benchmarks/quark-default.json) use a 2 ms editor budget and three repeats per size. The run was interrupted after the recorded workload samples; later persistence/shutdown checks were completed in separate live runs. No incomplete measurement is treated as a completed benchmark.

[Million-block run](../benchmarks/quark-million.json): **1,048,576 changes in 108.62 s**, approximately **9,653 changes/s**, using an explicitly configured **10 ms** editor budget. The editor consumed 21.94 s across 2,172 measured slices; the maximum slice was **19.32 ms**. The final rolling TPS average was **20.0** on a server with **zero connected clients**.

The ordinary placement baseline took 11.65 s in one synchronous loop. It excludes history and expected-state reads, and stalls the main thread; it is not a throughput-equivalent replacement for the scheduler. Both paths keep Quark's normal updates enabled. Peak PHP allocation was 509.5 MiB and peak process RSS 692.4 MiB across the combined baseline/scheduled run, including loaded worlds and Quark update queues. These are not plugin-only memory measurements.

The [packed-memory benchmark](../benchmarks/engine.php) isolates scheduling/history overhead. It excludes Quark, physics, lighting, network traffic and tick sleeps; its throughput must not be advertised as server throughput.
