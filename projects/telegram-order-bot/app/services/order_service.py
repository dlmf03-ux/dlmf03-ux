from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP

from app.models import OrderItem, ParsedArticle


def money(value: Decimal | float | str) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def generate_article_variants(article: str) -> list[str]:
    article = article.strip()
    variants: list[str] = []
    for length in range(len(article), 6):
        variants.append(article.zfill(length))
    if article not in variants:
        variants.append(article)
    return list(dict.fromkeys(variants))


def parse_articles_input(raw_text: str) -> tuple[list[ParsedArticle], list[str]]:
    parts = [part.strip() for part in raw_text.replace(",", " ").split() if part.strip()]
    parsed: list[ParsedArticle] = []
    errors: list[str] = []
    for index in range(0, len(parts), 2):
        if index + 1 >= len(parts):
            errors.append(parts[index])
            continue
        article = parts[index]
        quantity_raw = parts[index + 1]
        if not article.isdigit():
            errors.append(f"{article} {quantity_raw}")
            continue
        try:
            quantity = int(quantity_raw)
        except ValueError:
            errors.append(f"{article} {quantity_raw}")
            continue
        if quantity <= 0:
            errors.append(f"{article} {quantity_raw}")
            continue
        parsed.append(
            ParsedArticle(
                original=article,
                variants=generate_article_variants(article),
                quantity=quantity,
            )
        )
    return parsed, errors


def check_large_quantities(order_items: list[ParsedArticle], threshold: int = 100) -> list[dict]:
    result: list[dict] = []
    for item in order_items:
        if item.quantity > threshold:
            result.append({"original": item.original, "quantity": item.quantity})
    return result


def build_order_items(
    parsed_items: list[ParsedArticle],
    products: list[dict],
    excluded_articles: set[str],
    promo_used: bool,
    promo_code: str | None,
) -> tuple[list[OrderItem], list[str], Decimal, Decimal, bool, bool]:
    bot_base_discount = Decimal("0.17")
    promo_total_discount = Decimal("0.20")
    promo_multiplier = Decimal("1.00")
    if promo_used and promo_code:
        promo_multiplier = (Decimal("1.00") - promo_total_discount) / (Decimal("1.00") - bot_base_discount)

    original_by_variant: dict[str, str] = {}
    qty_by_original: dict[str, int] = {}
    for item in parsed_items:
        qty_by_original[item.original] = item.quantity
        for variant in item.variants:
            original_by_variant[variant] = item.original

    found_variants = {str(row["article"]) for row in products}
    not_found = [
        item.original
        for item in parsed_items
        if not any(variant in found_variants for variant in item.variants)
    ]

    order_items: list[OrderItem] = []
    total = Decimal("0.00")
    discounted_total = Decimal("0.00")
    has_exclusions = False
    discount_applied = False

    for row in products:
        db_article = str(row["article"])
        original = original_by_variant[db_article]
        quantity = qty_by_original[original]
        price = money(row["price"])
        item_sum = money(price * quantity)
        excluded = db_article in excluded_articles
        if excluded:
            discounted_sum = item_sum
            has_exclusions = True
        else:
            discounted_sum = money(item_sum * promo_multiplier)
            if promo_multiplier < Decimal("1.00"):
                discount_applied = True
        order_items.append(
            OrderItem(
                article=original,
                db_article=db_article,
                name=str(row["name"]),
                price=price,
                quantity=quantity,
                sum=item_sum,
                discounted_sum=discounted_sum,
                excluded=excluded,
            )
        )
        total += item_sum
        discounted_total += discounted_sum

    return (
        order_items,
        not_found,
        money(total),
        money(discounted_total),
        has_exclusions,
        discount_applied,
    )


PAYMENT_NAMES = {
    "cash": "Наличные",
    "card": "Карта",
    "bank_transfer": "Безналичный расчет",
    "sberbank": "Сбербанк",
}


def get_payment_method_name(code: str) -> str:
    return PAYMENT_NAMES.get(code, code)


def build_order_response(
    items: list[OrderItem],
    discounted_total: Decimal,
    total: Decimal,
    discount_applied: bool,
    has_exclusions: bool,
    promo_used: bool,
    promo_code: str | None,
) -> str:
    bot_base_discount = Decimal("0.17")
    promo_total_discount = Decimal("0.20")
    promo_multiplier = Decimal("1.00")
    if promo_used and promo_code:
        promo_multiplier = (Decimal("1.00") - promo_total_discount) / (Decimal("1.00") - bot_base_discount)

    lines = ["✅ Заказ оформлен!\n"]
    for item in items:
        lines.append(f"{item.article} - {item.name}")
        if item.excluded:
            lines.append(f"Цена: {item.price:.2f} руб. x {item.quantity} = {item.sum:.2f} руб. (товар по акции)\n")
        else:
            if discount_applied:
                discounted_price = money(item.price * promo_multiplier)
                lines.append(
                    f"Цена со скидкой: {discounted_price:.2f} руб. x {item.quantity} = {item.discounted_sum:.2f} руб."
                )
                lines.append(f"Цена без скидки: {item.price:.2f} руб. x {item.quantity} = {item.sum:.2f} руб.\n")
            else:
                lines.append(f"Цена: {item.price:.2f} руб. x {item.quantity} = {item.sum:.2f} руб.\n")

    lines.append(f"\nСумма заказа: {discounted_total:.2f} руб.")
    if discount_applied:
        lines.append(f"Сумма без скидки: {total:.2f} руб.")
    if has_exclusions:
        lines.append("\nПримечание: для некоторых позиций в заказе не применяются промокоды")
    lines.append(
        "\nИтоговая сумма заказа возможно станет меньше, если у Вас есть персональные скидки на определенные группы товаров."
        "\n\nВ ближайшее время с Вами свяжутся наши менеджеры.\nОжидайте звонка!"
    )
    return "\n".join(lines)
