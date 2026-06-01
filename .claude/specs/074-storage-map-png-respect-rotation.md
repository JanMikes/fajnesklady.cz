# 074 — Respect storage rotation in the generated map PNG

**Status:** done
**Type:** bugfix
**Scope:** tiny (1 service rewritten + 1 new unit test)
**Depends on:** none (touches the generator introduced/used by spec 011)

## Problem

When a customer finishes an order, the post-payment "rental activated" e-mail attaches a PNG of the place map with their storage highlighted (spec 011), and the same PNG is downloadable at `/objednavka/{id}/dokumenty/mapa.png`. On that PNG the highlighted unit is drawn in the **wrong orientation** compared with the interactive map the customer used while picking: a storage the operator rotated on the canvas (e.g. 45° / 90°) shows up axis-aligned (un-rotated) in the PNG.

Root cause: `StorageMapImageGenerator::drawStorage()` reads only `x/y/width/height` from the storage coordinates and draws an axis-aligned rectangle via `drawRectangle()`. It **never reads `coords['rotation']`**, so rotation is silently dropped. The interactive picker renders rotation correctly, hence the mismatch.

## Goal

The generated map PNG draws every storage (highlighted and not) at the same position, size **and rotation** the customer saw in the interactive picker — i.e. rotated around the rectangle's center by `coords['rotation']` degrees. A storage with `rotation: 0` renders exactly as today.

## Context (current state)

- **Generator (the bug):** `src/Service/StorageMapImageGenerator.php`.
  - `generate(Storage $highlightedStorage): ?string` decodes the place's `mapImagePath`, then loops `findByPlace($place)` calling `drawStorage()` per unit. Coordinates here are in **raw map-image pixel space** (the `normalized: true` flag) — the existing code already draws positions/sizes correctly onto the decoded image, which proves the coordinate space matches; only rotation is missing.
  - `drawStorage()` (lines 57-89) currently:
    ```php
    $x = (int) round((float) $coords['x']);
    // … y, width, height …
    $image->drawRectangle(function ($rectangle) use ($x, $y, $width, $height): void {
        $rectangle->at($x, $y);
        $rectangle->size($width, $height);
        $rectangle->background('rgba(34, 197, 94, 0.4)');     // highlighted
        $rectangle->border('rgba(22, 163, 74, 1.0)', 3);
    });
    // non-highlighted: background rgba(156,163,175,0.3) / border rgba(107,114,128,0.5),1
    ```
    Note the early-return guards that must be preserved: skips when `coords['normalized'] !== true`, and when `width <= 0 || height <= 0`.
- **Call sites (both consume `generate()`, so one fix covers both):**
  - `src/Event/SendRentalActivatedEmailHandler.php:75` — post-payment e-mail attachment.
  - `src/Controller/Public/OrderMapDownloadController.php:42` — `/dokumenty/mapa.png`.
- **The picker (reference behaviour to match):** `assets/controllers/storage_map_controller.js:465-479` (`createStorageGroup`). The Konva group is placed at the **center** `x: coords.x + coords.width/2, y: coords.y + coords.height/2`, given `rotation: coords.rotation || 0`, and the rect inside is offset to `-width/2, -height/2`. Konva rotation is **degrees, clockwise in screen (y-down) space**, applied with the standard matrix `x' = x·cosθ − y·sinθ`, `y' = x·sinθ + y·cosθ`. The minimap (line 299) and canvas editor (`storage_canvas_controller.js`) rotate the same way — the server PNG is the only renderer that doesn't.
- **Coordinate schema:** `rotation` is always present in stored coordinates — `Controller/Api/StorageApiValidationTrait.php:42` defaults it to `0`, `Form/StorageFormData.php:100` always writes it, and the `@param` array shape on `Storage`/`CreateStorageCommand`/`UpdateStorageCommand` includes `rotation`. Treat a missing key defensively as `0` anyway.
- **Library:** `intervention/image: ^4.0.3` (composer.lock 4.0.3). `ImageInterface::drawPolygon(callable|Polygon): self` exists (`vendor/intervention/image/src/Image.php:1079`). Inside the callback the `PolygonFactory` exposes `point(int $x, int $y)`, `background(string|ColorInterface)`, `border(string|ColorInterface, int $size = 1)` (`vendor/intervention/image/src/Geometry/Factories/PolygonFactory.php`). A 4-point polygon with `background` + `border` reproduces the current rectangle styling exactly, and a polygon with `rotation: 0` corners is byte-equivalent to the old axis-aligned rectangle.
- **Tests:** no unit test currently targets `StorageMapImageGenerator` (only `tests/Unit/Event/SendRentalActivatedEmailHandlerTest.php`, which mocks the generator). All fixtures use `rotation => 0`, so this bug is invisible in fixture-driven flows — the test must construct a rotated case explicitly.

## Requirements

### 1. Rewrite `StorageMapImageGenerator::drawStorage()` to draw a rotated polygon

Replace the `drawRectangle` call with a 4-corner `drawPolygon`, keeping the exact same colors/border widths and the two early-return guards. Read rotation as `(float) ($coords['rotation'] ?? 0)`.

```php
private function drawStorage(ImageInterface $image, Storage $storage, bool $isHighlighted): void
{
    $coords = $storage->coordinates;

    if (!isset($coords['normalized']) || true !== $coords['normalized']) {
        return;
    }

    $width = (float) $coords['width'];
    $height = (float) $coords['height'];

    if ($width <= 0.0 || $height <= 0.0) {
        return;
    }

    $corners = self::rotatedCorners(
        (float) $coords['x'],
        (float) $coords['y'],
        $width,
        $height,
        (float) ($coords['rotation'] ?? 0),
    );

    // Keep the existing highlighted / dimmed styling untouched.
    [$background, $border, $borderWidth] = $isHighlighted
        ? ['rgba(34, 197, 94, 0.4)', 'rgba(22, 163, 74, 1.0)', 3]
        : ['rgba(156, 163, 175, 0.3)', 'rgba(107, 114, 128, 0.5)', 1];

    $image->drawPolygon(function ($polygon) use ($corners, $background, $border, $borderWidth): void {
        foreach ($corners as [$px, $py]) {
            $polygon->point($px, $py);
        }
        $polygon->background($background);
        $polygon->border($border, $borderWidth);
    });
}
```

Match the existing file style: leave the closure parameter untyped (the current `drawRectangle` closure is `function ($rectangle)`), so no new `use` import is required.

### 2. Add the pure rotation helper `rotatedCorners()`

A `public static` method on the same class (public so it is directly unit-testable without reflection). This is the only part with subtlety — it must reproduce Konva's transform so the PNG matches the picker.

```php
/**
 * Four corners (clockwise from top-left) of a rectangle rotated around its center,
 * matching the Konva transform used by the interactive picker
 * (assets/controllers/storage_map_controller.js:465). Rotation is in degrees,
 * clockwise in image (y-down) space — the same standard matrix Konva applies.
 *
 * @return list<array{int, int}>
 */
public static function rotatedCorners(
    float $x,
    float $y,
    float $width,
    float $height,
    float $rotationDegrees,
): array {
    $centerX = $x + $width / 2;
    $centerY = $y + $height / 2;
    $halfWidth = $width / 2;
    $halfHeight = $height / 2;

    $rad = deg2rad($rotationDegrees);
    $cos = cos($rad);
    $sin = sin($rad);

    // Offsets from center, clockwise: top-left, top-right, bottom-right, bottom-left.
    $offsets = [
        [-$halfWidth, -$halfHeight],
        [$halfWidth, -$halfHeight],
        [$halfWidth, $halfHeight],
        [-$halfWidth, $halfHeight],
    ];

    $corners = [];
    foreach ($offsets as [$offsetX, $offsetY]) {
        $rotatedX = $offsetX * $cos - $offsetY * $sin;
        $rotatedY = $offsetX * $sin + $offsetY * $cos;
        $corners[] = [
            (int) round($centerX + $rotatedX),
            (int) round($centerY + $rotatedY),
        ];
    }

    return $corners;
}
```

**Direction sanity-check (for the implementer):** with `rotationDegrees = 90`, the unit-vector offset `(1, 0)` maps to `(0, 1)` — "right" becomes "down", i.e. clockwise in y-down space, exactly as Konva renders positive rotation. If a rotated storage comes out mirrored, the `$sin` sign is flipped.

### 3. New unit test `tests/Unit/Service/StorageMapImageGeneratorTest.php`

Test the pure `rotatedCorners()` geometry (no image/filesystem needed):

- **rotation 0** → axis-aligned corners exactly: for `(x=50, y=50, w=100, h=100, 0)` returns `[[50,50],[150,50],[150,150],[50,150]]`.
- **rotation 90** of a square `(50,50,100,100,90)` → center `(100,100)` preserved; corners are the 0° set rotated one position clockwise (top-left → where top-right was): expect `[[150,50],[150,150],[50,150],[50,50]]`.
- **rotation 180** → opposite corners: `[[150,150],[50,150],[50,50],[150,50]]`.
- **center invariance**: for an arbitrary angle (e.g. 37°) and a non-square rect, the mean of the four corners ≈ the rectangle center within ±1 px (rounding).

Keep it a plain `PHPUnit\Framework\TestCase` (pure function, no kernel, no MockClock needed).

## Acceptance

- [ ] `StorageMapImageGenerator::drawStorage()` draws each storage rotated about its center; `rotation: 0` output is unchanged from today.
- [ ] Both the rental-activated e-mail attachment and `/objednavka/{id}/dokumenty/mapa.png` show the highlighted unit in the same orientation as the interactive picker (manually verifiable by temporarily setting a storage's `rotation` to `45` and re-finalising an order, or via the unit test for the math).
- [ ] New `tests/Unit/Service/StorageMapImageGeneratorTest.php` covers rotations 0 / 90 / 180 + center invariance, and passes.
- [ ] `composer quality` is green.

## Out of scope

- **Refactoring the picker / canvas / minimap renderers** — they already render rotation correctly; only the server PNG was wrong.
- **Text labels inside the PNG rectangles** — the PNG has never drawn storage numbers (only the interactive picker does); not adding them here.
- **The mutable `Storage.status` column / availability coloring** — unrelated to rotation (spec 071 territory).
- **Migrating fixtures to include a rotated storage** — the unit test constructs the rotated case directly; no fixture churn needed.

## Open questions

None — proceed.
