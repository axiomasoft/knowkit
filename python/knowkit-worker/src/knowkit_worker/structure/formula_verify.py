"""Offline deterministic LaTeX formula AST validator backed by SymPy constructors.

Never invokes dynamic code evaluation or untrusted string-to-expression coercion.
Enforces strict byte length, delimiter nesting depth, token/macro allowlist,
and monotonic wall-clock parsing timeout before and during AST construction.
"""

from __future__ import annotations

from dataclasses import dataclass
import re
import time
from typing import Callable

import sympy
from sympy.core.expr import Expr

MAX_LATEX_BYTES = 4096
MAX_BRACE_DEPTH = 32
MAX_PARSE_MS = 200
_MAX_TOKENS = 1024

_DANGEROUS_PATTERNS = (
    re.compile(r"__(?:import|builtins|class|subclasses|bases|mro|globals|dict|code|reduce|getattribute)__", re.IGNORECASE),
    re.compile(r"\\(?:write18|write|immediate|openout|openin|read|input|include|catcode|def|edef|gdef|xdef|let|csname|endcsname|expandafter|special|verbatiminput|lstinputlisting|ShellEscape|url|href)\b"),
)

_GREEK_LETTERS = {
    "alpha", "beta", "gamma", "delta", "epsilon", "varepsilon", "zeta", "eta",
    "theta", "vartheta", "iota", "kappa", "lambda", "mu", "nu", "xi",
    "rho", "varrho", "sigma", "varsigma", "tau", "upsilon", "phi", "varphi",
    "chi", "psi", "omega",
    "Gamma", "Delta", "Theta", "Lambda", "Xi", "Pi", "Sigma", "Upsilon",
    "Phi", "Psi", "Omega",
}

_FUNCTIONS: dict[str, Callable[..., Expr]] = {
    "sin": sympy.sin,
    "cos": sympy.cos,
    "tan": sympy.tan,
    "cot": sympy.cot,
    "sec": sympy.sec,
    "csc": sympy.csc,
    "arcsin": sympy.asin,
    "arccos": sympy.acos,
    "arctan": sympy.atan,
    "sinh": sympy.sinh,
    "cosh": sympy.cosh,
    "tanh": sympy.tanh,
    "log": sympy.log,
    "ln": sympy.log,
    "exp": sympy.exp,
    "sqrt": sympy.sqrt,
    "abs": sympy.Abs,
}

_CONSTANTS: dict[str, Expr] = {
    "pi": sympy.pi,
    "infty": sympy.oo,
}

_RELATIONS = {
    "=": sympy.Eq,
    "neq": sympy.Ne,
    "ne": sympy.Ne,
    "<": sympy.Lt,
    "le": sympy.Le,
    "leq": sympy.Le,
    ">": sympy.Gt,
    "ge": sympy.Ge,
    "geq": sympy.Ge,
}

_SPACING_COMMANDS = {"quad", "qquad", "displaystyle", "textstyle", "scriptstyle", "limits", "nolimits"}
_TEXT_WRAPPERS = {"text", "mathrm", "mathbf", "mathit", "mathsf", "mathcal", "operatorname"}


@dataclass(frozen=True)
class FormulaVerifyResult:
    valid: bool
    canonical_latex: str | None
    diagnostic_code: str | None


class _ParseTimeout(Exception):
    pass


class _ParseSyntaxError(Exception):
    pass


@dataclass(frozen=True)
class _Token:
    kind: str  # NUM, IDENT, CMD, OP, LBRACE, RBRACE, LPAREN, RPAREN, LBRACK, RBRACK, CARET, UNDER, COMMA, BAR, EOF
    value: str


def _strip_outer_math_delimiters(text: str) -> str:
    s = text.strip()
    if s.startswith("$$") and s.endswith("$$") and len(s) >= 4:
        return s[2:-2].strip()
    if s.startswith("$") and s.endswith("$") and len(s) >= 2:
        return s[1:-1].strip()
    if s.startswith(r"\[") and s.endswith(r"\]") and len(s) >= 4:
        return s[2:-2].strip()
    if s.startswith(r"\(") and s.endswith(r"\)") and len(s) >= 4:
        return s[2:-2].strip()
    return s


def _check_brace_depth(text: str) -> str | None:
    """Validate nesting depth <= MAX_BRACE_DEPTH and balanced delimiters before parsing."""
    depth = 0
    stack: list[str] = []
    i = 0
    n = len(text)
    pairs = {"{": "}", "(": ")", "[": "]"}
    closing = {"}": "{", ")": "(", "]": "["}
    while i < n:
        ch = text[i]
        if ch == "\\":
            if i + 1 < n and text[i + 1] in "{}()[]|":
                i += 2
                continue
            if text.startswith(r"\left", i) and (i + 5 == n or not text[i + 5].isalpha()):
                depth += 1
                if depth > MAX_BRACE_DEPTH:
                    return "formula_too_deep"
                stack.append("left")
                i += 5
                continue
            if text.startswith(r"\right", i) and (i + 6 == n or not text[i + 6].isalpha()):
                if not stack or stack[-1] != "left":
                    return "formula_ast_invalid"
                stack.pop()
                depth -= 1
                i += 6
                continue
            i += 1
            continue
        if ch in pairs:
            depth += 1
            if depth > MAX_BRACE_DEPTH:
                return "formula_too_deep"
            stack.append(ch)
        elif ch in closing:
            if not stack or stack[-1] != closing[ch]:
                return "formula_ast_invalid"
            stack.pop()
            depth -= 1
        i += 1
    if stack:
        return "formula_ast_invalid"
    return None


def _tokenize(text: str, deadline: float) -> list[_Token]:
    tokens: list[_Token] = []
    i = 0
    n = len(text)
    while i < n:
        if time.monotonic() >= deadline:
            raise _ParseTimeout()
        if len(tokens) >= _MAX_TOKENS:
            raise _ParseSyntaxError("token_limit")
        ch = text[i]
        if ch.isspace():
            i += 1
            continue
        if ch == "\\":
            if i + 1 >= n:
                raise _ParseSyntaxError("dangling_backslash")
            nxt = text[i + 1]
            if nxt in (",", ";", ":", "!", " "):
                i += 2
                continue
            if nxt in ("{", "}"):
                tokens.append(_Token("LBRACE" if nxt == "{" else "RBRACE", nxt))
                i += 2
                continue
            if nxt == "|":
                tokens.append(_Token("BAR", "|"))
                i += 2
                continue
            j = i + 1
            while j < n and text[j].isalpha():
                j += 1
            if j == i + 1:
                raise _ParseSyntaxError("unknown_escape")
            cmd = text[i + 1 : j]
            i = j
            if cmd in _SPACING_COMMANDS:
                continue
            if cmd in ("left", "right"):
                # Skip the delimiter token right after \left / \right when it's '.'
                while i < n and text[i].isspace():
                    i += 1
                if i < n and text[i] == ".":
                    i += 1
                continue
            tokens.append(_Token("CMD", cmd))
            continue
        if ch.isdigit() or (ch == "." and i + 1 < n and text[i + 1].isdigit()):
            j = i
            has_dot = False
            while j < n and (text[j].isdigit() or (text[j] == "." and not has_dot)):
                if text[j] == ".":
                    has_dot = True
                j += 1
            tokens.append(_Token("NUM", text[i:j]))
            i = j
            continue
        if ch.isalpha():
            tokens.append(_Token("IDENT", ch))
            i += 1
            continue
        single = {
            "{": "LBRACE",
            "}": "RBRACE",
            "(": "LPAREN",
            ")": "RPAREN",
            "[": "LBRACK",
            "]": "RBRACK",
            "^": "CARET",
            "_": "UNDER",
            ",": "COMMA",
            "|": "BAR",
        }
        if ch in single:
            tokens.append(_Token(single[ch], ch))
            i += 1
            continue
        if ch in "+-*/=<>!":
            tokens.append(_Token("OP", ch))
            i += 1
            continue
        raise _ParseSyntaxError(f"unexpected_char:{ch}")
    tokens.append(_Token("EOF", ""))
    return tokens


class _LatexParser:
    def __init__(self, tokens: list[_Token], deadline: float) -> None:
        self._tokens = tokens
        self._pos = 0
        self._deadline = deadline

    def _check_deadline(self) -> None:
        if time.monotonic() >= self._deadline:
            raise _ParseTimeout()

    def _peek(self) -> _Token:
        self._check_deadline()
        return self._tokens[self._pos]

    def _consume(self, kind: str | None = None, value: str | None = None) -> _Token:
        tok = self._peek()
        if kind is not None and tok.kind != kind:
            raise _ParseSyntaxError(f"expected_{kind}_got_{tok.kind}")
        if value is not None and tok.value != value:
            raise _ParseSyntaxError(f"expected_{value}_got_{tok.value}")
        self._pos += 1
        return tok

    def parse(self) -> Expr:
        expr = self._parse_relation()
        if self._peek().kind != "EOF":
            raise _ParseSyntaxError("trailing_tokens")
        return expr

    def _parse_relation(self) -> Expr:
        lhs = self._parse_add()
        tok = self._peek()
        if tok.kind == "OP" and tok.value in ("=", "<", ">"):
            op = self._consume().value
            rhs = self._parse_add()
            return _RELATIONS[op](lhs, rhs, evaluate=False)
        if tok.kind == "CMD" and tok.value in _RELATIONS:
            cmd = self._consume().value
            rhs = self._parse_add()
            return _RELATIONS[cmd](lhs, rhs, evaluate=False)
        return lhs

    def _parse_add(self) -> Expr:
        left = self._parse_mul()
        while True:
            tok = self._peek()
            if tok.kind == "OP" and tok.value in ("+", "-"):
                op = self._consume().value
                right = self._parse_mul()
                if op == "+":
                    left = sympy.Add(left, right, evaluate=False)
                else:
                    left = sympy.Add(left, sympy.Mul(-1, right, evaluate=False), evaluate=False)
            elif tok.kind == "CMD" and tok.value in ("pm", "mp"):
                self._consume()
                right = self._parse_mul()
                left = sympy.Add(left, right, evaluate=False)
            else:
                break
        return left

    def _can_start_implicit_mul(self, tok: _Token) -> bool:
        if tok.kind in ("NUM", "IDENT", "LPAREN", "LBRACE"):
            return True
        if tok.kind == "CMD":
            return (
                tok.value in _GREEK_LETTERS
                or tok.value in _FUNCTIONS
                or tok.value in _CONSTANTS
                or tok.value in _TEXT_WRAPPERS
                or tok.value in ("frac", "dfrac", "tfrac", "sum", "prod", "int")
            )
        return False

    def _parse_mul(self) -> Expr:
        factors = [self._parse_unary()]
        while True:
            tok = self._peek()
            if tok.kind == "OP" and tok.value == "*":
                self._consume()
                factors.append(self._parse_unary())
            elif tok.kind == "OP" and tok.value == "/":
                self._consume()
                denom = self._parse_unary()
                factors[-1] = sympy.Mul(factors[-1], sympy.Pow(denom, -1, evaluate=False), evaluate=False)
            elif tok.kind == "CMD" and tok.value in ("cdot", "times"):
                self._consume()
                factors.append(self._parse_unary())
            elif tok.kind == "CMD" and tok.value == "div":
                self._consume()
                denom = self._parse_unary()
                factors[-1] = sympy.Mul(factors[-1], sympy.Pow(denom, -1, evaluate=False), evaluate=False)
            elif self._can_start_implicit_mul(tok):
                factors.append(self._parse_unary())
            else:
                break
        if len(factors) == 1:
            return factors[0]
        return sympy.Mul(*factors, evaluate=False)

    def _parse_unary(self) -> Expr:
        tok = self._peek()
        if tok.kind == "OP" and tok.value == "+":
            self._consume()
            return self._parse_unary()
        if tok.kind == "OP" and tok.value == "-":
            self._consume()
            return sympy.Mul(-1, self._parse_unary(), evaluate=False)
        return self._parse_postfix()

    def _parse_postfix(self) -> Expr:
        base = self._parse_atom()
        while True:
            tok = self._peek()
            if tok.kind == "CARET":
                self._consume()
                exp = self._parse_script_arg()
                base = sympy.Pow(base, exp, evaluate=False)
            elif tok.kind == "UNDER":
                self._consume()
                sub = self._parse_script_arg()
                if isinstance(base, sympy.Symbol):
                    base = sympy.Symbol(f"{base.name}_{sub}")
                else:
                    raise _ParseSyntaxError("invalid_subscript_target")
            elif tok.kind == "OP" and tok.value == "!":
                self._consume()
                base = sympy.factorial(base, evaluate=False)
            else:
                break
        return base

    def _parse_script_arg(self) -> Expr:
        tok = self._peek()
        if tok.kind == "LBRACE":
            return self._parse_braced_expr()
        if tok.kind == "NUM":
            num = self._consume().value
            return sympy.Integer(int(num)) if "." not in num else sympy.Float(num)
        if tok.kind == "IDENT":
            return sympy.Symbol(self._consume().value)
        if tok.kind == "CMD" and (tok.value in _GREEK_LETTERS or tok.value in _CONSTANTS):
            return self._parse_atom()
        raise _ParseSyntaxError("invalid_script_argument")

    def _parse_braced_expr(self) -> Expr:
        self._consume("LBRACE")
        if self._peek().kind == "RBRACE":
            raise _ParseSyntaxError("empty_braces")
        expr = self._parse_relation()
        self._consume("RBRACE")
        return expr

    def _parse_atom(self) -> Expr:
        tok = self._peek()
        if tok.kind == "NUM":
            self._consume()
            return sympy.Integer(int(tok.value)) if "." not in tok.value else sympy.Float(tok.value)
        if tok.kind == "IDENT":
            self._consume()
            return sympy.Symbol(tok.value)
        if tok.kind == "LBRACE":
            return self._parse_braced_expr()
        if tok.kind == "LPAREN":
            self._consume("LPAREN")
            if self._peek().kind == "RPAREN":
                raise _ParseSyntaxError("empty_parens")
            expr = self._parse_relation()
            self._consume("RPAREN")
            return expr
        if tok.kind == "LBRACK":
            self._consume("LBRACK")
            if self._peek().kind == "RBRACK":
                raise _ParseSyntaxError("empty_brackets")
            expr = self._parse_relation()
            self._consume("RBRACK")
            return expr
        if tok.kind == "BAR":
            self._consume("BAR")
            expr = self._parse_add()
            self._consume("BAR")
            return sympy.Abs(expr, evaluate=False)
        if tok.kind == "CMD":
            return self._parse_command()
        raise _ParseSyntaxError(f"unexpected_token:{tok.kind}")

    def _parse_command(self) -> Expr:
        cmd = self._consume("CMD").value
        if cmd in _CONSTANTS:
            return _CONSTANTS[cmd]
        if cmd in _GREEK_LETTERS:
            return sympy.Symbol(cmd)
        if cmd in ("frac", "dfrac", "tfrac"):
            num = self._parse_braced_expr()
            den = self._parse_braced_expr()
            return sympy.Mul(num, sympy.Pow(den, -1, evaluate=False), evaluate=False)
        if cmd == "sqrt":
            root_order: Expr | None = None
            if self._peek().kind == "LBRACK":
                self._consume("LBRACK")
                root_order = self._parse_add()
                self._consume("RBRACK")
            arg = self._parse_braced_expr()
            if root_order is not None:
                return sympy.Pow(arg, sympy.Pow(root_order, -1, evaluate=False), evaluate=False)
            return sympy.sqrt(arg, evaluate=False)
        if cmd in _FUNCTIONS:
            fn = _FUNCTIONS[cmd]
            if self._peek().kind == "LPAREN":
                self._consume("LPAREN")
                arg = self._parse_add()
                self._consume("RPAREN")
            elif self._peek().kind == "LBRACE":
                arg = self._parse_braced_expr()
            else:
                arg = self._parse_postfix()
            return fn(arg, evaluate=False)
        if cmd in _TEXT_WRAPPERS:
            return self._parse_text_symbol()
        if cmd in ("sum", "prod", "int"):
            return self._parse_big_operator(cmd)
        raise _ParseSyntaxError(f"disallowed_command:{cmd}")

    def _parse_text_symbol(self) -> Expr:
        self._consume("LBRACE")
        parts: list[str] = []
        while self._peek().kind != "RBRACE":
            tok = self._peek()
            if tok.kind in ("IDENT", "NUM"):
                parts.append(self._consume().value)
            else:
                raise _ParseSyntaxError("invalid_text_content")
        self._consume("RBRACE")
        if not parts:
            raise _ParseSyntaxError("empty_text")
        return sympy.Symbol("".join(parts))

    def _parse_big_operator(self, cmd: str) -> Expr:
        lower: Expr | None = None
        upper: Expr | None = None
        for _ in range(2):
            if self._peek().kind == "UNDER":
                self._consume()
                lower = self._parse_script_arg()
            elif self._peek().kind == "CARET":
                self._consume()
                upper = self._parse_script_arg()
        body = self._parse_mul()
        if cmd == "int":
            return sympy.Integral(body, sympy.Symbol("x")) if lower is None or upper is None else sympy.Integral(body, (sympy.Symbol("x"), lower, upper))
        var = sympy.Symbol("i")
        if isinstance(lower, sympy.Eq) and isinstance(lower.lhs, sympy.Symbol):
            var = lower.lhs
            lower = lower.rhs
        bounds = (var, lower if lower is not None else sympy.Integer(0), upper if upper is not None else sympy.Symbol("n"))
        if cmd == "sum":
            return sympy.Sum(body, bounds)
        return sympy.Product(body, bounds)


def verify_latex_formula(latex: str, *, timeout_ms: int = MAX_PARSE_MS) -> FormulaVerifyResult:
    """Validate and canonicalize a LaTeX math formula without dynamic evaluation."""
    if not isinstance(latex, str) or timeout_ms <= 0:
        return FormulaVerifyResult(False, None, "formula_ast_invalid")
    if len(latex.encode("utf-8")) > MAX_LATEX_BYTES:
        return FormulaVerifyResult(False, None, "formula_too_long")
    if "\x00" in latex or "\ufffd" in latex:
        return FormulaVerifyResult(False, None, "formula_ast_invalid")
    for pattern in _DANGEROUS_PATTERNS:
        if pattern.search(latex):
            return FormulaVerifyResult(False, None, "formula_ast_invalid")

    stripped = _strip_outer_math_delimiters(latex)
    if not stripped:
        return FormulaVerifyResult(False, None, "formula_ast_invalid")

    depth_error = _check_brace_depth(stripped)
    if depth_error is not None:
        return FormulaVerifyResult(False, None, depth_error)

    deadline = time.monotonic() + (timeout_ms / 1000.0)
    try:
        tokens = _tokenize(stripped, deadline)
        expr = _LatexParser(tokens, deadline).parse()
        if time.monotonic() >= deadline:
            return FormulaVerifyResult(False, None, "formula_timeout")
        canonical_latex = sympy.latex(expr)
    except _ParseTimeout:
        return FormulaVerifyResult(False, None, "formula_timeout")
    except (_ParseSyntaxError, ValueError, TypeError, RecursionError):
        return FormulaVerifyResult(False, None, "formula_ast_invalid")

    return FormulaVerifyResult(True, canonical_latex, None)
