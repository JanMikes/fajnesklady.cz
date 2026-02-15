# Map Building Overlay Editor -- Technical Decisions

## Goal

Interactive web editor to place and manage building rectangles (later
possibly polygons) on top of a JPG map screenshot.

Supports: - Admin edit mode - User read/select mode (desktop + mobile) -
Serialization to DB - Export to JPG with overlays

------------------------------------------------------------------------

# Core Technology

## Chosen Library: Konva.js

### Why

-   Canvas-based scene graph with object model
-   Strong support for:
    -   Drag/drop
    -   Transform (resize/rotate)
    -   Events (click/tap/hover)
    -   Serialization
    -   High-quality export
-   Handles 20--200 shapes easily
-   Mobile touch support
-   MIT license
-   Cleaner than SVG for zoom/pan + export workflows

------------------------------------------------------------------------

# Rendering Model

## Vector over Raster

-   Background map = JPG image
-   Buildings = vector shapes (Rect now, Polygon later)
-   Export = render both into one raster

## Coordinate System

-   World coordinates = image pixels
-   Ensures pixel-perfect placement
-   Makes export exact

------------------------------------------------------------------------

# Data Model (DB)

Store domain JSON (not raw Konva JSON):

``` json
{
  "image": {
    "id": "map123",
    "width": 4000,
    "height": 2500
  },
  "grid": {
    "size": 20
  },
  "buildings": [
    {
      "id": "b1",
      "code": "A1",
      "x": 1200,
      "y": 640,
      "w": 180,
      "h": 120,
      "rot": 0
    }
  ]
}
```

### Why domain JSON

-   Future-proof styling changes
-   Easier migrations
-   Clean separation from rendering library

------------------------------------------------------------------------

# Building Representation

Each building = Konva Group: - Rect (visible shape) - Text label
(centered, listening=false) - Optional invisible hit rect for easier
taps

Label centered inside rectangle.

------------------------------------------------------------------------

# Modes

## Admin Mode

-   Drag & drop
-   Resize/rotate (Transformer)
-   Optional grid snapping
-   Numeric input control (X/Y/W/H/Rotation)
-   Copy/paste
-   Hover highlight

## Viewer Mode

-   No dragging
-   Tap/click selects building
-   Selected style highlight
-   Mobile-friendly
-   Optional pan/pinch zoom

------------------------------------------------------------------------

# Grid Snapping

Toggle via checkbox.

Logic: - snap(value) = round(value / gridSize) \* gridSize - Applied on
drag or dragend - Optional snap for numeric input changes

------------------------------------------------------------------------

# Mobile Behavior

## Interaction

-   Tap to select
-   No hover
-   Large invisible hit areas
-   Text does not capture events

## Optional

-   Pan map
-   Pinch zoom

------------------------------------------------------------------------

# Performance Notes

-   20--200 shapes is trivial for Konva
-   Two layers only:
    -   Background
    -   Overlay
-   Redraw overlay layer only when needed

------------------------------------------------------------------------

# Export

Client-side: - stage.toDataURL() - pixelRatio = 1 for exact pixels -
Optional higher ratio for quality

Server-side: - Optional JPG conversion

------------------------------------------------------------------------

# Future Extensions

-   Polygon/triangle shapes
-   Snapping to other shapes
-   Undo/redo
-   Multi-user editing
-   Status-based coloring (available/sold/etc.)

------------------------------------------------------------------------

# Summary

Konva.js provides: - Best balance of power and simplicity - Reliable
mobile support - Easy serialization - Smooth zoom/pan - Clean export
pipeline

It fits the requirements better than SVG or ready-made image editors.
