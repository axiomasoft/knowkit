"""Regression coverage for bounded traversal, process state and filesystem gates."""

from contextlib import contextmanager
from dataclasses import replace
import errno
import importlib.util
from io import BytesIO
import json
import logging
import os
from pathlib import Path
import stat
import subprocess
import sys
from types import SimpleNamespace

import pytest

from knowkit_worker import cli, files, models, profiles
from knowkit_worker.ocr import engine as ocr
from knowkit_worker.parsers.pdf import engine as pdf, qualification
from knowkit_worker.protocol import Refusal, sha256
from knowkit_worker.rendering.pdf import bbox, inspect_objects, open_pdf
from knowkit_worker.runtime import offline_environment
from knowkit_worker.structure.tables import Cell, retain_table
from .pdf.conftest import job_for

ROOT = Path(__file__).resolve().parents[3]


def nested_pdf(depth):
    from reportlab.pdfgen.canvas import Canvas

    output = BytesIO()
    canvas = Canvas(output, pagesize=(100, 100))
    for level in reversed(range(depth)):
        canvas.beginForm(f"form{level}")
        if level + 1 < depth:
            canvas.doForm(f"form{level + 1}")
        else:
            canvas.rect(1, 1, 10, 10)
        canvas.endForm()
    canvas.doForm("form0")
    canvas.showPage()
    canvas.save()
    return output.getvalue()


def test_nested_forms_cannot_certify_absence_or_complete_coverage(monkeypatch):
    import pypdfium2

    source = nested_pdf(34)
    with pypdfium2.PdfDocument(source) as document:
        metadata = pdf.preflight(document)
        assert metadata[0]["truncated"] is True
        assert metadata[0]["raster"] is None
        monkeypatch.setattr(pdf, "page_png", lambda page: b"preview")
        native = SimpleNamespace(pages={1: object()}, iterate_items=lambda: [])
        job = job_for(source).value
        parsed = pdf.map_document(native, document, metadata, job)
        assert parsed.ir["pages"][0]["status"] == "skipped"
        assert "vector_only_page" not in {d["code"] for d in parsed.ir["coverage"]["diagnostics"]}
        ocr.finalize(parsed.ir, metadata, job)
        assert parsed.ir["coverage"]["state"] == "partial"
        assert "object_traversal" in parsed.ir["coverage"]["missingFeatures"]
        page = document[0]
        try:
            with pytest.raises(Refusal, match="page_object_depth_limit"):
                ocr.regions(page)
        finally:
            page.close()
    with pytest.raises(Refusal, match="page_object_depth_limit"):
        with open_pdf(source, 1):
            pytest.fail("unchecked forms reached rendering")
    with open_pdf(nested_pdf(31), 1):
        pass


@pytest.mark.parametrize("module", ["knowkit_worker.parsers.pdf.engine", "knowkit_worker.ocr.engine"])
def test_engines_import_before_activated_profile_registry(module):
    code = f'''
from pathlib import Path
from knowkit_worker.parsers.pdf import qualification
qualification.ready = lambda *args: True
qualification.verify_dependencies = lambda: None
qualification.verify_models = lambda root: None
import importlib
importlib.import_module({module!r})
from knowkit_worker.profiles import Asset, ParsedDocument, Profile, PROFILES
assert PROFILES['docling-pdf-text-v1'].parse is not None
'''
    subprocess.run([sys.executable, "-c", code], check=True, capture_output=True)
    assert profiles.Asset is models.Asset
    assert profiles.ParsedDocument is models.ParsedDocument
    assert profiles.Profile is models.Profile


def test_cli_custom_empty_registry_and_unknown_refusal(monkeypatch, capsys):
    assert cli.main(["capabilities"], profiles={}) == 0
    assert json.loads(capsys.readouterr().out)["profiles"] == []
    registry = {}

    def refuse(path, active, contracts):
        assert active is registry
        raise Refusal("uncertain", "unknown")

    monkeypatch.setattr(cli, "run_job", refuse)
    monkeypatch.setattr(cli.signal, "signal", lambda *args: None)
    monkeypatch.setattr(cli.os, "umask", lambda *args: None)
    assert cli.main(["run", "--envelope", "/input/job.json"], profiles=registry) == 75
    assert json.loads(capsys.readouterr().err)["outcome"] == "unknown"


@pytest.mark.parametrize("extra", ["file", "symlink", "fifo", "hardlink"])
def test_pdf_model_inventory_is_closed(tmp_path, monkeypatch, extra):
    model = tmp_path / "model"
    model.write_bytes(b"model")
    monkeypatch.setattr(qualification, "record", lambda: {
        "modelAssets": [dict(path="model", byteLength=5, sha256=sha256(b"model"))]})
    qualification.verify_models(tmp_path)
    path = tmp_path / "extra"
    if extra == "file":
        path.write_bytes(b"extra")
    elif extra == "symlink":
        path.symlink_to(model)
    elif extra == "fifo":
        os.mkfifo(path)
    else:
        os.link(model, path)
    with pytest.raises(Refusal, match="model_assets_invalid"):
        qualification.verify_models(tmp_path)


@pytest.mark.parametrize("values", [(0, 0, 1), (0, 0, 1, 1, 2), (False, 0, 1, 1)])
def test_bbox_requires_exact_nonboolean_coordinates(values):
    with pytest.raises(Refusal, match="invalid_geometry"):
        bbox(values, 10, 10)


@pytest.mark.parametrize("field", ["rows", "columns", "row", "column", "rowSpan", "colSpan"])
def test_table_integer_fields_reject_bool(field):
    ir = {"blocks": [{"blockId": "table", "origin": "source", "spans": [], "kind": "text"}]}
    cell = Cell("cell", 0, 0, 1, 1, False, [], None, [], [])
    rows, columns = 1, 1
    if field == "rows":
        rows = True
    elif field == "columns":
        columns = True
    else:
        cell = replace(cell, **{field: True})
    with pytest.raises(Refusal, match="table_shape_invalid|table_cell_out_of_range"):
        retain_table(ir, "table", rows, columns, [cell])


@pytest.mark.parametrize("failure", ["open", "fdopen", "space"])
def test_output_open_failures_are_typed_and_do_not_leak(tmp_path, monkeypatch, failure):
    before = set(os.listdir('/proc/self/fd'))
    error = OSError(errno.ENOSPC if failure == "space" else errno.EACCES, "test")

    def fail(*args, **kwargs):
        raise error

    monkeypatch.setattr(files.os, "open" if failure == "open" else "fdopen", fail)
    with pytest.raises(Refusal) as caught:
        files.write_new(tmp_path, "result.json", b"output")
    assert (caught.value.code, caught.value.outcome) == (
        ("output_limit", "unavailable") if failure == "space" else ("unsafe_file", "denied"))
    assert set(os.listdir('/proc/self/fd')) == before


def test_environment_and_logging_restored_on_failure(monkeypatch, tmp_path):
    monkeypatch.delenv("HF_HUB_OFFLINE", raising=False)
    monkeypatch.setenv("TESSDATA_PREFIX", "original")
    monkeypatch.setenv("OMP_NUM_THREADS", "7")
    before = dict(os.environ)
    previous = logging.root.manager.disable
    with pytest.raises(RuntimeError):
        with offline_environment(tmp_path):
            assert os.environ["OMP_NUM_THREADS"] == "2"
            assert os.environ["TESSDATA_PREFIX"] == str(tmp_path / "tessdata")
            raise RuntimeError("internal bug")
    assert dict(os.environ) == before
    assert logging.root.manager.disable == previous


@pytest.mark.parametrize("kind,stage", [("pdf", "map_document"), ("ocr", "map_document"),
                                        ("ocr", "process_regions"), ("ocr", "finalize")])
@pytest.mark.parametrize("bug_type", [KeyError, TypeError])
def test_mapping_bugs_are_not_api_incompatibility(monkeypatch, tmp_path, kind, stage, bug_type):
    engine = pdf if kind == "pdf" else ocr
    source = b"%PDF-stub"
    job = job_for(source)
    if kind == "ocr":
        profile = __import__('knowkit_worker.ocr.profile', fromlist=['descriptor']).descriptor()
        job.value.update(parserProfile=profile.id, parserSignature=profile.signature)
    monkeypatch.setattr(engine, "verify_dependencies", lambda: None)
    monkeypatch.setattr(engine, "verify_models", lambda path: None)

    @contextmanager
    def document(*args):
        yield object()

    monkeypatch.setattr(engine, "open_pdf", document)
    monkeypatch.setattr(engine, "preflight", lambda doc: [{}])
    monkeypatch.setattr(engine, "converter", lambda *args: SimpleNamespace(
        convert=lambda *args, **kwargs: SimpleNamespace(document=object())))
    monkeypatch.setattr(engine, "map_document", lambda *args, **kwargs: models.ParsedDocument({"pages": []}))
    if kind == "ocr":
        monkeypatch.setattr(engine, "process_regions", lambda *args: None)
        monkeypatch.setattr(engine, "finalize", lambda *args: None)

    def bug(*args, **kwargs):
        raise bug_type("mapper bug")

    monkeypatch.setattr(engine, stage, bug)
    before = dict(os.environ)
    previous = logging.root.manager.disable
    with pytest.raises(bug_type, match="mapper bug"):
        if kind == "pdf":
            engine.parse(source, job, tmp_path)
        else:
            engine.parse(source, job, language="eng", native_root=tmp_path, model_root=tmp_path)
    assert dict(os.environ) == before
    assert logging.root.manager.disable == previous


def load_deploy(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'deploy' / f'{name}.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.mark.parametrize("kind", ["symlink", "fifo", "socket", "hardlink"])
def test_delivery_rejects_nonregular_entries_before_reading(tmp_path, kind):
    source = tmp_path / "source"
    source.mkdir()
    (source / "data").write_bytes(b"data")
    (source / "artifact.json").write_text(json.dumps({
        "schemaVersion": "knowkit-local-artifact:1", "files": {"data": sha256(b"data")}}))
    load_deploy("verify").verify(source)
    extra = source / "extra"
    if kind == "symlink":
        extra.symlink_to(source / "data")
    elif kind == "fifo":
        os.mkfifo(extra)
    elif kind == "socket":
        os.mknod(extra, stat.S_IFSOCK | 0o600)
    else:
        os.link(source / "data", extra)
    with pytest.raises(ValueError):
        load_deploy("assemble").preflight(source)
    with pytest.raises(ValueError):
        load_deploy("verify").verify(source)


def test_words_reuse_the_job_fingerprint(monkeypatch):
    from knowkit_worker.ocr import qualification as ocr_qualification

    def unexpected_read():
        pytest.fail("word mapping re-read qualification.json")

    monkeypatch.setattr(ocr_qualification, "record", unexpected_read)
    ir = {"blocks": []}
    page = dict(pageId="page-1", physicalNo=1, printedLabel=None, text="")
    row = SimpleNamespace(conf=95, left=1, top=1, width=2, height=2, text="word")
    fingerprint = sha256(b"qualified-model")
    for _ in range(3):
        ocr.append_word(ir, page, row, (0, 0, 10, 10), (10, 10), (10, 10), "eng", fingerprint)
    assert len(ir["blocks"]) == 3
    assert all(fingerprint in b["blockId"] for b in ir["blocks"])
