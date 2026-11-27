"""Seeded fuzzing for offline LaTeX formula AST validator (formula_verify.py)."""

from __future__ import annotations

import os
import random
import socket
import time

from knowkit_worker.structure.formula_verify import (
    MAX_BRACE_DEPTH,
    MAX_LATEX_BYTES,
    MAX_PARSE_MS,
    FormulaVerifyResult,
    verify_latex_formula,
)


def _fuzz_seed() -> int:
    raw = os.environ.get("KNOWKIT_FUZZ_SEED", "20261127")
    return int(raw) if raw.isdigit() else 20261127


def _generate_cases(rng: random.Random, count: int) -> list[str]:
    tokens = [
        "x", "y", "z", r"\alpha", r"\beta", r"\pi", r"\infty",
        "+", "-", "*", "/", "=", r"\frac{x}{y}", r"\sqrt{x}",
        r"\sin(x)", r"\cos(y)", "{", "}", "(", ")", "[", "]",
        "^2", "_i", " ", "123", "0",
    ]
    adversarial = [
        "__import__('os').system('id')",
        r"\write18{cat /etc/passwd}",
        r"\input{/etc/passwd}",
        r"\catcode`\@=11",
        "{" * (MAX_BRACE_DEPTH + 5) + "x" + "}" * (MAX_BRACE_DEPTH + 5),
        "x + " * (MAX_LATEX_BYTES // 3),
        "(" * 50 + "x" + ")" * 49,
        r"\frac{1}{" * 20 + "x" + "}" * 20,
        r"((((((((((a+b))))))))))",
        "",
        "   ",
        r"\sum_{i=1}^{n} x_i^2",
    ]
    cases: list[str] = list(adversarial)
    while len(cases) < count:
        n_tok = rng.randint(1, 40)
        expr = "".join(rng.choice(tokens) for _ in range(n_tok))
        cases.append(expr)
    return cases[:count]


def test_formula_verify_seeded_fuzz_500_cases(monkeypatch) -> None:
    def _no_socket(*args, **kwargs):  # type: ignore[no-untyped-def]
        raise AssertionError("network socket creation forbidden")

    monkeypatch.setattr(socket, "socket", _no_socket)

    rng = random.Random(_fuzz_seed())
    cases = _generate_cases(rng, 500)
    assert len(cases) == 500

    for idx, latex in enumerate(cases):
        t0 = time.monotonic()
        res = verify_latex_formula(latex)
        elapsed_ms = (time.monotonic() - t0) * 1000.0
        assert isinstance(res, FormulaVerifyResult), f"case {idx} returned non-result"
        assert elapsed_ms <= MAX_PARSE_MS + 50.0, f"case {idx} exceeded timeout budget: {elapsed_ms:.1f}ms"
        if not res.valid:
            assert res.diagnostic_code is not None
