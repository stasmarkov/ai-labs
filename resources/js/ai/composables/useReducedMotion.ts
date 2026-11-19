import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

export function useReducedMotion(): Ref<boolean> {
    const reducedMotion = ref(false);
    let query: MediaQueryList | null = null;

    const update = (): void => {
        reducedMotion.value = query?.matches ?? false;
    };

    onMounted(() => {
        query = window.matchMedia('(prefers-reduced-motion: reduce)');
        update();
        query.addEventListener('change', update);
    });

    onBeforeUnmount(() => query?.removeEventListener('change', update));

    return reducedMotion;
}
