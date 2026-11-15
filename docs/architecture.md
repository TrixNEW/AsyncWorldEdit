# Runtime contract

Target: Quark `dev`, PHP 8.4, plugin API 6.0.0. API research used `3ba56651a66196d3b5c401f0d4e8d617f8e8645a`; subsequent live tests also passed on `897c04f3dcc9ab09e6ab48022416dc385c9ab1b4`.

Edits visit chunks in coordinate order. The main-thread scheduler rotates between owners, limits work units and elapsed time, and pins at most one chunk per job. Conflicting region reservations reject immediately; history operations require exclusive access to their world. Every write checks the expected current state and tile NBT. Undo leaves newer, conflicting changes intact.

Commits use `World::setBlockAt(..., update: true)`. Quark owns tile lifecycle, collision invalidation, dirty flags, lighting, neighbor updates and client notification. Direct palette writes only set terrain dirty and bypass these responsibilities, so the editor does not use them. A tick budget limits editor work; it cannot impose a hard deadline on an individual Quark placement or the world's subsequent update queues.

Only already loaded, populated chunks are editable. Quark's population promise still loads provider data synchronously before generation; automatic traversal of unloaded terrain needs a separately verified loading path. The editor fails explicitly and retains history for any preceding changes.

History stores sparse signed coordinates and before/after runtime states in 4,096-record compressed segments, with separate exact tile payloads. It is bounded across owners and worlds. Cancellation and failures keep committed changes undoable. Undo interrupted halfway retains the source entry for retry. History is held in memory: plugin disable, disconnect and process termination discard it. Edits are incremental, not crash-atomic transactions.

Schematic parsing, file I/O and compression run on dedicated sleeping threads, separate from Quark's network and generation pool. Each worker accepts one request. Requests and results use native igbinary; files remain NBT. Input limits cover compressed size, expanded size, allocation count, depth, dimensions, palettes and indices. Saves use a confined temporary file and atomic rename. Cancellation is checked before publication; a save that already reached its publication point can finish.

Clipboard coordinates are relative to the copy pivot. Rotations and mirrors compose an integer transform without copying the packed block buffer. Unsupported directional states and transformed block entities reject explicitly. Inventory-bearing tiles and secondary fluid layers reject before mutation. This deliberately restricts scope while preserving supported data.

## References inspected

- [Quark world lifecycle](https://github.com/Bedrock-Phanatics/Quark/blob/3ba56651a66196d3b5c401f0d4e8d617f8e8645a/src/world/World.php), `Chunk`, `SubChunk`, `BlockTransaction`, `AsyncTask`, Vow, command parsing and plugin loaders.
- [FAWE queue scheduling](https://github.com/IntellectualSites/FastAsyncWorldEdit/blob/94a3a0a37a1db90cef8c469f550a764e7ead45a4/worldedit-core/src/main/java/com/fastasyncworldedit/core/queue/implementation/QueueHandler.java), queue extents, clipboards, geometry and schematic readers. Java queue internals are not assumed thread-safe on Quark.
- [EasyEdit schematic import](https://github.com/platz1de/EasyEdit/blob/7502d15a719167d0612415edf2e5b208e5c8fcc6/src/platz1de/EasyEdit/schematic/type/SpongeSchematic.php), chunk requests, edit threads and transforms. Its commented offset handling and substitution on conversion failure are not adopted.

The implementation is original MIT-licensed code; GPL reference implementations are not bundled or copied.
