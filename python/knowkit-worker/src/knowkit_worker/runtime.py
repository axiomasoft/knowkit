"""Restore process settings after each offline parser job, including failures."""

from contextlib import contextmanager
import logging
import os


@contextmanager
def offline_environment(native_root=None):
    values = dict(HOME=os.environ.get("HOME", "/tmp"), HF_HUB_OFFLINE="1",
                  TRANSFORMERS_OFFLINE="1", OMP_NUM_THREADS="2",
                  OPENBLAS_NUM_THREADS="2", MKL_NUM_THREADS="2")
    if native_root is not None:
        values["TESSDATA_PREFIX"] = str(native_root / "tessdata")
    previous = dict(os.environ)
    previous_logging = logging.root.manager.disable
    try:
        os.environ.update(values)
        logging.disable(logging.CRITICAL)
        yield
    finally:
        logging.disable(previous_logging)
        # Imports (for example native imaging libraries) can mutate keys too.
        for key in os.environ.keys() - previous.keys():
            os.environ.pop(key, None)
        os.environ.update(previous)
