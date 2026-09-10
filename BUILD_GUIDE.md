# Tally — Build Guide

This guide covers hardware assembly and first-boot setup. It's written so a
builder following **Option 1 only** (radar) can skip every thermal-specific
step, and vice versa for **Option 2 only** (thermal). No real photos are
included yet — sections that should eventually show the builder's own
photos are marked `[PHOTO PLACEHOLDER]`.

> ⚠️ The MLX90640 thermal module's detection logic is implemented (blob
> detection, direction, parked dwell, speed estimate) but has not yet been
> validated against real hardware — no thermal sensor has been available
> during development. Wire it up, but expect to tune the blob-threshold
> and min-travel-column settings once you can see real detections, not
> plug-and-play accuracy on day one.

## 1. Parts list

### Core (always required)

| Part | Notes |
|---|---|
| Raspberry Pi 3B+ or compatible | Any model with GPIO + I2C + USB works |
| Enclosure | Reference build: LeMotech ABS project box, 200×120×56mm |

### Option 1 — HLK-LD2410B radar (×2)

| Part | Notes |
|---|---|
| 2× HLK-LD2410B module | UART, 256000 baud |
| 2× USB-serial adapter | CH340, CP2102, or FTDI |

### Option 2 — MLX90640 thermal array

| Part | Notes |
|---|---|
| Waveshare MLX90640-D110 | I2C, address 0x33 |
| IR window material | See section 4 below |

### Option 3 — Crowd/device scanning

| Part | Notes |
|---|---|
| 3a: onboard Pi Bluetooth | Free, no purchase, default — works out of the box |
| 3b: second USB WiFi adapter | Must support monitor mode — confirm before buying/relying on it. The Pi's own onboard WiFi chip does **not** support monitor mode (confirmed on real hardware, Pi 3B+ `brcmfmac` driver) — this has to be a genuine external adapter; confirmed working: RTL8192CU. Also needs elevated daemon privileges and the interface manually set to monitor mode — see README.md's "Enabling WiFi crowd scanning" before you plan around this one. |
| 3b: **powered USB hub** | **Strongly recommended, not optional, if you're adding a USB WiFi adapter.** Confirmed on real hardware: a Pi 3B+ already running 2 USB-serial radar adapters + a USB webcam hit real under-voltage (`vcgencmd get_throttled` showing the under-voltage bit set, `dmesg` logging "Undervoltage detected!") the moment a USB WiFi adapter was added directly to the Pi's own ports — the whole USB bus reset/re-enumerated repeatedly, which reads as the WiFi module randomly failing ("Network is down") rather than an obvious power problem. Put the WiFi adapter (and the webcam, if you're also using the camera panel) on a powered hub instead of the Pi's own ports once you're running more than one or two USB peripherals. |

### Option 4 — BME280 environment sensor

| Part | Notes |
|---|---|
| BME280 module | I2C, address 0x76 or 0x77 — no conflict with MLX90640's 0x33, no multiplexer needed |

### Developer-only, not a community build option

| Part | Notes |
|---|---|
| Arducam Pi Camera Module 3 NoIR | Calibration only — see section 7 |

## 2. Wiring — Option 1 (LD2410B radar)

```
Raspberry Pi                     HLK-LD2410B #A
┌──────────────┐   USB-serial    ┌──────────────┐
│  USB port     ├────────────────┤  UART TX/RX  │
└──────────────┘   (ttyUSB0)     └──────────────┘

Raspberry Pi                     HLK-LD2410B #B
┌──────────────┐   USB-serial    ┌──────────────┐
│  USB port     ├────────────────┤  UART TX/RX  │
└──────────────┘   (ttyUSB1)     └──────────────┘
```

Mount the two units at the two ends of the zone you're watching (e.g. two
ends of a driveway). Which one triggers first determines direction — the
Setup page's "A → B counts as direction" field lets you match the labels to
however you physically wired them, without re-wiring if you get it backwards.

## 3. Wiring — Option 2 (MLX90640 thermal)

```
Raspberry Pi              MLX90640-D110
┌──────────────┐   I2C    ┌──────────────┐
│  SDA (GPIO2)  ├──────────┤  SDA         │
│  SCL (GPIO3)  ├──────────┤  SCL         │
│  3.3V         ├──────────┤  VIN         │
│  GND          ├──────────┤  GND         │
└──────────────┘          └──────────────┘
```

## 4. Wiring — Option 4 (BME280)

Same I2C bus as the MLX90640 (Option 2), different address — no conflict,
no multiplexer needed:

```
Raspberry Pi              BME280
┌──────────────┐   I2C    ┌──────────────┐
│  SDA (GPIO2)  ├──────────┤  SDA         │
│  SCL (GPIO3)  ├──────────┤  SCL         │
│  3.3V         ├──────────┤  VIN         │
│  GND          ├──────────┤  GND         │
└──────────────┘          └──────────────┘
```

## 5. Enclosure assembly

`[PHOTO PLACEHOLDER — assembled enclosure, outside]`
`[PHOTO PLACEHOLDER — assembled enclosure, inside with Pi + sensors mounted]`

If the thermal module (Option 2) is included, the enclosure needs **two
separate cutouts**, never a shared clear panel:

1. **Thermal sensor window** — sized to the MLX90640's FOV cone with a
   margin, covered with IR-transmissive material (see section 6 below).
2. **Calibration camera window** *(developer build only — see section 7)*
   — standard clear acrylic, physically separate from the thermal window.

If you're building Option 1 only (no thermal), skip both cutouts — the
enclosure just needs cable glands for the two USB-serial leads.

## 6. IR window film — thermal builds only

- Frost King V73/4T polyethylene shrink film is the first thing to try —
  it's cheap and easy to source, but **untested for actual IR transmission
  quality**. Test a sample empirically (compare thermal readings through
  the film vs. with nothing in the beam path) before finalizing the build.
- If it underperforms, fall back to Edmund Optics IR Material Windows
  (0.38mm, 8-14μm rated).
- Size the cutout to the sensor's FOV cone with margin — don't crop the
  field of view.
- Mount the film **loosely**, not drum-tight (tension can distort readings)
  — seal only the perimeter.

## 7. Hidden developer camera (not a community build step)

The Arducam Camera Module 3 NoIR is exclusively for the developer's own
calibration builds — visually validating LD2410B/MLX90640 thresholds during
development. It is **never** part of a normal community build, never
appears in the Setup page's hardware-selection wizard, and is gated behind
a hidden password-protected URL that defaults OFF after every FPP restart.
If you're not the plugin's developer doing sensor-threshold tuning, skip
this section entirely — you don't need the camera, and the plugin doesn't
expect it.

## 8. Mounting geometry

- **Keep the sensor's field of view confined to the road/zone you actually
  want to count** — FOV bleed into an unrelated road or driveway will
  contaminate your counts. Measure your actual pole-to-road (or
  sensor-to-driveway) distance in the field before finalizing mount height
  and angle; don't rely on spec-sheet numbers alone.
- Enter your real mounting height/angle/distance into the Setup page's
  Thermal Config section — the (optional, best-effort) speed estimate is
  derived geometrically from these values, not measured directly.

## 9. First-run setup (hardware-selection wizard)

1. Install the plugin (FPP Plugin Manager, or manually — see README.md).
2. Open **Content Setup → Tally**.
3. Under **Hardware Selection**, check only the modules you actually have
   wired up. Unchecked modules' config sections stay hidden and their
   background threads simply idle — nothing breaks if you only check one
   box.
4. Fill in the serial ports (Option 1) or I2C bus/address (Options 2/4) for
   whatever you enabled.
5. Set your zone and direction labels to match your actual street/driveway
   layout — these are plain text, not hardcoded to the reference build's
   "Entrance"/"Driveway" naming.
6. Save. Use the **Trigger Test Vehicle Event** / **Trigger Test Crowd
   Scan** diagnostics buttons to confirm the daemon → database → MQTT →
   Reporting page pipeline works before wiring any FPP triggers to it.

## 10. Registration walkthrough

Open the Setup page's **Registration** section, enter an email address, and
Save. This is free and unlocks the Setup/Reporting pages — it does not gate
detection, logging, MQTT, or FPP triggers, which run regardless. See
README.md for what registration does and doesn't do.
