from __future__ import annotations

from decimal import Decimal

import aiomysql

from app.models import PromoCode
from app.repositories.database import Database


class CatalogRepository:
    def __init__(self, db: Database) -> None:
        self.db = db

    async def load_promo_codes(self) -> dict[str, PromoCode]:
        assert self.db.pool is not None
        query = "SELECT code, discount, message FROM promo_codes WHERE is_active = 1"
        result: dict[str, PromoCode] = {}
        async with self.db.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(query)
                rows = await cur.fetchall()
        for row in rows:
            result[row["code"]] = PromoCode(
                code=row["code"],
                discount=Decimal(str(row["discount"])),
                message=row["message"],
            )
        return result

    async def load_promo_exclusions(self) -> set[str]:
        assert self.db.pool is not None
        query = "SELECT article FROM promo_exclusions"
        async with self.db.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(query)
                rows = await cur.fetchall()
        return {str(row[0]) for row in rows}

    async def find_products_by_articles(self, articles: list[str]) -> list[dict]:
        assert self.db.pool is not None
        if not articles:
            return []
        placeholders = ",".join(["%s"] * len(articles))
        query = f"SELECT article, name, price FROM products WHERE article IN ({placeholders})"
        async with self.db.pool.acquire() as conn:
            async with conn.cursor(aiomysql.DictCursor) as cur:
                await cur.execute(query, tuple(articles))
                return list(await cur.fetchall())
