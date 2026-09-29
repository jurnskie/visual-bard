import Builder from './pages/Builder.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('visual-bard::Builder', Builder);
});
