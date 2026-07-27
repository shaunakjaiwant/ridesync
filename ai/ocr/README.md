# OCR Module

Shared OCR domain notes and future pipeline components.

## Current Implementation

OCR is currently handled inside `apps/ai-verification/app/main.py` using:
- `pytesseract` — Tesseract OCR wrapper for image-to-text extraction
- `Pillow` — image decoding and pre-processing
- `opencv-python-headless` — image decoding for binary data

## Document Type Regex Patterns

| Document | Pattern |
|----------|---------|
| Driving License | `[A-Z]{2}[0-9]{2}[A-Z0-9 -]{6,18}` |
| Aadhaar | `[0-9]{4}\s?[0-9]{4}\s?[0-9]{4}` |
| PAN Card | `[A-Z]{5}[0-9]{4}[A-Z]` |
| Vehicle RC | `[A-Z]{2}\s?[0-9]{1,2}\s?[A-Z]{0,3}\s?[0-9]{3,4}` |

## Planned Improvements

- Structured field extraction using layout-aware OCR (e.g., EasyOCR, Surya)
- Confidence scores per extracted field
- Multi-language support (Hindi, regional scripts for Aadhaar)
- PDF text layer extraction (using pypdf or pdfminer for digitally-generated PDFs)
- Image pre-processing pipeline: deskew, binarise, denoise before OCR
