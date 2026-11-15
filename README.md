# AsyncWorldEdit

World editing for **Quark 6 / PHP 8.4**. Chunk-ordered edits run within a configurable tick budget; schematic I/O uses dedicated workers. Includes selection wands and particle outlines.

## Install

Run `composer install` and `composer build`. Place `build/AsyncWorldEdit.phar` in Quark's `plugins` directory and restart. Settings are generated in the plugin's `config.toml`.

Permissions default to operators: `asyncworldedit.selection`, `.edit`, `.clipboard`, `.history`, `.schematic`, and `.brush`.

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
- Inventories, secondary fluid layers, legacy `.schematic`, biome/entity transfer and block-entity transforms are unsupported. Unsupported data fails explicitly.

The native million-block test completed in **108.6 s** with a 10 ms budget and no connected players; its final rolling TPS average was 20.0.

## Development

`composer test`, `composer analyse:core`, `composer build`. Full adapter analysis/tests require Quark's native runtime and source checkout. Run [live integration tests](tools/integration/README.md) for world, lighting and persistence checks.
