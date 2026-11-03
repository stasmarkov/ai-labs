import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import LabLayout from './Layouts/LabLayout.vue';

const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue');

createInertiaApp({
    resolve: async (name) => {
        const page = pages[`./Pages/${name}.vue`];

        if (page === undefined) {
            throw new Error(`Unknown lab page: ${name}`);
        }

        const resolved = await page();
        resolved.default.layout ??= LabLayout;

        return resolved;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
