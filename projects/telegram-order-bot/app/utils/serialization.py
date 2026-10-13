from __future__ import annotations

from dataclasses import asdict, is_dataclass
from decimal import Decimal
from typing import Any

import orjson


def _default(value: Any) -> Any:
    if isinstance(value, Decimal):
        return str(value)
    if is_dataclass(value):
        return asdict(value)
    raise TypeError(f"Unsupported type: {type(value)!r}")


def dumps(data: Any) -> str:
    return orjson.dumps(data, default=_default).decode("utf-8")


def loads(data: str | bytes | None, default: Any) -> Any:
    if not data:
        return default
    return orjson.loads(data)
