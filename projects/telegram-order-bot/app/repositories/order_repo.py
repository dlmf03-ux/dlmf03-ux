from __future__ import annotations

from decimal import Decimal

from app.models import OrderItem
from app.repositories.database import Database


class OrderRepository:
    def __init__(self, db: Database) -> None:
        self.db = db

    async def save_order(
        self,
        contacts: dict,
        region: str | None,
        payment_method: str,
        address: str,
        social_source: bool | None,
        promo_used: bool,
        promo_code: str | None,
        total_amount: Decimal,
        original_amount: Decimal,
        telegram_id: int | None,
        username: str | None,
        has_exclusions: bool,
        items: list[OrderItem],
    ) -> int:
        assert self.db.pool is not None
        order_sql = '''
        INSERT INTO orders (
            customer_name, phone, email, region, payment_method, address,
            social_source, promo_used, promo_code, total_amount, original_amount,
            telegram_id, username, has_exclusions, created_at
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        '''
        item_sql = '''
        INSERT INTO order_items (
            order_id, article, product_name, price, quantity, sum, excluded_from_promo
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s)
        '''
        async with self.db.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    order_sql,
                    (
                        contacts.get("name", ""),
                        contacts.get("phone", ""),
                        contacts.get("email", ""),
                        region or "",
                        payment_method,
                        address,
                        int(bool(social_source)) if social_source is not None else 0,
                        int(bool(promo_used)),
                        promo_code or "",
                        total_amount,
                        original_amount,
                        telegram_id,
                        username,
                        int(bool(has_exclusions)),
                    ),
                )
                order_id = cur.lastrowid
                for item in items:
                    await cur.execute(
                        item_sql,
                        (
                            order_id,
                            item.article,
                            item.name,
                            item.price,
                            item.quantity,
                            item.sum,
                            int(item.excluded),
                        ),
                    )
            await conn.commit()
        return int(order_id)
