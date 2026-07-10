#!/usr/bin/env python3
"""Generate Cookie/Collections-style CSV translations from languages/catalog.json."""

from __future__ import annotations

import csv
import json
import re
import signal
import sys
import time
from pathlib import Path

try:
    from deep_translator import GoogleTranslator
    import requests
except ImportError:
    print("Install dependency first: python3 -m pip install deep-translator", file=sys.stderr)
    raise


MODULE_ROOT = Path(__file__).resolve().parents[1]
CATALOG_FILE = MODULE_ROOT / "languages" / "catalog.json"
OUTPUT_DIR = MODULE_ROOT / "languages"

LANGUAGES = [
    ("Albanian", "sq"),
    ("Bosnian", "bs"),
    ("Bulgarian", "bg"),
    ("Croatian", "hr"),
    ("Czech", "cs"),
    ("Danish", "da"),
    ("Dutch", "nl"),
    ("Estonian", "et"),
    ("Finnish", "fi"),
    ("French", "fr"),
    ("German", "de"),
    ("Greek", "el"),
    ("Hungarian", "hu"),
    ("Icelandic", "is"),
    ("Irish", "ga"),
    ("Italian", "it"),
    ("Latvian", "lv"),
    ("Lithuanian", "lt"),
    ("Macedonian", "mk"),
    ("Maltese", "mt"),
    ("Norwegian", "no"),
    ("Polish", "pl"),
    ("Portuguese", "pt"),
    ("Romanian", "ro"),
    ("Russian", "ru"),
    ("Serbian", "sr"),
    ("Slovak", "sk"),
    ("Slovenian", "sl"),
    ("Spanish", "es"),
    ("Swedish", "sv"),
    ("Turkish", "tr"),
    ("Ukrainian", "uk"),
]

TOKEN_RE = re.compile(
    r"(<[^>]+>|%\d+\$[sdif]|%[sdif]|"
    r"\{[A-Za-z0-9_:-]+\}|"
    r"\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*|"
    r"\b[A-Za-z][A-Za-z0-9_]*::[A-Za-z0-9_]+\b)"
)
LINE_SEPARATOR = "\n§§ICHIBAN_LINE_SEPARATOR§§\n"
MAX_BATCH_CHARS = 3000
BATCH_SIZE = 20
REQUEST_TIMEOUT = 25

_request = requests.sessions.Session.request


def request_with_timeout(self, method, url, **kwargs):
    kwargs.setdefault("timeout", 20)
    return _request(self, method, url, **kwargs)


requests.sessions.Session.request = request_with_timeout


class TranslationTimeout(RuntimeError):
    pass


def _handle_alarm(signum, frame):
    raise TranslationTimeout("translation request timed out")


def timed_translate(translator: GoogleTranslator, text: str) -> str:
    previous_handler = signal.signal(signal.SIGALRM, _handle_alarm)
    signal.alarm(REQUEST_TIMEOUT)
    try:
        return translator.translate(text)
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, previous_handler)


def protect(text: str) -> tuple[str, list[str]]:
    tokens: list[str] = []

    def repl(match: re.Match[str]) -> str:
        tokens.append(match.group(0))
        return f"🔒{len(tokens) - 1}🔒"

    return TOKEN_RE.sub(repl, text), tokens


def restore(text: str, tokens: list[str]) -> str:
    for index, token in enumerate(tokens):
        text = text.replace(f"🔒{index}🔒", token)
    return text


def translate_text(translator: GoogleTranslator, text: str) -> str:
    if text == "" or should_keep(text):
        return text
    protected, tokens = protect(text)
    translated = timed_translate(translator, protected)
    return restore(translated, tokens)


def translate_batch(translator: GoogleTranslator, texts: list[str]) -> list[str]:
    prepared: list[tuple[str, list[str], bool]] = []
    for text in texts:
        if text == "" or should_keep(text):
            prepared.append((text, [], True))
            continue
        protected, tokens = protect(text)
        prepared.append((protected, tokens, False))

    output = [item[0] for item in prepared]
    translatable_indexes = [index for index, item in enumerate(prepared) if not item[2]]
    if not translatable_indexes:
        return output

    batch: list[int] = []
    batch_chars = 0

    def translate_indexes(indexes: list[int]) -> None:
        if not indexes:
            return
        joined = LINE_SEPARATOR.join(prepared[index][0] for index in indexes)
        try:
            translated = timed_translate(translator, joined)
            parts = translated.split(LINE_SEPARATOR)
            if len(parts) != len(indexes):
                raise RuntimeError(f"Batch split mismatch: expected {len(indexes)}, got {len(parts)}")
            for original_index, part in zip(indexes, parts):
                output[original_index] = restore(part, prepared[original_index][1])
        except Exception:
            if len(indexes) > 1:
                midpoint = len(indexes) // 2
                translate_indexes(indexes[:midpoint])
                translate_indexes(indexes[midpoint:])
                return
            original_index = indexes[0]
            try:
                output[original_index] = translate_text(translator, texts[original_index])
            except Exception:
                output[original_index] = texts[original_index]

    def flush() -> None:
        nonlocal batch, batch_chars, output
        if not batch:
            return
        translate_indexes(batch)
        batch = []
        batch_chars = 0

    for index in translatable_indexes:
        text = prepared[index][0]
        projected = batch_chars + len(text) + (len(LINE_SEPARATOR) if batch else 0)
        if batch and projected > MAX_BATCH_CHARS:
            flush()
        batch.append(index)
        batch_chars += len(text) + (len(LINE_SEPARATOR) if len(batch) > 1 else 0)
    flush()
    return output


def should_keep(text: str) -> bool:
    if re.fullmatch(r"[A-Z0-9_./:|{}@$%#<>\-\s]+", text):
        return True
    if re.fullmatch(r"[\d.,:%/\-\s]+", text):
        return True
    return False


def load_rows() -> list[dict[str, str]]:
    catalog = json.loads(CATALOG_FILE.read_text(encoding="utf-8"))
    rows = []
    seen: set[tuple[str, str]] = set()
    for entry in catalog:
        key = (entry["hash"], entry["file"])
        if key in seen:
            continue
        seen.add(key)
        rows.append(
            {
                "en": entry["text"],
                "description": entry.get("context", ""),
                "file": entry["file"],
                "hash": entry["hash"],
            }
        )
    return rows


def generate_language(rows: list[dict[str, str]], language_name: str, language_code: str) -> None:
    output_file = OUTPUT_DIR / f"{language_name}.csv"
    existing: dict[tuple[str, str], str] = {}
    if output_file.is_file():
        with output_file.open(newline="", encoding="utf-8") as handle:
            reader = csv.DictReader(handle)
            for row in reader:
                existing[(row.get("hash", ""), row.get("file", ""))] = row.get(language_code, "")

    translator = GoogleTranslator(source="en", target=language_code)
    out_rows = []
    missing_indexes = [
        index for index, row in enumerate(rows)
        if not existing.get((row["hash"], row["file"]), "")
    ]
    missing_translations: dict[int, str] = {}
    for offset in range(0, len(missing_indexes), BATCH_SIZE):
        chunk_indexes = missing_indexes[offset:offset + BATCH_SIZE]
        chunk_texts = [rows[index]["en"] for index in chunk_indexes]
        translated_chunk = translate_batch(translator, chunk_texts)
        for index, translated in zip(chunk_indexes, translated_chunk):
            missing_translations[index] = translated
        print(f"{language_name}: {min(offset + BATCH_SIZE, len(missing_indexes))}/{len(missing_indexes)}")
        time.sleep(0.2)

    for index, row in enumerate(rows):
        existing_translation = existing.get((row["hash"], row["file"]), "")
        translated = existing_translation or missing_translations[index]
        out_rows.append(
            {
                "en": row["en"],
                language_code: translated,
                "description": row["description"],
                "file": row["file"],
                "hash": row["hash"],
            }
        )

    with output_file.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=["en", language_code, "description", "file", "hash"])
        writer.writeheader()
        writer.writerows(out_rows)
    print(f"Wrote {output_file.relative_to(MODULE_ROOT)} ({len(out_rows)} rows)")


def main() -> int:
    rows = load_rows()
    OUTPUT_DIR.mkdir(exist_ok=True)
    for language_name, language_code in LANGUAGES:
        generate_language(rows, language_name, language_code)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
