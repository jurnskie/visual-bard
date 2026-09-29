# Visual Bard

An Elementor-style page builder for the sets of a Bard field, for Statamic 6. **Proof of concept.**

You edit the real page: its layout, navigation, footer and site assets, with every block rendered through your own front-end partials.

## Features

- Full-screen editor: widget panel on the left, the live page on the right
- Click a block on the page to select it; its fields open in the panel and every change shows on the page immediately
- Drag widgets onto the page, or click one to add it below the selected block
- Block toolbar on hover: drag to move, move up/down, add below, duplicate, hide/show, remove
- Desktop, tablet and mobile preview widths
- Save with the button or Ctrl/Cmd+S; a validation error selects the block it belongs to
- Fields use Statamic's own publish form, so every fieldtype and validation rule works
- Saves through the same validate + process pipeline as the entry editor

## How to use

Open an entry in the CP, click the `…` actions menu next to Save and choose **Open builder**. It is also in the entry listing's row actions. The action shows up for entries whose blueprint has a Bard field with sets, for users who can edit the entry, and opens the first such field.

Or go straight to `/cp/visual-bard/{entry-id}/{field-handle}`.

## How it renders

- `GET …/canvas` renders the entry with its own layout, with `visual-bard::canvas` as the template: the page without its content, plus a `data-visual-bard-root` mount point. The builder loads it in an iframe and mounts the blocks there.
- Every block is rendered by the `sets/{type}` view, the same partials the front end uses. Plain Bard text gets `sets/text`, so the template loop is simply:

```antlers
{{ page_builder }}
    {{ partial src="sets/{type}" }}
{{ /page_builder }}
```

- Links on the canvas don't navigate and block content ignores the pointer, so a click always selects.

## Development

```bash
cd addons/jurnskie/visual-bard
npm run build   # or: npm run dev
```

The Vite config uses `@statamic/cms/vite-plugin`, which maps Vue and the `@statamic/cms` imports onto the CP runtime. Without it the addon bundles its own copy of Vue, and CP components silently fail to render.

The Statamic 6 CP no longer exposes `axios`; the builder uses `fetch` with the CP CSRF token.

The canvas inside the iframe (`resources/js/canvas.js`) is plain DOM: the frame runs the site's assets, not the CP's Vue.

## Not built yet

- Editing rich text inline (text blocks link to the entry editor for now)
- Choosing between multiple Bard fields on one entry (the action opens the first)
- Nested sets, columns and sections
- Undo/redo
- Multi-site handling beyond the entry's own locale
