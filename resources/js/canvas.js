/**
 * Builds the editable canvas inside the preview iframe: the entry's real page,
 * with every Bard node rendered in place and wrapped in a selectable block.
 * Plain DOM on purpose; the frame runs the site's own assets, not the CP's Vue.
 */

const STYLE = `
[data-vb-root] { min-height: 40vh; }
/* The frame is an overlay: positioned site sections would paint over a plain outline. */
.vb-block { position: relative; cursor: pointer; }
.vb-block::after { content: ''; position: absolute; inset: 0; z-index: 20; pointer-events: none; border: 1px solid transparent; }
.vb-block:hover::after { border: 1px dashed #3b82f6; }
.vb-block.is-selected::after { border: 2px solid #3b82f6; box-shadow: inset 0 0 0 1px rgba(255,255,255,.7); }
.vb-block.is-hidden > .vb-content { opacity: .35; filter: grayscale(1); }
.vb-block.is-dragging { opacity: .4; }
.vb-content { pointer-events: none; }
.vb-content a, .vb-content button { pointer-events: none; }
.vb-loading { padding: 3rem; text-align: center; font: 14px system-ui, sans-serif; color: #9ca3af; }
.vb-toolbar { position: absolute; top: 0; right: 0; z-index: 30; display: none; align-items: center; gap: 1px;
  background: #3b82f6; border-bottom-left-radius: 6px; font: 12px/1 system-ui, sans-serif; color: #fff; }
.vb-block:hover > .vb-toolbar, .vb-block.is-selected > .vb-toolbar { display: flex; }
.vb-toolbar span { padding: 6px 8px; font-weight: 600; }
.vb-toolbar button { all: unset; box-sizing: border-box; display: flex; align-items: center; justify-content: center;
  width: 26px; height: 24px; cursor: pointer; }
.vb-toolbar svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.vb-toolbar button:hover { background: #2563eb; }
.vb-toolbar [data-action=drag] { cursor: grab; }
.vb-badge { position: absolute; top: 0; left: 0; z-index: 30; padding: 4px 8px; background: #6b7280; color: #fff;
  font: 11px/1 system-ui, sans-serif; border-bottom-right-radius: 6px; }
.vb-drop { position: absolute; left: 0; right: 0; z-index: 40; height: 4px; background: #3b82f6; pointer-events: none;
  box-shadow: 0 0 0 2px rgba(59,130,246,.25); }
.vb-drop.is-top { top: -2px; } .vb-drop.is-bottom { bottom: -2px; }
.vb-insert-marker { height: 4px; margin: 0 1rem; background: #3b82f6; border-radius: 2px; }
.vb-empty { margin: 2rem; padding: 4rem 2rem; border: 2px dashed #d1d5db; border-radius: 12px; text-align: center;
  font: 14px system-ui, sans-serif; color: #6b7280; }
.vb-empty.is-over { border-color: #3b82f6; color: #3b82f6; background: rgba(59,130,246,.05); }
`;

/** Inline SVG paths (Lucide-style) so icons render the same whatever fonts the site loads. */
const ICONS = {
    drag: '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
    up: '<path d="m18 15-6-6-6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    add: '<path d="M12 5v14M5 12h14"/>',
    duplicate: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/>',
    hide: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    show: '<path d="m3 3 18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.9M6.6 6.6C3.9 8.3 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/>',
    remove: '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
};

export function mountCanvas(doc, options) {
    const root = doc.querySelector('[data-visual-bard-root]');

    if (!root) return { render() {}, destroy() {} };

    root.setAttribute('data-vb-root', '');

    const style = doc.createElement('style');
    style.textContent = STYLE;
    doc.head.appendChild(style);

    // Links in the page chrome would navigate the canvas away.
    const blockNavigation = (event) => {
        if (event.target.closest('a[href]')) event.preventDefault();
    };
    doc.addEventListener('click', blockNavigation, true);

    let dropIndex = null;
    /** What's on screen per block: the DOM nodes plus the key they were built from. */
    let items = [];

    function button(action, label) {
        const el = doc.createElement('button');
        el.type = 'button';
        el.dataset.action = action;
        el.title = label;
        el.setAttribute('aria-label', label);
        el.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${ICONS[action]}</svg>`;
        if (action === 'drag') el.draggable = true;

        return el;
    }

    function element(tag, className, text) {
        const el = doc.createElement(tag);
        el.className = className;
        if (text !== undefined) el.textContent = text;

        return el;
    }

    /** The block plus its insert marker; selection is a class toggle, applied separately. */
    function blockNodes(block, index, total) {
        const { labels } = options;
        const el = element('section', 'vb-block');
        el.dataset.index = index;
        el.classList.toggle('is-hidden', block.hidden);

        const content = element('div', 'vb-content');
        content.innerHTML = block.html ?? `<div class="vb-loading">${labels.loading}</div>`;

        const toolbar = element('div', 'vb-toolbar');
        toolbar.append(
            button('drag', labels.drag),
            element('span', '', block.label),
            ...(index > 0 ? [button('up', labels.up)] : []),
            ...(index < total - 1 ? [button('down', labels.down)] : []),
            button('add', labels.add),
            button('duplicate', labels.duplicate),
            button(block.hidden ? 'show' : 'hide', block.hidden ? labels.show : labels.hide),
            button('remove', labels.remove),
        );

        el.append(content, toolbar);
        if (block.hidden) el.appendChild(element('div', 'vb-badge', labels.hidden));

        return block.insertBelow ? [el, element('div', 'vb-insert-marker')] : [el];
    }

    /**
     * Patch the canvas: blocks whose rendered output is unchanged keep their DOM
     * (no re-parsing, no image reloads), so typing or selecting only touches the
     * blocks involved. A changed block count rebuilds everything.
     */
    function render(blocks) {
        if (!blocks.length) {
            items = [];
            root.replaceChildren(element('div', 'vb-empty', options.labels.empty));

            return;
        }

        const rebuild = blocks.length !== items.length;

        const next = blocks.map((block, index) => {
            const { selected, ...visible } = block;
            const key = JSON.stringify([visible, index, blocks.length]);
            const current = items[index];

            if (!rebuild && current.key === key) return current;

            const nodes = blockNodes(block, index, blocks.length);
            if (!rebuild) {
                current.nodes[0].before(...nodes);
                current.nodes.forEach((node) => node.remove());
            }

            return { key, nodes };
        });

        if (rebuild) root.replaceChildren(...next.flatMap((item) => item.nodes));

        next.forEach((item, index) => item.nodes[0].classList.toggle('is-selected', blocks[index].selected));
        items = next;
    }

    function clearDrop() {
        dropIndex = null;
        doc.querySelectorAll('.vb-drop').forEach((el) => el.remove());
        doc.querySelector('.vb-empty')?.classList.remove('is-over');
    }

    /** Before or after the hovered block, depending on which half the pointer is in. */
    function showDrop(event) {
        const block = event.target.closest('.vb-block');

        if (!block) {
            if (event.target.closest('.vb-empty')) {
                clearDrop();
                dropIndex = 0;
                doc.querySelector('.vb-empty').classList.add('is-over');
            }

            return;
        }

        const rect = block.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;
        const index = Number(block.dataset.index) + (after ? 1 : 0);

        if (index === dropIndex) return;

        clearDrop();
        dropIndex = index;
        const line = doc.createElement('div');
        line.className = `vb-drop ${after ? 'is-bottom' : 'is-top'}`;
        block.appendChild(line);
    }

    const onClick = (event) => {
        const actionButton = event.target.closest('.vb-toolbar button');
        const block = event.target.closest('.vb-block');

        if (!block) {
            if (root.contains(event.target) || event.target === root) options.onDeselect();

            return;
        }

        const index = Number(block.dataset.index);
        event.preventDefault();

        if (!actionButton) return options.onSelect(index);

        ({
            up: () => options.onMove(index, index - 1),
            down: () => options.onMove(index, index + 1),
            add: () => options.onAddBelow(index),
            duplicate: () => options.onDuplicate(index),
            hide: () => options.onToggle(index),
            show: () => options.onToggle(index),
            remove: () => options.onRemove(index),
        })[actionButton.dataset.action]?.();
    };

    const onDragStart = (event) => {
        const handle = event.target.closest?.('[data-action=drag]');

        if (!handle) return;

        const block = handle.closest('.vb-block');
        event.dataTransfer.setData('text/plain', block.dataset.index);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setDragImage(block, 20, 20);
        block.classList.add('is-dragging');
        options.setDrag({ kind: 'move', index: Number(block.dataset.index) });
    };

    const onDragOver = (event) => {
        if (!options.getDrag()) return;

        event.preventDefault();
        showDrop(event);
    };

    const onDrop = (event) => {
        const drag = options.getDrag();

        if (!drag || dropIndex === null) return clearDrop();

        event.preventDefault();
        const target = dropIndex;
        clearDrop();
        options.setDrag(null);

        if (drag.kind === 'new') return options.onInsert(drag.set, target);

        // Removing the source first shifts every later index up by one.
        options.onMove(drag.index, target > drag.index ? target - 1 : target);
    };

    const onDragEnd = () => {
        clearDrop();
        doc.querySelectorAll('.is-dragging').forEach((el) => el.classList.remove('is-dragging'));
        options.setDrag(null);
    };

    const onDragLeave = (event) => {
        if (!event.relatedTarget) clearDrop();
    };

    doc.addEventListener('click', onClick);
    doc.addEventListener('dragstart', onDragStart);
    doc.addEventListener('dragover', onDragOver);
    doc.addEventListener('drop', onDrop);
    doc.addEventListener('dragend', onDragEnd);
    doc.addEventListener('dragleave', onDragLeave);

    return {
        render,
        destroy() {
            doc.removeEventListener('click', blockNavigation, true);
            doc.removeEventListener('click', onClick);
            doc.removeEventListener('dragstart', onDragStart);
            doc.removeEventListener('dragover', onDragOver);
            doc.removeEventListener('drop', onDrop);
            doc.removeEventListener('dragend', onDragEnd);
            doc.removeEventListener('dragleave', onDragLeave);
            style.remove();
        },
        /** Scroll a block into view after it is added. */
        reveal(index) {
            root.querySelector(`.vb-block[data-index="${index}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        },
    };
}
