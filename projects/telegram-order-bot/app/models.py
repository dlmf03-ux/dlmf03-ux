from __future__ import annotations

from dataclasses import dataclass, field
from decimal import Decimal
from typing import Any


@dataclass(slots=True)
class UserProfile:
    telegram_id: int | None
    username: str | None
    first_name: str | None
    last_name: str | None
    name: str | None
    phone: str | None
    email: str | None
    region: str | None
    address: str | None = None


@dataclass(slots=True)
class PromoCode:
    code: str
    discount: Decimal
    message: str | None


@dataclass(slots=True)
class ParsedArticle:
    original: str
    variants: list[str]
    quantity: int


@dataclass(slots=True)
class OrderItem:
    article: str
    db_article: str
    name: str
    price: Decimal
    quantity: int
    sum: Decimal
    discounted_sum: Decimal
    excluded: bool = False


@dataclass(slots=True)
class BotState:
    state: str = "start"
    user_checked: bool = False
    user_exists: bool = False
    social_source: bool | None = None
    promo_used: bool = False
    promo_code: str | None = None
    promo_discount: Decimal | None = None
    payment_method: str | None = None
    region: str | None = None
    address: str | None = None
    contacts: dict[str, Any] = field(default_factory=dict)
    temp_contacts: dict[str, Any] = field(default_factory=dict)
    order_items: list[dict[str, Any]] = field(default_factory=list)
    large_quantity_items: list[dict[str, Any]] = field(default_factory=list)

    def reset_for_new_order(self) -> None:
        self.state = "start"
        self.social_source = None
        self.promo_used = False
        self.promo_code = None
        self.promo_discount = None
        self.payment_method = None
        self.region = None
        self.address = None
        self.temp_contacts = {}
        self.order_items = []
        self.large_quantity_items = []
