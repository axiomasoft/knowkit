"""Author v1 synthetic scans. Never rewrite expected IR digests here."""

from io import BytesIO
from pathlib import Path
import shutil

from PIL import Image, ImageDraw, ImageFont
from reportlab.pdfgen.canvas import Canvas
from reportlab.lib.utils import ImageReader

ROOT = Path(__file__).resolve().parents[4]
CORPUS = ROOT / "tests/fixtures/documents/scans/v1"
FONT = Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")


def scan(language: str = "eng", rotated=False, unreadable=False):
    image = Image.new("RGB", (1400, 1500), "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.truetype(str(FONT), 34)
    lines = (["Approved English scan with original page references.",
              "The quick brown fox jumps over the lazy dog.",
              "Knowledge extraction keeps source bytes immutable.",
              "Confidence measures extraction quality only."] if language == "eng" else
             ["Проверенный русский текст исходной страницы.",
              "Точная ссылка сохраняет версию документа.",
              "Качество распознавания не доказывает истину.",
              "Исходные данные остаются неизменяемыми."])
    if unreadable:
        draw.rectangle((50, 50, 1250, 1400), fill="black")
    else:
        for index in range(16):
            draw.text((45, 60 + index * 80), lines[index % len(lines)], font=font, fill="black")
    if rotated:
        image = image.rotate(90, expand=True)
    return image


def build():
    CORPUS.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(ROOT / "tests/fixtures/documents/pdf/v1/DejaVu-NOTICE", CORPUS / "DejaVu-NOTICE")
    for name, options in (("approved-eng", {}), ("approved-rus", {"language": "rus"}),
                          ("rotated", {"rotated": True}), ("unreadable", {"unreadable": True})):
        canvas = Canvas(str(CORPUS / f"{name}.pdf"), pagesize=(600, 660), invariant=1)
        image = scan(**options)
        canvas.drawImage(ImageReader(image), 20, 20, 560, 600)
        canvas.showPage()
        canvas.save()
    canvas = Canvas(str(CORPUS / "mixed.pdf"), pagesize=(600, 660), invariant=1)
    canvas.setFont("Helvetica", 18)
    canvas.drawString(40, 540, "NATIVE FIRST PAGE")
    canvas.setFont("Helvetica", 12)
    for index in range(8):
        canvas.drawString(40, 480 - index * 28, "Native source text remains separate from OCR content.")
    canvas.showPage()
    canvas.drawImage(ImageReader(scan()), 20, 20, 560, 600)
    canvas.showPage()
    canvas.drawString(40, 635, "NATIVE CAPTION OUTSIDE RASTER")
    canvas.drawImage(ImageReader(scan()), 40, 40, 480, 540)
    canvas.showPage()
    canvas.showPage()  # deliberately blank physical page 4
    canvas.rect(80, 80, 200, 200)  # vector-only physical page 5
    canvas.showPage()
    canvas.drawImage(ImageReader(scan(unreadable=True)), 20, 20, 560, 600)
    canvas.showPage()
    canvas.save()
    canvas = Canvas(str(CORPUS / "sparse-native.pdf"), pagesize=(600, 660), invariant=1)
    canvas.drawString(40, 620, "NATIVE FIRST PAGE")
    canvas.showPage()
    canvas.save()


if __name__ == "__main__":
    build()
