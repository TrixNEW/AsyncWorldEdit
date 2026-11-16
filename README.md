# AsyncWorldEdit

World editing for **Quark 6 / PHP 8.4**. Chunk-ordered edits run within a configurable tick budget; schematic I/O uses dedicated workers. Includes selection wands and particle outlines. Scheduling stops when idle.

## Permissions

Permissions default to operators:

- `asyncworldedit.selection`
- `asyncworldedit.edit`
- `asyncworldedit.clipboard`
- `asyncworldedit.history`
- `asyncworldedit.schematic`
- `asyncworldedit.brush`

## Commands

| Area | Commands |
| --- | --- |
| Selection | `//wand`, `//pos1`, `//pos2`, `//hpos1`, `//hpos2`, `//sel`, `//outline`, `//expand`, `//contract`, `//shift`, `//size` |
| Editing | `//set`, `//replace`, `//walls`, `//faces`, `//hollow`, `//overlay`, `//naturalize`, `//smooth`, `//gmask`, `//seed` |
| Clipboard | `//copy`, `//cut`, `//paste`, `//rotate`, `//flip`, `//clearclipboard`, `//move`, `//stack` |
| Generation | `//sphere`, `//hsphere`, `//cyl`, `//hcyl`, `//cube`, `//hcube`, `//pyramid`, `//hpyramid` |
| Tools | `//brush`, `//mask`, `//range`, `//schem load`, `//schem save` |
| History and inspection | `//undo`, `//redo`, `//cancel`, `//status`, `//count`, `//distr` |

```text
//set 70%stone,30%dirt
//gmask #solid&!stone
//paste -a
//brush sphere stone 3
//brush none
//schem save house.mcstructure
```

Selectors: cuboid, ellipsoid and cylinder. Patterns support weights and `#existing`; masks support `#air`, `#solid`, negation, intersections and unions. Paste accepts `-a` to skip air and `-o` for the original position. Brushes support sphere, cylinder, overlay, smooth and clipboard.

## Support

- Edits require loaded, populated chunks. Overlapping operations reject; cancellation retains partial undo history. History is held in memory and ends on disconnect or restart.
- Sponge `.schem` v2/v3 imports accept states Quark can resolve exactly. General Java block and block-entity conversion is unsupported. Bedrock `.mcstructure` supports import/export; an optional `asyncworldedit_origin` extension preserves the clipboard pivot.
- Inventories, item-bearing tiles, pistons, secondary fluid layers, legacy `.schematic`, biome/entity transfer and block-entity transforms are unsupported. Unsupported data fails explicitly.
