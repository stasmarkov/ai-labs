<x-layouts.app :title="$listing->title ?? $listing->listing->make.' '.$listing->listing->model">
    <img src="{{ asset($listing->heroImagePath) }}" alt="{{ $listing->listing->make }} {{ $listing->listing->model }}" class="mb-6 w-full rounded-lg">@if ($similarCars !== [])
        <section id="similar-cars" class="mb-6" aria-labelledby="similar-cars-heading">
            <h2 id="similar-cars-heading" class="text-xl font-semibold">Similar cars</h2>
            <ul class="mt-4 grid grid-cols-2 gap-4">
                @foreach ($similarCars as $car)
                    <li>
                        <a href="{{ route('listings.show', ['id' => $car->listingId]) }}">
                            <img src="{{ asset('storage/'.$car->path) }}" alt="{{ $car->listingTitle }}" loading="lazy" class="w-full rounded-lg">
                            <p class="mt-1 text-sm">{{ $car->listingTitle }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif


    <h1 class="text-3xl font-semibold">@if ($listing->title !== null){{ $listing->title }}@else{{ $listing->listing->make }} {{ $listing->listing->model }}@endif</h1>@if ($listing->tags !== [])

    <ul id="listing-tags" class="mt-4" aria-label="Tags">
        @foreach ($listing->tags as $tag)
            <li class="inline-block rounded-full border border-[#e3e3e0] px-5 py-1 text-sm">{{ $tag }}</li>
        @endforeach
    </ul>
@endif

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
