<x-mail::message>
# {{ $listings->count() }} new listing{{ $listings->count() === 1 ? '' : 's' }}

@foreach ($listings as $listing)
## {{ $listing->title ?? 'Untitled listing' }}

**{{ number_format($listing->price) }} {{ $listing->currency }}**
@if ($listing->price_eur !== null)
(≈ {{ number_format($listing->price_eur, 0) }} EUR)
@endif

- Year: {{ $listing->year ?? '—' }}
- Mileage: {{ $listing->mileage_km !== null ? number_format($listing->mileage_km).' km' : '—' }}
- City: {{ $listing->city ?? '—' }}
- Source: {{ ucfirst($listing->source->value) }}
- Reliability score: {{ $listing->reliability_score ?? '—' }}/100
@if (! empty($listing->reliability_flags))
@foreach ($listing->reliability_flags as $flag)
  - ⚠ {{ $flag['message'] }}
@endforeach
@endif

<x-mail::button :url="$listing->url">
View listing
</x-mail::button>

---
@endforeach

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
