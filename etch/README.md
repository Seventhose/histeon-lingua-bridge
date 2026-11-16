# EtchWP language switcher

This package installs a reusable Etch component with a custom, accessible language menu. Lingua Bridge's `[lingua_bridge_switcher]` shortcode supplies the current post's published translation links; the component turns them into styled menu links and removes the source markup.

The site runtime does not expose Etch data sources, so the shortcode is used only as a dynamic link provider. The visible trigger and menu are authored as Etch elements; the browser's operating-system select popup is not used.

## Files

- `language-switcher.bundle.json`: Etch component and stylesheet definition.
- `language-switcher.css`: readable copy of the stylesheet.
- `language-switcher.component.js`: menu behavior, including outside click and keyboard navigation.
- `install-language-switcher.js`: idempotent Etch API script. It updates the existing named component and stylesheet, or creates them if absent.

## Requirements

- Lingua Bridge 0.2.1 or newer, activated, so `[lingua_bridge_switcher]` is registered.
- EtchWP with the public components and stylesheets APIs.
- Automatic.css is optional. The component uses the CSS variable names of Automatic.css when they are present, with fallbacks of its own; no stylesheet of Automatic.css is included.

## Install in Etch

Run `install-language-switcher.js` with the Etch connector against the intended Etch site. Place the reusable component in the header or template. The menu stays hidden when the current content has fewer than two published translations. It opens with click or Enter/Space, supports arrow keys and Escape, and closes on outside click.

On a statically generated site, rebuild or republish the static copy after changing translations or component styles; editing the Etch component does not refresh an already-generated copy by itself.
