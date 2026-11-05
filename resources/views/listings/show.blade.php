<x-layouts.app :title="$listing->listing->make.' '.$listing->listing->model">
    <img src="{{ asset($listing->heroImagePath) }}" alt="{{ $listing->listing->make }} {{ $listing->listing->model }}" class="mb-6 w-full rounded-lg">

    <h1 class="text-3xl font-semibold">{{ $listing->listing->make }} {{ $listing->listing->model }}</h1>
    <p id="listing-price" class="mt-1 text-lg" data-price-topic="{{ $watch->topic }}" data-price-rendered-ms="{{ $renderedAtMs }}"@if ($watch->privateTopic !== null) data-price-topic-private="{{ $watch->privateTopic }}"@endif>{{ \App\Support\PriceFormatter::format($listing->listing->priceCents, $listing->listing->currency) }}</p>
    <p id="listing-price-status" class="sr-only" aria-live="polite"></p>

    <dl class="mt-6 grid grid-cols-2 gap-2">
        <dt class="font-medium">Year</dt>
        <dd>{{ $listing->listing->year }}</dd>
        <dt class="font-medium">Fuel</dt>
        <dd>{{ $listing->listing->fuel }}</dd>
        <dt class="font-medium">Mileage</dt>
        <dd>{{ number_format($listing->listing->mileageKm, 0, '.', ',') }} km</dd>
        <dt class="font-medium">Location</dt>
        <dd>{{ $listing->listing->city }}</dd>
        <dt class="font-medium">Listed</dt>
        <dd>{{ $listing->listing->listedAt }}</dd>
    </dl>

    <section class="mt-8">
        <h2 class="text-xl font-semibold">Dealer</h2>
        <p>{{ $listing->dealer->name }}, {{ $listing->dealer->city }}</p>
    </section>
</x-layouts.app>
