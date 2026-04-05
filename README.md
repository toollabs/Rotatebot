# Rotatebot

A MediaWiki bot that automatically rotates images on [Wikimedia Commons](https://commons.wikimedia.org/) based on the `{{Rotate}}` template.

Originally written by Luxo & Saibo (2011–2014), maintained by [Steinsplitter](https://commons.wikimedia.org/wiki/User:Steinsplitter) (2014–present).

---

## How it works

1. Reads files from a configured Commons category (e.g. `Category:Photographs to be rotated`)
2. Validates each file and the user who added the `{{Rotate}}` template
3. Downloads the original file
4. Rotates it using the appropriate lossless method for the file type
5. Re-uploads the rotated file and removes the template from the file description page
6. Writes a log entry to the bot's log page on Commons

---

## Supported formats

| Format | Method |
|--------|--------|
| JPEG   | Lossless rotation via `jpegtran`; EXIF orientation tag normalized |
| PNG    | Lossless rotation via ImageMagick (`magick`) |
| GIF    | Rotation via ImageMagick (`magick`) |
| TIFF   | Lossless rotation via ImageMagick; original compression (LZW, ZIP, RLE, etc.) is detected and preserved |
| WebP   | Lossless rotation via ImageMagick with `-define webp:lossless=true` |
| WebM   | Lossless rotation via `ffmpeg` with VP9 lossless re-encode |

---

## Requirements

- **OS**: Debian/Ubuntu Linux
- **PHP**: 8.0 or newer, with extensions: `curl`, `mbstring`, `xml`
- **ImageMagick 7** (`/usr/bin/magick`)
- **ExifTool** (`/usr/bin/exiftool`)
- **libjpeg-turbo** (`/usr/bin/jpegtran`)
- **ffmpeg** with libvpx-vp9 (for WebM rotation)
- **Composer** (PHP dependency manager)

---

## File structure

```
rotbot.php            — Main bot script
accessdata.php     — Bot credentials (not committed)
vendor/            — Composer dependencies
cache/             — Temporary image downloads (auto-cleared each run)
public_html/
  rotatelogs/      — Daily log files
counter.txt        — Running total of rotated images
```
