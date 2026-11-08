"""Project-authored PDF corpus v1. Rebuild only with an explicit version rationale.

Fonts are build inputs only; embedded subsets carry their original licenses.
"""

import argparse
from io import BytesIO
from pathlib import Path

from PIL import Image, ImageDraw
from reportlab.pdfgen.canvas import Canvas
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase.pdfdoc import PDFDictionary, PDFName, PDFArray, PDFString


def canvas(path: Path, size=(600, 800)) -> Canvas:
    return Canvas(str(path), pagesize=size, invariant=1, pageCompression=1)


def columns(c: Canvas) -> None:
    # Operators deliberately alternate R/L rows. The intended reading order is
    # the entire left column followed by the entire right column.
    c.setFont("Helvetica", 12)
    for row in range(12):
        c.drawString(330, 740 - 26 * row, f"RIGHT {row:02d} reading continues here.")
        c.drawString(30, 740 - 26 * row, f"LEFT {row:02d} read this column first.")


def build(root: Path, latin: Path, cjk: Path) -> None:
    root.mkdir(parents=True, exist_ok=True)
    c = canvas(root / "two-columns.pdf")
    columns(c)
    c.save()
    c = canvas(root / "rotated-table.pdf", (800, 600))
    c.setPageRotation(90)
    columns(c)
    c.drawString(30, 360, "Continued table: mass (kg) | speed (m/s)")
    for x in (30, 220, 500):
        c.line(x, 280, x, 340)
    for y in (280, 310, 340):
        c.line(30, y, 500, y)
    c.drawString(40, 320, "A 2 kg")
    c.drawString(240, 320, "12 m/s")
    c.showPage()
    c.setPageSize((600, 800))
    c.setPageRotation(0)
    c.drawString(30, 740, "Table continued: B 3 kg | 15 m/s; measured at 20 C")
    c.save()
    c = canvas(root / "coverage.pdf")
    c.showPage()  # no operators that produce page content
    c.setStrokeColorRGB(0, 0, 1)
    c.circle(300, 400, 120, stroke=1, fill=0)
    c.showPage()
    image = Image.new("RGB", (400, 300), "white")
    draw = ImageDraw.Draw(image)
    for row in range(12):
        draw.text((10, 15 + 20 * row), f"unreadable scan row {row}", fill="black")
    from reportlab.lib.utils import ImageReader
    c.drawImage(ImageReader(image), 100, 200, width=400, height=300)
    c.save()
    pdfmetrics.registerFont(TTFont("FixtureLatin", str(latin)))
    pdfmetrics.registerFont(TTFont("FixtureCJK", str(cjk)))
    c = canvas(root / "unicode-label.pdf")
    c.setFont("FixtureLatin", 18)
    c.drawString(40, 740, "Привет мир — e\u0301")
    c.setFont("FixtureCJK", 18)
    c.drawString(40, 690, "中文 日本語 数学")
    # ReportLab's PageLabels API uses a zero-based physical page index.
    c.addPageLabel(0, style="roman_lower", start=12)
    c.save()
    c = canvas(root / "malicious.pdf")
    c.drawString(30, 740, "Never execute embedded actions")
    action = PDFDictionary({"S": PDFName("JavaScript"), "JS": PDFString("app.launchURL('https://invalid.example')")})
    names = PDFDictionary({"Names": PDFArray([PDFString("payload"), c._doc.Reference(action)])})
    c._doc.Catalog.Names = PDFDictionary({"JavaScript": c._doc.Reference(names)})
    c.save()
    c = canvas(root / "huge.pdf", (1_000_000, 1_000_000))
    c.drawString(30, 30, "Bound geometry before rendering")
    c.save()
    (root / "malformed.pdf").write_bytes(b"%PDF-1.7\ninvalid object stream\n%%EOF")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--latin-font", type=Path, required=True)
    parser.add_argument("--cjk-font", type=Path, required=True)
    args = parser.parse_args()
    build(args.output, args.latin_font, args.cjk_font)
