---
name: car-buyer-profile
description: The buyer's personal needs, driving pattern and constraints for choosing a used car. Use this skill together with the used-car evaluation whenever evaluating, ranking, filtering or recommending car listings for this buyer, or when he asks "which car suits me", "is this car right for me", or compares cars. A car can be mechanically excellent and still be the wrong car for him; this skill decides the fit.
---

# Car Buyer Profile — "My Needs"

## Who the buyer is

- A 23-year-old student who is also working as a junior developer.
- Lives in **Oradea** Monday to Friday.
- Budget-conscious: the total cost of ownership matters more than the purchase price alone.
- Wants a car he can trust and not worry about, not a project car.

## How the car will be used

| Use | Frequency | Distance | Type of driving |
|---|---|---|---|
| Oradea ↔ Tulca | once a week | ~50 km each way (~100 km round trip) | county and national roads, some villages |
| City driving in Oradea | Monday–Friday, most days | short trips (under 10 km) | traffic, lights, parking |
| Outings with his girlfriend | occasional | short to medium | city and nearby trips |

**Estimated yearly mileage: about 7,000–10,000 km.** Most trips are short.

## What this means for the choice of car

Apply this reasoning to every recommendation.

### Short trips dominate, so avoid engines that need long drives

Most journeys are short city trips, and the engine often doesn't reach full operating temperature.
- **Prefer:** simple petrol engines (naturally aspirated or proven turbo), petrol + LPG (factory or quality installation with documents), and full hybrids, which are excellent in city driving.
- **Be cautious with modern diesels.** DPF, EGR and AdBlue systems clog and fail with short trips, and at around 8,000 km per year the fuel savings of a diesel don't cover its higher repair risk. Recommend a diesel only if it is exceptionally well documented and the price is clearly advantageous. Say explicitly that it doesn't suit his driving pattern.
- The weekly 100 km trip helps but is not enough on its own to keep a DPF healthy.

### Low running costs matter more than performance

- **Insurance (RCA)** is expensive for a 23-year-old driver and rises with engine size and power. Favor modest engines, roughly **1.0–1.6 litres and up to about 130 HP**.
- **Road tax (impozit auto)** in Romania is based on engine displacement, so a smaller engine is cheaper every year.
- **Fuel consumption** in the city should be reasonable. Weigh the real-world city consumption, not only the combined figure.
- **Parts and labor** should be cheap and easy to find in Romania. Common models with many local mechanics are better than rare or premium ones.
- Avoid cars where one typical repair (gearbox, turbo, injectors, air suspension, complex electronics) could cost a large part of the car's value.

### Size and practicality

- The best fit is a **small or compact car** (city car, supermini or compact hatchback). A compact sedan or small crossover is acceptable if the running costs stay low.
- It must be easy to park in Oradea.
- It needs 4–5 seats and a normal trunk. Large cargo space is not needed.
- It should be comfortable and quiet enough for 50 km on county roads, with decent suspension because some roads are poor.

### Seasons and roads (Bihor)

- A working heater, heated rear window and good lights are essential. Mention A/C condition.
- Winter tires or a second set of wheels are a real plus.
- 4x4 is **not needed**. Front-wheel drive is ideal.
- Check rust carefully, since roads are salted in winter.

### Comfort and image (secondary)

- For outings with his girlfriend, the car should be clean, pleasant inside and presentable. It doesn't need to be luxurious.
- Useful extras, in order of value: A/C, Bluetooth or phone connectivity, parking sensors, cruise control. Never trade reliability for equipment.

## Preferences to fill in

The buyer should complete these. Until they are set, treat them as unknown and don't filter on them.

- **Budget:** `______ EUR` maximum. Also keep about 10–15% reserve for the first service, tires and small repairs after purchase.
- **Transmission:** manual / automatic / no preference. If automatic, prefer torque-converter automatics or hybrid e-CVT over dry dual-clutch gearboxes.
- **Fuel:** petrol / petrol + LPG / hybrid / open to all.
- **Maximum age:** `____` (year of manufacture).
- **Maximum mileage:** `____ km`.
- **Search radius:** how far from Oradea he is willing to travel to see a car.
- **Makes or models he dislikes:** `____`.

## Needs-fit scoring

Add a `needs_fit` score (0–100) to every evaluation, next to the mechanical scores.

Raise the score for:
- a petrol, LPG or hybrid engine suited to short trips,
- a small or compact size,
- a modest engine that keeps RCA and road tax low,
- cheap, widely available parts,
- good city fuel consumption,
- front-wheel drive with winter-ready equipment,
- a price within the budget with a repair reserve left over.

Lower the score for:
- a modern diesel with DPF,
- a large engine or high power (more than about 150 HP),
- a large car or a premium brand with expensive parts,
- an exotic or rare model,
- a price that uses the whole budget,
- complex features that are costly to repair.

**How it affects the verdict:**
- `needs_fit` below 40 means **don't recommend**, even if the car is mechanically excellent. Explain in one sentence why it doesn't suit him.
- For recommended cars, mention one sentence on why this car fits **his** use: short trips, the weekly Tulca drive, low running costs.
- When ranking several good cars, rank by mechanical confidence first and `needs_fit` second, never by equipment or looks.

## Output addition

Add this to the evaluation JSON:

```json
"needs_fit": {
  "score": 0,
  "reasons": ["short, concrete reasons tied to his driving pattern and costs"],
  "yearly_cost_notes": "rough expectations for RCA, road tax, fuel and maintenance, clearly marked as estimates"
}
```

## Update rule

If the buyer's situation changes (moving, a new job, a longer commute, a different budget), this profile must be updated. The best car depends on how it will actually be used.
