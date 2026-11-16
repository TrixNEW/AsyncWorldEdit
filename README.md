# AsyncWorldEdit

World editing for **Quark 6 / PHP 8.4**. Edits run on the main thread in chunk order within a per-tick time budget, through Quark's ordinary `setBlockAt()` so lighting, tiles, neighbour updates and clients stay in sync. Schematic files are read and written by worker threads. Nothing is scheduled while idle.

## Permissions

Permissions default to operators. Each subcommand checks its own permission:

- `asyncworldedit.selection`
- `asyncworldedit.edit`
- `asyncworldedit.clipboard`
- `asyncworldedit.history`
- `asyncworldedit.schematic`
- `asyncworldedit.brush`

## Commands

Everything lives under `//we` (alias `//worldedit`); `//we` on its own lists what you can run. Each subcommand also has the familiar shortcut without `we`, such as `//set` or `//schem load`.

| Area | Subcommands |
| --- | --- |
| Selection | `wand`, `pos1 [x y z]`, `pos2 [x y z]`, `hpos1`, `hpos2`, `sel [cuboid\|ellipsoid\|cylinder\|clear]`, `outline`, `expand`, `contract`, `shift`, `size`, `count <mask>`, `distr` |
| Editing | `set`, `replace <mask> <pattern>`, `walls`, `faces`, `hollow`, `overlay`, `naturalize`, `smooth`, `move`, `stack`, `gmask [mask]`, `seed` |
| Generation | `sphere`, `hsphere`, `cyl`, `hcyl`, `cube`, `hcube`, `pyramid`, `hpyramid` |
| Clipboard | `copy`, `cut`, `paste [-a\|-o]`, `rotate <degrees>`, `flip <x\|y\|z>`, `clearclipboard` |
| History | `undo`, `redo`, `cancel`, `status` |
| Brushes | `brush sphere\|cylinder\|overlay <pattern> [radius]`, `brush smooth [radius]`, `brush clipboard`, `brush none`, `brush mask [mask]` (`//mask`), `brush range <blocks>` (`//range`) |
| Schematics | `schem load <file>`, `schem save <file>` |

```text
//we set 70%stone,30%dirt
//gmask #solid&!stone
//paste -a
//we brush sphere stone 3
//schem save house.mcstructure
```

Selections can be cuboids, ellipsoids or cylinders. Patterns support weights and `#existing`; masks support `#air`, `#solid`, `!`, `&`, `,` and parentheses. The global mask applies to the blocks being replaced, except for `move` and `stack`, where it selects which source blocks move.

## Behaviour and limits

- Edits only touch loaded, populated chunks. Each player runs one operation at a time; operations in overlapping boxes are refused rather than interleaved. Undo and redo lock only the box they touch.
- Cancelling keeps applied changes undoable. History is held in memory, shared under one limit with the oldest steps evicted first, and ends on disconnect, world unload or restart.
- Sponge `.schem` v2/v3 imports accept states Quark resolves exactly. Bedrock `.mcstructure` imports and exports up to the clipboard block limit; an `asyncworldedit_origin` tag keeps the clipboard pivot.
- Unsupported data fails explicitly: inventories and other item-bearing tiles, pistons, secondary block layers (waterlogging), legacy `.schematic`, entities, biomes, Java block entities and rotating block entities.
