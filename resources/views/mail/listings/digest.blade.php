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
@if ($listing->fuel_type || $listing->engine_capacity_cc || $listing->horsepower)
- Engine: {{ collect([$listing->fuel_type, $listing->engine_capacity_cc ? $listing->engine_capacity_cc.' cc' : null, $listing->horsepower ? $listing->horsepower.' HP' : null])->filter()->implode(' · ') }}
@endif
@if ($listing->looks_score !== null)
- Looks: {{ $listing->looks_score }}/100 — {{ $listing->looks_notes }}
@else
- Looks: not reviewed yet
@endif
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
