import { computed, ref } from 'vue';
import type { ProviderOption } from '../components/LabProviderSelect.vue';

/**
 * The selector's value starts on the feature's configured provider. `override` is the value to send as "provider",
 * or null while the configured provider is selected so the request stays as it was before the selector existed.
 */
export function useProviderChoice(providers: ProviderOption[]) {
    const configured = providers.find((option) => option.configured)?.name ?? null;
    const provider = ref(configured ?? providers[0]?.name ?? '');
    const override = computed<string | null>(() => (provider.value !== '' && provider.value !== configured ? provider.value : null));

    return { provider, override };
}
