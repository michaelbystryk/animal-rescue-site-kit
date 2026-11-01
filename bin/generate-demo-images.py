#!/usr/bin/env python3
"""Run with python3 -I bin/generate-demo-images.py. No external dependencies."""
from pathlib import Path
import struct
import zlib

PALETTE = {name: bytes.fromhex(value) for name, value in {
    "blue": "1F4E79", "gold": "B58B35", "cream": "F7F1E3",
    "paper": "FFFDF8", "ink": "2B2A28",
}.items()}
WIDTH, HEIGHT = 640, 480
# Species, silhouette and colour combinations all vary. Every pixel uses the kit palette.
DESIGNS = {
    "demo-dog-blue.png": (False, "sit", "blue", "gold"),
    "demo-dog-gold.png": (False, "floppy", "gold", "blue"),
    "demo-cat-blue.png": (True, "sit", "blue", "gold"),
    "demo-dog-play.png": (False, "play", "gold", "ink"),
    "demo-dog-pointed.png": (False, "pointed", "ink", "gold"),
    "demo-dog-walk.png": (False, "walk", "blue", "gold"),
    "demo-dog-curly.png": (False, "curly", "cream", "blue"),
    "demo-dog-rest.png": (False, "rest", "gold", "blue"),
    "demo-dog-spot.png": (False, "floppy", "paper", "blue"),
    "demo-dog-tall.png": (False, "tall", "ink", "gold"),
    "demo-cat-gold.png": (True, "walk", "gold", "blue"),
    "demo-dog-husky.png": (False, "husky", "blue", "cream"),
}


def chunk(kind, data):
    return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))


def illustration(cat, pose, body, accent):
    pixels = bytearray(PALETTE["paper"] * WIDTH * HEIGHT)

    def ellipse(cx, cy, rx, ry, colour):
        for y in range(max(0, cy - ry), min(HEIGHT, cy + ry + 1)):
            for x in range(max(0, cx - rx), min(WIDTH, cx + rx + 1)):
                if ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1:
                    offset = (y * WIDTH + x) * 3
                    pixels[offset:offset + 3] = PALETTE[colour]

    def triangle(points, colour):
        (ax, ay), (bx, by), (cx, cy) = points
        for y in range(max(0, min(ay, by, cy)), min(HEIGHT, max(ay, by, cy) + 1)):
            for x in range(max(0, min(ax, bx, cx)), min(WIDTH, max(ax, bx, cx) + 1)):
                edges = [(x - ax) * (by - ay) - (y - ay) * (bx - ax),
                         (x - bx) * (cy - by) - (y - by) * (cx - bx),
                         (x - cx) * (ay - cy) - (y - cy) * (ax - cx)]
                if min(edges) >= 0 or max(edges) <= 0:
                    offset = (y * WIDTH + x) * 3
                    pixels[offset:offset + 3] = PALETTE[colour]

    ellipse(320, 242, 210, 204, "cream")
    ellipse(480, 85, 26, 26, accent)
    ellipse(320, 412, 160, 14, "gold")
    side = pose in ("walk", "play", "rest")
    hx, hy = (255, 250 if pose == "play" else 215) if side else (320, 194)
    rx, ry = (68, 64) if cat else (78, 72)
    if side:
        # Long body, curled tail, and different leg angles create a walking or resting pose.
        ellipse(422, 287, 26, 74, accent)
        ellipse(350, 319 if pose == "rest" else 290, 112, 53, body)
        if pose == "rest":
            ellipse(255, 375, 64, 17, accent)
            ellipse(376, 375, 53, 17, body)
        else:
            ellipse(285, 347, 18, 57, body)
            ellipse(380, 347, 18, 57, body)
            ellipse(275 if pose == "play" else 300, 398, 32, 12, accent)
            ellipse(395, 398, 32, 12, accent)
    else:
        tall = pose == "tall"
        ellipse(320, 315, 64 if tall else 88, 91, body)
        ellipse(320, 321, 32, 58, "paper")
        ellipse(275, 395, 31, 15, accent)
        ellipse(365, 395, 31, 15, accent)
    if pose == "husky":
        ellipse(438, 290, 24, 72, body)
        ellipse(442, 232, 14, 24, "paper")
        # Wide-set upright ears that lean outward, kept short so the long muzzle leads.
        triangle(((hx-rx-6, hy-5), (hx-rx-14, hy-92), (hx-26, hy-52)), body)
        triangle(((hx+26, hy-52), (hx+rx+14, hy-92), (hx+rx+6, hy-5)), body)
        triangle(((hx-rx+2, hy-30), (hx-rx-4, hy-72), (hx-38, hy-50)), accent)
        triangle(((hx+38, hy-50), (hx+rx+4, hy-72), (hx+rx-2, hy-30)), accent)
    elif cat or pose == "pointed":
        triangle(((hx-rx, hy-10), (hx-rx+5, hy-110), (hx-10, hy-50)), body)
        triangle(((hx+10, hy-50), (hx+rx-5, hy-110), (hx+rx, hy-10)), body)
        triangle(((hx-rx+16, hy-35), (hx-rx+19, hy-86), (hx-30, hy-45)), accent)
        triangle(((hx+30, hy-45), (hx+rx-19, hy-86), (hx+rx-16, hy-35)), accent)
    else:
        for ear in (-1, 1):
            ellipse(hx+ear*70, hy+22, 28 if pose == "floppy" else 24, 77 if pose == "floppy" else 61, accent)
    ellipse(hx, hy, rx, ry, body)
    if pose == "curly":
        for dx, dy in ((-60,-38),(-25,-62),(20,-64),(59,-35)):
            ellipse(hx+dx, hy+dy, 30, 28, accent)
    if body == "paper" or pose == "pointed":
        ellipse(hx+35, hy-9, 32, 36, accent)
    if pose == "husky":
        husky_face(ellipse, hx, hy, accent)
        return finish(pixels)
    ellipse(hx, hy+32, 46 if cat else 48, 32, "paper")
    for eye in (-1, 1):
        if body == "ink":
            ellipse(hx+eye*29, hy-4, 10, 13, "paper")
            ellipse(hx+eye*29, hy-4, 5, 8, "ink")
        else:
            ellipse(hx+eye*29, hy-4, 7, 10, "ink")
    triangle(((hx-11, hy+23), (hx+11, hy+23), (hx, hy+34)), "ink")
    ellipse(hx, hy+46, 4, 4, "ink")
    if cat:
        for side_sign in (-1, 1):
            for dy in (22, 35, 48):
                ellipse(hx+side_sign*55, hy+dy, 23, 2, "ink")
    else:
        ellipse(hx, hy+77, 49, 7, accent)
        ellipse(hx, hy+87, 9, 10, "gold")
    return finish(pixels)


def husky_face(ellipse, hx, hy, accent):
    """Dog face: cream mask, long muzzle, big dog nose, mouth and tongue, no whisker marks."""
    ellipse(hx, hy+12, 58, 50, accent)
    for eye in (-1, 1):
        ellipse(hx+eye*31, hy-8, 17, 15, accent)
        ellipse(hx+eye*31, hy-8, 7, 10, "ink")
    ellipse(hx, hy+50, 37, 52, "gold")
    ellipse(hx, hy+18, 21, 14, "ink")
    ellipse(hx-5, hy+14, 6, 3, "paper")
    ellipse(hx, hy+40, 2, 12, "ink")
    ellipse(hx, hy+96, 14, 18, "ink")
    ellipse(hx, hy+97, 11, 15, "paper")


def finish(pixels):
    rows = b"".join(b"\x00" + pixels[y * WIDTH * 3:(y + 1) * WIDTH * 3] for y in range(HEIGHT))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", WIDTH, HEIGHT, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(rows, 9)) + chunk(b"IEND", b""))


if __name__ == "__main__":
    target = Path(__file__).resolve().parent.parent / "demo-content" / "images"
    target.mkdir(parents=True, exist_ok=True)
    for name, design in DESIGNS.items():
        (target / name).write_bytes(illustration(*design))
        print("Generated demo-content/images/" + name)
