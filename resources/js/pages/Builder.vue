<script setup>
import { Teleport, computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { debounce, deepClone } from '@statamic/cms';
import { toast } from '@statamic/cms/api';
import { Head } from '@statamic/cms/inertia';
import { Icon, Modal, PublishContainer, PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';
import { mountCanvas } from '../canvas.js';

// `__`, `cp_url` and `uniqid` are CP runtime globals.

const props = defineProps({
    entry: { type: Object, required: true },
    field: { type: Object, required: true },
    sets: { type: Array, required: true },
    value: { type: Array, required: true },
    meta: { type: Object, required: true },
    previews: { type: Array, required: true },
    canvas_url: { type: String, required: true },
});

/** JSON request against the addon's CP routes; the Statamic 6 CP no longer exposes axios. */
async function request(method, path, body) {
    const response = await fetch(cp_url(path), {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': window.StatamicConfig.csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const error = new Error(`${method} ${path} failed with ${response.status}`);
        error.errors = response.status === 422 ? (await response.json()).errors : null;
        throw error;
    }

    return response.json();
}

const endpoint = (suffix = '') => `visual-bard/${props.entry.id}/${props.field.handle}${suffix}`;
const t = (key) => __(`visual-bard::messages.${key}`);

/** Working copy of the Bard nodes: the source of truth for the canvas and the save payload. */
const nodes = reactive(deepClone(props.value));
const dirty = ref(false);
const saving = ref(false);

const allSets = computed(() => props.sets.flatMap((group) => group.sets));
const setConfig = (handle) => allSets.value.find((set) => set.handle === handle);

function nodeLabel(node) {
    if (node?.type !== 'set') return t('text_node');

    return setConfig(node.attrs.values.type)?.display ?? node.attrs.values.type;
}

/**
 * Rendered HTML per block. Sets are cached by id so an edited block keeps its
 * last render until the new one arrives; text nodes are cached by content.
 * `renderedFrom` / `requestedFrom` remember which values a render was (or is
 * being) made from, so only blocks that actually changed go to the server.
 */
const htmlCache = reactive(new Map());
const renderedFrom = new Map();
const requestedFrom = new Map();
const cacheKey = (node) => (node.type === 'set' ? `set:${node.attrs.id}` : `text:${JSON.stringify(node)}`);
const renderSource = (node) => JSON.stringify(node.type === 'set' ? node.attrs.values : node);

function cachePreview(node, html, source = renderSource(node)) {
    htmlCache.set(cacheKey(node), html);
    renderedFrom.set(cacheKey(node), source);
}

props.previews.forEach((preview) => cachePreview(props.value[preview.index], preview.html));

async function refreshPreviews() {
    const stale = nodes
        .map((node) => ({ node, key: cacheKey(node), source: renderSource(node) }))
        .filter(({ key, source }) => renderedFrom.get(key) !== source && requestedFrom.get(key) !== source);

    if (!stale.length) return;

    stale.forEach(({ key, source }) => requestedFrom.set(key, source));

    // Disabled sets render too; the canvas dims them instead of hiding them.
    const payload = stale.map(({ node }) => (node.type === 'set' ? { ...node, attrs: { ...node.attrs, enabled: true } } : node));

    try {
        const { previews } = await request('POST', endpoint('/preview'), { nodes: payload });

        previews.forEach((preview) => {
            const { key, source } = stale[preview.index];

            // A newer render of this block is on its way; don't flash an older one.
            if (requestedFrom.get(key) === source) {
                htmlCache.set(key, preview.html);
                renderedFrom.set(key, source);
            }
        });
    } finally {
        stale.forEach(({ key, source }) => requestedFrom.get(key) === source && requestedFrom.delete(key));
    }
}

const debouncedPreview = debounce(refreshPreviews, 300);

watch(nodes, () => {
    dirty.value = true;
    debouncedPreview();
}, { deep: true });

/** Selection: the block whose fields show in the side panel. */
const selectedIndex = ref(null);
const selectedNode = computed(() => (selectedIndex.value === null ? null : nodes[selectedIndex.value]));
const selectedSet = computed(() => (selectedNode.value?.type === 'set' ? setConfig(selectedNode.value.attrs.values.type) : null));

/** Where a clicked widget lands: below the selection, else at the end. */
const insertIndex = ref(null);

function select(index) {
    selectedIndex.value = index;
    insertIndex.value = null;
}

function deselect() {
    selectedIndex.value = null;
}

const editingValues = ref({});

watch(selectedNode, (node) => {
    editingValues.value = node?.type === 'set' ? deepClone(node.attrs.values) : {};
}, { immediate: true });

// Live editing: every field change flows straight into the node and the canvas.
watch(editingValues, (values) => {
    const node = selectedNode.value;

    if (node?.type !== 'set' || JSON.stringify(node.attrs.values) === JSON.stringify(values)) return;

    node.attrs.values = deepClone(values);
}, { deep: true });

const editingBlueprint = computed(() => ({
    handle: 'visual_bard_set',
    tabs: [{ handle: 'main', sections: [{ fields: selectedSet.value?.fields ?? [] }] }],
}));

/** Existing sets reuse Bard's preloaded meta; new ones get the set's "new" meta. */
const editingMeta = computed(() => props.meta.existing?.[selectedNode.value?.attrs?.id] ?? selectedSet.value?.new ?? {});

/** Block operations, shared by the side panel and the canvas toolbar. */
function newNode(set) {
    return {
        type: 'set',
        attrs: { id: uniqid(), enabled: true, values: { type: set.handle, ...deepClone(set.defaults) } },
    };
}

function insertSet(set, index = null) {
    const at = index ?? insertIndex.value ?? (selectedIndex.value === null ? nodes.length : selectedIndex.value + 1);

    nodes.splice(at, 0, newNode(set));
    refreshPreviews();
    select(at);
}

function moveNode(from, to) {
    if (from === to || to < 0 || to >= nodes.length) return;

    const selected = selectedNode.value;
    const [moved] = nodes.splice(from, 1);

    nodes.splice(to, 0, moved);
    selectedIndex.value = selected ? nodes.indexOf(selected) : null;
}

function duplicateNode(index) {
    const original = cacheKey(nodes[index]);
    const copy = deepClone(nodes[index]);

    if (copy.type === 'set') {
        copy.attrs.id = uniqid();
        if (htmlCache.has(original)) cachePreview(copy, htmlCache.get(original), renderedFrom.get(original));
    }

    nodes.splice(index + 1, 0, copy);
    select(index + 1);
}

function toggleNode(index) {
    if (nodes[index].type !== 'set') return;

    nodes[index].attrs.enabled = nodes[index].attrs.enabled === false;
}

const confirmingRemoval = ref(null);

function removeNode() {
    nodes.splice(confirmingRemoval.value, 1);
    confirmingRemoval.value = null;
    deselect();
}

/** Widgets dragged from the panel drop into the canvas at a computed index. */
const dragPayload = ref(null);

function onWidgetDragStart(event, set) {
    event.dataTransfer.setData('text/plain', set.handle);
    event.dataTransfer.effectAllowed = 'copy';
    dragPayload.value = { kind: 'new', set };
}

/** The canvas: the entry's real page in an iframe, blocks mounted into it. */
const frame = ref(null);
const device = ref('desktop');
const devices = {
    desktop: { width: '100%', icon: 'monitor' },
    tablet: { width: '768px', icon: 'tablet' },
    mobile: { width: '390px', icon: 'mobile' },
};
let canvas = null;

const canvasLabels = Object.fromEntries(Object.entries({
    empty: 'empty_canvas',
    loading: 'loading',
    drag: 'drag_to_move',
    up: 'move_up',
    down: 'move_down',
    add: 'add_below',
    duplicate: 'duplicate',
    hide: 'disable',
    show: 'enable',
    remove: 'remove',
    hidden: 'hidden',
}).map(([label, key]) => [label, t(key)]));

/** What the canvas draws per block; only recomputes when something visible changes, not on every keystroke. */
const canvasBlocks = computed(() => nodes.map((node, index) => ({
    label: nodeLabel(node),
    html: htmlCache.get(cacheKey(node)) ?? null,
    hidden: node.type === 'set' && node.attrs.enabled === false,
    selected: index === selectedIndex.value,
    insertBelow: insertIndex.value === index + 1,
})));

watch(canvasBlocks, (blocks) => canvas?.render(blocks));

function onFrameLoad() {
    canvas?.destroy();
    canvas = mountCanvas(frame.value.contentDocument, {
        labels: canvasLabels,
        getDrag: () => dragPayload.value,
        setDrag: (payload) => { dragPayload.value = payload; },
        onSelect: select,
        onDeselect: deselect,
        onMove: moveNode,
        onInsert: insertSet,
        onAddBelow: (index) => { deselect(); insertIndex.value = index + 1; },
        onDuplicate: duplicateNode,
        onToggle: toggleNode,
        onRemove: (index) => { confirmingRemoval.value = index; },
    });
    canvas.render(canvasBlocks.value);
}

async function save() {
    saving.value = true;

    try {
        const response = await request('PATCH', endpoint(), { nodes });
        const selected = selectedIndex.value;

        nodes.splice(0, nodes.length, ...deepClone(response.value));
        selectedIndex.value = selected !== null && selected < nodes.length ? selected : null;
        // Let the deep `nodes` watcher see the replacement before clearing the flag it sets.
        await nextTick();
        dirty.value = false;
        toast.success(response.message);
        refreshPreviews();
    } catch (error) {
        showSaveError(error);
    } finally {
        saving.value = false;
    }
}

/** Validation errors point at `<field>.<index>.attrs.values.<handle>`: open that block and say what's wrong. */
function showSaveError(error) {
    const [key, messages] = Object.entries(error.errors ?? {})[0] ?? [];
    const index = key?.match(/^[^.]+\.(\d+)\./)?.[1];

    if (index === undefined) return toast.error(t('save_failed'));

    select(Number(index));
    canvas?.reveal(Number(index));
    toast.error(`${nodeLabel(nodes[index])}: ${messages[0]}`);
}

function onKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key === 's') {
        event.preventDefault();
        if (dirty.value && !saving.value) save();
    }
}

const warnBeforeLeaving = (event) => dirty.value && event.preventDefault();

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('beforeunload', warnBeforeLeaving);
});

onBeforeUnmount(() => {
    debouncedPreview.cancel();
    canvas?.destroy();
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('beforeunload', warnBeforeLeaving);
});
</script>

<template>
    <Head :title="entry.title" />

    <Teleport to="body">
    <div class="visual-bard-editor">
        <!-- Side panel: widgets, or the selected block's fields -->
        <aside class="visual-bard-panel">
            <div class="visual-bard-panel-header">
                <ui-button :href="entry.edit_url" variant="ghost" size="sm" icon="arrow-left" :text="t('back_to_entry')" />
            </div>

            <div v-if="selectedNode" class="visual-bard-panel-body">
                <button type="button" class="visual-bard-panel-back" @click="deselect">
                    <Icon name="chevron-left" />
                    {{ t('widgets') }}
                </button>

                <h2 class="visual-bard-panel-title">{{ nodeLabel(selectedNode) }}</h2>

                <PublishContainer
                    v-if="selectedSet"
                    :key="selectedNode.attrs.id"
                    name="visual-bard-set"
                    :blueprint="editingBlueprint"
                    :meta="editingMeta"
                    :track-dirty-state="false"
                    v-model="editingValues"
                >
                    <PublishFieldsProvider :fields="selectedSet.fields">
                        <PublishFields />
                    </PublishFieldsProvider>
                </PublishContainer>

                <div v-else class="visual-bard-muted text-sm">
                    <p class="mb-3">{{ t('text_not_editable') }}</p>
                    <ui-button :href="entry.edit_url" size="sm" :text="t('edit_in_entry')" />
                </div>
            </div>

            <div v-else class="visual-bard-panel-body">
                <h2 class="visual-bard-panel-title">{{ t('widgets') }}</h2>
                <p class="visual-bard-muted mb-4 text-xs">{{ t('drag_or_click') }}</p>

                <div v-for="group in sets" :key="group.handle" class="mb-5">
                    <h3 class="visual-bard-muted mb-2 text-xs font-semibold uppercase tracking-wide">{{ group.display }}</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="set in group.sets"
                            :key="set.handle"
                            type="button"
                            draggable="true"
                            class="visual-bard-widget"
                            :title="set.instructions"
                            @dragstart="onWidgetDragStart($event, set)"
                            @dragend="dragPayload = null"
                            @click="insertSet(set)"
                        >
                            <Icon :name="set.icon ?? 'blocks'" class="size-5" />
                            <span>{{ set.display }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Top bar + canvas -->
        <div class="visual-bard-main">
            <div class="visual-bard-topbar">
                <span class="truncate text-sm font-medium">{{ entry.title }}</span>

                <div class="visual-bard-devices">
                    <button
                        v-for="({ icon }, name) in devices"
                        :key="name"
                        type="button"
                        :class="{ 'is-active': device === name }"
                        :title="t(`device_${name}`)"
                        @click="device = name"
                    >
                        <Icon :name="icon" />
                    </button>
                </div>

                <ui-button
                    variant="primary"
                    size="sm"
                    :disabled="!dirty || saving"
                    :text="saving ? t('saving') : t('save')"
                    @click="save"
                />
            </div>

            <div class="visual-bard-stage">
                <iframe
                    ref="frame"
                    class="visual-bard-frame"
                    :style="{ width: devices[device].width }"
                    :src="canvas_url"
                    :title="entry.title"
                    @load="onFrameLoad"
                />
            </div>
        </div>

        <!-- Removal confirmation -->
        <Modal :open="confirmingRemoval !== null" :title="t('remove_this_block')" @update:open="confirmingRemoval = null">
            <p>{{ t('cannot_be_undone') }}</p>
            <template #footer>
                <ui-button variant="ghost" :text="t('cancel')" @click="confirmingRemoval = null" />
                <ui-button variant="danger" :text="t('remove')" @click="removeNode" />
            </template>
        </Modal>
    </div>
    </Teleport>
</template>

<style scoped>
/*
 * Full-screen editor over the CP chrome, like a page builder. Colors come from
 * the CP theme so the panel follows light/dark mode: in dark mode Statamic
 * swaps the surface tokens but not the gray scale, so text shades flip here.
 */
.visual-bard-editor {
    --vb-surface: var(--theme-color-content-bg, #fff);
    --vb-stage: var(--theme-color-gray-100);
    --vb-divider: var(--theme-color-content-border);
    --vb-control-border: var(--theme-color-gray-200);
    --vb-text: var(--theme-color-gray-900);
    --vb-muted: var(--theme-color-gray-500);
    --vb-active-bg: var(--theme-color-gray-100);
    --vb-accent: var(--theme-color-ui-accent-text);

    position: fixed;
    inset: 0;
    /* Above the CP's fixed global header. */
    z-index: 1000;
    display: flex;
    background: var(--vb-stage);
    color: var(--vb-text);
}

:global(.dark .visual-bard-editor) {
    --vb-stage: var(--theme-color-gray-950);
    --vb-divider: var(--theme-color-gray-800);
    --vb-control-border: var(--theme-color-gray-700);
    --vb-text: var(--theme-color-gray-100);
    --vb-muted: var(--theme-color-gray-400);
    --vb-active-bg: var(--theme-color-gray-800);
}

.visual-bard-muted {
    color: var(--vb-muted);
}

.visual-bard-panel {
    display: flex;
    flex-direction: column;
    width: 20rem;
    flex-shrink: 0;
    background: var(--vb-surface);
    border-right: 1px solid var(--vb-divider);
}

.visual-bard-panel-header {
    display: flex;
    align-items: center;
    height: 3rem;
    padding: 0 0.75rem;
    border-bottom: 1px solid var(--vb-divider);
}

.visual-bard-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.visual-bard-panel-title {
    margin-bottom: 0.75rem;
    font-size: 1rem;
    font-weight: 600;
}

.visual-bard-panel-back {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    margin-bottom: 0.75rem;
    font-size: 0.75rem;
    color: var(--vb-muted);
}

.visual-bard-panel-back:hover {
    color: var(--vb-text);
}

.visual-bard-widget {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 0.5rem;
    border: 1px solid var(--vb-control-border);
    border-radius: 0.5rem;
    font-size: 0.75rem;
    text-align: center;
    color: var(--vb-text);
    cursor: grab;
}

.visual-bard-widget:hover,
.visual-bard-widget:focus-visible {
    border-color: var(--vb-accent);
    color: var(--vb-accent);
}

.visual-bard-main {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.visual-bard-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    height: 3rem;
    padding: 0 1rem;
    background: var(--vb-surface);
    border-bottom: 1px solid var(--vb-divider);
}

.visual-bard-devices {
    display: flex;
    gap: 0.25rem;
}

.visual-bard-devices button {
    padding: 0.375rem;
    border-radius: 0.375rem;
    color: var(--vb-muted);
}

.visual-bard-devices button:hover,
.visual-bard-devices button.is-active {
    color: var(--vb-text);
}

.visual-bard-devices button.is-active {
    background: var(--vb-active-bg);
}

.visual-bard-stage {
    display: flex;
    flex: 1;
    justify-content: center;
    min-height: 0;
    padding: 1rem;
    overflow: auto;
}

/* The canvas is the site itself, which brings its own (light) background. */
.visual-bard-frame {
    height: 100%;
    max-width: 100%;
    background: white;
    border: 0;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
    transition: width 0.2s;
}
</style>
