"""Inspect synthetic PDFs. Optional tooling: pip install pypdf pymupdf.
Run `php tests/resume-pdf-fixture.php` in backend first. No database is used.
"""
from pathlib import Path
import pypdf
import pymupdf

root = Path(__file__).resolve().parents[1]
for template in ("classic", "modern"):
    path = root / "backend/storage/framework/cache" / f"resume-{template}.pdf"
    pdf = pypdf.PdfReader(path)
    text = " ".join("\n".join(page.extract_text() for page in pdf.pages).split())
    assert len(pdf.pages) > 1
    for expected in ("Alex Example", "SEARCHABLE RESUME", "END ENTRY 12", "Communication", "English"):
        assert expected in text, (template, expected)
    assert "SECRET-ID" not in text and "PRIVATE-EXPERIENCE" not in text
    for page in pdf.pages:
        assert abs(float(page.mediabox.width) - 595.28) < 1
        assert abs(float(page.mediabox.height) - 841.89) < 1
    doc = pymupdf.open(path)
    for page in doc:
        assert not page.get_images(), "Resume should contain text, not screenshots"
        for word in page.get_text("words"):
            assert word[0] >= 48 and word[1] >= 48 and word[2] <= 548 and word[3] <= 795, (template, page.number, word)
    doc[0].get_pixmap(matrix=pymupdf.Matrix(1, 1)).save(path.with_suffix('.png'))
    print(f"PASS {template}: {len(pdf.pages)} A4 pages, searchable text including final entry, no text outside margins, no raster images")
