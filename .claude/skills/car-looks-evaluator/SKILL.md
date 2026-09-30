---
name: car-looks-evaluator
description: Judge how a used car LOOKS from its ad photos — exterior condition (paint, panel gaps, dents, rust, wheels, lights), interior cleanliness and wear, and overall presentability — and store a looks score so only good-looking cars reach the buyer's email. Use whenever the user asks to review, rate or check the looks/condition/appearance of the shortlisted cars, says "does it look good", "check the photos", "review the shortlist", or wants the Car Finder's cars filtered by how they look. Works together with used-car-evaluator (mechanics) and car-buyer-profile (fit).
---

# Car Looks Evaluator

## Why this exists

The scrapers run unattended at 07:00 and cannot judge photos for free. So the cars that already passed every
automatic filter (reliability >= 90, sedan/estate whitelist, price range, not diesel, engine <= 2.0 L) are reviewed
**here, by you, on demand**. The result is stored per car and used by the 22:00 email: a car scored below the minimum
(`CAR_KNOWLEDGE_LOOKS_MIN_SCORE`, default 60) is never emailed; a car not reviewed yet is still emailed and marked
"not reviewed yet".

You are the buyer's eyes. The buyer is a 23-year-old who wants a car that is **clean, pleasant and presentable**
for everyday use and for outings with his girlfriend. It does not need to be luxurious. Looks NEVER outrank
reliability: this skill only removes cars that look neglected or hide problems, it never promotes a mechanically
doubtful car.

## Steps

1. **Get the shortlist.** Run (PowerShell; PHP is not on the Git Bash PATH):
   `php artisan listings:shortlist --limit=10`
   It prints the cars that pass every current filter and have no looks review yet (id, title, year, km, price, fuel).
   If the user names specific cars, use those ids instead.
2. **Download the photos** of one car: `php artisan listings:photos <id> --max=8`
   It saves `01.jpg`, `02.jpg`, ... under `storage/app/looks/<id>/` and prints the paths. Read each image with the
   Read tool. If it says no gallery photos were found, the ad has no usable photos: score it 40 with the note
   "no usable photos" (a car sold without photos is a red flag), and move on.
3. **Check the photos belong to this car.** The first photos should match the title (make, model, body style, colour).
   If they clearly show a different model (for example a hatchback for a "Logan sedan" ad), set the score to 0 and
   note "photos do not match the ad" — that is a scam signal.
4. **Judge what you can actually see** using the rubric below. Do not invent what is not visible: an angle you
   cannot see is "not shown", not "fine".
5. **Save the result:** `php artisan listings:looks-set <id> <score> "<short notes>"`
   Notes: one line, the 2-4 facts that drove the score, in the user's language (Romanian if the user writes
   Romanian), for example `Alb curat, fără rugină vizibilă, interior îngrijit; lipsă poze motor`.
6. **Delete the downloaded photos** when finished with a car (they are temporary): remove `storage/app/looks/<id>/`.
7. **Report** a table: id, title, price, looks score, one-line reason — best first. Tell the user which cars will now
   be dropped from the email (score below 60) and which remain.

## Scoring rubric (0-100)

Start at 70 (an ordinary car in ordinary condition), then adjust.

**Exterior (what matters most)**
- Paint: even colour and shine across all panels (+); mismatched shade between panels, orange-peel texture, overspray
  on rubber seals, or dull faded paint (-10 to -25) — these suggest accident repair or a repaint.
- Panel gaps: even and straight (+); uneven gaps, misaligned bumper/bonnet/doors (-15 to -30) — accident sign.
- Dents, deep scratches, scraped bumpers (-5 each, up to -20).
- Rust: bubbling paint or corrosion on sills, wheel arches, door bottoms (-20 to -40). Bihor roads are salted, so
  rust is a serious issue.
- Wheels and tyres: matching set in good shape (+); curb-rash, mismatched tyres, worn tread (-5 to -10).
- Lights and glass: clear lights, no cracks, no moisture inside the headlights (-5 to -10 if not).

**Interior (if shown)**
- Clean, tidy seats, dash and steering wheel; no heavy wear or stains (+).
- Worn-shiny steering wheel or gear knob, torn or stained seats, broken trim, strong wear that does not match the
  advertised mileage (-10 to -25) — also a mileage-rollback hint, mention it.
- Dashboard: a photo of the instrument cluster with warning lights on (-20 to -30 and say so).

**Presentation of the ad**
- 6+ photos covering front, rear, sides, interior and dashboard (+5). Only 1-3 photos, dark or blurry photos,
  wet/dirty car, photos that hide one side of the car (-10 to -20) — sellers with a good car show it.
- A car photographed dirty or in a messy yard: -5.

**Caps**
- Visible accident repair, rust perforation, or photos that do not match the ad: score at most 39.
- Everything shown is clean and consistent but the photos are few: score at most 70.
- Only give 85+ when exterior AND interior are shown and both are genuinely clean and consistent.

## Output per car

```json
{
  "id": 0,
  "looks_score": 0,
  "strengths": ["short, concrete, visible"],
  "concerns": ["short, concrete, visible"],
  "not_shown": ["angles or areas the photos do not cover"],
  "verdict": "good | acceptable | drop"
}
```

`good` = 75+, `acceptable` = 60-74, `drop` = below 60. Follow the JSON with at most 3 plain sentences. Always remind the
user that photos are chosen by the seller and that an in-person inspection (and an independent pre-purchase check)
is still required before paying.
